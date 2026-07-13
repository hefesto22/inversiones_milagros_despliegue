<?php

namespace App\Filament\Resources\ClienteResource\RelationManagers;

use App\Models\ClienteProducto;
use App\Models\Producto;
use App\Models\ProductoPrecioTipo;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PreciosRelationManager extends RelationManager
{
    protected static string $relationship = 'preciosCliente';

    protected static ?string $title = 'Historial de Precios y Descuentos';

    protected static ?string $modelLabel = 'precio';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('producto.unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('ultimo_precio_venta')
                    ->label('Último Precio')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('ultimo_precio_con_isv')
                    ->label('Precio + ISV')
                    ->money('HNL')
                    ->sortable()
                    ->color('warning')
                    ->description('Con 15% ISV'),

                Tables\Columns\TextColumn::make('cantidad_ultima_venta')
                    ->label('Últ. Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('fecha_ultima_venta')
                    ->label('Última Venta')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->description(fn ($record) => $record->fecha_ultima_venta
                        ? $record->fecha_ultima_venta->diffForHumans()
                        : null
                    ),

                Tables\Columns\TextColumn::make('total_ventas')
                    ->label('Veces Vendido')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('cantidad_total_vendida')
                    ->label('Total Vendido')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // === COLUMNAS DE DESCUENTO ===

                Tables\Columns\TextColumn::make('regla_tipo')
                    ->label('Descuento por Tipo')
                    ->getStateUsing(function ($record) {
                        $cliente = $this->getOwnerRecord();
                        $regla = ProductoPrecioTipo::where('producto_id', $record->producto_id)
                            ->where('tipo_cliente', $cliente->tipo)
                            ->where('activo', true)
                            ->first();

                        if (! $regla) {
                            return null;
                        }

                        return (float) $regla->descuento_maximo;
                    })
                    ->formatStateUsing(fn ($state) => $state !== null ? 'L '.number_format($state, 2) : null)
                    ->placeholder('Sin regla')
                    ->color('gray')
                    ->tooltip(function ($record) {
                        $cliente = $this->getOwnerRecord();

                        return 'Regla general para tipo: '.ucfirst($cliente->tipo);
                    }),

                Tables\Columns\TextColumn::make('descuento_maximo_override')
                    ->label('Descuento Especial')
                    ->formatStateUsing(fn ($state) => $state !== null ? 'L '.number_format($state, 2) : null)
                    ->placeholder('Usa regla general')
                    ->color('danger')
                    ->weight('bold')
                    ->tooltip('Override individual para este cliente'),

                Tables\Columns\TextColumn::make('precio_minimo_efectivo')
                    ->label('Precio Mínimo')
                    ->getStateUsing(function ($record) {
                        $cliente = $this->getOwnerRecord();
                        $producto = Producto::find($record->producto_id);

                        if (! $producto) {
                            return null;
                        }

                        $precioVenta = (float) ($producto->precio_venta_maximo ?? 0);

                        if ($precioVenta <= 0) {
                            return null;
                        }

                        $resultado = $producto->obtenerPrecioMinimo($cliente, $precioVenta);

                        return $resultado['precio_minimo'];
                    })
                    ->formatStateUsing(fn ($state) => $state !== null ? 'L '.number_format($state, 2) : null)
                    ->placeholder('Sin restricción')
                    ->color('success')
                    ->weight('bold'),

                // ==========================================================
                // COLUMNA PRECIO AUTORIZADO (solo visible para Consumidor Final)
                // ==========================================================
                Tables\Columns\TextColumn::make('precio_autorizado')
                    ->label('🔒 Precio Autorizado')
                    ->formatStateUsing(fn ($state) => $state !== null ? 'L '.number_format($state, 2) : null)
                    ->placeholder('Usa precio del producto')
                    ->color('warning')
                    ->weight('bold')
                    ->tooltip('Precio fijo autorizado por Admin. Solo aplica a Consumidor Final.')
                    ->visible(fn () => $this->getOwnerRecord()->esConsumidorFinal()),
            ])
            ->filters([
                Tables\Filters\Filter::make('con_ventas_recientes')
                    ->label('Ventas últimos 30 días')
                    ->query(fn ($query) => $query->where('fecha_ultima_venta', '>=', now()->subDays(30))),

                Tables\Filters\Filter::make('productos_frecuentes')
                    ->label('Productos frecuentes (5+ ventas)')
                    ->query(fn ($query) => $query->where('total_ventas', '>=', 5)),

                Tables\Filters\Filter::make('con_descuento_especial')
                    ->label('Con descuento especial')
                    ->query(fn ($query) => $query->whereNotNull('descuento_maximo_override'))
                    ->toggle(),
            ])
            ->headerActions([
                // ==========================================================
                // ACCIÓN: Configurar Precio Autorizado (solo Consumidor Final)
                // ==========================================================
                Tables\Actions\Action::make('configurar_precio_autorizado')
                    ->label('Configurar Precio Autorizado')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn () => $this->getOwnerRecord()->esConsumidorFinal()
                        && auth()->user()?->can('update_cliente::precio')
                    )
                    ->form([
                        Forms\Components\Select::make('producto_id')
                            ->label('Producto')
                            ->options(fn () => Producto::where('activo', true)->orderBy('nombre')->pluck('nombre', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $producto = Producto::find($state);
                                    if ($producto && $producto->precio_venta_maximo > 0) {
                                        $set('info_precio_default', 'Precio default actual: L '.number_format($producto->precio_venta_maximo, 2));
                                    } else {
                                        $set('info_precio_default', 'Producto sin precio_venta_maximo configurado — debe configurarse antes de vender a Consumidor Final.');
                                    }
                                }
                            }),

                        Forms\Components\Placeholder::make('info_precio_default')
                            ->label('')
                            ->content(fn (Forms\Get $get) => $get('info_precio_default') ?? '')
                            ->visible(fn (Forms\Get $get) => ! empty($get('info_precio_default'))),

                        Forms\Components\TextInput::make('precio_autorizado')
                            ->label('Precio Autorizado (sin ISV)')
                            ->prefix('L')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.0001)
                            ->helperText('Este valor reemplazará el precio_venta_maximo del producto SOLO para Consumidor Final.'),
                    ])
                    ->action(function (array $data) {
                        ClienteProducto::updateOrCreate(
                            [
                                'cliente_id' => $this->getOwnerRecord()->id,
                                'producto_id' => $data['producto_id'],
                            ],
                            [
                                'precio_autorizado' => $data['precio_autorizado'],
                            ]
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Precio autorizado configurado')
                            ->body('Las próximas ventas a Consumidor Final usarán L '.number_format($data['precio_autorizado'], 2).' para este producto.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('agregar_descuento_especial')
                    ->label('Agregar Descuento Especial')
                    ->icon('heroicon-o-tag')
                    ->color('warning')
                    // Ocultar para Consumidor Final: ese cliente usa precio_autorizado.
                    ->visible(fn () => ! $this->getOwnerRecord()->esConsumidorFinal())
                    ->form([
                        Forms\Components\Select::make('producto_id')
                            ->label('Producto')
                            ->options(function () {
                                return Producto::where('activo', true)
                                    ->orderBy('nombre')
                                    ->pluck('nombre', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $producto = Producto::find($state);
                                    $cliente = $this->getOwnerRecord();

                                    if ($producto && $producto->precio_venta_maximo > 0) {
                                        $set('info_precio', 'Precio de venta: L '.number_format($producto->precio_venta_maximo, 2));

                                        // Mostrar regla por tipo si existe
                                        $regla = ProductoPrecioTipo::where('producto_id', $state)
                                            ->where('tipo_cliente', $cliente->tipo)
                                            ->where('activo', true)
                                            ->first();

                                        if ($regla) {
                                            $set('info_regla', 'Regla por tipo ('.ucfirst($cliente->tipo).'): Descuento máx. L '.number_format($regla->descuento_maximo, 2));
                                        } else {
                                            $set('info_regla', 'Sin regla por tipo configurada para '.ucfirst($cliente->tipo));
                                        }
                                    } else {
                                        $set('info_precio', 'Sin precio de venta configurado');
                                        $set('info_regla', '');
                                    }
                                }
                            }),

                        Forms\Components\Placeholder::make('info_precio')
                            ->label('')
                            ->content(fn (Forms\Get $get) => $get('info_precio') ?? '')
                            ->visible(fn (Forms\Get $get) => ! empty($get('info_precio'))),

                        Forms\Components\Placeholder::make('info_regla')
                            ->label('')
                            ->content(fn (Forms\Get $get) => $get('info_regla') ?? '')
                            ->visible(fn (Forms\Get $get) => ! empty($get('info_regla'))),

                        Forms\Components\TextInput::make('descuento_maximo_override')
                            ->label('Descuento Máximo Especial (L)')
                            ->prefix('L')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(999999)
                            ->step(0.0001)
                            ->helperText('Este valor sobreescribe la regla general por tipo de cliente'),
                    ])
                    ->action(function (array $data) {
                        $clienteId = $this->getOwnerRecord()->id;
                        $productoId = $data['producto_id'];
                        $descuento = $data['descuento_maximo_override'];

                        // Buscar si ya existe el registro en cliente_producto
                        $registro = ClienteProducto::where('cliente_id', $clienteId)
                            ->where('producto_id', $productoId)
                            ->first();

                        if ($registro) {
                            $registro->update(['descuento_maximo_override' => $descuento]);
                        } else {
                            ClienteProducto::create([
                                'cliente_id' => $clienteId,
                                'producto_id' => $productoId,
                                'descuento_maximo_override' => $descuento,
                                'total_ventas' => 0,
                                'cantidad_total_vendida' => 0,
                            ]);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Descuento especial configurado')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                // ==========================================================
                // ACCIÓN: Editar Precio Autorizado (solo Consumidor Final)
                // ==========================================================
                Tables\Actions\Action::make('editar_precio_autorizado')
                    ->label('Precio Autorizado')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->size('sm')
                    ->visible(fn () => $this->getOwnerRecord()->esConsumidorFinal()
                        && auth()->user()?->can('update_cliente::precio')
                    )
                    ->form([
                        Forms\Components\Placeholder::make('info_producto')
                            ->label('Producto')
                            ->content(fn ($record) => $record->producto->nombre ?? 'N/A'),

                        Forms\Components\Placeholder::make('info_precio_default')
                            ->label('Precio default (sin autorización)')
                            ->content(function ($record) {
                                $producto = $record->producto;
                                if ($producto && $producto->precio_venta_maximo > 0) {
                                    return 'L '.number_format($producto->precio_venta_maximo, 2);
                                }

                                return 'Sin precio_venta_maximo configurado';
                            }),

                        Forms\Components\TextInput::make('precio_autorizado')
                            ->label('Precio Autorizado (sin ISV)')
                            ->prefix('L')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->default(fn ($record) => $record->precio_autorizado)
                            ->helperText('Dejar vacío o 0 para volver a usar el precio_venta_maximo del producto.'),
                    ])
                    ->action(function ($record, array $data) {
                        $precio = $data['precio_autorizado'] ?? null;

                        if (empty($precio) || $precio <= 0) {
                            $record->update(['precio_autorizado' => null]);

                            \Filament\Notifications\Notification::make()
                                ->title('Precio autorizado removido')
                                ->body('Se volverá a usar el precio_venta_maximo del producto.')
                                ->success()
                                ->send();
                        } else {
                            $record->update(['precio_autorizado' => $precio]);

                            \Filament\Notifications\Notification::make()
                                ->title('Precio autorizado actualizado')
                                ->body('Nuevo precio para Consumidor Final: L '.number_format($precio, 2))
                                ->success()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('editar_descuento')
                    ->label('Descuento')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->size('sm')
                    // Ocultar para Consumidor Final: ese cliente usa precio_autorizado,
                    // no reglas de descuento variable. Mantener limpia la UI.
                    ->visible(fn () => ! $this->getOwnerRecord()->esConsumidorFinal())
                    ->form([
                        Forms\Components\Placeholder::make('info_producto')
                            ->label('Producto')
                            ->content(fn ($record) => $record->producto->nombre ?? 'N/A'),

                        Forms\Components\Placeholder::make('info_precio_venta')
                            ->label('Precio de Venta')
                            ->content(function ($record) {
                                $producto = $record->producto;
                                if ($producto && $producto->precio_venta_maximo > 0) {
                                    return 'L '.number_format($producto->precio_venta_maximo, 2);
                                }

                                return 'Sin precio configurado';
                            }),

                        Forms\Components\Placeholder::make('info_regla_tipo')
                            ->label('Regla por Tipo')
                            ->content(function ($record) {
                                $cliente = $this->getOwnerRecord();
                                $regla = ProductoPrecioTipo::where('producto_id', $record->producto_id)
                                    ->where('tipo_cliente', $cliente->tipo)
                                    ->where('activo', true)
                                    ->first();

                                if ($regla) {
                                    return 'Descuento máx. L '.number_format($regla->descuento_maximo, 2).' (tipo: '.ucfirst($cliente->tipo).')';
                                }

                                return 'Sin regla para tipo '.ucfirst($cliente->tipo);
                            }),

                        Forms\Components\TextInput::make('descuento_maximo_override')
                            ->label('Descuento Máximo Especial (L)')
                            ->prefix('L')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(999999)
                            ->step(0.0001)
                            ->default(fn ($record) => $record->descuento_maximo_override)
                            ->helperText('Dejar vacío para usar la regla general por tipo. Poner un valor para sobreescribir.'),
                    ])
                    ->action(function ($record, array $data) {
                        $override = $data['descuento_maximo_override'];

                        // Si está vacío o 0, quitar el override
                        if (empty($override) || $override == 0) {
                            $record->update(['descuento_maximo_override' => null]);

                            \Filament\Notifications\Notification::make()
                                ->title('Descuento especial removido')
                                ->body('Se usará la regla general por tipo de cliente')
                                ->success()
                                ->send();
                        } else {
                            $record->update(['descuento_maximo_override' => $override]);

                            \Filament\Notifications\Notification::make()
                                ->title('Descuento especial actualizado')
                                ->body('Descuento máximo: L '.number_format($override, 2))
                                ->success()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('quitar_override')
                    ->label('Quitar Especial')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->size('sm')
                    ->visible(fn ($record) => $record->descuento_maximo_override !== null
                        && ! $this->getOwnerRecord()->esConsumidorFinal()
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Quitar Descuento Especial')
                    ->modalDescription('Se eliminará el descuento especial y se usará la regla general por tipo de cliente.')
                    ->action(function ($record) {
                        $record->update(['descuento_maximo_override' => null]);

                        \Filament\Notifications\Notification::make()
                            ->title('Descuento especial removido')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('ver_producto')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->size('sm')
                    ->url(fn ($record) => route('filament.admin.resources.productos.edit', ['record' => $record->producto_id])),
            ])
            ->defaultSort('fecha_ultima_venta', 'desc')
            ->emptyStateHeading('Sin historial de precios')
            ->emptyStateDescription('Cuando se realicen ventas a este cliente, aquí aparecerá el historial de precios por producto. También puedes agregar descuentos especiales antes de la primera venta.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}
