<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class VentasRelationManager extends RelationManager
{
    protected static string $relationship = 'ventas';

    protected static ?string $title = 'Ventas en Ruta';

    protected static ?string $modelLabel = 'venta';

    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->estado === 'finalizado';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de la Venta')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('cliente_id')
                                    ->label('Cliente')
                                    ->relationship('cliente', 'nombre', fn ($query) => $query->where('estado', true))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('nombre')->required(),
                                        Forms\Components\Select::make('tipo')
                                            ->options([
                                                'mayorista' => 'Mayorista',
                                                'minorista' => 'Minorista',
                                                'distribuidor' => 'Distribuidor',
                                                'ruta' => 'Ruta',
                                            ])
                                            ->default('ruta'),
                                        Forms\Components\TextInput::make('telefono'),
                                    ]),

                                Forms\Components\DateTimePicker::make('fecha_venta')
                                    ->label('Fecha')
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->seconds(false),

                                Forms\Components\Select::make('tipo_pago')
                                    ->label('Tipo de Pago')
                                    ->required()
                                    ->options([
                                        'contado' => 'Contado',
                                        'credito' => 'Crédito',
                                    ])
                                    ->default('contado')
                                    ->live()
                                    ->native(false),

                                Forms\Components\TextInput::make('plazo_dias')
                                    ->label('Plazo (días)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Forms\Get $get) => $get('tipo_pago') === 'credito'),

                                Forms\Components\TextInput::make('numero_factura')
                                    ->label('No. Factura')
                                    ->maxLength(50),

                                Forms\Components\Select::make('estado')
                                    ->options([
                                        'borrador' => 'Borrador',
                                        'confirmada' => 'Confirmada',
                                        'cancelada' => 'Cancelada',
                                    ])
                                    ->default('confirmada')
                                    ->native(false),
                            ]),

                        Forms\Components\Textarea::make('nota')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Detalles de Venta')
                    ->schema([
                        Forms\Components\Repeater::make('detalles')
                            ->relationship('detalles')
                            ->schema([
                                Forms\Components\Select::make('producto_id')
                                    ->label('Producto')
                                    ->options(function () {
                                        // Solo productos cargados en el viaje
                                        return $this->getOwnerRecord()->cargas()
                                            ->with('producto')
                                            ->get()
                                            ->pluck('producto.nombre', 'producto_id')
                                            ->unique();
                                    })
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $set('unidad_id_presentacion', null);
                                        $set('precio_unitario_presentacion', null);

                                        if ($state) {
                                            $producto = Producto::find($state);
                                            $precioRef = $producto?->getPrecioReferenciaActivo();
                                            if ($precioRef) {
                                                $set('precio_referencia', $precioRef);
                                            }
                                        }
                                    }),

                                Forms\Components\Select::make('unidad_id_presentacion')
                                    ->label('Presentación')
                                    ->required()
                                    ->options(function (Forms\Get $get) {
                                        $productoId = $get('producto_id');
                                        if (!$productoId) return [];

                                        return ProductoPresentacion::where('producto_id', $productoId)
                                            ->where('activo', true)
                                            ->with('unidad')
                                            ->get()
                                            ->pluck('unidad.nombre', 'unidad.id');
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $productoId = $get('producto_id');
                                        if (!$productoId || !$state) return;

                                        $presentacion = ProductoPresentacion::where('producto_id', $productoId)
                                            ->where('unidad_id', $state)
                                            ->first();

                                        if ($presentacion) {
                                            $set('factor_a_base', $presentacion->factor_a_base);

                                            if ($presentacion->precio_sugerido) {
                                                $set('precio_unitario_presentacion', $presentacion->precio_sugerido);
                                            }
                                        }
                                    }),

                                Forms\Components\TextInput::make('cantidad_presentacion')
                                    ->label('Cantidad')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.01)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $factor = $get('factor_a_base');
                                        if ($state && $factor) {
                                            $set('cantidad_base', $state * $factor);
                                        }
                                    }),

                                Forms\Components\Hidden::make('factor_a_base')->default(1),
                                Forms\Components\Hidden::make('cantidad_base'),
                                Forms\Components\Hidden::make('precio_referencia'),

                                Forms\Components\TextInput::make('precio_unitario_presentacion')
                                    ->label('Precio')
                                    ->required()
                                    ->numeric()
                                    ->prefix('L')
                                    ->minValue(0)
                                    ->step(0.0001)
                                    ->live(onBlur: true),

                                Forms\Components\TextInput::make('descuento')
                                    ->label('Desc.')
                                    ->numeric()
                                    ->prefix('L')
                                    ->default(0)
                                    ->step(0.01),

                                Forms\Components\Placeholder::make('total_linea_display')
                                    ->label('Total')
                                    ->content(function (Forms\Get $get) {
                                        $cantidad = $get('cantidad_presentacion') ?? 0;
                                        $precio = $get('precio_unitario_presentacion') ?? 0;
                                        $descuento = $get('descuento') ?? 0;
                                        $total = ($cantidad * $precio) - $descuento;
                                        return 'L ' . number_format($total, 2);
                                    }),
                            ])
                            ->columns(7)
                            ->defaultItems(1)
                            ->collapsible()
                            ->addActionLabel('Agregar producto'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('numero_venta')
            ->columns([
                Tables\Columns\TextColumn::make('numero_venta')
                    ->label('No. Venta')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('fecha_venta')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'contado' => 'success',
                        'credito' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('saldo_pendiente')
                    ->label('Saldo')
                    ->money('HNL')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->visible(fn ($record) => $record->tipo_pago === 'credito'),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'borrador' => 'Borrador',
                        'confirmada' => 'Confirmada',
                        'cancelada' => 'Cancelada',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'borrador' => 'gray',
                        'confirmada' => 'success',
                        'cancelada' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado'),
                Tables\Filters\SelectFilter::make('tipo_pago'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->visible(fn () => $this->getOwnerRecord()->estado === 'en_ruta')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = Auth::id();

                        // Generar número de venta
                        $ultimaVenta = \App\Models\ViajeVenta::latest('id')->first();
                        $numero = $ultimaVenta ? $ultimaVenta->id + 1 : 1;
                        $data['numero_venta'] = 'VR-' . $this->getOwnerRecord()->id . '-' . str_pad($numero, 4, '0', STR_PAD_LEFT);

                        // Calcular saldo pendiente
                        if ($data['tipo_pago'] === 'credito') {
                            $data['saldo_pendiente'] = $data['total'] ?? 0;
                        }

                        return $data;
                    })
                    ->after(function () {
                        \Filament\Notifications\Notification::make()
                            ->title('Venta registrada')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => $this->getOwnerRecord()->estado === 'en_ruta' && $record->estado === 'borrador'),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $this->getOwnerRecord()->estado === 'en_ruta' && $record->estado === 'borrador'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => $this->getOwnerRecord()->estado === 'en_ruta'),
                ]),
            ])
            ->defaultSort('fecha_venta', 'desc');
    }
}
