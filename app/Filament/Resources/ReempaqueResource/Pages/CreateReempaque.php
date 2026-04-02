<?php

namespace App\Filament\Resources\ReempaqueResource\Pages;

use App\Filament\Resources\ReempaqueResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ReempaqueLote;
use App\Models\ReempaqueProducto;

class CreateReempaque extends CreateRecord
{
    protected static string $resource = ReempaqueResource::class;

    // Datos calculados para compartir entre metodos
    private array $datosCalculados = [];

    /**
     * Validar datos antes de crear
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
        $distribuciones = $data['distribuciones'] ?? [];

        // =============================================
        // VALIDACION 1: Debe haber al menos un lote seleccionado
        // =============================================
        if (empty($lotesSeleccionados)) {
            Notification::make()
                ->title('Error de validacion')
                ->body('Debe seleccionar al menos un lote.')
                ->danger()
                ->send();
            
            throw ValidationException::withMessages([
                'lotes_seleccionados' => 'Debe seleccionar al menos un lote.',
            ]);
        }

        // =============================================
        // VALIDACION 2: Detectar lotes duplicados
        // =============================================
        $loteIds = [];
        foreach ($lotesSeleccionados as $index => $loteData) {
            $loteId = $loteData['lote_id'] ?? null;
            if ($loteId) {
                if (in_array($loteId, $loteIds)) {
                    $lote = Lote::find($loteId);
                    $nombreLote = $lote ? $lote->numero_lote : "ID {$loteId}";
                    
                    Notification::make()
                        ->title('Lote duplicado')
                        ->body("El lote {$nombreLote} esta seleccionado mas de una vez.")
                        ->danger()
                        ->send();
                    
                    throw ValidationException::withMessages([
                        "lotes_seleccionados.{$index}.lote_id" => "Lote duplicado. Cada lote solo puede seleccionarse una vez.",
                    ]);
                }
                $loteIds[] = $loteId;
            }
        }

        // =============================================
        // VALIDACION 3: Verificar cada lote
        // =============================================
        foreach ($lotesSeleccionados as $index => $loteData) {
            $lote = Lote::find($loteData['lote_id'] ?? null);
            
            if (!$lote) {
                throw ValidationException::withMessages([
                    "lotes_seleccionados.{$index}.lote_id" => 'Lote no encontrado.',
                ]);
            }

            $huevosAUsar = (float) ($loteData['cantidad_huevos'] ?? 0);
            
            // Validar cantidad > 0
            if ($huevosAUsar <= 0) {
                Notification::make()
                    ->title('Cantidad invalida')
                    ->body("Debe especificar una cantidad mayor a 0 para el lote {$lote->numero_lote}.")
                    ->danger()
                    ->send();
                
                throw ValidationException::withMessages([
                    "lotes_seleccionados.{$index}.cantidad_c30" => 'Debe especificar una cantidad mayor a 0.',
                ]);
            }

            // Refrescar para obtener datos actualizados (evitar race conditions)
            $lote->refresh();
            
            // Verificar que el lote no este agotado
            if ($lote->estado === \App\Enums\LoteEstado::Agotado) {
                Notification::make()
                    ->title('Lote agotado')
                    ->body("El lote {$lote->numero_lote} esta agotado y no puede usarse.")
                    ->danger()
                    ->send();
                
                throw ValidationException::withMessages([
                    "lotes_seleccionados.{$index}.lote_id" => "El lote {$lote->numero_lote} esta agotado.",
                ]);
            }

            // Verificar disponibilidad real
            if ($huevosAUsar > $lote->cantidad_huevos_remanente) {
                $disponibleCartones = floor($lote->cantidad_huevos_remanente / 30);
                
                Notification::make()
                    ->title('Stock insuficiente')
                    ->body("El lote {$lote->numero_lote} solo tiene " . number_format($lote->cantidad_huevos_remanente, 0) . " huevos disponibles ({$disponibleCartones} cartones).")
                    ->danger()
                    ->send();
                
                throw ValidationException::withMessages([
                    "lotes_seleccionados.{$index}.cantidad_c30" => "Stock insuficiente. Disponible: {$disponibleCartones} cartones.",
                ]);
            }
        }

        // =============================================
        // VALIDACION 4: Debe haber al menos una distribucion
        // =============================================
        if (empty($distribuciones)) {
            throw ValidationException::withMessages([
                'distribuciones' => 'Debe especificar al menos una linea de distribucion.',
            ]);
        }

        // =============================================
        // VALIDACION 5: Cada distribucion debe estar completa
        // =============================================
        foreach ($distribuciones as $index => $dist) {
            $categoriaId = $dist['categoria_id'] ?? null;
            $unidadId = $dist['unidad_id'] ?? null;
            $cantidad = (int) ($dist['cantidad'] ?? 0);

            if (!$categoriaId) {
                throw ValidationException::withMessages([
                    "distribuciones.{$index}.categoria_id" => 'Debe seleccionar una categoria.',
                ]);
            }

            if (!$unidadId) {
                throw ValidationException::withMessages([
                    "distribuciones.{$index}.unidad_id" => 'Debe seleccionar una unidad.',
                ]);
            }

            if ($cantidad <= 0) {
                throw ValidationException::withMessages([
                    "distribuciones.{$index}.cantidad" => 'La cantidad debe ser mayor a 0.',
                ]);
            }

            // Verificar que existe el producto para esta combinacion
            $producto = Producto::where('categoria_id', $categoriaId)
                ->where('unidad_id', $unidadId)
                ->where('activo', true)
                ->first();

            if (!$producto) {
                $categoria = \App\Models\Categoria::find($categoriaId);
                $unidad = \App\Models\Unidad::find($unidadId);
                
                Notification::make()
                    ->title('Producto no encontrado')
                    ->body("No existe un producto activo para {$categoria->nombre} con unidad {$unidad->nombre}.")
                    ->danger()
                    ->send();
                
                throw ValidationException::withMessages([
                    "distribuciones.{$index}.categoria_id" => "No existe producto para esta combinacion de categoria y unidad.",
                ]);
            }
        }

        // Calcular datos con logica FIFO (facturados primero, regalo despues)
        $this->datosCalculados = $this->calcularDatosLotes($lotesSeleccionados);

        $totalHuevosUsados = $this->datosCalculados['total_huevos'];
        $huevosUtiles = $totalHuevosUsados;

        // FIX: costo_total ya viene con 4 decimales de precisión
        $costoTotal = $this->datosCalculados['costo_total'];

        // Calcular costo unitario promedio (4 decimales)
        $costoUnitarioPromedio = $totalHuevosUsados > 0
            ? round($costoTotal / $totalHuevosUsados, 4)
            : 0;

        // Calcular totales de distribuciones usando unidad_id
        $cartones30Total = 0;
        $cartones15Total = 0;

        foreach ($distribuciones as $dist) {
            $cantidad = (int) ($dist['cantidad'] ?? 0);
            $unidadId = $dist['unidad_id'] ?? null;
            
            if ($unidadId) {
                $unidad = \App\Models\Unidad::find($unidadId);
                if ($unidad && str_contains($unidad->nombre, '15')) {
                    $cartones15Total += $cantidad;
                } else {
                    $cartones30Total += $cantidad;
                }
            }
        }

        // =============================================
        // VALIDACION 6: El empaque debe cuadrar exactamente
        // =============================================
        $totalEmpacado = ($cartones30Total * 30) + ($cartones15Total * 15);

        if (abs($totalEmpacado - $huevosUtiles) > 0.01) {
            $diferencia = $huevosUtiles - $totalEmpacado;
            $mensaje = $diferencia > 0 
                ? "Faltan {$diferencia} huevos por asignar." 
                : "Exceso de " . abs($diferencia) . " huevos.";
            
            Notification::make()
                ->title('Distribucion incompleta')
                ->body($mensaje)
                ->danger()
                ->send();
            
            throw ValidationException::withMessages([
                'distribuciones' => $mensaje,
            ]);
        }

        $data['numero_reempaque'] = $this->generarNumeroReempaque($data['bodega_id']);
        $data['total_huevos_usados'] = $totalHuevosUsados;
        $data['huevos_utiles'] = $huevosUtiles;
        $data['costo_total'] = round($costoTotal, 4);
        $data['costo_unitario_promedio'] = $costoUnitarioPromedio;
        $data['estado'] = 'completado';
        $data['created_by'] = Auth::id();
        $data['cartones_30'] = $cartones30Total;
        $data['cartones_15'] = $cartones15Total;
        $data['huevos_sueltos'] = 0;
        $data['merma'] = 0;

        // Remover campos del formulario que no son columnas de la tabla
        unset($data['lotes_seleccionados'], $data['distribuciones']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $reempaque = $this->record;
        $data = $this->data;

        try {
            DB::transaction(function () use ($reempaque, $data) {
                $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
                $distribuciones = $data['distribuciones'] ?? [];
                $bodegaId = $reempaque->bodega_id;

                // 1. Procesar lotes origen con logica FIFO
                foreach ($lotesSeleccionados as $loteData) {
                    $this->procesarLoteOrigen($reempaque, $loteData);
                }

                // 2. Crear productos destino
                foreach ($distribuciones as $dist) {
                    $this->crearProductoDestino($reempaque, $dist, $bodegaId);
                }
            });

        } catch (\Exception $e) {
            // Si falla el procesamiento, marcar el reempaque como cancelado
            $reempaque->update([
                'estado' => 'cancelado',
                'nota' => ($reempaque->nota ? $reempaque->nota . "\n" : '') . 
                          '[ERROR] Fallo en procesamiento: ' . $e->getMessage(),
            ]);

            Log::error('Error en reempaque', [
                'reempaque_id' => $reempaque->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Error al procesar reempaque')
                ->body('Ocurrio un error durante el procesamiento. El reempaque ha sido cancelado. Error: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw $e;
        }
    }

    /**
     * CALCULAR DATOS DE LOTES CON LOGICA FIFO
     */
    private function calcularDatosLotes(array $lotesSeleccionados): array
    {
        $costoTotal = 0;
        $totalHuevos = 0;
        $totalHuevosRegaloUsados = 0;
        $totalHuevosFacturadosUsados = 0;

        foreach ($lotesSeleccionados as $loteData) {
            $lote = Lote::find($loteData['lote_id']);
            if (!$lote) continue;

            $huevosUsados = (float) ($loteData['cantidad_huevos'] ?? 0);
            $totalHuevos += $huevosUsados;

            // Usar el metodo del modelo para calcular consumo FIFO
            $resultado = $lote->calcularConsumoHuevos($huevosUsados);

            $totalHuevosFacturadosUsados += $resultado['huevos_facturados_usados'];
            $totalHuevosRegaloUsados += $resultado['huevos_regalo_usados'];

            // Solo los huevos facturados tienen costo
            $costoTotal += $resultado['costo'];
        }

        return [
            // FIX: 4 decimales para preservar precisión en toda la cadena
            'costo_total' => round($costoTotal, 4),
            'total_huevos' => $totalHuevos,
            'huevos_facturados_usados' => $totalHuevosFacturadosUsados,
            'huevos_regalo_usados' => $totalHuevosRegaloUsados,
        ];
    }

    /**
     * PROCESAR LOTE ORIGEN CON LOGICA FIFO
     */
    private function procesarLoteOrigen($reempaque, array $loteData): void
    {
        $lote = Lote::find($loteData['lote_id']);
        if (!$lote) {
            throw new \Exception("Lote ID {$loteData['lote_id']} no encontrado.");
        }

        // Refrescar para obtener datos actualizados
        $lote->refresh();

        $huevosUsados = (float) ($loteData['cantidad_huevos'] ?? 0);
        
        // Validar stock antes de procesar
        if ($huevosUsados > $lote->cantidad_huevos_remanente) {
            throw new \Exception(
                "Stock insuficiente en lote {$lote->numero_lote}. " .
                "Requerido: {$huevosUsados}, Disponible: {$lote->cantidad_huevos_remanente}"
            );
        }

        $huevosPorCarton = $lote->huevos_por_carton ?? 30;

        // Usar el metodo del modelo para calcular consumo FIFO
        $resultado = $lote->calcularConsumoHuevos($huevosUsados);

        // Convertir huevos a cartones para el registro
        $cartonesFacturadosUsados = $resultado['huevos_facturados_usados'] / $huevosPorCarton;
        $cartonesRegaloUsados = $resultado['huevos_regalo_usados'] / $huevosPorCarton;
        $cartonesTotalesUsados = $cartonesFacturadosUsados + $cartonesRegaloUsados;

        // El costo parcial solo incluye los huevos facturados
        $costoParcial = $resultado['costo'];

        ReempaqueLote::create([
            'reempaque_id' => $reempaque->id,
            'lote_id' => $loteData['lote_id'],
            'cantidad_cartones_usados' => round($cartonesTotalesUsados, 3),
            'cantidad_huevos_usados' => $huevosUsados,
            'cartones_facturados_usados' => round($cartonesFacturadosUsados, 3),
            'cartones_regalo_usados' => round($cartonesRegaloUsados, 3),
            'costo_parcial' => round($costoParcial, 4),
        ]);

        // Reducir remanente del lote Y registrar huevos de regalo consumidos
        $lote->reducirRemanente($huevosUsados, $resultado['huevos_regalo_usados']);
    }

    /**
     * Crear producto destino desde distribucion
     */
    private function crearProductoDestino($reempaque, array $dist, int $bodegaId): void
    {
        $categoriaId = $dist['categoria_id'] ?? null;
        $unidadId = $dist['unidad_id'] ?? null;
        $cantidad = (int) ($dist['cantidad'] ?? 0);

        if (!$categoriaId || !$unidadId || $cantidad <= 0) return;

        // Determinar huevos por unidad basado en nombre
        $unidad = \App\Models\Unidad::find($unidadId);
        $huevosPorUnidad = ($unidad && str_contains($unidad->nombre, '15')) ? 15 : 30;

        // Buscar producto por categoria y unidad
        $producto = Producto::where('categoria_id', $categoriaId)
            ->where('unidad_id', $unidadId)
            ->where('activo', true)
            ->first();

        if (!$producto) {
            $categoria = \App\Models\Categoria::find($categoriaId);
            throw new \Exception(
                "No existe un producto activo para la categoria '{$categoria->nombre}' con unidad '{$unidad->nombre}'. " .
                "Por favor, cree el producto primero."
            );
        }

        // FIX: Sin redondeo intermedio, preservar precisión completa
        $costoUnitario = ($reempaque->total_huevos_usados > 0)
        ? ($reempaque->costo_total / $reempaque->total_huevos_usados) * $huevosPorUnidad
        : 0;
        $costoTotal = $costoUnitario * $cantidad;

        // Registrar en reempaque_productos
        ReempaqueProducto::create([
            'reempaque_id' => $reempaque->id,
            'producto_id' => $producto->id,
            'bodega_id' => $bodegaId,
            'cantidad' => $cantidad,
            'costo_unitario' => round($costoUnitario, 4),
            'costo_total' => round($costoTotal, 4),
            'agregado_a_stock' => true,
        ]);

        // Agregar al stock de bodega_producto
        $bodegaProducto = \App\Models\BodegaProducto::firstOrCreate(
            [
                'bodega_id' => $bodegaId,
                'producto_id' => $producto->id,
            ],
            [
                'stock' => 0,
                'stock_minimo' => 0,
                'costo_promedio_actual' => 0,
                'precio_venta_sugerido' => 0,
                'activo' => true,
            ]
        );

        // Usar metodo de costo promedio ponderado
        $bodegaProducto->actualizarCostoPromedio($cantidad, $costoUnitario);
    }

    /**
     * Generar numero de reempaque
     */
    private function generarNumeroReempaque(int $bodegaId): string
    {
        $ultimoReempaque = \App\Models\Reempaque::where('bodega_id', $bodegaId)
            ->orderBy('id', 'desc')
            ->first();

        $secuencial = $ultimoReempaque
            ? intval(substr($ultimoReempaque->numero_reempaque, -6)) + 1
            : 1;

        return sprintf('R-B%d-%06d', $bodegaId, $secuencial);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $regaloUsados = $this->datosCalculados['huevos_regalo_usados'] ?? 0;
        
        if ($regaloUsados > 0) {
            $cartonesRegalo = $regaloUsados / 30;
            return "Reempaque creado - Incluye " . number_format($cartonesRegalo, 1) . " cartones de regalo (costo L 0)";
        }
        
        return 'Reempaque creado exitosamente';
    }
}