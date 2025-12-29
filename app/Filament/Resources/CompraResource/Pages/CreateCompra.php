<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Filament\Resources\CompraResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        // GENERAR NÚMERO DE COMPRA BASADO EN LA BODEGA
        $bodegaId = $data['bodega_id'] ?? null;

        if ($bodegaId) {
            $data['numero_compra'] = $this->generarNumeroCompra($bodegaId);
        } else {
            // Fallback si no hay bodega (no debería pasar)
            $data['numero_compra'] = 'C-' . time();
        }

        // Calcular saldo pendiente
        if (isset($data['tipo_pago']) && $data['tipo_pago'] === 'credito') {
            $data['saldo_pendiente'] = $data['total'] ?? 0;
        }

        return $data;
    }

    /**
     * Actualizar precios en proveedor_producto después de crear la compra
     *
     * NOTA: Los LOTES NO se crean aquí.
     * Los lotes se crean SOLO cuando la compra se marca como RECIBIDA en ViewCompra.
     * Esto evita tener lotes "fantasma" de compras que nunca se completaron.
     */
    protected function afterCreate(): void
    {
        $compra = $this->record;
        $proveedorId = $compra->proveedor_id;

        // Obtener todos los detalles de la compra
        $detalles = $compra->detalles;

        foreach ($detalles as $detalle) {
            $productoId = $detalle->producto_id;
            $precioUnitario = $detalle->precio_unitario;

            // 🎯 Actualizar o crear registro en proveedor_producto
            DB::table('proveedor_producto')->updateOrInsert(
                [
                    'proveedor_id' => $proveedorId,
                    'producto_id' => $productoId,
                ],
                [
                    'ultimo_precio_compra' => $precioUnitario,
                    'actualizado_en' => now(),
                ]
            );
        }
    }

    /**
     * Generar número de compra automático basado en la bodega
     * Formato: B{ID_BODEGA}{SECUENCIAL}
     * Ejemplos: B10000001, B20000001, B10000002
     */
    protected function generarNumeroCompra(int $bodegaId): string
    {
        // Buscar el último número de compra de esta bodega
        $ultimaCompra = \App\Models\Compra::where('numero_compra', 'LIKE', "B{$bodegaId}%")
            ->orderBy('numero_compra', 'desc')
            ->value('numero_compra');

        if ($ultimaCompra) {
            // Extraer el número secuencial del último número de compra
            $prefijo = "B{$bodegaId}";
            $numeroSecuencial = (int) str_replace($prefijo, '', $ultimaCompra);
            $nuevoNumero = $numeroSecuencial + 1;
        } else {
            // Si no hay compras de esta bodega, empezar en 1
            $nuevoNumero = 1;
        }

        // Formatear el número con padding de 7 dígitos
        $numeroFormateado = str_pad($nuevoNumero, 7, '0', STR_PAD_LEFT);

        return "B{$bodegaId}{$numeroFormateado}";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
