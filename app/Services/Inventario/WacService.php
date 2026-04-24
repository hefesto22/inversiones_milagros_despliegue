<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Lote;
use App\Services\Inventario\Dto\WacDelta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Servicio puro de cálculo y persistencia del Weighted Average Cost Perpetuo.
 *
 * Responsabilidades:
 *   - Aplicar entradas (compra, devolución) al WAC del lote → aumenta numerador + denominador.
 *   - Aplicar salidas (venta, merma) al WAC del lote       → reduce numerador + denominador.
 *   - Preservar la invariante del promedio ponderado: las salidas NO modifican el costo unitario.
 *   - Persistir wac_ultima_actualizacion y wac_motivo_ultima_actualizacion para auditoría.
 *
 * Característica crítica — lockForUpdate defensivo:
 *   Cada método abre su propia transacción con savepoint. Cuando es invocado dentro de
 *   una transacción existente (caso normal desde los listeners de eventos disparados por
 *   Lote::agregarCompra, etc.), MySQL relocka la fila sin conflicto porque el lock original
 *   pertenece a la misma conexión. Esto garantiza consistencia sin depender de que el caller
 *   haya hecho el lock correcto.
 *
 * Retornos nullable:
 *   - aplicarVenta/aplicarMerma/aplicarDevolucion pueden retornar null cuando el WAC del
 *     lote no está inicializado (todas las columnas wac_* en NULL). Este estado es
 *     esperado durante la ventana entre deploy de Fase 2 y ejecución del backfill
 *     de Fase 3. El listener loguea warning y continúa sin romper la operación del usuario.
 *
 * Distinción NULL vs agotado-con-memoria:
 *   - wac_* = NULL           → lote nunca tocado por WAC (pre-backfill). Salidas y
 *                              devoluciones se omiten porque no hay costo de referencia.
 *   - inv=0, huevos=0, unit>0 → lote agotado después de ventas/mermas. El costo_unit
 *                              se preservó en la última salida (invariante WAC). Una
 *                              devolución posterior reintegra a ese costo_unit memoria.
 *                              Una nueva compra reinicia el WAC desde cero.
 *
 *   El chequeo `wacNoInicializado()` discrimina los dos casos usando `=== null`,
 *   no comparación contra 0.0 (que conflatearía ambos estados).
 */
final class WacService
{
    /** Redondeo del costo unitario derivado (wac_costo_por_huevo) */
    private const DECIMALES_COSTO_UNITARIO = 6;

    /** Redondeo del numerador (wac_costo_inventario) */
    private const DECIMALES_COSTO_TOTAL = 4;

    /** Redondeo del costo por cartón derivado */
    private const DECIMALES_COSTO_CARTON = 4;

    /** Redondeo del denominador (wac_huevos_inventario) */
    private const DECIMALES_HUEVOS = 4;

    // =================================================================
    // API PÚBLICA — una operación por cada evento de dominio
    // =================================================================

    /**
     * Aplica una compra al WAC del lote (entrada).
     *
     * Aritmética del WAC en entradas:
     *   nuevo_numerador   = numerador_prev + costo_compra
     *   nuevo_denominador = denominador_prev + huevos_facturados
     *   nuevo_costo_unit  = nuevo_numerador / nuevo_denominador
     *
     * El costo unitario SÍ puede cambiar en una entrada (a menos que el precio
     * de compra sea exactamente igual al promedio actual).
     *
     * @throws InvalidArgumentException si los parámetros son inválidos
     */
    public function aplicarCompra(
        Lote  $lote,
        float $huevosFacturados,
        float $costoCompra,
        array $contextoAuditoria = []
    ): WacDelta {
        if ($huevosFacturados <= 0) {
            throw new InvalidArgumentException(
                "aplicarCompra: huevosFacturados debe ser > 0, recibido {$huevosFacturados}"
            );
        }
        if ($costoCompra < 0) {
            throw new InvalidArgumentException(
                "aplicarCompra: costoCompra no puede ser negativo, recibido {$costoCompra}"
            );
        }

        return DB::transaction(function () use ($lote, $huevosFacturados, $costoCompra, $contextoAuditoria) {
            $lote = $this->lockAndRefresh($lote);

            $antesRaw = $this->snapshot($lote);

            // aplicarCompra procede aun sobre lote NULL (primera compra del ciclo).
            // Coalescemos a 0 solo para la aritmética; el delta reporta los valores
            // coalescidos como "antes" (equivalente matemático: NULL ≡ 0 al sumar
            // la primera compra).
            //
            // Caso agotado-con-memoria (inv=0, huevos=0, costo_unit>0): la memoria
            // del costo_unit se DESCARTA naturalmente porque la nueva compra
            // establece un nuevo WAC desde cero (inv=0+costo, huevos=0+huevos).
            // Es el comportamiento correcto: una nueva compra reinicia el promedio.
            $inventarioAntes = $antesRaw['inventario'] ?? 0.0;
            $huevosAntes     = $antesRaw['huevos']     ?? 0.0;
            $costoUnitAntes  = $antesRaw['costo_unit'] ?? 0.0;

            $inventarioDespues = round($inventarioAntes + $costoCompra, self::DECIMALES_COSTO_TOTAL);
            $huevosDespues     = round($huevosAntes + $huevosFacturados, self::DECIMALES_HUEVOS);
            $costoUnitDespues  = $huevosDespues > 0
                ? round($inventarioDespues / $huevosDespues, self::DECIMALES_COSTO_UNITARIO)
                : 0.0;

            $this->persistir($lote, $inventarioDespues, $huevosDespues, $costoUnitDespues, 'compra');

            return $this->construirDelta(
                lote:              $lote,
                motivo:            'compra',
                deltaHuevos:       $huevosFacturados,
                deltaCosto:        $costoCompra,
                antes:             [
                    'inventario' => $inventarioAntes,
                    'huevos'     => $huevosAntes,
                    'costo_unit' => $costoUnitAntes,
                ],
                inventarioDespues: $inventarioDespues,
                huevosDespues:     $huevosDespues,
                costoUnitDespues:  $costoUnitDespues,
                contexto:          $contextoAuditoria,
            );
        });
    }

    /**
     * Aplica una venta al WAC del lote (salida).
     *
     * Aritmética del WAC en salidas:
     *   costo_saliente    = huevos_salida × costo_unit_prev
     *   nuevo_numerador   = numerador_prev - costo_saliente
     *   nuevo_denominador = denominador_prev - huevos_salida
     *   nuevo_costo_unit  = costo_unit_prev  (INVARIANTE: se preserva)
     *
     * Retorna null si el WAC del lote no está inicializado (espera de backfill).
     */
    public function aplicarVenta(
        Lote  $lote,
        float $huevosFacturadosConsumidos,
        array $contextoAuditoria = []
    ): ?WacDelta {
        return $this->aplicarSalida($lote, $huevosFacturadosConsumidos, 'venta', $contextoAuditoria);
    }

    /**
     * Aplica la parte "pérdida real" de una merma al WAC del lote (salida).
     *
     * Solo se invoca con los huevos que NO fueron absorbidos por el buffer de regalo.
     * Los huevos cubiertos por buffer no tienen costo asociado y no afectan WAC.
     */
    public function aplicarMerma(
        Lote  $lote,
        float $huevosPerdidaReal,
        array $contextoAuditoria = []
    ): ?WacDelta {
        return $this->aplicarSalida($lote, $huevosPerdidaReal, 'merma', $contextoAuditoria);
    }

    /**
     * Aplica una devolución al WAC del lote (entrada al WAC actual).
     *
     * Política pragmática de Fase 2: los huevos devueltos reingresan al lote
     * valorados al costo unitario WAC ACTUAL, no al costo al que salieron.
     * Ver justificación en el docblock de DevolucionAplicadaAlLote.
     *
     * Retorna null si el WAC del lote no está inicializado.
     */
    public function aplicarDevolucion(
        Lote  $lote,
        float $huevosFacturadosDevueltos,
        array $contextoAuditoria = []
    ): ?WacDelta {
        if ($huevosFacturadosDevueltos <= 0) {
            throw new InvalidArgumentException(
                "aplicarDevolucion: huevosFacturadosDevueltos debe ser > 0, recibido {$huevosFacturadosDevueltos}"
            );
        }

        return DB::transaction(function () use ($lote, $huevosFacturadosDevueltos, $contextoAuditoria) {
            $lote = $this->lockAndRefresh($lote);

            $antesRaw = $this->snapshot($lote);

            // Solo omitimos cuando el lote JAMÁS fue inicializado (wac_* = NULL).
            // Un lote agotado-con-memoria (inv=0, huevos=0, costo_unit>0) SÍ puede
            // recibir devoluciones — el costo_unit preservado es exactamente el
            // valor a usar para reintegrar los huevos devueltos al WAC.
            // Habilita Escenario B: reempaque revertido sobre lote agotado,
            // devolución de cliente posterior al agotamiento total.
            if ($this->wacNoInicializado($antesRaw)) {
                $this->logOmitido($lote, 'devolucion', $huevosFacturadosDevueltos, $contextoAuditoria);
                return null;
            }

            // Post-check: wacNoInicializado=false ⇒ las 3 columnas son non-null
            // (invariante persistido en bloque). Cast directo sin coalescer.
            $inventarioAntes = (float) $antesRaw['inventario'];
            $huevosAntes     = (float) $antesRaw['huevos'];
            $costoUnitAntes  = (float) $antesRaw['costo_unit'];

            $costoEntrante     = round($huevosFacturadosDevueltos * $costoUnitAntes, self::DECIMALES_COSTO_TOTAL);
            $inventarioDespues = round($inventarioAntes + $costoEntrante, self::DECIMALES_COSTO_TOTAL);
            $huevosDespues     = round($huevosAntes + $huevosFacturadosDevueltos, self::DECIMALES_HUEVOS);
            $costoUnitDespues  = $huevosDespues > 0
                ? round($inventarioDespues / $huevosDespues, self::DECIMALES_COSTO_UNITARIO)
                : $costoUnitAntes;

            $this->persistir($lote, $inventarioDespues, $huevosDespues, $costoUnitDespues, 'devolucion');

            return $this->construirDelta(
                lote:              $lote,
                motivo:            'devolucion',
                deltaHuevos:       $huevosFacturadosDevueltos,
                deltaCosto:        $costoEntrante,
                antes:             [
                    'inventario' => $inventarioAntes,
                    'huevos'     => $huevosAntes,
                    'costo_unit' => $costoUnitAntes,
                ],
                inventarioDespues: $inventarioDespues,
                huevosDespues:     $huevosDespues,
                costoUnitDespues:  $costoUnitDespues,
                contexto:          $contextoAuditoria,
            );
        });
    }

    // =================================================================
    // LÓGICA COMPARTIDA
    // =================================================================

    /**
     * Lógica común para ventas y mermas — ambas son salidas del inventario
     * que deben preservar el costo unitario WAC.
     */
    private function aplicarSalida(
        Lote   $lote,
        float  $huevosSalida,
        string $motivo,
        array  $contextoAuditoria
    ): ?WacDelta {
        if ($huevosSalida <= 0) {
            throw new InvalidArgumentException(
                "aplicarSalida ({$motivo}): huevosSalida debe ser > 0, recibido {$huevosSalida}"
            );
        }

        return DB::transaction(function () use ($lote, $huevosSalida, $motivo, $contextoAuditoria) {
            $lote = $this->lockAndRefresh($lote);

            $antesRaw = $this->snapshot($lote);

            // Solo omitimos cuando wac_* = NULL (lote pre-backfill).
            // Un lote agotado-con-memoria no debería recibir una salida (porque
            // cantidad_huevos_remanente ya es 0 a nivel de modelo), pero si
            // llegara por alguna ruta degenerada, la aritmética con max(0,..)
            // clampa correctamente sin producir valores negativos.
            if ($this->wacNoInicializado($antesRaw)) {
                $this->logOmitido($lote, $motivo, $huevosSalida, $contextoAuditoria);
                return null;
            }

            // Post-check: wacNoInicializado=false ⇒ las 3 columnas son non-null
            // (invariante persistido en bloque). Cast directo sin coalescer.
            $inventarioAntes = (float) $antesRaw['inventario'];
            $huevosAntes     = (float) $antesRaw['huevos'];
            $costoUnitAntes  = (float) $antesRaw['costo_unit'];

            $costoSaliente     = round($huevosSalida * $costoUnitAntes, self::DECIMALES_COSTO_TOTAL);
            $inventarioDespues = round(max(0.0, $inventarioAntes - $costoSaliente), self::DECIMALES_COSTO_TOTAL);
            $huevosDespues     = round(max(0.0, $huevosAntes - $huevosSalida), self::DECIMALES_HUEVOS);

            // Invariante del WAC: el costo unitario se preserva en salidas.
            // Si quedan huevos, recalculamos para mantener consistencia numérica
            // (defensiva ante pérdidas de precisión por redondeo).
            // Si el lote se vacía, preservamos el costo_unit previo para que una
            // eventual devolución posterior reingrese al costo correcto
            // (esto es lo que habilita el "agotado-con-memoria").
            $costoUnitDespues = $huevosDespues > 0
                ? round($inventarioDespues / $huevosDespues, self::DECIMALES_COSTO_UNITARIO)
                : $costoUnitAntes;

            $this->persistir($lote, $inventarioDespues, $huevosDespues, $costoUnitDespues, $motivo);

            return $this->construirDelta(
                lote:              $lote,
                motivo:            $motivo,
                deltaHuevos:       -$huevosSalida,
                deltaCosto:        -$costoSaliente,
                antes:             [
                    'inventario' => $inventarioAntes,
                    'huevos'     => $huevosAntes,
                    'costo_unit' => $costoUnitAntes,
                ],
                inventarioDespues: $inventarioDespues,
                huevosDespues:     $huevosDespues,
                costoUnitDespues:  $costoUnitDespues,
                contexto:          $contextoAuditoria,
            );
        });
    }

    /**
     * Adquiere lock pesimista sobre la fila del lote y refresca el modelo.
     *
     * Si ya estamos dentro de una transacción con el lote locked por la misma
     * conexión (caso normal dispatch desde Lote::agregarCompra), MySQL permite
     * el relock sin conflicto. Si no hay transacción previa, este método la
     * abre vía DB::transaction() en el método caller.
     */
    private function lockAndRefresh(Lote $lote): Lote
    {
        // Lock sobre la fila — en la misma connection es idempotente
        Lote::where('id', $lote->id)->lockForUpdate()->first();

        // Refresh para traer el estado persistido más reciente
        $lote->refresh();

        return $lote;
    }

    /**
     * Snapshot numérico del estado WAC antes de aplicar una operación.
     *
     * Retorna valores RAW (nullable) para preservar la distinción semántica
     * crítica entre:
     *   - wac_* = NULL       → lote jamás inicializado (pre-backfill)
     *   - wac_* = 0.0 (num/den) + costo_unit > 0 → agotado con memoria de costo
     *
     * Los callers que requieran aritmética deben coalescer a 0 cuando apliquen
     * (típicamente solo aplicarCompra, que procede aun sobre lote NULL).
     *
     * Invariante persistido: las 3 columnas viajan en bloque. Si una es NULL,
     * las tres lo son (garantizado por persistir() y resetearWac()).
     *
     * @return array{inventario: ?float, huevos: ?float, costo_unit: ?float}
     */
    private function snapshot(Lote $lote): array
    {
        return [
            'inventario' => $lote->wac_costo_inventario !== null ? (float) $lote->wac_costo_inventario : null,
            'huevos'     => $lote->wac_huevos_inventario !== null ? (float) $lote->wac_huevos_inventario : null,
            'costo_unit' => $lote->wac_costo_por_huevo  !== null ? (float) $lote->wac_costo_por_huevo  : null,
        ];
    }

    /**
     * WAC no inicializado: columnas wac_* en NULL (lote jamás tocado por WAC).
     *
     * El chequeo usa `=== null`, NO comparación contra 0.0. Un lote agotado
     * con memoria de costo_unit (inv=0, huevos=0, costo_unit>0) NO está "no
     * inicializado" — su costo_unit preservado es necesario para valorar
     * devoluciones posteriores (Escenario B: reversión de reempaque, devolución
     * de cliente tras agotamiento total).
     *
     * @param array{inventario: ?float, huevos: ?float, costo_unit: ?float} $snapshot
     */
    private function wacNoInicializado(array $snapshot): bool
    {
        return $snapshot['inventario'] === null
            && $snapshot['huevos']     === null
            && $snapshot['costo_unit'] === null;
    }

    private function logOmitido(Lote $lote, string $motivo, float $huevos, array $contexto): void
    {
        Log::warning("WacService::aplicar{$motivo} omitido — WAC no inicializado", [
            'lote_id'  => $lote->id,
            'motivo'   => $motivo,
            'huevos'   => $huevos,
            'contexto' => $contexto,
            'hint'     => 'Ejecutar BackfillWacCommand (Fase 3) para inicializar wac_* desde datos legacy',
        ]);
    }

    /**
     * Persistencia atómica del nuevo estado WAC en el lote.
     * También calcula wac_costo_por_carton_facturado (derivado) para mantener
     * shape idéntico a la columna legacy y permitir swap limpio en Fase 5.
     */
    private function persistir(
        Lote   $lote,
        float  $inventarioDespues,
        float  $huevosDespues,
        float  $costoUnitDespues,
        string $motivo
    ): void {
        $huevosPorCarton = (int) ($lote->huevos_por_carton ?? 30);

        $lote->wac_costo_inventario              = $inventarioDespues;
        $lote->wac_huevos_inventario             = $huevosDespues;
        $lote->wac_costo_por_huevo               = $costoUnitDespues;
        $lote->wac_costo_por_carton_facturado    = round(
            $costoUnitDespues * $huevosPorCarton,
            self::DECIMALES_COSTO_CARTON
        );
        $lote->wac_ultima_actualizacion          = now();
        $lote->wac_motivo_ultima_actualizacion   = $motivo;

        $lote->save();
    }

    /**
     * Factory helper para no repetir el wiring del DTO en cada método público.
     */
    private function construirDelta(
        Lote   $lote,
        string $motivo,
        float  $deltaHuevos,
        float  $deltaCosto,
        array  $antes,
        float  $inventarioDespues,
        float  $huevosDespues,
        float  $costoUnitDespues,
        array  $contexto,
    ): WacDelta {
        return new WacDelta(
            loteId:                     $lote->id,
            motivo:                     $motivo,
            deltaHuevos:                $deltaHuevos,
            deltaCostoInventario:       $deltaCosto,
            wacCostoInventarioAntes:    $antes['inventario'],
            wacCostoInventarioDespues:  $inventarioDespues,
            wacHuevosInventarioAntes:   $antes['huevos'],
            wacHuevosInventarioDespues: $huevosDespues,
            wacCostoPorHuevoAntes:      $antes['costo_unit'],
            wacCostoPorHuevoDespues:    $costoUnitDespues,
            contextoAuditoria:          $contexto,
        );
    }
}
