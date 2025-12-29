<?php

namespace App\Filament\Resources\ProductoResource\Pages;

use App\Filament\Resources\ProductoResource;
use App\Models\BodegaProducto;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;

class CreateProducto extends CreateRecord
{
    protected static string $resource = ProductoResource::class;

    // Propiedades para almacenar datos temporales
    protected $bodegaId;
    protected $stockMinimo;
    protected $precioSugerido;
    protected $margenGanancia;
    protected $tipoMargen;

    protected function getRedirectUrl(): string
    {
        // Redirigir al edit del producto recién creado
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $currentUser = Auth::user();

        Log::info('Datos antes de mutar:', $data);

        // 🔧 Guardar datos temporalmente ANTES de eliminarlos
        $this->bodegaId = $data['bodega_id'] ?? null;
        $this->stockMinimo = isset($data['stock_minimo']) ? round((float)$data['stock_minimo'], 2) : 0;

        // Guardar datos de precio para usarlos en afterCreate
        $this->precioSugerido = isset($data['precio_sugerido']) ? (float)$data['precio_sugerido'] : 0;
        $this->margenGanancia = isset($data['margen_ganancia']) ? (float)$data['margen_ganancia'] : 5;
        $this->tipoMargen = $data['tipo_margen'] ?? 'monto';

        Log::info('Datos de precio guardados:', [
            'precio_sugerido' => $this->precioSugerido,
            'margen_ganancia' => $this->margenGanancia,
            'tipo_margen' => $this->tipoMargen
        ]);

        // 🔧 DETERMINAR BODEGA FINAL PRIMERO
        $bodegaFinal = $this->determinarBodegaFinal($currentUser, $this->bodegaId);

        Log::info('Bodega Final en mutate: ' . $bodegaFinal);

        // 🆕 VALIDAR QUE NO EXISTA EL MISMO PRODUCTO EN ESA BODEGA
        if ($bodegaFinal && isset($data['nombre'])) {
            $existe = DB::table('productos')
                ->join('bodega_producto', 'productos.id', '=', 'bodega_producto.producto_id')
                ->where('productos.nombre', $data['nombre'])
                ->where('bodega_producto.bodega_id', $bodegaFinal)
                ->where('productos.deleted_at', null)
                ->exists();

            if ($existe) {
                $nombreBodega = DB::table('bodegas')
                    ->where('id', $bodegaFinal)
                    ->value('nombre');

                Notification::make()
                    ->title('Producto duplicado')
                    ->body("Ya existe el producto \"{$data['nombre']}\" en la bodega \"{$nombreBodega}\".")
                    ->danger()
                    ->send();

                $this->halt();
            }
        }

        // 🆕 GENERAR SKU AUTOMÁTICO BASADO EN LA BODEGA
        if ($bodegaFinal) {
            $data['sku'] = $this->generarSKU($bodegaFinal);
            Log::info('SKU generado: ' . $data['sku']);
        } else {
            Log::error('No se pudo determinar bodega para generar SKU');
            $data['sku'] = 'SKU-' . time();
        }

        // Remover campos que no pertenecen al modelo Producto
        unset(
            $data['bodega_id'],
            $data['stock_minimo']
        );

        // Agregar usuario creador
        $data['created_by'] = $currentUser->id;

        Log::info('Datos después de mutar (con SKU):', $data);

        return $data;
    }

    protected function afterCreate(): void
    {
        $currentUser = Auth::user();
        $producto = $this->record;

        Log::info('En afterCreate - Bodega ID: ' . $this->bodegaId);
        Log::info('En afterCreate - Stock Minimo: ' . $this->stockMinimo);
        Log::info('En afterCreate - Precio Sugerido: ' . $this->precioSugerido);
        Log::info('Producto creado con SKU: ' . $producto->sku);

        // Determinar la bodega a usar
        $bodegaFinal = $this->determinarBodegaFinal($currentUser, $this->bodegaId);

        Log::info('Bodega Final determinada: ' . $bodegaFinal);

        // 🎯 Crear el registro en bodega_producto con costo promedio inicial
        if ($bodegaFinal) {

            // 🎯 Calcular precio de venta inicial (si hay precio sugerido)
            $costoPromedioInicial = 0;
            $precioVentaInicial = 0;

            if ($this->precioSugerido > 0) {
                // 🎯 Usar precio sugerido como costo inicial (redondeado hacia arriba)
                $costoPromedioInicial = ceil($this->precioSugerido);

                // Calcular precio de venta según el tipo de margen
                if ($this->tipoMargen === 'porcentaje') {
                    $precioVentaInicial = $costoPromedioInicial * (1 + ($this->margenGanancia / 100));
                } else {
                    // monto
                    $precioVentaInicial = $costoPromedioInicial + $this->margenGanancia;
                }

                // 🎯 Redondear precio de venta hacia arriba
                $precioVentaInicial = ceil($precioVentaInicial);

                Log::info('Precios calculados para bodega_producto:', [
                    'costo_promedio_inicial' => $costoPromedioInicial,
                    'precio_venta_inicial' => $precioVentaInicial,
                    'margen' => $this->margenGanancia,
                    'tipo_margen' => $this->tipoMargen
                ]);
            }

            // 🎯 Crear registro en bodega_producto (SIN campos semanales)
            $bodegaProducto = BodegaProducto::create([
                'bodega_id' => $bodegaFinal,
                'producto_id' => $producto->id,
                'stock' => 0.00,
                'stock_minimo' => $this->stockMinimo,
                'activo' => true,
                // 🎯 NUEVOS CAMPOS DE COSTO PROMEDIO CONTINUO
                'costo_promedio_actual' => $costoPromedioInicial,
                'precio_venta_sugerido' => $precioVentaInicial,
            ]);

            Log::info('BodegaProducto creado con costo promedio:', $bodegaProducto->toArray());
        } else {
            Log::error('No se pudo determinar la bodega final');
        }
    }

    protected function determinarBodegaFinal($user, $bodegaIdSeleccionada): ?int
    {
        // Verificar si el usuario es super_admin o jefe
        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($user))
            ->where('model_has_roles.model_id', '=', $user->id)
            ->whereIn('roles.name', ['super_admin', 'jefe'])
            ->exists();

        // Si tiene uno de esos roles, usar la bodega seleccionada
        if ($esSuperAdminOJefe && $bodegaIdSeleccionada) {
            return $bodegaIdSeleccionada;
        }

        // Si no, usar la primera bodega asignada al usuario
        return DB::table('bodega_user')
            ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
            ->where('bodega_user.user_id', $user->id)
            ->where('bodegas.activo', true)
            ->value('bodegas.id');
    }

    /**
     * Generar SKU automático basado en la bodega
     * Formato: B{ID_BODEGA}{SECUENCIAL}
     * Ejemplos: B100001, B200001, B1000001
     */
    protected function generarSKU(int $bodegaId): string
    {
        // Buscar el último SKU de esta bodega
        $ultimoSKU = \App\Models\Producto::where('sku', 'LIKE', "B{$bodegaId}%")
            ->orderBy('sku', 'desc')
            ->value('sku');

        if ($ultimoSKU) {
            // Extraer el número secuencial del último SKU
            $prefijo = "B{$bodegaId}";
            $numeroSecuencial = (int) str_replace($prefijo, '', $ultimoSKU);
            $nuevoNumero = $numeroSecuencial + 1;
        } else {
            // Si no hay productos de esta bodega, empezar en 1
            $nuevoNumero = 1;
        }

        // Formatear el número con padding de 5 dígitos
        $numeroFormateado = str_pad($nuevoNumero, 5, '0', STR_PAD_LEFT);

        return "B{$bodegaId}{$numeroFormateado}";
    }
}
