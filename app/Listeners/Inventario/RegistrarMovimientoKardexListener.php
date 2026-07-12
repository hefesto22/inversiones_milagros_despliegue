<?php

declare(strict_types=1);

namespace App\Listeners\Inventario;

use App\Enums\MovimientoInventarioTipo;
use App\Events\Inventario\AjusteEntradaAplicadoAlLote;
use App\Events\Inventario\AjusteSalidaAplicadaAlLote;
use App\Events\Inventario\CompraAplicadaAlLote;
use App\Events\Inventario\DevolucionAplicadaAlLote;
use App\Events\Inventario\MermaAplicadaAlLote;
use App\Events\Inventario\StockBodegaMovido;
use App\Events\Inventario\VentaAplicadaAlLote;
use App\Services\Inventario\RegistradorMovimientos;

/**
 * Listener síncrono que asienta en el Kardex cada movimiento de inventario,
 * consumiendo los MISMOS eventos de dominio que alimentan el WAC más el
 * evento StockBodegaMovido (nivel bodega, nacido con el Kardex).
 *
 * Diseño:
 *   - Síncrono (no ShouldQueue): corre dentro de la transacción del caller,
 *     así saldo_despues es exacto y, en modo estricto, un fallo revierte todo.
 *   - Sin try/catch propio: la política de resiliencia (estricto vs rollout)
 *     vive en RegistradorMovimientos — un solo lugar.
 *   - Auto-descubierto por Laravel 11 (app/Listeners/) — NO registrar manual.
 *
 * Clasificación del tipo:
 *   Los eventos del lote no siempre saben QUÉ documento los originó (una
 *   salida puede ser reempaque para venta o para carga de viaje). Por eso el
 *   listener respeta 'kardex_tipo' del contexto cuando el caller lo envía
 *   (contexto enriquecido) y usa un default razonable cuando no:
 *     - VentaAplicadaAlLote       → salida_reempaque (hoy TODA salida de
 *                                    huevos pasa por el reempaque automático)
 *     - DevolucionAplicadaAlLote  → devolucion_reempaque
 */
final class RegistrarMovimientoKardexListener
{
    public function __construct(
        private readonly RegistradorMovimientos $registrador,
    ) {}

    // =================================================================
    // NIVEL LOTE (huevo suelto)
    // =================================================================

    public function handleCompraKardex(CompraAplicadaAlLote $event): void
    {
        // El Kardex es un libro FÍSICO: la compra entra con facturados + regalo
        $huevosTotales = (float) $event->huevosFacturados + (float) $event->huevosRegalo;

        if ($huevosTotales <= 0) {
            return;
        }

        // Costo por huevo físico entrante → valor del asiento ≈ costo de la compra
        $costoUnitario = round((float) $event->costoCompra / $huevosTotales, 6);

        $this->registrador->registrarLote(
            lote:          $event->lote,
            tipo:          MovimientoInventarioTipo::Compra,
            delta:         $huevosTotales,
            costoUnitario: $costoUnitario,
            descripcion:   "Compra recibida en lote {$event->lote->numero_lote}",
            contexto:      [
                'compra_id'         => $event->compraId,
                'compra_detalle_id' => $event->compraDetalleId,
                'proveedor_id'      => $event->proveedorId,
                'huevos_facturados' => $event->huevosFacturados,
                'huevos_regalo'     => $event->huevosRegalo,
                'costo_compra'      => $event->costoCompra,
            ],
        );
    }

    public function handleVentaKardex(VentaAplicadaAlLote $event): void
    {
        $huevosTotales = (float) $event->huevosFacturadosConsumidos + (float) $event->huevosRegaloConsumidos;

        if ($huevosTotales <= 0) {
            return;
        }

        $this->registrador->registrarLote(
            lote:           $event->lote,
            tipo:           $this->tipoDesdeContexto($event->contexto, MovimientoInventarioTipo::SalidaReempaque),
            delta:          -$huevosTotales,
            costoUnitario:  (float) $event->lote->costo_por_huevo_efectivo,
            descripcion:    $event->contexto['kardex_descripcion']
                ?? "Salida de huevos del lote {$event->lote->numero_lote}",
            contexto:       $this->contextoLimpio($event->contexto) + [
                'huevos_facturados' => $event->huevosFacturadosConsumidos,
                'huevos_regalo'     => $event->huevosRegaloConsumidos,
            ],
            referenciaTipo: $event->contexto['kardex_referencia_type'] ?? null,
            referenciaId:   isset($event->contexto['kardex_referencia_id'])
                ? (int) $event->contexto['kardex_referencia_id'] : null,
        );
    }

    public function handleDevolucionKardex(DevolucionAplicadaAlLote $event): void
    {
        $huevosTotales = (float) $event->huevosFacturadosDevueltos + (float) $event->huevosRegaloDevueltos;

        if ($huevosTotales <= 0) {
            return;
        }

        $this->registrador->registrarLote(
            lote:           $event->lote,
            tipo:           $this->tipoDesdeContexto($event->contexto, MovimientoInventarioTipo::DevolucionReempaque),
            delta:          $huevosTotales,
            costoUnitario:  (float) $event->lote->costo_por_huevo_efectivo,
            descripcion:    $event->contexto['kardex_descripcion']
                ?? "Devolución de huevos al lote {$event->lote->numero_lote}",
            contexto:       $this->contextoLimpio($event->contexto) + [
                'huevos_facturados' => $event->huevosFacturadosDevueltos,
                'huevos_regalo'     => $event->huevosRegaloDevueltos,
            ],
            referenciaTipo: $event->contexto['kardex_referencia_type'] ?? null,
            referenciaId:   isset($event->contexto['kardex_referencia_id'])
                ? (int) $event->contexto['kardex_referencia_id'] : null,
        );
    }

    public function handleMermaKardex(MermaAplicadaAlLote $event): void
    {
        $huevosTotales = (float) $event->huevosPerdidaReal + (float) $event->huevosCubiertoBuffer;

        if ($huevosTotales <= 0) {
            return;
        }

        $this->registrador->registrarLote(
            lote:          $event->lote,
            tipo:          MovimientoInventarioTipo::Merma,
            delta:         -$huevosTotales,
            costoUnitario: (float) $event->lote->costo_por_huevo_efectivo,
            referencia:    $event->merma,
            descripcion:   "Merma en lote {$event->lote->numero_lote}",
            contexto:      [
                'huevos_perdida_real'    => $event->huevosPerdidaReal,
                'huevos_cubierto_buffer' => $event->huevosCubiertoBuffer,
            ],
        );
    }

    public function handleAjusteSalidaKardex(AjusteSalidaAplicadaAlLote $event): void
    {
        if ($event->huevosSalientes <= 0) {
            return;
        }

        $esCorreccion = ($event->contextoAuditoria['tipo_movimiento'] ?? null) === 'ajuste_correccion';

        $this->registrador->registrarLote(
            lote:          $event->lote,
            tipo:          $esCorreccion
                ? MovimientoInventarioTipo::AjusteCorreccion
                : MovimientoInventarioTipo::AjusteSalida,
            delta:         -(float) $event->huevosSalientes,
            costoUnitario: (float) $event->ajuste->costo_unitario_aplicado,
            referencia:    $event->ajuste,
            descripcion:   "Ajuste de inventario #{$event->ajuste->id} ({$event->ajuste->motivo->label()})",
            contexto:      $event->contextoAuditoria,
        );
    }

    public function handleAjusteEntradaKardex(AjusteEntradaAplicadoAlLote $event): void
    {
        if ($event->huevosEntrantes <= 0) {
            return;
        }

        $esCorreccion = ($event->contextoAuditoria['tipo_movimiento'] ?? null) === 'ajuste_correccion';

        $this->registrador->registrarLote(
            lote:          $event->lote,
            tipo:          $esCorreccion
                ? MovimientoInventarioTipo::AjusteCorreccion
                : MovimientoInventarioTipo::AjusteEntrada,
            delta:         (float) $event->huevosEntrantes,
            costoUnitario: (float) $event->costoUnitarioAplicado,
            referencia:    $event->ajuste,
            descripcion:   "Ajuste de inventario #{$event->ajuste->id} ({$event->ajuste->motivo->label()})",
            contexto:      $event->contextoAuditoria,
        );
    }

    // =================================================================
    // NIVEL BODEGA (producto terminado / lácteos)
    // =================================================================

    public function handleStockBodegaKardex(StockBodegaMovido $event): void
    {
        if ($event->delta == 0.0) {
            return;
        }

        // La referencia viaja como type+id en el contexto (el evento no
        // acarrea el modelo para no acoplar BodegaProducto a documentos).
        $refType = $event->contexto['kardex_referencia_type'] ?? null;
        $refId   = isset($event->contexto['kardex_referencia_id'])
            ? (int) $event->contexto['kardex_referencia_id']
            : null;

        $contexto = $event->contexto;
        unset($contexto['kardex_tipo'], $contexto['kardex_descripcion'],
              $contexto['kardex_referencia_type'], $contexto['kardex_referencia_id']);

        $this->registrador->registrarBodega(
            bodegaProducto: $event->bodegaProducto,
            tipo:           $this->tipoDesdeContexto($event->contexto, MovimientoInventarioTipo::Otro),
            delta:          $event->delta,
            costoUnitario:  $event->costoUnitario,
            descripcion:    $event->contexto['kardex_descripcion'] ?? "Movimiento de stock ({$event->origen})",
            contexto:       $contexto + ['origen_primitiva' => $event->origen],
            referenciaTipo: $refType,
            referenciaId:   $refId,
        );
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Quita las claves kardex_* del contexto antes de persistirlo — ya se
     * materializaron en columnas propias (tipo, descripcion, referencia).
     */
    private function contextoLimpio(array $contexto): array
    {
        unset($contexto['kardex_tipo'], $contexto['kardex_descripcion'],
              $contexto['kardex_referencia_type'], $contexto['kardex_referencia_id']);

        return $contexto;
    }

    /**
     * Resuelve el tipo del asiento: respeta 'kardex_tipo' del contexto si el
     * caller lo envió (contexto enriquecido); si no, usa el default del evento.
     */
    private function tipoDesdeContexto(array $contexto, MovimientoInventarioTipo $default): MovimientoInventarioTipo
    {
        $valor = $contexto['kardex_tipo'] ?? null;

        if (is_string($valor)) {
            return MovimientoInventarioTipo::tryFrom($valor) ?? $default;
        }

        return $default;
    }
}
