<?php

namespace App\Application\DTOs;

use App\Models\VentaDetalle;
use App\Domain\Shared\ValueObjects\Money;

/**
 * DTO para detalle de venta
 */
final class VentaDetalleDTO
{
    private function __construct(
        public readonly int $id,
        public readonly int $ventaId,
        public readonly int $productoId,
        public readonly int $unidadId,
        public readonly float $cantidad,
        public readonly Money $precioUnitario,
        public readonly Money $precioConISV,
        public readonly Money $costoUnitario,
        public readonly bool $aplicaISV,
        public readonly Money $isvUnitario,
        public readonly float $descuentoPorcentaje,
        public readonly Money $descuentoMonto,
        public readonly Money $subtotal,
        public readonly Money $totalISV,
        public readonly Money $totalLinea,
        public readonly ?Money $precioAnterior = null,
    ) {}

    /**
     * Crear desde modelo
     */
    public static function fromModel(VentaDetalle $detalle): self
    {
        return new self(
            id: $detalle->id,
            ventaId: $detalle->venta_id,
            productoId: $detalle->producto_id,
            unidadId: $detalle->unidad_id,
            cantidad: $detalle->cantidad,
            precioUnitario: Money::make($detalle->precio_unitario),
            precioConISV: Money::make($detalle->precio_con_isv),
            costoUnitario: Money::make($detalle->costo_unitario),
            aplicaISV: $detalle->aplica_isv,
            isvUnitario: Money::make($detalle->isv_unitario),
            descuentoPorcentaje: $detalle->descuento_porcentaje,
            descuentoMonto: Money::make($detalle->descuento_monto),
            subtotal: Money::make($detalle->subtotal),
            totalISV: Money::make($detalle->total_isv),
            totalLinea: Money::make($detalle->total_linea),
            precioAnterior: $detalle->precio_anterior
                ? Money::make($detalle->precio_anterior)
                : null,
        );
    }

    /**
     * Calcular ganancia de esta línea
     */
    public function calcularGanancia(): Money
    {
        $costoTotal = $this->costoUnitario->multiply($this->cantidad);
        return $this->subtotal->subtract($costoTotal);
    }

    /**
     * Margen de ganancia en porcentaje
     */
    public function getMargenPorcentaje(): float
    {
        if ($this->costoUnitario->getAmount() <= 0) {
            return 100;
        }

        $diferencia = $this->precioUnitario->getAmount() - $this->costoUnitario->getAmount();
        return ($diferencia / $this->costoUnitario->getAmount()) * 100;
    }

    /**
     * ¿El precio cambió respecto al anterior?
     */
    public function precioCambio(): bool
    {
        if (!$this->precioAnterior) {
            return false;
        }

        return !$this->precioUnitario->equals($this->precioAnterior);
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'venta_id' => $this->ventaId,
            'producto_id' => $this->productoId,
            'unidad_id' => $this->unidadId,
            'cantidad' => $this->cantidad,
            'precio_unitario' => $this->precioUnitario->getAmount(),
            'precio_con_isv' => $this->precioConISV->getAmount(),
            'costo_unitario' => $this->costoUnitario->getAmount(),
            'aplica_isv' => $this->aplicaISV,
            'isv_unitario' => $this->isvUnitario->getAmount(),
            'descuento_porcentaje' => $this->descuentoPorcentaje,
            'descuento_monto' => $this->descuentoMonto->getAmount(),
            'subtotal' => $this->subtotal->getAmount(),
            'total_isv' => $this->totalISV->getAmount(),
            'total_linea' => $this->totalLinea->getAmount(),
        ];
    }
}
