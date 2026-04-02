<?php

namespace App\Models;

use App\Enums\MermaMotivo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Merma extends Model
{
    use HasFactory;

    protected $table = 'mermas';

    protected $fillable = [
        'lote_id',
        'bodega_id',
        'producto_id',
        'numero_merma',
        'cantidad_huevos',
        'cubierto_por_regalo',
        'perdida_real_huevos',
        'perdida_real_lempiras',
        'motivo',
        'descripcion',
        'buffer_antes',
        'buffer_despues',
        'created_by',
    ];

    protected $casts = [
        'cantidad_huevos' => 'decimal:2',
        'cubierto_por_regalo' => 'decimal:2',
        'perdida_real_huevos' => 'decimal:2',
        'perdida_real_lempiras' => 'decimal:2',
        'buffer_antes' => 'decimal:2',
        'buffer_despues' => 'decimal:2',
        'motivo' => MermaMotivo::class,
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================
    // METODOS ESTATICOS
    // ============================================

    /**
     * Generar numero de merma automatico.
     * Usa MAX(id) con lockForUpdate para evitar race conditions
     * donde dos requests simultáneos generen el mismo número.
     *
     * NOTA: Este método debe llamarse dentro de una transacción (registrarMerma ya lo hace).
     */
    public static function generarNumeroMerma(int $bodegaId): string
    {
        // Usar MAX(id) en vez de ORDER BY string para obtener el último registro real
        $ultimaMerma = self::where('bodega_id', $bodegaId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('numero_merma');

        if ($ultimaMerma) {
            $prefijo = "M-B{$bodegaId}-";
            $numeroSecuencial = (int) str_replace($prefijo, '', $ultimaMerma);
            $nuevoNumero = $numeroSecuencial + 1;
        } else {
            $nuevoNumero = 1;
        }

        $numeroFormateado = str_pad($nuevoNumero, 6, '0', STR_PAD_LEFT);

        return "M-B{$bodegaId}-{$numeroFormateado}";
    }

    // ============================================
    // METODOS DE INSTANCIA
    // ============================================

    /**
     * Verificar si hubo perdida economica
     */
    public function tuvoPerdidaEconomica(): bool
    {
        return $this->perdida_real_huevos > 0;
    }

    /**
     * Obtener el porcentaje cubierto por regalo
     */
    public function getPorcentajeCubierto(): float
    {
        if ($this->cantidad_huevos <= 0) {
            return 0;
        }

        return round(($this->cubierto_por_regalo / $this->cantidad_huevos) * 100, 2);
    }

    /**
     * Obtener etiqueta del motivo
     */
    public function getMotivoLabel(): string
    {
        if ($this->motivo instanceof MermaMotivo) {
            return $this->motivo->label();
        }

        // Fallback para valores legacy sin cast
        return (string) $this->motivo;
    }

    /**
     * Obtener resumen de la merma
     */
    public function getResumen(): array
    {
        return [
            'numero' => $this->numero_merma,
            'cantidad' => $this->cantidad_huevos,
            'cubierto' => $this->cubierto_por_regalo,
            'perdida_huevos' => $this->perdida_real_huevos,
            'perdida_lempiras' => $this->perdida_real_lempiras,
            'motivo' => $this->getMotivoLabel(),
            'tuvo_perdida' => $this->tuvoPerdidaEconomica(),
            'porcentaje_cubierto' => $this->getPorcentajeCubierto(),
        ];
    }

    /**
     * Eliminar merma y revertir cambios en el lote
     * 
     * IMPORTANTE: Este metodo revierte todos los cambios que se hicieron
     * al registrar la merma, incluyendo:
     * - Devolver huevos al remanente
     * - Reducir merma_total_acumulada
     * - Recalcular costo si hubo perdida economica
     * 
     * @param string|null $motivo Motivo de la eliminacion (para auditoria)
     * @return array Resumen de lo que se revirtio
     */
    public function eliminarYRevertir(?string $motivo = null): array
    {
        $resumen = [
            'merma_numero' => $this->numero_merma,
            'huevos_devueltos' => $this->cantidad_huevos,
            'buffer_restaurado' => $this->cubierto_por_regalo,
            'perdida_revertida' => $this->perdida_real_lempiras,
            'motivo_eliminacion' => $motivo,
        ];

        DB::transaction(function () {
            // Lock pesimista para evitar modificaciones concurrentes al lote
            $lote = Lote::where('id', $this->lote_id)->lockForUpdate()->first();

            if (!$lote) {
                throw new \Exception("No se encontro el lote asociado a esta merma.");
            }

            // 1. Devolver huevos al remanente
            $lote->cantidad_huevos_remanente += $this->cantidad_huevos;

            // 2. Reducir merma total acumulada
            $lote->merma_total_acumulada = max(0, $lote->merma_total_acumulada - $this->cantidad_huevos);

            // 3. Si hubo perdida economica, revertir los huevos facturados
            if ($this->perdida_real_huevos > 0) {
                $lote->huevos_facturados_acumulados += $this->perdida_real_huevos;

                // Recalcular costo por huevo
                if ($lote->huevos_facturados_acumulados > 0) {
                    $lote->costo_por_huevo = round(
                        $lote->costo_total_acumulado / $lote->huevos_facturados_acumulados,
                        4
                    );
                    $lote->costo_por_carton_facturado = round(
                        $lote->costo_por_huevo * ($lote->huevos_por_carton ?? 30),
                        4
                    );
                }
            }

            // 4. Cambiar estado si ahora tiene stock
            if ($lote->cantidad_huevos_remanente > 0) {
                $lote->estado = 'disponible';
            }

            $lote->save();

            // 5. Eliminar el registro de merma
            $this->delete();
        });

        return $resumen;
    }

    /**
     * Verificar si la merma puede ser eliminada
     * 
     * Reglas:
     * - Solo se pueden eliminar mermas recientes (menos de 24 horas)
     * - O si el usuario tiene permisos especiales (Jefe/Super Admin)
     */
    public function puedeSerEliminada(): bool
    {
        $user = Auth::user();
        $esJefeOAdmin = $user && $user->roles->whereIn('name', ['Jefe', 'Super Admin'])->count();

        if ($esJefeOAdmin) {
            return $this->created_at->diffInDays(now()) <= 7;
        }

        return $this->created_at->diffInHours(now()) < 24;
    }

    /**
     * Obtener mensaje de por que no se puede eliminar
     */
    public function getMensajeNoPuedeEliminar(): string
    {
        $dias = $this->created_at->diffInDays(now());
        return "Esta merma fue registrada hace {$dias} días. Las mermas mayores a 7 días no pueden eliminarse.";
    }
}
