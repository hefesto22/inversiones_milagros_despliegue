<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\ClienteProducto;
use App\Models\Producto;

/**
 * Centraliza la lógica de "precio bloqueado" para clientes con restricción
 * de precio fijo (actualmente solo Consumidor Final).
 *
 * MOTIVACIÓN
 * ----------
 * Antes de este servicio, los vendedores de ruta podían digitar cualquier
 * precio al vender a Consumidor Final, abriendo la posibilidad de cobrar
 * el precio sugerido al cliente y reportar un precio menor al sistema
 * "como si hubieran dado descuento", quedándose con la diferencia.
 *
 * El servicio resuelve un único precio EXACTO autorizado por la
 * administración, y los formularios bloquean la edición a ese valor.
 *
 * JERARQUÍA DE PRECIO
 * -------------------
 * 1. Si el cliente NO es Consumidor Final → null (precio editable normal).
 * 2. Si hay cliente_producto.precio_autorizado configurado → ese valor exacto.
 * 3. Si no hay excepción → producto.precio_venta_maximo como default.
 * 4. Si tampoco hay precio_venta_maximo → null (el caller bloquea la venta).
 */
class PrecioVentaService
{
    /**
     * Devuelve el precio EXACTO que debe cobrarse, o null si NO hay bloqueo
     * para esta combinación cliente+producto (precio queda editable normal).
     *
     * Retornar null cuando el cliente es Consumidor Final pero no hay precio
     * configurable indica deuda: el caller debe bloquear la venta y avisar
     * que falta configurar el producto.
     */
    public function obtenerPrecioBloqueado(Cliente $cliente, Producto $producto): ?float
    {
        // Solo aplica a clientes con bloqueo de precio.
        // Hoy: únicamente Consumidor Final. Si en el futuro se necesita
        // extender a otros clientes, agregar la condición aquí.
        if (! $cliente->esConsumidorFinal()) {
            return null;
        }

        // 1. Excepción configurada por Admin para este cliente+producto
        $excepcion = ClienteProducto::query()
            ->where('cliente_id', $cliente->id)
            ->where('producto_id', $producto->id)
            ->whereNotNull('precio_autorizado')
            ->value('precio_autorizado');

        if ($excepcion !== null) {
            return (float) $excepcion;
        }

        // 2. Default: precio_venta_maximo del producto
        if ($producto->precio_venta_maximo > 0) {
            return (float) $producto->precio_venta_maximo;
        }

        // 3. Sin configuración → el caller debe rechazar la venta
        return null;
    }

    /**
     * Indica si el precio enviado por el cliente del formulario coincide con
     * el precio bloqueado autorizado. Se compara con tolerancia de 0.01 L
     * para absorber redondeos del front, sin permitir desvíos significativos.
     *
     * - Devuelve TRUE si el cliente no tiene bloqueo (cualquier precio es válido).
     * - Devuelve TRUE si el cliente tiene bloqueo y el precio coincide.
     * - Devuelve FALSE si el cliente tiene bloqueo y el precio NO coincide.
     */
    public function precioCoincide(Cliente $cliente, Producto $producto, float $precioIntentado): bool
    {
        $precioBloqueado = $this->obtenerPrecioBloqueado($cliente, $producto);

        if ($precioBloqueado === null) {
            // Sin bloqueo: cualquier precio es válido a este nivel.
            // Las otras reglas (descuento máximo, costo+1) las maneja PuntoVentaRuta.
            return ! $cliente->esConsumidorFinal();
        }

        // Tolerancia de 1 centavo para absorber redondeos float del front.
        return abs($precioIntentado - $precioBloqueado) < 0.01;
    }

    /**
     * Mensaje de error a mostrar cuando el precio no coincide o no se puede
     * vender porque falta configuración. Centralizado aquí para que el mensaje
     * sea uniforme en PuntoVentaRuta y VentaResource.
     */
    public function obtenerMensajeBloqueo(Cliente $cliente, Producto $producto): string
    {
        $precioBloqueado = $this->obtenerPrecioBloqueado($cliente, $producto);

        if ($precioBloqueado === null) {
            return "El producto \"{$producto->nombre}\" no tiene precio configurado para Consumidor Final. "
                .'Pedir al administrador que configure el precio de venta máximo del producto o un precio autorizado específico.';
        }

        return 'Precio bloqueado para Consumidor Final: L '.number_format($precioBloqueado, 2)
            .'. Solo Admin puede modificar precios autorizados.';
    }
}
