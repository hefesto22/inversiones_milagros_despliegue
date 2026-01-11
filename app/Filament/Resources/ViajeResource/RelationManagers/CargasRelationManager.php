<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\Producto;
use App\Models\BodegaProducto;
use App\Models\Unidad;
use App\Models\Viaje;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class CargasRelationManager extends RelationManager
{
    protected static string $relationship = 'cargas';

    protected static ?string $title = 'Productos Cargados';

    protected static ?string $modelLabel = 'Carga';

    protected static ?string $pluralModelLabel = 'Cargas';

    // Tasa de ISV
    private const ISV_RATE = 0.15;

    public function isReadOnly(): bool
    {
        return !in_array($this->getOwnerRecord()->estado, [
            Viaje::ESTADO_PLANIFICADO,
            Viaje::ESTADO_CARGANDO
        ]);
    }

    public function form(Form $form): Form
    {
        $viaje = $this->getOwnerRecord();

        return $form
            ->schema([
                Forms\Components\Select::make('producto_id')
                    ->label('Producto')
                    ->options(function () use ($viaje) {
                        // Obtener los IDs de productos ya cargados en este viaje
                        $productosYaCargados = $viaje->cargas()->pluck('producto_id')->toArray();

                        return BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('stock', '>', 0)
                            ->whereNotIn('producto_id', $productosYaCargados)
                            ->with('producto')
                            ->get()
                            ->pluck('producto.nombre', 'producto_id');
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) use ($viaje) {
                        if ($state) {
                            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                ->where('producto_id', $state)
                                ->first();

                            $producto = Producto::find($state);

                            if ($bodegaProducto && $producto) {
                                $precioBase = $bodegaProducto->precio_venta_sugerido ?? ($bodegaProducto->costo_promedio_actual + 5);
                                $aplicaIsv = $producto->aplica_isv ?? false;
                                
                                // Precio con ISV redondeado hacia arriba
                                $precioConIsv = $aplicaIsv 
                                    ? (int) ceil($precioBase * (1 + self::ISV_RATE)) 
                                    : (int) $precioBase;

                                $set('stock_disponible', number_format((float) $bodegaProducto->stock, 2));
                                $set('costo_unitario', $bodegaProducto->costo_promedio_actual);
                                $set('precio_venta_sugerido', $precioBase);
                                $set('precio_venta_minimo', $bodegaProducto->costo_promedio_actual);
                                $set('aplica_isv', $aplicaIsv);
                                $set('precio_con_isv', $precioConIsv);
                                $set('unidad_id', $producto->unidad_id);
                                
                                // Inicializar subtotales en 0
                                $set('subtotal_costo', 0);
                                $set('subtotal_venta', 0);
                                
                                // Si ya hay cantidad, calcular subtotales
                                $cantidad = floatval($get('cantidad') ?? 0);
                                if ($cantidad > 0) {
                                    $set('subtotal_costo', round($bodegaProducto->costo_promedio_actual * $cantidad, 2));
                                    $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
                                }
                            }
                        } else {
                            // Limpiar campos si no hay producto seleccionado
                            $set('stock_disponible', null);
                            $set('costo_unitario', null);
                            $set('precio_venta_sugerido', null);
                            $set('precio_venta_minimo', null);
                            $set('aplica_isv', false);
                            $set('precio_con_isv', null);
                            $set('subtotal_costo', 0);
                            $set('subtotal_venta', 0);
                        }
                    })
                    ->columnSpan(2),

                Forms\Components\Select::make('unidad_id')
                    ->label('Unidad')
                    ->options(fn () => Unidad::where('activo', true)->pluck('nombre', 'id'))
                    ->required()
                    ->searchable(),

                Forms\Components\TextInput::make('stock_disponible')
                    ->label('Stock Disponible')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\TextInput::make('cantidad')
                    ->label('Cantidad a Cargar')
                    ->numeric()
                    ->required()
                    ->minValue(0.001)
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $this->recalcularSubtotales($get, $set, $state);
                    }),

                Forms\Components\TextInput::make('costo_unitario')
                    ->label('Costo Unitario')
                    ->numeric()
                    ->prefix('L')
                    ->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $set('precio_venta_minimo', floatval($state));
                        $this->recalcularSubtotales($get, $set, $get('cantidad'));
                    }),

                Forms\Components\TextInput::make('precio_venta_sugerido')
                    ->label('Precio Venta (sin ISV)')
                    ->numeric()
                    ->prefix('L')
                    ->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $precioBase = floatval($state ?? 0);
                        $aplicaIsv = $get('aplica_isv') ?? false;
                        
                        // Actualizar precio con ISV (redondeado hacia arriba)
                        $precioConIsv = $aplicaIsv 
                            ? (int) ceil($precioBase * (1 + self::ISV_RATE)) 
                            : (int) $precioBase;
                        $set('precio_con_isv', $precioConIsv);
                        
                        // Recalcular subtotales
                        $this->recalcularSubtotales($get, $set, $get('cantidad'));
                    }),

                Forms\Components\Toggle::make('aplica_isv')
                    ->label('Aplica ISV (15%)')
                    ->disabled()
                    ->dehydrated(false)
                    ->inline(false)
                    ->onColor('success')
                    ->offColor('gray'),

                Forms\Components\TextInput::make('precio_con_isv')
                    ->label('Precio con ISV')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Precio final al cliente'),

                Forms\Components\TextInput::make('precio_venta_minimo')
                    ->label('Precio Mínimo')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true)
                    ->helperText('No vender por debajo'),

                Forms\Components\TextInput::make('subtotal_costo')
                    ->label('Subtotal Costo')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true)
                    ->default(0),

                Forms\Components\TextInput::make('subtotal_venta')
                    ->label('Subtotal Venta (con ISV)')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true)
                    ->default(0)
                    ->helperText('Incluye ISV si aplica'),
            ])
            ->columns(3);
    }

    /**
     * Recalcular subtotales considerando ISV
     * CORRECTO: Usa precio_con_isv (ya redondeado) × cantidad
     */
    private function recalcularSubtotales(Forms\Get $get, Forms\Set $set, $cantidad): void
    {
        $cantidad = floatval($cantidad ?? 0);
        $costo = floatval($get('costo_unitario') ?? 0);
        $precioConIsv = floatval($get('precio_con_isv') ?? 0);

        // Subtotal costo = costo unitario × cantidad
        $set('subtotal_costo', round($costo * $cantidad, 2));

        // Subtotal venta = precio con ISV (redondeado) × cantidad
        // Esto es correcto para facturación: el cliente paga el precio unitario redondeado
        $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('costo_unitario')
                    ->label('Costo Unit.')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('precio_venta_sugerido')
                    ->label('Precio Venta')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\IconColumn::make('producto.aplica_isv')
                    ->label('ISV')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->producto?->aplica_isv ? 'Aplica 15% ISV' : 'Sin ISV'),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Subtotal Costo')
                    ->money('HNL')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL')),

                Tables\Columns\TextColumn::make('subtotal_venta')
                    ->label('Subtotal Venta')
                    ->money('HNL')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL'))
                    ->tooltip('Incluye ISV si aplica'),

                // Columnas visibles cuando está en ruta o cerrado
                Tables\Columns\TextColumn::make('cantidad_vendida')
                    ->label('Vendido')
                    ->numeric(decimalPlaces: 2)
                    ->color('success')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO,
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('cantidad_merma')
                    ->label('Merma')
                    ->numeric(decimalPlaces: 2)
                    ->color('danger')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO,
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('disponible')
                    ->label('Disponible')
                    ->getStateUsing(fn ($record) => $record->getCantidadDisponible())
                    ->numeric(decimalPlaces: 2)
                    ->color('warning')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_REGRESANDO,
                    ])),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Producto')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO
                    ]))
                    ->before(function (array $data) {
                        // Validar stock disponible
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $data['producto_id'])
                            ->first();

                        if (!$bodegaProducto || $bodegaProducto->stock < $data['cantidad']) {
                            Notification::make()
                                ->title('Stock insuficiente')
                                ->body('No hay suficiente stock disponible para este producto.')
                                ->danger()
                                ->send();

                            $this->halt();
                        }
                    })
                    ->after(function ($record) {
                        // Descontar del stock de bodega
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($bodegaProducto) {
                            $bodegaProducto->reducirStock($record->cantidad);
                        }

                        // Cambiar estado a cargando si está planificado
                        if ($viaje->estado === Viaje::ESTADO_PLANIFICADO) {
                            $viaje->iniciarCarga();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO
                    ]))
                    ->mutateRecordDataUsing(function (array $data, $record): array {
                        // Cargar datos adicionales para el formulario de edición
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();
                        
                        $producto = $record->producto;
                        $aplicaIsv = $producto?->aplica_isv ?? false;
                        $precioBase = $data['precio_venta_sugerido'] ?? 0;
                        
                        $data['stock_disponible'] = number_format((float) (($bodegaProducto->stock ?? 0) + $record->cantidad), 2);
                        $data['aplica_isv'] = $aplicaIsv;
                        $data['precio_con_isv'] = $aplicaIsv 
                            ? (int) ceil($precioBase * (1 + self::ISV_RATE)) 
                            : (int) $precioBase;
                        
                        return $data;
                    })
                    ->before(function ($record, array $data) {
                        // Si cambia la cantidad, ajustar stock
                        $viaje = $this->getOwnerRecord();
                        $diferencia = $data['cantidad'] - $record->cantidad;

                        if ($diferencia > 0) {
                            // Necesita más stock
                            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                ->where('producto_id', $record->producto_id)
                                ->first();

                            if (!$bodegaProducto || $bodegaProducto->stock < $diferencia) {
                                Notification::make()
                                    ->title('Stock insuficiente')
                                    ->body('No hay suficiente stock para aumentar la cantidad.')
                                    ->danger()
                                    ->send();

                                $this->halt();
                            }
                        }
                    })
                    ->after(function ($record, array $data) {
                        $viaje = $this->getOwnerRecord();
                        $cantidadAnterior = $record->getOriginal('cantidad');
                        $diferencia = $record->cantidad - $cantidadAnterior;

                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($bodegaProducto && $diferencia != 0) {
                            if ($diferencia > 0) {
                                $bodegaProducto->reducirStock($diferencia);
                            } else {
                                // Devolver stock
                                $bodegaProducto->stock += abs($diferencia);
                                $bodegaProducto->save();
                            }
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO
                    ]))
                    ->before(function ($record) {
                        // Devolver stock a bodega
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($bodegaProducto) {
                            $bodegaProducto->stock += $record->cantidad;
                            $bodegaProducto->save();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                            Viaje::ESTADO_PLANIFICADO,
                            Viaje::ESTADO_CARGANDO
                        ])),
                ]),
            ])
            ->emptyStateHeading('Sin productos cargados')
            ->emptyStateDescription('Agregue productos para cargar en el camión.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }
}