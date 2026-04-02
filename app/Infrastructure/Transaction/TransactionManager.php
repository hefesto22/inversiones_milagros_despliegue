<?php

namespace App\Infrastructure\Transaction;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Exception;

/**
 * Gestor centralizado de transacciones
 *
 * Proporciona:
 * - Manejo automático de rollback
 * - Retry en deadlock
 * - Logging de transacciones
 * - Garantías ACID
 */
final class TransactionManager
{
    private const MAX_ATTEMPTS = 3;
    private const DEADLOCK_DELAY_MIN_MS = 100;
    private const DEADLOCK_DELAY_MAX_MS = 500;

    /**
     * Ejecutar un callback dentro de una transacción
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     * @throws Throwable
     */
    public function execute(Closure $callback): mixed
    {
        $attempts = 0;

        do {
            try {
                return DB::transaction(function () use ($callback) {
                    $result = $callback();

                    // Logging de transacción exitosa
                    Log::debug('Transacción ejecutada exitosamente', [
                        'timestamp' => now(),
                    ]);

                    return $result;
                });
            } catch (Throwable $e) {
                $attempts++;

                // Si es deadlock y hay reintentos disponibles
                if ($this->isDeadlockException($e) && $attempts < self::MAX_ATTEMPTS) {
                    $delay = random_int(self::DEADLOCK_DELAY_MIN_MS, self::DEADLOCK_DELAY_MAX_MS);

                    Log::warning("Deadlock detectado, reintentando ({$attempts}/" . self::MAX_ATTEMPTS . ") en {$delay}ms", [
                        'error' => $e->getMessage(),
                    ]);

                    usleep($delay * 1000);
                    continue;
                }

                // Log del error final
                Log::error('Error en transacción', [
                    'error' => $e->getMessage(),
                    'attempts' => $attempts,
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        } while ($attempts < self::MAX_ATTEMPTS);
    }

    /**
     * Ejecutar múltiples callbacks en una misma transacción
     *
     * @param Closure[] $callbacks
     * @return mixed[]
     */
    public function executeMultiple(array $callbacks): array
    {
        return $this->execute(function () use ($callbacks) {
            $results = [];

            foreach ($callbacks as $key => $callback) {
                $results[$key] = $callback();
            }

            return $results;
        });
    }

    /**
     * Ejecutar con savepoint
     *
     * Permite rollback parcial sin afectar toda la transacción
     */
    public function executeWithSavepoint(string $savepointName, Closure $callback): mixed
    {
        try {
            DB::statement("SAVEPOINT {$savepointName}");
            $result = $callback();
            return $result;
        } catch (Throwable $e) {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepointName}");
            Log::warning("Savepoint {$savepointName} rolled back", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Verificar si es excepción de deadlock
     */
    private function isDeadlockException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || (method_exists($e, 'getCode') && in_array($e->getCode(), [1205, 1213]));
    }
}
