<?php

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * DTO que encapsula todos los datos calculados para la liquidacion de un viaje.
 *
 * Fuente unica de verdad para PDF, Filament UI, API, reportes, etc.
 * Todos los valores se calculan en tiempo real desde la BD,
 * sin depender de campos pre-calculados del modelo Viaje.
 */
class LiquidacionData
{
    public function __construct(
        // Resumen de ventas
        public readonly float $ventas_contado,
        public readonly float $ventas_credito,
        public readonly float $total_ventas,
        public readonly Collection $ventas_por_cliente,

        // Carga
        public readonly Collection $carga_inicial,
        public readonly float $total_cargado_costo,
        public readonly float $total_cargado_venta,
        public readonly float $porcentaje_vendido,

        // Descuentos
        public readonly float $total_descuentos,
        public readonly Collection $detalle_descuentos,
        public readonly float $venta_esperada_de_lo_vendido,

        // Devoluciones
        public readonly float $total_devuelto_costo,

        // Mermas (calculadas en tiempo real)
        public readonly float $total_merma_costo,
        public readonly Collection $mermas_detalle,
        public readonly float $mermas_cobrar_chofer,

        // Gastos
        public readonly Collection $gastos_aprobados,
        public readonly float $total_gastos,

        // Comisiones
        public readonly float $comision_ganada,

        // Rentabilidad
        public readonly float $costo_vendido,
        public readonly float $margen_bruto,
        public readonly float $utilidad_neta,

        // ISV detallado para el SAR
        public readonly float $isv_cobrado,
        public readonly float $isv_credito_fiscal,
        public readonly float $isv_a_pagar_sar,
        public readonly float $ganancia_real_sin_isv,

        // Efectivo
        public readonly float $efectivo_debe_entregar,
        public readonly float $efectivo_entregado,
        public readonly float $diferencia_efectivo,
        public readonly string $estado_efectivo,
    ) {}

    /**
     * Convierte el DTO a array asociativo.
     *
     * Esto permite que las vistas Blade existentes sigan funcionando
     * con $datos['key'] sin necesidad de cambiar la sintaxis.
     */
    public function toArray(): array
    {
        return [
            'ventas_contado' => $this->ventas_contado,
            'ventas_credito' => $this->ventas_credito,
            'total_ventas' => $this->total_ventas,
            'ventas_por_cliente' => $this->ventas_por_cliente,

            'carga_inicial' => $this->carga_inicial,
            'total_cargado_costo' => $this->total_cargado_costo,
            'total_cargado_venta' => $this->total_cargado_venta,
            'porcentaje_vendido' => $this->porcentaje_vendido,

            'total_descuentos' => $this->total_descuentos,
            'detalle_descuentos' => $this->detalle_descuentos,
            'venta_esperada_de_lo_vendido' => $this->venta_esperada_de_lo_vendido,

            'total_devuelto_costo' => $this->total_devuelto_costo,

            'total_merma_costo' => $this->total_merma_costo,
            'mermas_detalle' => $this->mermas_detalle,
            'mermas_cobrar_chofer' => $this->mermas_cobrar_chofer,

            'gastos_aprobados' => $this->gastos_aprobados,
            'total_gastos' => $this->total_gastos,

            'comision_ganada' => $this->comision_ganada,

            'costo_vendido' => $this->costo_vendido,
            'margen_bruto' => $this->margen_bruto,
            'utilidad_neta' => $this->utilidad_neta,

            'isv_cobrado' => $this->isv_cobrado,
            'isv_credito_fiscal' => $this->isv_credito_fiscal,
            'isv_a_pagar_sar' => $this->isv_a_pagar_sar,
            'ganancia_real_sin_isv' => $this->ganancia_real_sin_isv,

            'efectivo_debe_entregar' => $this->efectivo_debe_entregar,
            'efectivo_entregado' => $this->efectivo_entregado,
            'diferencia_efectivo' => $this->diferencia_efectivo,
            'estado_efectivo' => $this->estado_efectivo,
        ];
    }
}
