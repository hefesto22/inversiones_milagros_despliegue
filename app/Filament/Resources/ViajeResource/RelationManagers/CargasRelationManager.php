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
                                // Costo sin ISV (como está en la BD)
                                $costoSinIsv = $bodegaProducto->costo_promedio_actual ?? 0;
                                $aplicaIsv = $producto->aplica_isv ?? false;
                                
                                // Costo con ISV (lo que realmente pagó) - para mostrar
                                $costoConIsv = $aplicaIsv ? round($costoSinIsv * 1.15, 2) : $costoSinIsv;
                                
                                // El precio_venta_sugerido YA INCLUYE ISV
                                $precioConIsv = $bodegaProducto->precio_venta_sugerido ?? 0;
                                
                                // Precio sin ISV (desglosado)
                                $precioSinIsv = $aplicaIsv && $precioConIsv > 0
                                    ? round($precioConIsv / 1.15, 2) 
                                    : $precioConIsv;

                                $set('stock_disponible', number_format((float) $bodegaProducto->stock, 2));
                                $set('costo_unitario', $costoSinIsv); // Guardamos sin ISV en BD
                                $set('costo_con_isv', $costoConIsv); // Mostramos con ISV
                                $set('precio_venta_sugerido', $precioSinIsv); // Precio sin ISV
                                $set('precio_venta_minimo', $costoConIsv); // Precio mínimo = lo que pagaste
                                $set('aplica_isv', $aplicaIsv);
                                $set('precio_con_isv', $precioConIsv); // Precio con ISV (tal cual de BD)
                                $set('unidad_id', $producto->unidad_id);
                                
                                // Inicializar subtotales en 0
                                $set('subtotal_costo', 0);
                                $set('subtotal_venta', 0);
                                
                                // Si ya hay cantidad, calcular subtotales
                                $cantidad = floatval($get('cantidad') ?? 0);
                                if ($cantidad > 0) {
                                    $set('subtotal_costo', round($costoSinIsv * $cantidad, 2));
                                    $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
                                }
                            }
                        } else {
                            // Limpiar campos si no hay producto seleccionado
                            $set('stock_disponible', null);
                            $set('costo_unitario', null);
                            $set('costo_con_isv', null);
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

                // Costo que se muestra (con ISV si aplica)
                Forms\Components\TextInput::make('costo_con_isv')
                    ->label('Costo Unitario')
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(fn (Forms\Get $get) => 
                        ($get('aplica_isv') ?? false) 
                            ? 'Lo que pagaste (incluye ISV)' 
                            : 'Costo del producto'
                    ),

                // Costo real que se guarda en BD (sin ISV)
                Forms\Components\Hidden::make('costo_unitario')
                    ->default(0),

                Forms\Components\TextInput::make('precio_venta_sugerido')
                    ->label(fn (Forms\Get $get) => 
                        ($get('aplica_isv') ?? false) 
                            ? 'Precio Venta (sin ISV)' 
                            : 'Precio Venta'
                    )
                    ->numeric()
                    ->prefix('L')
                    ->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $precioSinIsv = floatval($state ?? 0);
                        $aplicaIsv = $get('aplica_isv') ?? false;
                        
                        // Calcular precio con ISV
                        $precioConIsv = $aplicaIsv 
                            ? round($precioSinIsv * (1 + self::ISV_RATE), 2)
                            : $precioSinIsv;
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
                    ->helperText('Precio final al cliente')
                    ->visible(fn (Forms\Get $get) => $get('aplica_isv') ?? false),

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
                    ->label(fn (Forms\Get $get) => 
                        ($get('aplica_isv') ?? false) 
                            ? 'Subtotal Venta (con ISV)' 
                            : 'Subtotal Venta'
                    )
                    ->numeric()
                    ->prefix('L')
                    ->disabled()
                    ->dehydrated(true)
                    ->default(0)
                    ->helperText(fn (Forms\Get $get) => 
                        ($get('aplica_isv') ?? false) 
                            ? 'Incluye ISV' 
                            : ''
                    ),
            ])
            ->columns(3);
    }

    /**
     * Recalcular subtotales considerando ISV
     */
    private function recalcularSubtotales(Forms\Get $get, Forms\Set $set, $cantidad): void
    {
        $cantidad = floatval($cantidad ?? 0);
        $costoSinIsv = floatval($get('costo_unitario') ?? 0);
        $precioConIsv = floatval($get('precio_con_isv') ?? 0);

        // Subtotal costo = costo unitario (sin ISV) × cantidad
        $set('subtotal_costo', round($costoSinIsv * $cantidad, 2));

        // Subtotal venta = precio con ISV × cantidad
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

                // Mostrar costo CON ISV (lo que realmente pagó)
                Tables\Columns\TextColumn::make('costo_unitario')
                    ->label('Costo Unit.')
                    ->money('HNL')
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        $costoSinIsv = $record->costo_unitario ?? 0;
                        $aplicaIsv = $record->producto?->aplica_isv ?? false;
                        
                        // Mostrar con ISV si aplica
                        if ($aplicaIsv && $costoSinIsv > 0) {
                            return round($costoSinIsv * 1.15, 2);
                        }
                        return $costoSinIsv;
                    })
                    ->description(fn ($record) => $record->producto?->aplica_isv ? 'Incluye ISV' : ''),

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
                        $costoSinIsv = $data['costo_unitario'] ?? 0;
                        $precioSinIsv = $data['precio_venta_sugerido'] ?? 0;
                        
                        $data['stock_disponible'] = number_format((float) (($bodegaProducto->stock ?? 0) + $record->cantidad), 2);
                        $data['aplica_isv'] = $aplicaIsv;
                        $data['costo_con_isv'] = $aplicaIsv ? round($costoSinIsv * 1.15, 2) : $costoSinIsv;
                        $data['precio_con_isv'] = $aplicaIsv 
                            ? round($precioSinIsv * (1 + self::ISV_RATE), 2)
                            : $precioSinIsv;
                        
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