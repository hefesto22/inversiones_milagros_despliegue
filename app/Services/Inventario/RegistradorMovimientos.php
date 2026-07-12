<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\MovimientoInventarioTipo;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ÚNICO punto de escritura del Kardex de Inventario.
 *
 * Contrato con los callers:
 *   - Llamar SIEMPRE después de persistir la mutación de stock (lote->save() /
 *     bodegaProducto->save()) y DENTRO de la misma transacción. Así
 *     saldo_despues es el saldo real post-movimiento y, en modo estricto,
 *     un fallo del Kardex revierte la operación completa.
 *
 * Flags (config/inventario.php → kardex):
 *   - habilitado: kill-switch global. En false no se registra nada (rollout /
 *     emergencia), sin romper a los callers.
 *   - estricto:   en false (rollout inicial) un error del Kardex se loguea y
 *     la operación de negocio continúa. En true, el error se relanza y la
 *     transacción del caller hace rollback — exactitud garantizada por diseño.
 *     Mismo patrón de despliegue seguro que usamos en el shadow-mode del WAC.
 */
final class RegistradorMovimientos
{
    /**
     * Asienta un movimiento de HUEVO SUELTO a nivel de lote.
     *
     * @param Lote        $lote           Lote YA persistido con el saldo post-movimiento
     * @param float       $delta          Huevos: positivo = entra, negativo = sale
     * @param float|null  $costoUnitario  Costo por huevo del movimiento (null = sin valorar)
     * @param Model|null  $referencia     Documento origen (Venta, Viaje, Reempaque, Merma, AjusteInventario, ...)
     */
    public function registrarLote(
        Lote                     $lote,
        MovimientoInventarioTipo $tipo,
        float                    $delta,
        ?float                   $costoUnitario = null,
        ?Model                   $referencia = null,
        ?string                  $descripcion = null,
        array                    $contexto = [],
        ?int                     $userId = null,
    ): ?MovimientoInventario {
        return $this->registrar([
            'nivel'              => MovimientoInventario::NIVEL_LOTE,
            'lote_id'            => $lote->id,
            'bodega_producto_id' => null,
            'producto_id'        => $lote->producto_id,
            'bodega_id'          => $lote->bodega_id,
            'unidad'             => MovimientoInventario::UNIDAD_HUEVOS,
            'saldo_despues'      => (float) $lote->cantidad_huevos_remanente,
        ], $tipo, $delta, $costoUnitario, $referencia, $descripcion, $contexto, $userId, null, null);
    }

    /**
     * Asienta un movimiento de PRODUCTO TERMINADO (empacado / lácteos) a nivel
     * de bodega_producto.
     *
     * @param BodegaProducto $bodegaProducto Registro YA persistido con el stock post-movimiento
     * @param float          $delta          Unidades: positivo = entra, negativo = sale
     */
    public function registrarBodega(
        BodegaProducto           $bodegaProducto,
        MovimientoInventarioTipo $tipo,
        float                    $delta,
        ?float                   $costoUnitario = null,
        ?Model                   $referencia = null,
        ?string                  $descripcion = null,
        array                    $contexto = [],
        ?int                     $userId = null,
        ?string                  $referenciaTipo = null,
        ?int                     $referenciaId = null,
    ): ?MovimientoInventario {
        return $this->registrar([
            'nivel'              => MovimientoInventario::NIVEL_BODEGA,
            'lote_id'            => null,
            'bodega_producto_id' => $bodegaProducto->id,
            'producto_id'        => $bodegaProducto->producto_id,
            'bodega_id'          => $bodegaProducto->bodega_id,
            'unidad'             => MovimientoInventario::UNIDAD_UNIDADES,
            'saldo_despues'      => (float) $bodegaProducto->stock,
        ], $tipo, $delta, $costoUnitario, $referencia, $descripcion, $contexto, $userId, $referenciaTipo, $referenciaId);
    }

    // ============================================================
    // NÚCLEO PRIVADO
    // ============================================================

    private function registrar(
        array                    $contenedor,
        MovimientoInventarioTipo $tipo,
        float                    $delta,
        ?float                   $costoUnitario,
        ?Model                   $referencia,
        ?string                  $descripcion,
        array                    $contexto,
        ?int                     $userId,
        ?string                  $referenciaTipo,
        ?int                     $referenciaId,
    ): ?MovimientoInventario {
        if (! $this->habilitado()) {
            return null;
        }

        try {
            if ($delta == 0.0) {
                // Un movimiento de cero no aporta información — no ensucia el libro.
                return null;
            }

            return MovimientoInventario::create(array_merge($contenedor, [
                'ocurrido_en'     => now(),
                'tipo'            => $tipo,
                'delta'           => $delta,
                'costo_unitario'  => $costoUnitario,
                'valor'           => $costoUnitario !== null
                    ? round(abs($delta) * $costoUnitario, 4)
                    : null,
                'referencia_type' => $referencia?->getMorphClass() ?? $referenciaTipo,
                'referencia_id'   => $referencia?->getKey() ?? $referenciaId,
                'descripcion'     => $descripcion,
                'contexto'        => $contexto !== [] ? $contexto : null,
                'created_by'      => $userId ?? Auth::id(),
            ]));
        } catch (Throwable $e) {
            if ($this->estricto()) {
                // Modo estricto: el Kardex es parte de la transacción de negocio.
                // Si no se puede asentar, la operación completa se revierte.
                throw $e;
            }

            // Rollout: nunca romper una venta/carga por un bug del Kardex.
            Log::error('Kardex: fallo al registrar movimiento (modo no estricto, operación continúa)', [
                'tipo'       => $tipo->value,
                'delta'      => $delta,
                'contenedor' => $contenedor,
                'exception'  => [
                    'class'   => get_class($e),
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ],
            ]);

            return null;
        }
    }

    private function habilitado(): bool
    {
        return (bool) config('inventario.kardex.habilitado', true);
    }

    private function estricto(): bool
    {
        return (bool) config('inventario.kardex.estricto', false);
    }
}
