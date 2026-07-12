<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Enums\AjusteEstado;
use App\Enums\AjusteMotivo;
use App\Enums\AjusteTipoMovimiento;
use App\Events\Inventario\AjusteEntradaAplicadoAlLote;
use App\Events\Inventario\AjusteSalidaAplicadaAlLote;
use App\Models\AjusteInventario;
use App\Models\Lote;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Servicio centralizado para crear, aprobar y aplicar Ajustes de Inventario.
 *
 * Tres operaciones principales:
 *   1) Reclasificación entre productos (Mediano → Pequeño):
 *      crea 2 ajustes vinculados (salida + entrada) en una sola transacción.
 *   2) Merma residual: decrementa el lote y suma a merma_total_acumulada.
 *   3) Ajuste de corrección: para revertir un ajuste aplicado erróneo.
 *
 * Workflow:
 *   crearXxx() → estado Borrador
 *   aprobar()  → estado Aprobado (si requería aprobación)
 *   aplicar()  → estado Aplicado, modifica el lote, emite events para WAC
 *
 * Atomicidad de reclasificaciones:
 *   La pareja salida/entrada SIEMPRE se aprueba y aplica junta. Si una falla,
 *   ambas hacen rollback.
 *
 * Integración WAC:
 *   Al aplicar, se emiten AjusteSalidaAplicadaAlLote y AjusteEntradaAplicadoAlLote
 *   que el ActualizarWacListener captura para recalcular WAC (en modo shadow).
 *   La merma residual emite el evento existente MermaAplicadaAlLote para
 *   reutilizar el handler WAC actual.
 */
final class AjusteInventarioService
{
    /**
     * Crea una reclasificación entre dos lotes (de productos distintos o iguales).
     *
     * Política de costos — Opción B (preservar costo origen):
     *   Por default, $costoUnitarioAplicado = costo unitario del lote ORIGEN.
     *   Los huevos "viajan" con su costo original al lote destino. NO se materializa
     *   pérdida valorativa en el ajuste (ese resultado natural llega cuando los
     *   huevos se vendan al precio del producto destino, vía margen real).
     *
     *   Esta política refleja que una reclasificación física no es una pérdida
     *   contable inmediata — solo es una corrección de etiqueta. El dinero ya se
     *   invirtió en esos huevos al precio original.
     *
     *   Si se pasa un costo explícito (caso edge: ajuste de calidad que sí amerita
     *   pérdida valorativa inmediata), se usa ese costo. Por default = costo origen.
     *
     * Estado de salida: ambos ajustes quedan en Borrador o PendienteAprobacion
     * según el umbral configurado en config('inventario.ajustes.umbral_aprobacion_huevos').
     *
     * @param  Lote          $loteOrigen            Lote del que salen los huevos
     * @param  Lote          $loteDestino           Lote al que entran los huevos
     * @param  float         $huevosAMover          Cantidad de huevos a mover (> 0)
     * @param  float|null    $costoUnitarioAplicado Costo por huevo aplicado al movimiento (null = costo del origen, default)
     * @param  AjusteMotivo  $motivo                Motivo de la reclasificación
     * @param  string        $descripcion           Justificación obligatoria
     * @param  string|null   $evidenciaPath         Ruta de la foto del conteo (opcional)
     * @param  User          $solicitante           Usuario que crea el ajuste
     *
     * @return array{salida: AjusteInventario, entrada: AjusteInventario}
     *
     * @throws InvalidArgumentException
     * @throws DomainException
     */
    public function crearReclasificacion(
        Lote         $loteOrigen,
        Lote         $loteDestino,
        float        $huevosAMover,
        ?float       $costoUnitarioAplicado,
        AjusteMotivo $motivo,
        string       $descripcion,
        ?string      $evidenciaPath,
        User         $solicitante,
    ): array {
        $this->validarReclasificacion(
            $loteOrigen, $loteDestino, $huevosAMover, $costoUnitarioAplicado, $motivo, $descripcion
        );

        return DB::transaction(function () use (
            $loteOrigen, $loteDestino, $huevosAMover, $costoUnitarioAplicado,
            $motivo, $descripcion, $evidenciaPath, $solicitante
        ) {
            // Lock pesimista sobre ambos lotes (orden por id para evitar deadlock)
            $lotesOrdenados = collect([$loteOrigen, $loteDestino])->sortBy('id')->values();
            foreach ($lotesOrdenados as $l) {
                Lote::where('id', $l->id)->lockForUpdate()->first();
            }

            // Validación post-lock: stock actual suficiente en origen
            $loteOrigen->refresh();
            $loteDestino->refresh();

            if ($loteOrigen->cantidad_huevos_remanente < $huevosAMover) {
                throw new DomainException(
                    "Stock insuficiente en lote origen {$loteOrigen->numero_lote}: " .
                    "actual {$loteOrigen->cantidad_huevos_remanente}, requerido {$huevosAMover}"
                );
            }

            $requiereAprobacion = $this->requiereAprobacion($huevosAMover, $loteOrigen);

            // Costo EFECTIVO del origen (accessor Fase 5): respeta el flag
            // inventario.wac.read_source. Con 'wac' el ajuste se valora con el
            // mismo costo que muestran las pantallas — evita descuadres entre
            // la bitácora del ajuste y lo que el usuario ve en Filament.
            $costoOrigen = (float) ($loteOrigen->costo_por_huevo_efectivo ?? 0);

            // Opción B: por default el costo viaja con los huevos (preservar costo origen)
            $costoAplicado = $costoUnitarioAplicado ?? $costoOrigen;

            $valorSalida  = round($huevosAMover * $costoOrigen, 2);
            $valorEntrada = round($huevosAMover * $costoAplicado, 2);

            // Crear ambos registros sin pareja vinculada todavía
            $salida = AjusteInventario::create([
                'lote_id'                 => $loteOrigen->id,
                'producto_id'             => $loteOrigen->producto_id,
                'bodega_id'               => $loteOrigen->bodega_id,
                'tipo_movimiento'         => AjusteTipoMovimiento::SalidaReclasificacion,
                'motivo'                  => $motivo,
                'huevos_antes'            => $loteOrigen->cantidad_huevos_remanente,
                'huevos_despues'          => $loteOrigen->cantidad_huevos_remanente - $huevosAMover,
                'delta_huevos'            => -$huevosAMover,
                'costo_unitario_aplicado' => $costoOrigen,
                'valor_contable_afectado' => $valorSalida,
                'descripcion'             => $descripcion,
                'evidencia_path'          => $evidenciaPath,
                'estado'                  => $requiereAprobacion ? AjusteEstado::PendienteAprobacion : AjusteEstado::Borrador,
                'requiere_aprobacion'     => $requiereAprobacion,
                'created_by'              => $solicitante->id,
            ]);

            $entrada = AjusteInventario::create([
                'lote_id'                 => $loteDestino->id,
                'producto_id'             => $loteDestino->producto_id,
                'bodega_id'               => $loteDestino->bodega_id,
                'tipo_movimiento'         => AjusteTipoMovimiento::EntradaReclasificacion,
                'motivo'                  => $motivo,
                'ajuste_pareja_id'        => $salida->id,
                'huevos_antes'            => $loteDestino->cantidad_huevos_remanente,
                'huevos_despues'          => $loteDestino->cantidad_huevos_remanente + $huevosAMover,
                'delta_huevos'            => $huevosAMover,
                'costo_unitario_aplicado' => $costoAplicado,
                'valor_contable_afectado' => $valorEntrada,
                'descripcion'             => $descripcion,
                'evidencia_path'          => $evidenciaPath,
                'estado'                  => $requiereAprobacion ? AjusteEstado::PendienteAprobacion : AjusteEstado::Borrador,
                'requiere_aprobacion'     => $requiereAprobacion,
                'created_by'              => $solicitante->id,
            ]);

            // Vincular la salida con la entrada (relación bidireccional)
            $salida->update(['ajuste_pareja_id' => $entrada->id]);

            Log::info('AjusteInventario reclasificacion creada', [
                'salida_id'  => $salida->id,
                'entrada_id' => $entrada->id,
                'motivo'     => $motivo->value,
                'huevos'     => $huevosAMover,
                'requiere_aprobacion' => $requiereAprobacion,
            ]);

            return ['salida' => $salida->fresh(), 'entrada' => $entrada->fresh()];
        });
    }

    /**
     * Crea una merma residual sobre un lote (huevos se pierden sin reclasificar).
     *
     * @throws InvalidArgumentException
     * @throws DomainException
     */
    public function crearMermaResidual(
        Lote         $lote,
        float        $huevosAMermar,
        AjusteMotivo $motivo,
        string       $descripcion,
        ?string      $evidenciaPath,
        User         $solicitante,
    ): AjusteInventario {
        if ($huevosAMermar <= 0) {
            throw new InvalidArgumentException("huevosAMermar debe ser > 0, recibido {$huevosAMermar}");
        }
        if (! $motivo->aplicaAMerma()) {
            throw new InvalidArgumentException(
                "El motivo '{$motivo->value}' no aplica a merma residual. " .
                "Motivos válidos: conteo_fisico_diferencia, rotura_no_documentada, otro."
            );
        }
        if (trim($descripcion) === '') {
            throw new InvalidArgumentException("La descripción es obligatoria.");
        }

        return DB::transaction(function () use ($lote, $huevosAMermar, $motivo, $descripcion, $evidenciaPath, $solicitante) {
            Lote::where('id', $lote->id)->lockForUpdate()->first();
            $lote->refresh();

            if ($lote->cantidad_huevos_remanente < $huevosAMermar) {
                throw new DomainException(
                    "Stock insuficiente en lote {$lote->numero_lote}: " .
                    "actual {$lote->cantidad_huevos_remanente}, requerido {$huevosAMermar}"
                );
            }

            $requiereAprobacion = $this->requiereAprobacion($huevosAMermar, $lote);

            // Costo EFECTIVO (accessor Fase 5) — misma razón que en crearReclasificacion.
            $costoUnitario = (float) ($lote->costo_por_huevo_efectivo ?? 0);

            $ajuste = AjusteInventario::create([
                'lote_id'                 => $lote->id,
                'producto_id'             => $lote->producto_id,
                'bodega_id'               => $lote->bodega_id,
                'tipo_movimiento'         => AjusteTipoMovimiento::MermaResidual,
                'motivo'                  => $motivo,
                'huevos_antes'            => $lote->cantidad_huevos_remanente,
                'huevos_despues'          => $lote->cantidad_huevos_remanente - $huevosAMermar,
                'delta_huevos'            => -$huevosAMermar,
                'costo_unitario_aplicado' => $costoUnitario,
                'valor_contable_afectado' => round($huevosAMermar * $costoUnitario, 2),
                'descripcion'             => $descripcion,
                'evidencia_path'          => $evidenciaPath,
                'estado'                  => $requiereAprobacion ? AjusteEstado::PendienteAprobacion : AjusteEstado::Borrador,
                'requiere_aprobacion'     => $requiereAprobacion,
                'created_by'              => $solicitante->id,
            ]);

            Log::info('AjusteInventario merma residual creada', [
                'ajuste_id' => $ajuste->id,
                'lote_id'   => $lote->id,
                'huevos'    => $huevosAMermar,
                'motivo'    => $motivo->value,
            ]);

            return $ajuste->fresh();
        });
    }

    /**
     * Aprueba un ajuste (y su pareja si es reclasificación).
     *
     * Transición: PendienteAprobacion → Aprobado
     *
     * @throws DomainException
     */
    public function aprobar(AjusteInventario $ajuste, User $aprobador): void
    {
        if ($ajuste->estado !== AjusteEstado::PendienteAprobacion) {
            throw new DomainException(
                "El ajuste #{$ajuste->id} no está en estado pendiente de aprobación " .
                "(estado actual: {$ajuste->estado->value})."
            );
        }

        DB::transaction(function () use ($ajuste, $aprobador) {
            $ahora = now();

            $ajuste->update([
                'estado'       => AjusteEstado::Aprobado,
                'aprobado_por' => $aprobador->id,
                'aprobado_en'  => $ahora,
            ]);

            // Si tiene pareja, también la aprobamos (reclasificación atómica)
            if ($ajuste->ajuste_pareja_id) {
                AjusteInventario::where('id', $ajuste->ajuste_pareja_id)
                    ->where('estado', AjusteEstado::PendienteAprobacion)
                    ->update([
                        'estado'       => AjusteEstado::Aprobado,
                        'aprobado_por' => $aprobador->id,
                        'aprobado_en'  => $ahora,
                    ]);
            }

            Log::info('AjusteInventario aprobado', [
                'ajuste_id'    => $ajuste->id,
                'pareja_id'    => $ajuste->ajuste_pareja_id,
                'aprobado_por' => $aprobador->id,
            ]);
        });
    }

    /**
     * Rechaza un ajuste (y su pareja si es reclasificación).
     *
     * Transición: PendienteAprobacion → Rechazado
     *
     * @throws DomainException
     */
    public function rechazar(AjusteInventario $ajuste, User $aprobador, string $motivoRechazo): void
    {
        if ($ajuste->estado !== AjusteEstado::PendienteAprobacion) {
            throw new DomainException(
                "El ajuste #{$ajuste->id} no está pendiente (estado: {$ajuste->estado->value})."
            );
        }
        if (trim($motivoRechazo) === '') {
            throw new InvalidArgumentException("El motivo de rechazo es obligatorio.");
        }

        DB::transaction(function () use ($ajuste, $aprobador, $motivoRechazo) {
            $ahora = now();

            $ajuste->update([
                'estado'         => AjusteEstado::Rechazado,
                'rechazado_por'  => $aprobador->id,
                'rechazado_en'   => $ahora,
                'motivo_rechazo' => $motivoRechazo,
            ]);

            if ($ajuste->ajuste_pareja_id) {
                AjusteInventario::where('id', $ajuste->ajuste_pareja_id)
                    ->where('estado', AjusteEstado::PendienteAprobacion)
                    ->update([
                        'estado'         => AjusteEstado::Rechazado,
                        'rechazado_por'  => $aprobador->id,
                        'rechazado_en'   => $ahora,
                        'motivo_rechazo' => $motivoRechazo,
                    ]);
            }

            Log::info('AjusteInventario rechazado', [
                'ajuste_id' => $ajuste->id,
                'motivo'    => $motivoRechazo,
            ]);
        });
    }

    /**
     * Aplica el ajuste al lote (y su pareja si es reclasificación).
     *
     * Transición: Borrador|Aprobado → Aplicado
     *
     * En esta operación se modifica el cantidad_huevos_remanente del lote y
     * se emiten los events correspondientes para que el WAC se actualice.
     *
     * Para reclasificaciones, ambas (salida + entrada) se aplican juntas dentro
     * de la misma transacción. Si una falla, ambas hacen rollback.
     *
     * @throws DomainException
     */
    public function aplicar(AjusteInventario $ajuste, User $aplicador): void
    {
        $this->validarPuedeAplicarse($ajuste);

        DB::transaction(function () use ($ajuste, $aplicador) {
            // Si es reclasificación, aplicar AMBOS lados; si es merma/corrección, aplicar solo este
            if ($ajuste->esReclasificacion() && $ajuste->ajuste_pareja_id) {
                // Cargar ambos con lock para evitar race conditions
                $salida = AjusteInventario::where('id', $ajuste->tipo_movimiento === AjusteTipoMovimiento::SalidaReclasificacion ? $ajuste->id : $ajuste->ajuste_pareja_id)
                    ->lockForUpdate()->firstOrFail();
                $entrada = AjusteInventario::where('id', $ajuste->tipo_movimiento === AjusteTipoMovimiento::EntradaReclasificacion ? $ajuste->id : $ajuste->ajuste_pareja_id)
                    ->lockForUpdate()->firstOrFail();

                $this->validarPuedeAplicarse($salida);
                $this->validarPuedeAplicarse($entrada);

                $this->aplicarSalidaInterna($salida, $aplicador);
                $this->aplicarEntradaInterna($entrada, $aplicador);

                Log::info('Reclasificacion aplicada completa', [
                    'salida_id'  => $salida->id,
                    'entrada_id' => $entrada->id,
                    'huevos'     => abs((float) $salida->delta_huevos),
                ]);
                return;
            }

            // Merma residual o corrección
            if ($ajuste->tipo_movimiento === AjusteTipoMovimiento::MermaResidual) {
                $this->aplicarMermaResidualInterna($ajuste, $aplicador);
                return;
            }

            if ($ajuste->tipo_movimiento === AjusteTipoMovimiento::AjusteCorreccion) {
                $this->aplicarCorreccionInterna($ajuste, $aplicador);
                return;
            }

            throw new DomainException(
                "Tipo de movimiento '{$ajuste->tipo_movimiento->value}' no soportado para aplicar()."
            );
        });
    }

    // ============================================================
    // INTERNOS
    // ============================================================

    private function aplicarSalidaInterna(AjusteInventario $salida, User $aplicador): void
    {
        $lote = Lote::where('id', $salida->lote_id)->lockForUpdate()->firstOrFail();
        $huevosASacar = abs((float) $salida->delta_huevos);

        if ($lote->cantidad_huevos_remanente < $huevosASacar) {
            throw new DomainException(
                "Stock insuficiente al aplicar salida #{$salida->id}: " .
                "lote tiene {$lote->cantidad_huevos_remanente}, requerido {$huevosASacar}"
            );
        }

        $lote->cantidad_huevos_remanente -= $huevosASacar;
        // Una salida de reclasificación NO suma a merma_total_acumulada (no es pérdida)
        // pero SÍ a huevos_facturados_acumulados para que el reporte de salida total cuadre
        $lote->huevos_facturados_acumulados += $huevosASacar;
        if ($lote->cantidad_huevos_remanente <= 0) {
            $lote->estado = 'agotado';
        }
        $lote->save();

        $salida->update([
            'estado'       => AjusteEstado::Aplicado,
            'aplicado_por' => $aplicador->id,
            'aplicado_en'  => now(),
        ]);

        // Emitir evento para WAC
        AjusteSalidaAplicadaAlLote::dispatch(
            $lote,
            $salida,
            $huevosASacar,
            [
                'ajuste_id'      => $salida->id,
                'motivo'         => $salida->motivo->value,
                'pareja_id'      => $salida->ajuste_pareja_id,
                'descripcion'    => $salida->descripcion,
            ],
        );
    }

    private function aplicarEntradaInterna(AjusteInventario $entrada, User $aplicador): void
    {
        $lote = Lote::where('id', $entrada->lote_id)->lockForUpdate()->firstOrFail();
        $huevosAEntrar = (float) $entrada->delta_huevos;

        $lote->cantidad_huevos_remanente += $huevosAEntrar;
        if ($lote->estado === 'agotado' && $lote->cantidad_huevos_remanente > 0) {
            $lote->estado = 'disponible';
        }
        $lote->save();

        $entrada->update([
            'estado'       => AjusteEstado::Aplicado,
            'aplicado_por' => $aplicador->id,
            'aplicado_en'  => now(),
        ]);

        AjusteEntradaAplicadoAlLote::dispatch(
            $lote,
            $entrada,
            $huevosAEntrar,
            (float) $entrada->costo_unitario_aplicado,
            [
                'ajuste_id'   => $entrada->id,
                'motivo'      => $entrada->motivo->value,
                'pareja_id'   => $entrada->ajuste_pareja_id,
                'descripcion' => $entrada->descripcion,
            ],
        );
    }

    private function aplicarMermaResidualInterna(AjusteInventario $ajuste, User $aplicador): void
    {
        $lote = Lote::where('id', $ajuste->lote_id)->lockForUpdate()->firstOrFail();
        $huevosAMermar = abs((float) $ajuste->delta_huevos);

        if ($lote->cantidad_huevos_remanente < $huevosAMermar) {
            throw new DomainException(
                "Stock insuficiente para merma residual #{$ajuste->id}"
            );
        }

        $lote->cantidad_huevos_remanente   -= $huevosAMermar;
        $lote->merma_total_acumulada       += $huevosAMermar;
        $lote->huevos_facturados_acumulados += $huevosAMermar;
        if ($lote->cantidad_huevos_remanente <= 0) {
            $lote->estado = 'agotado';
        }
        $lote->save();

        $ajuste->update([
            'estado'       => AjusteEstado::Aplicado,
            'aplicado_por' => $aplicador->id,
            'aplicado_en'  => now(),
        ]);

        // Para merma residual usamos el event de Ajuste Salida (no MermaAplicada, porque
        // no creamos registro en tabla mermas). El listener WAC tratará esto como salida.
        AjusteSalidaAplicadaAlLote::dispatch(
            $lote,
            $ajuste,
            $huevosAMermar,
            [
                'ajuste_id'        => $ajuste->id,
                'motivo'           => $ajuste->motivo->value,
                'tipo_movimiento'  => 'merma_residual',
                'descripcion'      => $ajuste->descripcion,
            ],
        );
    }

    private function aplicarCorreccionInterna(AjusteInventario $ajuste, User $aplicador): void
    {
        // Una corrección puede ser entrada o salida según el signo del delta
        $lote = Lote::where('id', $ajuste->lote_id)->lockForUpdate()->firstOrFail();
        $delta = (float) $ajuste->delta_huevos;

        if ($delta < 0 && $lote->cantidad_huevos_remanente < abs($delta)) {
            throw new DomainException("Stock insuficiente para corrección #{$ajuste->id}");
        }

        $lote->cantidad_huevos_remanente += $delta; // delta puede ser + o -
        if ($lote->cantidad_huevos_remanente <= 0) {
            $lote->estado = 'agotado';
        } elseif ($lote->estado === 'agotado') {
            $lote->estado = 'disponible';
        }
        $lote->save();

        $ajuste->update([
            'estado'       => AjusteEstado::Aplicado,
            'aplicado_por' => $aplicador->id,
            'aplicado_en'  => now(),
        ]);

        // Emitir evento según dirección
        if ($delta < 0) {
            AjusteSalidaAplicadaAlLote::dispatch(
                $lote, $ajuste, abs($delta),
                ['ajuste_id' => $ajuste->id, 'motivo' => $ajuste->motivo->value, 'tipo_movimiento' => 'ajuste_correccion']
            );
        } else {
            AjusteEntradaAplicadoAlLote::dispatch(
                $lote, $ajuste, $delta, (float) $ajuste->costo_unitario_aplicado,
                ['ajuste_id' => $ajuste->id, 'motivo' => $ajuste->motivo->value, 'tipo_movimiento' => 'ajuste_correccion']
            );
        }
    }

    // ============================================================
    // VALIDACIONES
    // ============================================================

    private function validarReclasificacion(
        Lote $loteOrigen, Lote $loteDestino, float $huevos, ?float $costo,
        AjusteMotivo $motivo, string $descripcion,
    ): void {
        if ($huevos <= 0) {
            throw new InvalidArgumentException("huevosAMover debe ser > 0");
        }
        if ($costo !== null && $costo < 0) {
            throw new InvalidArgumentException("costoUnitarioAplicado no puede ser negativo");
        }
        if ($loteOrigen->id === $loteDestino->id) {
            throw new InvalidArgumentException("loteOrigen y loteDestino no pueden ser el mismo");
        }
        if ($loteOrigen->bodega_id !== $loteDestino->bodega_id) {
            throw new InvalidArgumentException(
                "Reclasificación solo permitida entre lotes de la misma bodega"
            );
        }
        if (! $motivo->aplicaAReclasificacion()) {
            throw new InvalidArgumentException(
                "El motivo '{$motivo->value}' no aplica a reclasificaciones. " .
                "Motivos válidos: clasificacion_incorrecta, reempaque_no_documentado, " .
                "reclasificacion_calidad, correccion_captura_erronea."
            );
        }
        if (trim($descripcion) === '') {
            throw new InvalidArgumentException("La descripción es obligatoria.");
        }

        // Validar que no excede el % máximo del lote origen
        $pctMax = (float) config('inventario.ajustes.porcentaje_maximo_lote', 25);
        $pctMov = $loteOrigen->cantidad_huevos_remanente > 0
            ? ($huevos / (float) $loteOrigen->cantidad_huevos_remanente) * 100
            : 100;
        if ($pctMov > $pctMax) {
            throw new DomainException(
                "El movimiento ({$pctMov}%) excede el máximo permitido del lote ({$pctMax}%). " .
                "Para ajustes mayores, requiere autorización especial."
            );
        }
    }

    private function validarPuedeAplicarse(AjusteInventario $ajuste): void
    {
        $estadosValidos = [AjusteEstado::Borrador, AjusteEstado::Aprobado];

        // Si requiere aprobación, solo puede aplicarse desde Aprobado
        if ($ajuste->requiere_aprobacion) {
            $estadosValidos = [AjusteEstado::Aprobado];
        }

        if (! in_array($ajuste->estado, $estadosValidos, true)) {
            throw new DomainException(
                "El ajuste #{$ajuste->id} no puede aplicarse desde estado {$ajuste->estado->value}. " .
                "Estados válidos: " . implode(', ', array_map(fn($e) => $e->value, $estadosValidos))
            );
        }
    }

    private function requiereAprobacion(float $huevos, Lote $lote): bool
    {
        $umbral = (float) config('inventario.ajustes.umbral_aprobacion_huevos', 300);
        return abs($huevos) >= $umbral;
    }
}
