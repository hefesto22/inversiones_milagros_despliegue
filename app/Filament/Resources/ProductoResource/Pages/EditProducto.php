<?php

namespace App\Filament\Resources\ProductoResource\Pages;

use App\Filament\Resources\ProductoResource;
use App\Models\BodegaProducto;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditProducto extends EditRecord
{
    protected static string $resource = ProductoResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Verificar si el usuario actual puede editar stock
     */
    protected function usuarioPuedeEditarStock(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['super_admin', 'jefe'])
            ->exists();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Cargar los valores de cada bodega
        $bodegasProducto = BodegaProducto::where('producto_id', $this->record->id)
            ->with('bodega')
            ->get();

        // 🎯 CARGAR COSTO PROMEDIO desde la primera bodega como referencia
        if ($bodegasProducto->isNotEmpty()) {
            $primeraBodega = $bodegasProducto->first();

            // Si existe costo_promedio_actual, usarlo como precio_sugerido de referencia
            if ($primeraBodega->costo_promedio_actual > 0) {
                $data['precio_sugerido'] = $primeraBodega->costo_promedio_actual;
            }
        }

        foreach ($bodegasProducto as $bodegaProducto) {
            // Cargar nombre de bodega
            $data["bodega_nombre_{$bodegaProducto->id}"] = $bodegaProducto->bodega
                ? $bodegaProducto->bodega->nombre
                : 'Sin bodega';

            // Cargar stock actual formateado a 2 decimales
            $data["stock_actual_{$bodegaProducto->id}"] = number_format($bodegaProducto->stock, 2, '.', '');

            // Cargar stock mínimo formateado a 2 decimales
            $data["stock_minimo_{$bodegaProducto->id}"] = number_format($bodegaProducto->stock_minimo, 2, '.', '');

            // 🎯 CARGAR COSTO PROMEDIO ACTUAL (sin decimales, ya está redondeado)
            $data["costo_promedio_{$bodegaProducto->id}"] = number_format($bodegaProducto->costo_promedio_actual ?? 0, 0, '.', '');

            // 🎯 CARGAR PRECIO DE VENTA SUGERIDO (sin decimales, ya está redondeado)
            $data["precio_venta_{$bodegaProducto->id}"] = number_format($bodegaProducto->precio_venta_sugerido ?? 0, 0, '.', '');

            // 🆕 CARGAR PRECIO CON ISV (calculado)
            $precioBase = $bodegaProducto->precio_venta_sugerido ?? 0;
            $aplicaIsv = $this->record->aplica_isv ?? true;
            $precioConIsv = $aplicaIsv && $precioBase > 0 ? ceil($precioBase * 1.15) : $precioBase;
            $data["precio_isv_{$bodegaProducto->id}"] = number_format($precioConIsv, 0, '.', '');
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentUser = Auth::user();
        $data['updated_by'] = $currentUser->id;

        // 🎯 MANEJAR PRECIO MÁXIMO COMPETITIVO
        // Convertir string vacío a null para que se guarde correctamente
        if (array_key_exists('precio_venta_maximo', $data)) {
            $data['precio_venta_maximo'] = !empty($data['precio_venta_maximo']) 
                ? (float) $data['precio_venta_maximo'] 
                : null;
        }

        // 🎯 MANEJAR MARGEN MÍNIMO DE SEGURIDAD
        if (array_key_exists('margen_minimo_seguridad', $data)) {
            $data['margen_minimo_seguridad'] = !empty($data['margen_minimo_seguridad']) 
                ? (float) $data['margen_minimo_seguridad'] 
                : 3.00; // Valor por defecto
        }

        // Verificar si puede editar stock
        $puedeEditarStock = $this->usuarioPuedeEditarStock();

        // Guardar los cambios de stock_minimo y stock_actual (si tiene permiso)
        $bodegasProducto = BodegaProducto::where('producto_id', $this->record->id)->get();

        foreach ($bodegasProducto as $bodegaProducto) {
            $updateData = [];

            // 🎯 GUARDAR STOCK MÍNIMO (siempre permitido)
            $keyMinimo = "stock_minimo_{$bodegaProducto->id}";
            if (isset($data[$keyMinimo])) {
                $updateData['stock_minimo'] = round((float)$data[$keyMinimo], 2);
                unset($data[$keyMinimo]);
            }

            // 🎯 GUARDAR STOCK ACTUAL (solo si tiene permiso: jefe o super_admin)
            $keyActual = "stock_actual_{$bodegaProducto->id}";
            if (isset($data[$keyActual]) && $puedeEditarStock) {
                $nuevoStock = round((float)$data[$keyActual], 2);
                $stockAnterior = $bodegaProducto->stock;

                // Solo actualizar si cambió
                if ($nuevoStock != $stockAnterior) {
                    $updateData['stock'] = $nuevoStock;

                    // 📝 Registrar el ajuste manual en logs
                    Log::info("Ajuste manual de stock", [
                        'producto_id' => $this->record->id,
                        'bodega_id' => $bodegaProducto->bodega_id,
                        'stock_anterior' => $stockAnterior,
                        'stock_nuevo' => $nuevoStock,
                        'usuario_id' => $currentUser->id,
                        'usuario_nombre' => $currentUser->name ?? 'N/A',
                    ]);
                }

                unset($data[$keyActual]);
            } elseif (isset($data[$keyActual])) {
                // Si no tiene permiso, solo remover del array sin guardar
                unset($data[$keyActual]);
            }

            // Aplicar actualizaciones si hay cambios
            if (!empty($updateData)) {
                $bodegaProducto->update($updateData);
            }
        }

        // 🎯 Limpiar todos los campos temporales (excepto precio_venta_maximo que es un campo real)
        foreach ($data as $key => $value) {
            if (
                str_starts_with($key, 'bodega_nombre_') ||
                str_starts_with($key, 'stock_actual_') ||
                str_starts_with($key, 'stock_minimo_') ||
                str_starts_with($key, 'costo_promedio_') ||
                (str_starts_with($key, 'precio_venta_') && $key !== 'precio_venta_maximo') ||
                str_starts_with($key, 'precio_isv_')
            ) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * 🆕 DESPUÉS DE GUARDAR: Recalcular precios de venta en todas las bodegas
     * 
     * Esto es necesario porque cuando cambias:
     * - precio_venta_maximo
     * - margen_minimo_seguridad  
     * - margen_ganancia
     * - tipo_margen
     * 
     * Los precios de venta deben recalcularse inmediatamente.
     */
    protected function afterSave(): void
    {
        $this->recalcularPreciosVenta();
    }

    /**
     * Hook alternativo de Filament v3
     */
    protected function saved(): void
    {
        $this->recalcularPreciosVenta();
    }

    /**
     * Método centralizado para recalcular precios
     */
    private function recalcularPreciosVenta(): void
    {
        // Recargar el producto para tener los valores actualizados
        $producto = $this->record->fresh();
        
        if (!$producto) {
            return;
        }

        // Obtener todas las bodegas de este producto
        $bodegasProducto = BodegaProducto::where('producto_id', $producto->id)->get();
        
        foreach ($bodegasProducto as $bodegaProducto) {
            // Solo recalcular si tiene costo promedio
            if ($bodegaProducto->costo_promedio_actual > 0) {
                // Recalcular precio de venta con la nueva configuración
                $bodegaProducto->actualizarPrecioVentaSegunCosto();
                $bodegaProducto->save();
                
                Log::info("Precio recalculado para producto en bodega", [
                    'producto_id' => $producto->id,
                    'producto_nombre' => $producto->nombre,
                    'bodega_id' => $bodegaProducto->bodega_id,
                    'costo_promedio' => $bodegaProducto->costo_promedio_actual,
                    'precio_venta_nuevo' => $bodegaProducto->precio_venta_sugerido,
                    'precio_maximo_configurado' => $producto->precio_venta_maximo,
                ]);
            }
        }
    }
}