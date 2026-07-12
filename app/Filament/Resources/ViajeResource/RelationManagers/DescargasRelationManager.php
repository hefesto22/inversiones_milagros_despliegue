<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\Lote;
use App\Models\Producto;
use App\Models\ViajeCarga;
use App\Models\ViajeDescarga;
use App\Models\BodegaProducto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DescargasRelationManager extends RelationManager
{
    protected static string $relationship = 'descargas';

    protected static ?string $title = 'Descarga / Devolución';

    protected static ?string $modelLabel = 'Descarga';

    protected static ?string $pluralModelLabel = 'Descargas';

    protected static ?string $icon = 'heroicon-o-archive-box-arrow-down';

    public function isReadOnly(): bool
    {
        return in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Producto a Descargar')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('producto_id')
                                    ->label('Producto')
                                    ->options(function () {
                                        return $this->getOwnerRecord()->cargas()
                                            ->with('producto')
                                            ->get()
                                            ->mapWithKeys(function ($carga) {
                                                $disponible = $carga->getCantidadDisponible();
                                                return [
                                                    $carga->producto_id => $carga->producto->nombre . " (Disp: {$disponible})"
                                                ];
                                            });
                                    })
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if ($state) {
                                            $carga = $this->getOwnerRecord()->cargas()
                                                ->where('producto_id', $state)
                                                ->first();

                                            $producto = Producto::find($state);

                                            if ($carga) {
                                                $set('unidad_id', $carga->unidad_id);
                                                $set('costo_unitario', $carga->costo_unitario);
                                                $set('cantidad', $carga->getCantidadDisponible());

                                                // Verificar si tiene unidades por bulto (para detectar fracciones)
                                                $unidadesPorBulto = $producto->unidades_por_bulto ?? null;
                                                $set('unidades_por_bulto', $unidadesPorBulto);
                                                $set('tiene_subunidades', !empty($unidadesPorBulto) && $unidadesPorBulto > 1);

                                                // Calcular subtotal
                                                $cantidad = $carga->getCantidadDisponible();
                                                $set('subtotal_costo', round($cantidad * $carga->costo_unitario, 2));
                                            }
                                        }
                                    }),

                                Forms\Components\Select::make('unidad_id')
                                    ->label('Unidad')
                                    ->relationship('unidad', 'nombre')
                                    ->required()
                                    ->disabled()
                                    ->dehydrated(),

                                Forms\Components\TextInput::make('cantidad')
                                    ->label('Cantidad a Devolver')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->live(debounce: 300)
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        $costo = $get('costo_unitario') ?? 0;
                                        $set('subtotal_costo', round($state * $costo, 2));

                                        // Mostrar información de fracción si aplica
                                        $unidadesPorBulto = $get('unidades_por_bulto');
                                        if ($unidadesPorBulto && $unidadesPorBulto > 1) {
                                            $parteEntera = floor($state);
                                            $fraccion = $state - $parteEntera;

                                            if ($fraccion > 0) {
                                                $huevosSueltos = round($fraccion * $unidadesPorBulto);
                                                $set('info_fraccion', "⚠️ {$parteEntera} cartones completos + {$huevosSueltos} unidades sueltas (irán al lote de sueltos)");
                                            } else {
                                                $set('info_fraccion', "✅ {$parteEntera} cartones completos");
                                            }
                                        }
                                    }),

                                Forms\Components\TextInput::make('costo_unitario')
                                    ->label('Costo Unitario')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(),

                                // Placeholder para mostrar información de fracción
                                Forms\Components\Placeholder::make('info_fraccion')
                                    ->label('Distribución')
                                    ->content(fn(Forms\Get $get) => $get('info_fraccion') ?? '-')
                                    ->visible(fn(Forms\Get $get) => $get('tiene_subunidades') && $get('cantidad'))
                                    ->columnSpanFull(),

                                Forms\Components\Select::make('estado_producto')
                                    ->label('Estado del Producto')
                                    ->required()
                                    ->options([
                                        'bueno' => 'Bueno (reingresa a inventario)',
                                        'danado' => 'Dañado',
                                        'vencido' => 'Vencido',
                                    ])
                                    ->default('bueno')
                                    ->live()
                                    ->native(false),

                                Forms\Components\TextInput::make('subtotal_costo')
                                    ->label('Costo Total')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated(),
                            ]),

                        Forms\Components\Toggle::make('reingresa_stock')
                            ->label('¿Reingresa al inventario?')
                            ->default(true)
                            ->helperText('Si está en buen estado, el producto vuelve a la bodega. Las fracciones irán al lote de sueltos.')
                            ->live(),

                        Forms\Components\Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // Campos ocultos para procesamiento
                Forms\Components\Hidden::make('unidades_por_bulto'),
                Forms\Components\Hidden::make('tiene_subunidades'),

                Forms\Components\Section::make('Cobro al Chofer')
                    ->schema([
                        Forms\Components\Toggle::make('cobrar_chofer')
                            ->label('¿Cobrar al chofer?')
                            ->default(false)
                            ->live()
                            ->helperText('Marcar si esta devolución se descontará del chofer'),

                        Forms\Components\TextInput::make('monto_cobrar')
                            ->label('Monto a Cobrar')
                            ->numeric()
                            ->prefix('L')
                            ->visible(fn(Forms\Get $get) => $get('cobrar_chofer'))
                            ->helperText('Por defecto es el costo total'),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
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
                    ->formatStateUsing(function ($state, $record) {
                        $producto = $record->producto;
                        $unidadesPorBulto = $producto?->unidades_por_bulto;

                        // Si es fracción, mostrar detalle
                        if ($unidadesPorBulto && $unidadesPorBulto > 1) {
                            $parteEntera = floor($state);
                            $fraccion = $state - $parteEntera;

                            if ($fraccion > 0.0001) {
                                $unidadesSueltas = round($fraccion * $unidadesPorBulto);
                                return number_format($state, 4) . " ({$unidadesSueltas} sueltos)";
                            }
                        }

                        return number_format($state, 2);
                    })
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('estado_producto')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        'bueno' => 'Bueno',
                        'danado' => 'Dañado',
                        'vencido' => 'Vencido',
                        default => $state,
                    })
                    ->color(fn($state) => match ($state) {
                        'bueno' => 'success',
                        'danado' => 'warning',
                        'vencido' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('reingresa_stock')
                    ->label('Reingresa')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Costo')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\IconColumn::make('cobrar_chofer')
                    ->label('Cobrar')
                    ->boolean()
                    ->trueIcon('heroicon-o-currency-dollar')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado_producto')
                    ->label('Estado')
                    ->options([
                        'bueno' => 'Bueno',
                        'danado' => 'Dañado',
                        'vencido' => 'Vencido',
                    ]),

                Tables\Filters\TernaryFilter::make('reingresa_stock')
                    ->label('Reingresa Stock'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Descarga Manual')
                    ->icon('heroicon-o-plus')
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, ['regresando', 'descargando', 'liquidando']))
                    ->mutateFormDataUsing(function (array $data): array {
                        // Limpiar campos temporales
                        unset($data['info_fraccion']);
                        unset($data['tiene_subunidades']);
                        unset($data['unidades_por_bulto']);

                        if ($data['cobrar_chofer'] && empty($data['monto_cobrar'])) {
                            $data['monto_cobrar'] = $data['subtotal_costo'];
                        }
                        return $data;
                    })
                    ->before(function (array $data) {
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $data['producto_id'])
                            ->first();

                        if (!$carga) {
                            throw new \Exception('Este producto no está cargado en el viaje');
                        }

                        $disponible = $carga->getCantidadDisponible();
                        if ($data['cantidad'] > $disponible) {
                            Notification::make()
                                ->title('Cantidad excede disponible')
                                ->body("Solo hay {$disponible} unidades disponibles para descargar")
                                ->danger()
                                ->send();
                            throw new \Exception('Cantidad excede disponible');
                        }
                    })
                    ->after(function ($record) {
                        // Actualizar cantidad_devuelta en la carga
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($carga) {
                            $totalDevuelto = ViajeDescarga::where('viaje_id', $record->viaje_id)
                                ->where('producto_id', $record->producto_id)
                                ->sum('cantidad');

                            $carga->cantidad_devuelta = $totalDevuelto;
                            $carga->save();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\EditAction::make()
                    ->visible(fn() => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),

                Tables\Actions\Action::make('procesar_reingreso')
                    ->label('Reingresar a Bodega')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn($record) => $record->reingresa_stock
                        && $record->estado_producto === 'bueno'
                        && !$record->procesado_reingreso
                        && !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->requiresConfirmation()
                    ->modalHeading('Reingresar Producto a Bodega')
                    ->modalDescription(function ($record) {
                        $producto = $record->producto;
                        $unidadesPorBulto = $producto?->unidades_por_bulto;

                        if ($unidadesPorBulto && $unidadesPorBulto > 1) {
                            $parteEntera = floor($record->cantidad);
                            $fraccion = $record->cantidad - $parteEntera;

                            if ($fraccion > 0.0001) {
                                $huevosSueltos = round($fraccion * $unidadesPorBulto);
                                return "Se reingresarán {$parteEntera} cartones completos al stock y {$huevosSueltos} unidades sueltas al lote único del producto. ¿Confirma?";
                            }
                        }

                        return '¿Confirma el reingreso de este producto al inventario de bodega?';
                    })
                    ->action(function ($record) {
                        $this->procesarReingreso($record);
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn() => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado']))
                    ->after(function ($record) {
                        // Actualizar cantidad_devuelta en la carga
                        $carga = $this->getOwnerRecord()->cargas()
                            ->where('producto_id', $record->producto_id)
                            ->first();

                        if ($carga) {
                            $totalDevuelto = ViajeDescarga::where('viaje_id', $record->viaje_id)
                                ->where('producto_id', $record->producto_id)
                                ->sum('cantidad');

                            $carga->cantidad_devuelta = $totalDevuelto;
                            $carga->save();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn() => !in_array($this->getOwnerRecord()->estado, ['cerrado', 'cancelado'])),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin descargas')
            ->emptyStateDescription('Las descargas se generan automáticamente al presionar "Descargar" en el viaje, o puede agregar manualmente.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }

    /**
     * Procesar el reingreso de producto a bodega
     * - Cartones completos → BodegaProducto (stock)
     * - Unidades sueltas → Lote SUELTOS
     */
    protected function procesarReingreso($record): void
    {
        $viaje = $this->getOwnerRecord();
        $producto = $record->producto;
        $bodegaId = $viaje->bodega_origen_id;
        $unidadesPorBulto = $producto->unidades_por_bulto ?? null;

        DB::transaction(function () use ($record, $producto, $bodegaId, $unidadesPorBulto) {
            $cantidad = $record->cantidad;
            $cartonesCompletos = floor($cantidad);
            $fraccion = $cantidad - $cartonesCompletos;

            $mensajes = [];

            // 1. Reingresar cartones completos al stock de BodegaProducto
            if ($cartonesCompletos > 0) {
                $bodegaProducto = BodegaProducto::firstOrCreate(
                    [
                        'bodega_id' => $bodegaId,
                        'producto_id' => $record->producto_id,
                    ],
                    [
                        'stock' => 0,
                        'costo_promedio_actual' => 0,
                        'activo' => true,
                    ]
                );

                // FIX: Usar costo_bodega_original de la carga para promedio ponderado correcto
                $viaje = $this->getOwnerRecord();
                $carga = $viaje->cargas()->where('producto_id', $record->producto_id)->first();
                $costoParaReintegro = $carga
                    ? floatval($carga->costo_bodega_original ?? $carga->costo_unitario ?? $record->costo_unitario)
                    : $record->costo_unitario;

                $bodegaProducto->actualizarCostoPromedio($cartonesCompletos, $costoParaReintegro, [
                    'kardex_tipo'            => 'retorno_viaje',
                    'kardex_descripcion'     => "Retorno de viaje #{$viaje->id}",
                    'kardex_referencia_type' => $viaje->getMorphClass(),
                    'kardex_referencia_id'   => $viaje->id,
                ]);
                $mensajes[] = "{$cartonesCompletos} cartones al stock";
            }

            // 2. Si hay fracción y el producto tiene unidades por bulto, enviar al lote único
            if ($fraccion > 0.0001 && $unidadesPorBulto && $unidadesPorBulto > 1) {
                $huevosSueltos = round($fraccion * $unidadesPorBulto);

                if ($huevosSueltos > 0) {
                    $this->agregarALoteSueltos(
                        bodegaId: $bodegaId,
                        productoId: $record->producto_id,
                        cantidadHuevos: $huevosSueltos,
                        unidadesPorBulto: $unidadesPorBulto
                    );

                    $mensajes[] = "{$huevosSueltos} unidades al lote único";
                }
            } elseif ($fraccion > 0.0001) {
                // Producto sin subunidades, agregar fracción al stock normal
                $bodegaProducto = BodegaProducto::firstOrCreate(
                    [
                        'bodega_id' => $bodegaId,
                        'producto_id' => $record->producto_id,
                    ],
                    [
                        'stock' => 0,
                        'costo_promedio_actual' => 0,
                        'activo' => true,
                    ]
                );

                // FIX: Usar costo_bodega_original de la carga para promedio ponderado correcto
                $viaje = $this->getOwnerRecord();
                $carga = $viaje->cargas()->where('producto_id', $record->producto_id)->first();
                $costoParaReintegro = $carga
                    ? floatval($carga->costo_bodega_original ?? $carga->costo_unitario ?? $record->costo_unitario)
                    : $record->costo_unitario;

                $bodegaProducto->actualizarCostoPromedio($fraccion, $costoParaReintegro, [
                    'kardex_tipo'            => 'retorno_viaje',
                    'kardex_descripcion'     => "Retorno de viaje #{$viaje->id}",
                    'kardex_referencia_type' => $viaje->getMorphClass(),
                    'kardex_referencia_id'   => $viaje->id,
                ]);
                $mensajes[] = number_format($fraccion, 4) . " unidades al stock";
            }

            // Marcar como procesado (si tienes este campo)
            if (method_exists($record, 'setAttribute')) {
                $record->procesado_reingreso = true;
                $record->save();
            }

            Notification::make()
                ->title('Stock reingresado')
                ->body("Se agregaron: " . implode(', ', $mensajes))
                ->success()
                ->send();
        });
    }

    /**
     * Agregar huevos sueltos al LOTE ÚNICO del producto en la bodega.
     *
     * Refactor 2026-05-18: migrado del patrón obsoleto "SUELTOS-{codigoBodega}"
     * al patrón oficial "Lote Único" (LU-B*-P*). El método anterior chocaba
     * contra la constraint unique(producto_id, bodega_id, estado) cada vez
     * que ya existía un lote único disponible para esa combinación.
     *
     * Delega en los helpers oficiales de Lote (los mismos que usan compras,
     * reempaques y ventas) para mantener consistencia con el motor WAC Perpetuo.
     */
    protected function agregarALoteSueltos(
        int $bodegaId,
        int $productoId,
        int $cantidadHuevos,
        int $unidadesPorBulto
    ): void {
        $lote = Lote::obtenerOCrearLoteUnico(
            productoId: $productoId,
            bodegaId: $bodegaId,
            huevosPorCarton: $unidadesPorBulto,
            createdBy: Auth::id(),
        );

        $viaje = $this->getOwnerRecord();

        $lote->devolverHuevos(
            cantidadHuevos: (float) $cantidadHuevos,
            huevosRegaloDevueltos: 0.0,
            contexto: [
                'kardex_tipo'            => 'retorno_viaje',
                'kardex_descripcion'     => "Retorno de viaje #{$viaje->id} — sueltos al lote único",
                'kardex_referencia_type' => $viaje->getMorphClass(),
                'kardex_referencia_id'   => $viaje->id,
            ],
        );
    }
}
