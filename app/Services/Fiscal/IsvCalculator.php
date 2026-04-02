<?php

namespace App\Services\Fiscal;

use App\Models\Producto;

/**
 * Servicio de cálculo de ISV (Impuesto Sobre Ventas) de Honduras.
 *
 * Centraliza la lógica fiscal que antes estaba dispersa en CompraResource.
 * Tasa actual: 15%.
 */
class IsvCalculator
{
    /**
     * Tasa de ISV vigente en Honduras.
     */
    public const TASA_ISV = 0.15;

    /**
     * Verificar si un producto aplica ISV según su categoría.
     */
    public static function productoAplicaIsv(?int $productoId): bool
    {
        if (!$productoId) {
            return false;
        }

        $producto = Producto::with('categoria')->find($productoId);

        if (!$producto || !$producto->categoria) {
            return false;
        }

        return (bool) $producto->categoria->aplica_isv;
    }

    /**
     * Calcular desglose de ISV a partir de un precio que ya incluye el impuesto.
     *
     * @param float $precioConIsv Precio final incluyendo ISV
     * @return array{precio_con_isv: float, costo_sin_isv: float, isv_credito: float}
     */
    public static function calcularDesglose(float $precioConIsv): array
    {
        $factor = 1 + self::TASA_ISV;
        $costoSinIsv = round($precioConIsv / $factor, 2);
        $isvCredito = round($precioConIsv - $costoSinIsv, 2);

        return [
            'precio_con_isv' => round($precioConIsv, 2),
            'costo_sin_isv' => $costoSinIsv,
            'isv_credito' => $isvCredito,
        ];
    }

    /**
     * Calcular ISV a partir de un precio sin impuesto.
     *
     * @param float $precioSinIsv Precio base sin ISV
     * @return array{precio_sin_isv: float, isv: float, precio_con_isv: float}
     */
    public static function calcularIsv(float $precioSinIsv): array
    {
        $isv = round($precioSinIsv * self::TASA_ISV, 2);
        $precioConIsv = round($precioSinIsv + $isv, 2);

        return [
            'precio_sin_isv' => round($precioSinIsv, 2),
            'isv' => $isv,
            'precio_con_isv' => $precioConIsv,
        ];
    }
}
