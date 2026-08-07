<?php

declare(strict_types=1);

namespace App\Services\Viaje;

use App\Exceptions\LimiteVentaDiariaException;
use App\Models\AutorizacionSegundaVenta;
use App\Models\Cliente;
use App\Models\ViajeVenta;
use Carbon\CarbonInterface;

/**
 * Control: una sola factura de ruta por cliente por día.
 *
 * Existe para que no se le carguen varias facturas (o todas) a un mismo
 * cliente. La regla completa:
 *
 *   1. Consumidor Final NO tiene límite. No es un cliente real, es el
 *      mostrador: ahí caen todas las ventas sin cliente registrado y limitarlo
 *      trabaría la ruta entera.
 *   2. Cualquier otro cliente: una venta viva por día calendario local,
 *      en CUALQUIER camión o viaje (el límite es del cliente, no del viaje).
 *   3. Las ventas canceladas no cuentan: si el chofer se equivocó y anuló,
 *      puede volver a facturarle.
 *   4. Un Jefe o Encargado puede autorizar una venta extra puntual
 *      (ver AutorizacionSegundaVenta). Cada autorización sirve una sola vez.
 *
 * IMPORTANTE: verificar() debe llamarse DENTRO de la transacción de la venta.
 * Usa lockForUpdate, que además de hacer la regla a prueba de dos usuarios
 * simultáneos, frena el doble tap del chofer con mala señal: el segundo toque
 * espera al primero, lo ve y rebota.
 */
class LimiteVentaDiariaService
{
    /**
     * Verifica si el cliente puede recibir una venta de ruta.
     *
     * @param  CarbonInterface|null  $momento  Momento de la venta (default: ahora).
     * @return AutorizacionSegundaVenta|null La autorización que habilitó la
     *                                       venta, para consumirla una vez
     *                                       creada; null si no hizo falta.
     *
     * @throws LimiteVentaDiariaException Si ya facturó hoy y no hay autorización.
     */
    public function verificar(Cliente $cliente, ?CarbonInterface $momento = null): ?AutorizacionSegundaVenta
    {
        // Regla 1: el mostrador no tiene límite.
        if ($cliente->esConsumidorFinal()) {
            return null;
        }

        $momento ??= now();

        $ventaDelDia = $this->ventaVivaDelDia($cliente, $momento);

        // Regla 2 y 3: sin venta viva hoy, pasa sin más.
        if (! $ventaDelDia) {
            return null;
        }

        // Regla 4: ¿hay una autorización sin usar para hoy?
        $autorizacion = AutorizacionSegundaVenta::query()
            ->delDia($cliente->id, $momento->toDateString())
            ->disponibles()
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($autorizacion) {
            return $autorizacion;
        }

        throw new LimiteVentaDiariaException(
            'Ya se le facturó a '.$cliente->nombre.' el día de hoy ('.$ventaDelDia->numero_venta.'). '
            .'Solo se permite una factura por cliente al día. Pida al encargado o al jefe que autorice una segunda venta.'
        );
    }

    /**
     * ¿Cuántas ventas vivas de ruta lleva el cliente en el día?
     *
     * Para mostrárselo a quien autoriza, no para decidir: la decisión la toma
     * verificar() con el lock puesto.
     */
    public function ventasVivasDelDia(Cliente $cliente, ?CarbonInterface $momento = null): int
    {
        return $this->consultaDelDia($cliente, $momento ?? now())->count();
    }

    /**
     * Autorizaciones sin usar que tiene el cliente para ese día.
     */
    public function autorizacionesDisponibles(Cliente $cliente, ?CarbonInterface $momento = null): int
    {
        return AutorizacionSegundaVenta::query()
            ->delDia($cliente->id, ($momento ?? now())->toDateString())
            ->disponibles()
            ->count();
    }

    private function ventaVivaDelDia(Cliente $cliente, CarbonInterface $momento): ?ViajeVenta
    {
        return $this->consultaDelDia($cliente, $momento)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Ventas de ruta del cliente en el día, sin contar las canceladas.
     *
     * El rango se arma con whereBetween en vez de whereDate para que MySQL
     * pueda usar el índice (cliente_id, fecha_venta) que ya trae viaje_ventas:
     * con el índice, el lock cae sobre esas filas y no sobre media tabla.
     */
    private function consultaDelDia(Cliente $cliente, CarbonInterface $momento)
    {
        return ViajeVenta::query()
            ->where('cliente_id', $cliente->id)
            ->whereBetween('fecha_venta', [
                $momento->copy()->startOfDay(),
                $momento->copy()->endOfDay(),
            ])
            ->where('estado', '!=', 'cancelada')
            ->orderBy('id');
    }
}
