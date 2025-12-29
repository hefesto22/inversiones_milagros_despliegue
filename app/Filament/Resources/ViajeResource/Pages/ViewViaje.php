<?php

namespace App\Filament\Resources\ViajeResource\Pages;

use App\Filament\Resources\ViajeResource;
use App\Models\Viaje;
use App\Models\Producto;
use App\Models\BodegaProducto;
use App\Models\Unidad;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViewViaje extends ViewRecord
{
    protected static string $resource = ViajeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => !in_array($this->record->estado, [Viaje::ESTADO_CERRADO, Viaje::ESTADO_CANCELADO])),

            // ========================================
            // AGREGAR CARGA (Modal con productos)
            // ========================================
            Actions\Action::make('agregar_carga')
                ->label('Agregar Carga')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->modalHeading('Cargar Productos al Viaje')
                ->modalDescription('Seleccione los productos y cantidades a cargar en el camión.')
                ->modalSubmitActionLabel('Cargar Productos')
                ->modalWidth('4xl')
                ->visible(fn () => in_array($this->record->estado, [Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO]))
                ->form([
                    Forms\Components\Repeater::make('productos')
                        ->label('')
                        ->schema([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\Select::make('producto_id')
                                        ->label('Producto')
                                        ->options(function () {
                                            return BodegaProducto::where('bodega_id', $this->record->bodega_origen_id)
                                                ->where('stock', '>', 0)
                                                ->with('producto')
                                                ->get()
                                                ->pluck('producto.nombre', 'producto_id');
                                        })
                                        ->searchable()
                                        ->preload()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                                            if ($state) {
                                                $bodegaProducto = BodegaProducto::where('bodega_id', $this->record->bodega_origen_id)
                                                    ->where('producto_id', $state)
                                                    ->first();

                                                $producto = Producto::find($state);

                                                if ($bodegaProducto) {
                                                    $set('stock_disponible', number_format($bodegaProducto->stock, 2));
                                                    $set('costo_unitario', number_format($bodegaProducto->costo_promedio_actual, 2));
                                                    $set('precio_sugerido', number_format($bodegaProducto->precio_venta_sugerido ?? ($bodegaProducto->costo_promedio_actual * 1.3), 2));
                                                }

                                                if ($producto) {
                                                    $set('unidad_id', $producto->unidad_id);
                                                }
                                            }
                                        }),

                                    Forms\Components\Select::make('unidad_id')
                                        ->label('Unidad')
                                        ->options(fn () => Unidad::where('activo', true)->pluck('nombre', 'id'))
                                        ->required(),
                                ]),

                            Forms\Components\Grid::make(4)
                                ->schema([
                                    Forms\Components\TextInput::make('stock_disponible')
                                        ->label('Stock Disponible')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->placeholder('0.00'),

                                    Forms\Components\TextInput::make('cantidad')
                                        ->label('Cantidad a Cargar')
                                        ->numeric()
                                        ->required()
                                        ->minValue(0.001)
                                        ->live(debounce: 500)
                                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                            $costo = floatval(str_replace(',', '', $get('costo_unitario') ?? 0));
                                            $precio = floatval(str_replace(',', '', $get('precio_sugerido') ?? 0));
                                            $cantidad = floatval($state ?? 0);

                                            $set('subtotal_costo', number_format($costo * $cantidad, 2));
                                            $set('subtotal_venta', number_format($precio * $cantidad, 2));
                                        }),

                                    Forms\Components\TextInput::make('costo_unitario')
                                        ->label('Costo Unitario')
                                        ->numeric()
                                        ->prefix('L')
                                        ->required()
                                        ->live(debounce: 500)
                                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                            $costo = floatval(str_replace(',', '', $state ?? 0));
                                            $cantidad = floatval($get('cantidad') ?? 0);
                                            $set('subtotal_costo', number_format($costo * $cantidad, 2));
                                        }),

                                    Forms\Components\TextInput::make('precio_sugerido')
                                        ->label('Precio Venta')
                                        ->numeric()
                                        ->prefix('L')
                                        ->required()
                                        ->live(debounce: 500)
                                        ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                            $precio = floatval(str_replace(',', '', $state ?? 0));
                                            $cantidad = floatval($get('cantidad') ?? 0);
                                            $set('subtotal_venta', number_format($precio * $cantidad, 2));
                                        }),
                                ]),

                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('subtotal_costo')
                                        ->label('Subtotal Costo')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->prefix('L')
                                        ->placeholder('0.00'),

                                    Forms\Components\TextInput::make('subtotal_venta')
                                        ->label('Subtotal Venta')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->prefix('L')
                                        ->placeholder('0.00'),
                                ]),
                        ])
                        ->defaultItems(1)
                        ->addActionLabel('+ Agregar otro producto')
                        ->reorderable(false)
                        ->collapsible()
                        ->cloneable()
                        ->itemLabel(fn (array $state): ?string =>
                            isset($state['producto_id']) && $state['producto_id']
                                ? Producto::find($state['producto_id'])?->nombre ?? 'Producto'
                                : 'Nuevo producto'
                        ),
                ])
                ->action(function (array $data) {
                    DB::beginTransaction();

                    try {
                        $productosAgregados = 0;

                        foreach ($data['productos'] as $item) {
                            if (empty($item['producto_id']) || empty($item['cantidad'])) {
                                continue;
                            }

                            // Limpiar valores con comas
                            $cantidad = floatval(str_replace(',', '', $item['cantidad']));
                            $costoUnitario = floatval(str_replace(',', '', $item['costo_unitario']));
                            $precioSugerido = floatval(str_replace(',', '', $item['precio_sugerido']));

                            // Validar stock disponible
                            $bodegaProducto = BodegaProducto::where('bodega_id', $this->record->bodega_origen_id)
                                ->where('producto_id', $item['producto_id'])
                                ->first();

                            if (!$bodegaProducto || $bodegaProducto->stock < $cantidad) {
                                throw new \Exception("Stock insuficiente para: " . (Producto::find($item['producto_id'])?->nombre ?? 'Desconocido'));
                            }

                            // Verificar si ya existe una carga para este producto
                            $cargaExistente = $this->record->cargas()
                                ->where('producto_id', $item['producto_id'])
                                ->first();

                            $cantidadAnterior = $cargaExistente?->cantidad ?? 0;
                            $diferencia = $cantidad - $cantidadAnterior;

                            // Si necesita más stock, validar disponibilidad
                            if ($diferencia > 0 && $bodegaProducto->stock < $diferencia) {
                                throw new \Exception("Stock insuficiente para: " . (Producto::find($item['producto_id'])?->nombre ?? 'Desconocido'));
                            }

                            // Crear o actualizar la carga
                            $this->record->cargas()->updateOrCreate(
                                ['producto_id' => $item['producto_id']],
                                [
                                    'unidad_id' => $item['unidad_id'],
                                    'cantidad' => $cantidad,
                                    'costo_unitario' => $costoUnitario,
                                    'precio_venta_sugerido' => $precioSugerido,
                                    'precio_venta_minimo' => $costoUnitario,
                                    'subtotal_costo' => $cantidad * $costoUnitario,
                                    'subtotal_venta' => $cantidad * $precioSugerido,
                                ]
                            );

                            // Ajustar stock de bodega
                            if ($diferencia > 0) {
                                // Descontar del stock
                                $bodegaProducto->reducirStock($diferencia);
                            } elseif ($diferencia < 0) {
                                // Devolver al stock
                                $bodegaProducto->stock += abs($diferencia);
                                $bodegaProducto->save();
                            }

                            $productosAgregados++;
                        }

                        if ($productosAgregados === 0) {
                            throw new \Exception("Debe agregar al menos un producto.");
                        }

                        // Cambiar estado a cargando si está planificado
                        if ($this->record->estado === Viaje::ESTADO_PLANIFICADO) {
                            $this->record->iniciarCarga();
                        }

                        DB::commit();

                        Notification::make()
                            ->title('Productos cargados')
                            ->body("Se han agregado {$productosAgregados} producto(s) al viaje.")
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));

                    } catch (\Exception $e) {
                        DB::rollBack();

                        Notification::make()
                            ->title('Error al cargar productos')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // ========================================
            // INICIAR RUTA
            // ========================================
            Actions\Action::make('iniciar_ruta')
                ->label('Iniciar Ruta')
                ->icon('heroicon-o-truck')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Iniciar Ruta')
                ->modalDescription(function () {
                    $totalCargas = $this->record->cargas()->count();
                    $totalCosto = $this->record->cargas()->sum('subtotal_costo');
                    return "El viaje tiene {$totalCargas} producto(s) cargado(s) con un costo total de L " . number_format($totalCosto, 2) . ". ¿Desea iniciar la ruta?";
                })
                ->modalSubmitActionLabel('Iniciar Ruta')
                ->visible(fn () => in_array($this->record->estado, [Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO]))
                ->action(function () {
                    if ($this->record->cargas()->count() === 0) {
                        Notification::make()
                            ->title('Error')
                            ->body('No puede iniciar ruta sin cargar productos.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $this->record->iniciarRuta();

                    Notification::make()
                        ->title('Viaje en ruta')
                        ->body('El camión está ahora en ruta.')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                }),

            // ========================================
            // INICIAR REGRESO
            // ========================================
            Actions\Action::make('iniciar_regreso')
                ->label('Regresar')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Iniciar Regreso')
                ->modalDescription('El viaje cambiará a estado "Regresando".')
                ->modalSubmitActionLabel('Confirmar Regreso')
                ->visible(fn () => $this->record->estado === Viaje::ESTADO_EN_RUTA)
                ->action(function () {
                    $this->record->iniciarRegreso();

                    Notification::make()
                        ->title('Viaje regresando')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                }),

            // ========================================
            // INICIAR DESCARGA
            // ========================================
            Actions\Action::make('iniciar_descarga')
                ->label('Descargar')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Iniciar Descarga')
                ->modalDescription('Podrá registrar los productos que regresan al inventario.')
                ->modalSubmitActionLabel('Iniciar Descarga')
                ->visible(fn () => $this->record->estado === Viaje::ESTADO_REGRESANDO)
                ->action(function () {
                    $this->record->iniciarDescarga();

                    Notification::make()
                        ->title('Descarga iniciada')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                }),

            // ========================================
            // LIQUIDAR
            // ========================================
            Actions\Action::make('liquidar')
                ->label('Liquidar')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Iniciar Liquidación')
                ->modalDescription('Se revisarán los cobros y comisiones del chofer.')
                ->modalSubmitActionLabel('Iniciar Liquidación')
                ->visible(fn () => in_array($this->record->estado, [Viaje::ESTADO_REGRESANDO, Viaje::ESTADO_DESCARGANDO]))
                ->action(function () {
                    $this->record->iniciarLiquidacion();

                    Notification::make()
                        ->title('Liquidación iniciada')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                }),

            // ========================================
            // CERRAR VIAJE
            // ========================================
            Actions\Action::make('cerrar')
                ->label('Cerrar Viaje')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Cerrar Viaje')
                ->modalDescription('Se calcularán los totales finales. Esta acción no se puede revertir.')
                ->modalSubmitActionLabel('Cerrar Viaje')
                ->visible(fn () => $this->record->estado === Viaje::ESTADO_LIQUIDANDO)
                ->action(function () {
                    try {
                        $this->record->cerrar();

                        Notification::make()
                            ->title('Viaje cerrado')
                            ->body("Neto chofer: L " . number_format($this->record->neto_chofer, 2))
                            ->success()
                            ->duration(10000)
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error al cerrar')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // ========================================
            // CANCELAR
            // ========================================
            Actions\Action::make('cancelar')
                ->label('Cancelar Viaje')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar Viaje')
                ->modalDescription('¿Está seguro de cancelar este viaje?')
                ->form([
                    Forms\Components\Textarea::make('motivo')
                        ->label('Motivo de cancelación')
                        ->required(),
                ])
                ->visible(fn () => !in_array($this->record->estado, [Viaje::ESTADO_CERRADO, Viaje::ESTADO_CANCELADO]))
                ->action(function (array $data) {
                    $this->record->cancelar($data['motivo']);

                    Notification::make()
                        ->title('Viaje cancelado')
                        ->warning()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                }),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->estado === Viaje::ESTADO_PLANIFICADO),
        ];
    }
}
