<?php

namespace App\Console\Commands;

use App\Models\BodegaProducto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ActualizarPreciosSemanales extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'precios:actualizar-semanales';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualiza los precios de venta de todos los productos por bodega basándose en las compras de la semana anterior';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Iniciando actualización de precios semanales por bodega...');
        $this->newLine();

        // Obtener todas las relaciones bodega-producto activas
        $bodegaProductos = BodegaProducto::where('activo', true)
            ->with(['bodega', 'producto'])
            ->get();

        $totalRegistros = $bodegaProductos->count();
        $registrosActualizados = 0;
        $registrosSinCompras = 0;
        $registrosConError = 0;

        $this->info("📦 Total de productos en bodegas: {$totalRegistros}");
        $this->newLine();

        // Crear barra de progreso
        $bar = $this->output->createProgressBar($totalRegistros);
        $bar->start();

        foreach ($bodegaProductos as $bodegaProducto) {
            try {
                // Verificar si hay compras de la semana anterior en esta bodega
                $comprasSemanaAnterior = $bodegaProducto->comprasSemanaAnterior()->get();

                $bodegaNombre = $bodegaProducto->bodega->nombre ?? 'Sin bodega';
                $productoNombre = $bodegaProducto->producto->nombre ?? 'Sin producto';
                $productoSku = $bodegaProducto->producto->sku ?? 'Sin SKU';

                if ($comprasSemanaAnterior->isNotEmpty()) {
                    // Guardar valores anteriores para log
                    $precioAnterior = $bodegaProducto->precio_compra_semana_actual;
                    $precioVentaAnterior = $bodegaProducto->precio_venta_calculado;

                    // Ejecutar el método del modelo
                    $bodegaProducto->establecerPrecioParaSemanaNueva();

                    // Recargar para obtener los nuevos valores
                    $bodegaProducto->refresh();

                    $registrosActualizados++;

                    Log::info("✅ Precio actualizado: {$bodegaNombre} - {$productoNombre}", [
                        'bodega_id' => $bodegaProducto->bodega_id,
                        'producto_id' => $bodegaProducto->producto_id,
                        'sku' => $productoSku,
                        'precio_compra_anterior' => $precioAnterior,
                        'precio_compra_nuevo' => $bodegaProducto->precio_compra_semana_actual,
                        'precio_venta_anterior' => $precioVentaAnterior,
                        'precio_venta_nuevo' => $bodegaProducto->precio_venta_calculado,
                        'compras_procesadas' => $comprasSemanaAnterior->count(),
                    ]);
                } else {
                    // Sin compras, solo actualizar fecha
                    $bodegaProducto->update([
                        'fecha_inicio_semana' => BodegaProducto::getInicioSemanaActual(),
                    ]);

                    $registrosSinCompras++;

                    Log::info("ℹ️  Sin compras: {$bodegaNombre} - {$productoNombre} - Precio mantenido", [
                        'bodega_id' => $bodegaProducto->bodega_id,
                        'producto_id' => $bodegaProducto->producto_id,
                        'sku' => $productoSku,
                    ]);
                }
            } catch (\Exception $e) {
                $registrosConError++;

                $bodegaNombre = $bodegaProducto->bodega->nombre ?? 'Sin bodega';
                $productoNombre = $bodegaProducto->producto->nombre ?? 'Sin producto';

                Log::error("❌ Error al actualizar: {$bodegaNombre} - {$productoNombre}", [
                    'bodega_id' => $bodegaProducto->bodega_id,
                    'producto_id' => $bodegaProducto->producto_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->error("\n❌ Error en: {$bodegaNombre} - {$productoNombre}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Resumen final
        $this->info('✅ Actualización completada!');
        $this->newLine();

        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Total registros procesados (bodega-producto)', $totalRegistros],
                ['✅ Actualizados con nuevos precios', $registrosActualizados],
                ['ℹ️  Sin compras (precio mantenido)', $registrosSinCompras],
                ['❌ Errores', $registrosConError],
            ]
        );

        // Log final
        Log::info('📊 Resumen de actualización de precios semanales por bodega', [
            'total_registros' => $totalRegistros,
            'actualizados' => $registrosActualizados,
            'sin_compras' => $registrosSinCompras,
            'errores' => $registrosConError,
            'fecha' => now()->format('Y-m-d H:i:s'),
        ]);

        return Command::SUCCESS;
    }
}
