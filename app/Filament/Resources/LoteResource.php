<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoteResource\Pages;
use App\Models\Lote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class LoteResource extends Resource
{
    protected static ?string $model = Lote::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 3;

    protected static ?string $pluralModelLabel = 'Lotes';

    protected static ?string $modelLabel = 'Lote';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        if ($esSuperAdminOJefe) {
            return $query;
        }

        $bodegasUsuario = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->where('activo', true)
            ->pluck('bodega_id')
            ->toArray();

        return $query->whereIn('bodega_id', $bodegasUsuario);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Lote')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('numero_lote')
                                    ->label('No. Lote')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Select::make('producto_id')
                                    ->label('Producto')
                                    ->relationship('producto', 'nombre')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Select::make('bodega_id')
                                    ->label('Bodega')
                                    ->relationship('bodega', 'nombre')
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\Select::make('proveedor_id')
                                    ->label('Último Proveedor')
                                    ->relationship('proveedor', 'nombre')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder('Varios proveedores'),

                                Forms\Components\Select::make('estado')
                                    ->label('Estado')
                                    ->options([
                                        'disponible' => 'Disponible',
                                        'agotado' => 'Agotado',
                                    ])
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                    ]),

                Forms\Components\Section::make('Cantidades')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('cantidad_huevos_remanente')
                                    ->label('Huevos Disponibles')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('huevos'),

                                Forms\Components\TextInput::make('huevos_facturados_acumulados')
                                    ->label('Facturados Acumulados')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('huevos')
                                    ->helperText('Total comprado (pagado)'),

                                Forms\Components\TextInput::make('huevos_regalo_acumulados')
                                    ->label('Regalos Acumulados')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('huevos')
                                    ->helperText('Buffer para mermas'),

                                Forms\Components\Placeholder::make('buffer_disponible')
                                    ->label('Buffer Disponible')
                                    ->content(function ($record) {
                                        if (!$record) return '0 huevos';
                                        $buffer = $record->getBufferRegaloDisponible();
                                        $color = $buffer > 0 ? 'text-green-600' : 'text-red-600';
                                        return new \Illuminate\Support\HtmlString(
                                            "<span class='{$color} font-bold'>" . number_format($buffer, 0) . " huevos</span>"
                                        );
                                    }),
                            ]),
                    ]),

                Forms\Components\Section::make('Costos')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('costo_total_acumulado')
                                    ->label('Costo Total Acumulado')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->prefix('L')
                                    ->helperText('Suma de todas las compras'),

                                Forms\Components\TextInput::make('costo_por_huevo')
                                    ->label('Costo por Huevo')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->prefix('L')
                                    ->helperText('Costo promedio ponderado'),

                                Forms\Components\TextInput::make('costo_por_carton_facturado')
                                    ->label('Costo por Cartón')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->prefix('L'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_lote')
                    ->label('No. Lote')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->icon(function ($record) {
                        if ($record->esLoteUnico()) return 'heroicon-o-cube';
                        if ($record->esLoteSueltos()) return 'heroicon-o-arrow-path';
                        return 'heroicon-o-archive-box';
                    })
                    ->description(function ($record) {
                        if ($record->esLoteUnico()) return 'Lote Único';
                        if ($record->esLoteSueltos()) return 'Lote Sueltos';
                        return null;
                    }),

                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('proveedor.nombre')
                    ->label('Últ. Proveedor')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->placeholder('Varios'),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cantidad_huevos_remanente')
                    ->label('Huevos Disponibles')
                    ->numeric(decimalPlaces: 0)
                    ->sortable()
                    ->color(fn($state) => $state > 0 ? 'success' : 'gray')
                    ->weight(fn($state) => $state > 0 ? 'bold' : 'normal')
                    ->description(function ($record) {
                        $c30 = floor($record->cantidad_huevos_remanente / 30);
                        $resto = $record->cantidad_huevos_remanente % 30;
                        return "{$c30} cartones + {$resto} sueltos";
                    }),

                Tables\Columns\TextColumn::make('buffer_regalo')
                    ->label('Buffer 🎁')
                    ->getStateUsing(fn($record) => $record->getBufferRegaloDisponible())
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' huevos')
                    ->color(fn($state) => $state > 0 ? 'success' : 'warning')
                    ->description(fn($record) => $record->getBufferRegaloDisponible() > 0 ? 'Disponible' : 'Agotado')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('merma_total_acumulada')
                    ->label('Mermas')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' huevos')
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('costo_por_huevo')
                    ->label('Costo/Huevo')
                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                    ->sortable()
                    ->description('Promedio ponderado'),

                Tables\Columns\TextColumn::make('costo_por_carton_facturado')
                    ->label('Costo/Cartón')
                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('historial_compras_count')
                    ->label('Compras')
                    ->counts('historialCompras')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state === 'disponible' ? 'Disponible' : 'Agotado')
                    ->color(fn($state) => $state === 'disponible' ? 'success' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'disponible' => 'Disponible',
                        'agotado' => 'Agotado',
                    ])
                    ->default('disponible'),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload()
                    ->visible(function () {
                        $currentUser = Auth::user();
                        if (!$currentUser) return false;

                        return DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                            ->exists();
                    }),

                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('tipo_lote')
                    ->form([
                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de Lote')
                            ->options([
                                'unicos' => 'Lotes Únicos (LU-*)',
                                'sueltos' => 'Lotes de Sueltos (SUELTOS-*)',
                                'tradicionales' => 'Lotes Tradicionales (L-*)',
                            ])
                            ->placeholder('Todos'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (!isset($data['tipo']) || empty($data['tipo'])) {
                            return $query;
                        }

                        return match ($data['tipo']) {
                            'unicos' => $query->where('numero_lote', 'LIKE', 'LU-%'),
                            'sueltos' => $query->where('numero_lote', 'LIKE', 'SUELTOS-%'),
                            'tradicionales' => $query->where('numero_lote', 'LIKE', 'L-%')
                                ->where('numero_lote', 'NOT LIKE', 'LU-%')
                                ->where('numero_lote', 'NOT LIKE', 'SUELTOS-%'),
                            default => $query,
                        };
                    }),

                Tables\Filters\Filter::make('con_remanente')
                    ->label('Con Huevos Disponibles')
                    ->query(fn($query) => $query->where('cantidad_huevos_remanente', '>', 0))
                    ->default(),

                Tables\Filters\Filter::make('con_buffer')
                    ->label('Con Buffer de Regalo')
                    ->query(function ($query) {
                        return $query->whereRaw('huevos_regalo_acumulados > merma_total_acumulada');
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // 🎯 ACCIÓN PRINCIPAL: REGISTRAR MERMA
                Tables\Actions\Action::make('registrar_merma')
                    ->label('Registrar Merma')
                    ->icon('heroicon-o-minus-circle')
                    ->color('danger')
                    ->form([
                        Forms\Components\Placeholder::make('info_lote')
                            ->label('')
                            ->content(function ($record) {
                                $buffer = $record->getBufferRegaloDisponible();
                                $bufferColor = $buffer > 0 ? 'green' : 'red';
                                
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-gray-50 dark:bg-gray-900 p-4 space-y-2'>
                                        <div class='grid grid-cols-2 gap-4'>
                                            <div>
                                                <p class='text-sm text-gray-500'>Huevos Disponibles</p>
                                                <p class='text-xl font-bold text-blue-600'>" . number_format($record->cantidad_huevos_remanente, 0) . "</p>
                                            </div>
                                            <div>
                                                <p class='text-sm text-gray-500'>Buffer de Regalos 🎁</p>
                                                <p class='text-xl font-bold text-{$bufferColor}-600'>" . number_format($buffer, 0) . "</p>
                                            </div>
                                        </div>
                                        <div class='border-t pt-2 mt-2'>
                                            <p class='text-sm text-gray-500'>Costo por Huevo</p>
                                            <p class='text-lg font-bold'>L " . number_format($record->costo_por_huevo, 2) . "</p>
                                        </div>
                                    </div>
                                ");
                            }),

                        Forms\Components\TextInput::make('cantidad_huevos')
                            ->label('Cantidad de Huevos Dañados')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(fn($record) => $record->cantidad_huevos_remanente)
                            ->suffix('huevos')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set, $record) {
                                $cantidad = (int) ($state ?? 0);
                                $buffer = $record->getBufferRegaloDisponible();
                                $costoPorHuevo = $record->costo_por_huevo ?? 0;

                                $cubierto = min($cantidad, $buffer);
                                $perdidaHuevos = max(0, $cantidad - $buffer);
                                $perdidaLempiras = $perdidaHuevos * $costoPorHuevo;

                                $set('_cubierto_por_regalo', $cubierto);
                                $set('_perdida_huevos', $perdidaHuevos);
                                $set('_perdida_lempiras', $perdidaLempiras);
                            })
                            ->helperText(fn($record) => 'Máximo: ' . number_format($record->cantidad_huevos_remanente, 0) . ' huevos'),

                        Forms\Components\Select::make('motivo')
                            ->label('Motivo')
                            ->required()
                            ->options([
                                'rotos' => '🥚 Rotos',
                                'podridos' => '🤢 Podridos',
                                'vencidos' => '📅 Vencidos',
                                'dañados_transporte' => '🚚 Dañados en Transporte',
                                'otros' => '❓ Otros',
                            ])
                            ->native(false)
                            ->default('rotos'),

                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripción (opcional)')
                            ->placeholder('Detalles adicionales sobre la merma...')
                            ->rows(2),

                        // Campos ocultos para mostrar cálculo
                        Forms\Components\Hidden::make('_cubierto_por_regalo')->dehydrated(false),
                        Forms\Components\Hidden::make('_perdida_huevos')->dehydrated(false),
                        Forms\Components\Hidden::make('_perdida_lempiras')->dehydrated(false),

                        // Resumen del impacto
                        Forms\Components\Placeholder::make('resumen_impacto')
                            ->label('Impacto de esta merma')
                            ->content(function (Forms\Get $get, $record) {
                                $cantidad = (int) ($get('cantidad_huevos') ?? 0);

                                if ($cantidad <= 0) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='text-gray-500 text-sm'>
                                            Ingresa la cantidad de huevos para ver el impacto
                                        </div>
                                    ");
                                }

                                $buffer = $record->getBufferRegaloDisponible();
                                $costoPorHuevo = $record->costo_por_huevo ?? 0;

                                $cubierto = min($cantidad, $buffer);
                                $perdidaHuevos = max(0, $cantidad - $buffer);
                                $perdidaLempiras = $perdidaHuevos * $costoPorHuevo;

                                if ($perdidaHuevos == 0) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-4'>
                                            <p class='text-green-800 dark:text-green-200 font-bold'>
                                                ✅ Sin pérdida económica
                                            </p>
                                            <p class='text-sm text-green-700 dark:text-green-300 mt-1'>
                                                Los {$cantidad} huevos serán cubiertos por el buffer de regalos.<br>
                                                Buffer restante después: " . number_format($buffer - $cubierto, 0) . " huevos
                                            </p>
                                        </div>
                                    ");
                                } else {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-4'>
                                            <p class='text-red-800 dark:text-red-200 font-bold'>
                                                ⚠️ Pérdida económica
                                            </p>
                                            <div class='text-sm text-red-700 dark:text-red-300 mt-2 space-y-1'>
                                                <p>• Cubierto por buffer: <strong>{$cubierto} huevos</strong></p>
                                                <p>• Pérdida real: <strong>{$perdidaHuevos} huevos</strong></p>
                                                <p>• Pérdida en dinero: <strong>L " . number_format($perdidaLempiras, 2) . "</strong></p>
                                            </div>
                                            <p class='text-xs text-red-600 dark:text-red-400 mt-2'>
                                                El costo por huevo aumentará porque perdiste huevos pagados.
                                            </p>
                                        </div>
                                    ");
                                }
                            }),
                    ])
                    ->action(function ($record, array $data) {
                        $merma = $record->registrarMerma(
                            (float) $data['cantidad_huevos'],
                            $data['motivo'],
                            $data['descripcion'] ?? null,
                            Auth::id()
                        );

                        $mensaje = "Merma #{$merma->numero_merma} registrada. ";

                        if ($merma->tuvoPerdidaEconomica()) {
                            $mensaje .= "Pérdida: L " . number_format($merma->perdida_real_lempiras, 2);
                        } else {
                            $mensaje .= "Sin pérdida económica (cubierto por buffer).";
                        }

                        Notification::make()
                            ->title('Merma registrada')
                            ->body($mensaje)
                            ->color($merma->tuvoPerdidaEconomica() ? 'warning' : 'success')
                            ->duration(5000)
                            ->send();
                    })
                    ->visible(fn($record) => $record->estado === 'disponible' && $record->cantidad_huevos_remanente > 0)
                    ->modalWidth('lg'),

                // Ver historial de compras
                Tables\Actions\Action::make('ver_historial')
                    ->label('Historial')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->url(fn($record) => route('filament.admin.resources.lotes.view', ['record' => $record]))
                    ->visible(fn($record) => $record->esLoteUnico()),

                Tables\Actions\Action::make('reempacar')
                    ->label('Reempacar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->url(fn() => route('filament.admin.resources.reempaques.create'))
                    ->visible(fn($record) => $record->estado === 'disponible' && $record->cantidad_huevos_remanente > 0),
            ])
            ->bulkActions([
                // Sin acciones bulk
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLotes::route('/'),
            'view' => Pages\ViewLote::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return null;
        }

        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        $query = static::getModel()::where('estado', 'disponible')
            ->where('cantidad_huevos_remanente', '>', 0);

        if (!$esSuperAdminOJefe) {
            $bodegasUsuario = DB::table('bodega_user')
                ->where('user_id', $currentUser->id)
                ->where('activo', true)
                ->pluck('bodega_id')
                ->toArray();

            if (!empty($bodegasUsuario)) {
                $query->whereIn('bodega_id', $bodegasUsuario);
            }
        }

        $disponibles = $query->count();

        return $disponibles > 0 ? (string) $disponibles : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}