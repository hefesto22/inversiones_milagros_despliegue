<?php

namespace App\Filament\Resources;

use App\Enums\LoteEstado;
use App\Enums\MermaMotivo;
use App\Filament\Resources\LoteResource\Pages;
use App\Models\Concerns\HasBodegaScope;
use App\Models\Lote;
use App\Models\Merma;
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
    use HasBodegaScope;
    protected static ?string $model = Lote::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 3;

    protected static ?string $pluralModelLabel = 'Lotes';

    protected static ?string $modelLabel = 'Lote';

    public static function getEloquentQuery(): Builder
    {
        return self::scopeQueryPorBodega(parent::getEloquentQuery());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informacion del Lote')
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
                                    ->label('Ultimo Proveedor')
                                    ->relationship('proveedor', 'nombre')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder('Varios proveedores'),

                                Forms\Components\Select::make('estado')
                                    ->label('Estado')
                                    ->options(LoteEstado::options())
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
                                    ->label('Costo por Carton')
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
                        if ($record->esLoteUnico()) return 'Lote Unico';
                        if ($record->esLoteSueltos()) return 'Lote Sueltos';
                        return null;
                    }),

                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('proveedor.nombre')
                    ->label('Ult. Proveedor')
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
                    ->label('Buffer')
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

                // Fase 5: display vía accessor `_efectivo` + sortable con columna SQL dinámica.
                // Mantenemos `make('costo_por_huevo')` como identificador estable y delegamos
                // tanto la lectura visible (getStateUsing) como el ORDER BY (sortable query)
                // al helper central `Lote::columnaSqlCostoPorHuevo()`. Esto permite alternar
                // legacy ↔ wac vía feature flag sin tocar esta vista.
                Tables\Columns\TextColumn::make('costo_por_huevo')
                    ->label('Costo/Huevo')
                    ->getStateUsing(fn($record) => $record->costo_por_huevo_efectivo)
                    ->formatStateUsing(fn($state) => 'L ' . number_format((float) $state, 2))
                    ->sortable(query: fn($query, string $direction) => $query->orderBy(Lote::columnaSqlCostoPorHuevo(), $direction))
                    ->description('Promedio ponderado'),

                Tables\Columns\TextColumn::make('costo_por_carton_facturado')
                    ->label('Costo/Carton')
                    ->getStateUsing(fn($record) => $record->costo_por_carton_facturado_efectivo)
                    ->formatStateUsing(fn($state) => 'L ' . number_format((float) $state, 2))
                    ->sortable(query: fn($query, string $direction) => $query->orderBy(Lote::columnaSqlCostoPorCartonFacturado(), $direction))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('historial_compras_count')
                    ->label('Compras')
                    ->counts('historialCompras')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state instanceof LoteEstado ? $state->label() : (LoteEstado::tryFrom($state)?->label() ?? $state))
                    ->color(fn($state) => $state instanceof LoteEstado ? $state->color() : (LoteEstado::tryFrom($state)?->color() ?? 'gray'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options(LoteEstado::options())
                    ->default(LoteEstado::Disponible->value),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => self::esSuperAdminOJefe()),

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
                                'unicos' => 'Lotes Unicos (LU-*)',
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
                        return $query->whereRaw('huevos_regalo_acumulados > merma_total_acumulada + COALESCE(huevos_regalo_consumidos, 0)');
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // ACCION PRINCIPAL: REGISTRAR MERMA
                Tables\Actions\Action::make('registrar_merma')
                    ->label('Reg. Merma')
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
                                                <p class='text-sm text-gray-500'>Buffer de Regalos</p>
                                                <p class='text-xl font-bold text-{$bufferColor}-600'>" . number_format($buffer, 0) . "</p>
                                            </div>
                                        </div>
                                        <div class='border-t pt-2 mt-2'>
                                            <p class='text-sm text-gray-500'>Costo por Huevo</p>
                                            <p class='text-lg font-bold'>L " . number_format($record->costo_por_huevo_efectivo, 2) . "</p>
                                        </div>
                                    </div>
                                ");
                            }),

                        Forms\Components\TextInput::make('cantidad_huevos')
                            ->label('Cantidad de Huevos Danados')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(fn($record) => $record->cantidad_huevos_remanente)
                            ->suffix('huevos')
                            ->live()
                            ->helperText(fn($record) => 'Maximo: ' . number_format($record->cantidad_huevos_remanente, 0) . ' huevos'),

                        Forms\Components\Select::make('motivo')
                            ->label('Motivo')
                            ->required()
                            ->options([
                                ...MermaMotivo::options(),
                            ])
                            ->native(false)
                            ->default(MermaMotivo::Rotos->value),

                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripcion (opcional)')
                            ->placeholder('Detalles adicionales sobre la merma...')
                            ->rows(2),

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

                                // Fase 5: usar accessor efectivo para respetar inventario.wac.read_source.
                                $costoPorHuevo = $record->costo_por_huevo_efectivo;

                                $cubierto = min($cantidad, $buffer);
                                $perdidaHuevos = max(0, $cantidad - $buffer);
                                $perdidaLempiras = $perdidaHuevos * $costoPorHuevo;

                                if ($perdidaHuevos == 0) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-4'>
                                            <p class='text-green-800 dark:text-green-200 font-bold'>
                                                Sin perdida economica
                                            </p>
                                            <p class='text-sm text-green-700 dark:text-green-300 mt-1'>
                                                Los {$cantidad} huevos seran cubiertos por el buffer de regalos.<br>
                                                Buffer restante despues: " . number_format($buffer - $cubierto, 0) . " huevos
                                            </p>
                                        </div>
                                    ");
                                } else {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-4'>
                                            <p class='text-red-800 dark:text-red-200 font-bold'>
                                                Perdida economica
                                            </p>
                                            <div class='text-sm text-red-700 dark:text-red-300 mt-2 space-y-1'>
                                                <p>Cubierto por buffer: <strong>{$cubierto} huevos</strong></p>
                                                <p>Perdida real: <strong>{$perdidaHuevos} huevos</strong></p>
                                                <p>Perdida en dinero: <strong>L " . number_format($perdidaLempiras, 2) . "</strong></p>
                                            </div>
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
                            $mensaje .= "Perdida: L " . number_format($merma->perdida_real_lempiras, 2);
                        } else {
                            $mensaje .= "Sin perdida economica (cubierto por buffer).";
                        }

                        Notification::make()
                            ->title('Merma registrada')
                            ->body($mensaje)
                            ->color($merma->tuvoPerdidaEconomica() ? 'warning' : 'success')
                            ->duration(5000)
                            ->send();
                    })
                    ->visible(fn($record) => $record->estado === LoteEstado::Disponible && $record->cantidad_huevos_remanente > 0)
                    ->modalWidth('lg'),

                // ACCION: ELIMINAR MERMA
                Tables\Actions\Action::make('eliminar_merma')
                    ->label('Elim. Merma')
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->form([
                        Forms\Components\Placeholder::make('info_mermas')
                            ->label('')
                            ->content(function ($record) {
                                $mermas = $record->mermas()->orderBy('created_at', 'desc')->get()->filter(fn($m) => $m->puedeSerEliminada());

                                if ($mermas->isEmpty()) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='text-gray-500 text-center p-4'>
                                            Este lote no tiene mermas registradas.
                                        </div>
                                    ");
                                }

                                $html = "<div class='space-y-2'>";
                                foreach ($mermas as $merma) {
                                    $puedeEliminar = $merma->puedeSerEliminada();
                                    $color = $puedeEliminar ? 'gray-50' : 'red-50';
                                    $html .= "
                                        <div class='rounded-lg bg-{$color} dark:bg-gray-800 p-3 text-sm'>
                                            <div class='flex justify-between items-start'>
                                                <div>
                                                    <span class='font-bold'>{$merma->numero_merma}</span>
                                                    <span class='text-gray-500'> - {$merma->cantidad_huevos} huevos</span>
                                                </div>
                                                <span class='text-xs text-gray-400'>{$merma->created_at->format('d/m/Y H:i')}</span>
                                            </div>
                                            <div class='text-xs text-gray-600 mt-1'>
                                                Motivo: {$merma->getMotivoLabel()} | 
                                                Perdida: L " . number_format($merma->perdida_real_lempiras, 2) . "
                                            </div>
                                        </div>
                                    ";
                                }
                                $html .= "</div>";

                                return new \Illuminate\Support\HtmlString($html);
                            }),

                        Forms\Components\Select::make('merma_id')
                            ->label('Seleccionar Merma a Eliminar')
                            ->required()
                            ->options(function ($record) {
                                return $record->mermas()
                                    ->orderBy('created_at', 'desc')
                                    ->get()
                                    ->filter(fn($merma) => $merma->puedeSerEliminada())
                                    ->mapWithKeys(function ($merma) {
                                        $label = "{$merma->numero_merma} - {$merma->cantidad_huevos} huevos ({$merma->created_at->format('d/m H:i')})";
                                        return [$merma->id => $label];
                                    });
                            })
                            ->helperText('Se revertiran todos los cambios que hizo esta merma'),

                        Forms\Components\Textarea::make('motivo_eliminacion')
                            ->label('Motivo de Eliminacion')
                            ->required()
                            ->placeholder('Por que se elimina esta merma?')
                            ->rows(2),
                    ])
                    ->action(function ($record, array $data) {
                        $merma = Merma::find($data['merma_id']);

                        if (!$merma) {
                            Notification::make()
                                ->title('Error')
                                ->body('No se encontro la merma seleccionada.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Verificar permisos
                        if (!$merma->puedeSerEliminada()) {
                            $user = Auth::user();
                            if (!$user->roles->whereIn('name', ['Jefe', 'Super Admin'])->count()) {
                                Notification::make()
                                    ->title('Sin permisos')
                                    ->body($merma->getMensajeNoPuedeEliminar())
                                    ->danger()
                                    ->send();
                                return;
                            }
                        }

                        $numeroMerma = $merma->numero_merma;
                        $resumen = $merma->eliminarYRevertir($data['motivo_eliminacion']);

                        Notification::make()
                            ->title('Merma eliminada')
                            ->body("Merma {$numeroMerma} eliminada. Se devolvieron {$resumen['huevos_devueltos']} huevos al lote.")
                            ->success()
                            ->duration(5000)
                            ->send();
                    })
                    ->visible(fn($record) => $record->mermas()->get()->filter(fn($m) => $m->puedeSerEliminada())->isNotEmpty())
                    ->modalWidth('lg')
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar Merma')
                    ->modalDescription('Esta accion revertira todos los cambios que hizo la merma en el lote.'),

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
                    ->visible(fn($record) => $record->estado === LoteEstado::Disponible && $record->cantidad_huevos_remanente > 0),
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
        $query = static::getModel()::where('estado', LoteEstado::Disponible)
            ->where('cantidad_huevos_remanente', '>', 0);

        if (!self::esSuperAdminOJefe()) {
            $bodegasUsuario = self::getBodegasUsuario();
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
