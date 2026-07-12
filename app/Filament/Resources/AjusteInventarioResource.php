<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Application\Services\AjusteInventarioService;
use App\Enums\AjusteEstado;
use App\Enums\AjusteMotivo;
use App\Enums\AjusteTipoMovimiento;
use App\Filament\Resources\AjusteInventarioResource\Pages;
use App\Models\AjusteInventario;
use App\Models\Bodega;
use App\Models\Lote;
use App\Models\Producto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AjusteInventarioResource extends Resource
{
    protected static ?string $model = AjusteInventario::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 5;

    protected static ?string $pluralModelLabel = 'Ajustes de Inventario';

    protected static ?string $modelLabel = 'Ajuste de Inventario';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::pendientes()->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipo de ajuste')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('tipo_movimiento')
                        ->label('Tipo de movimiento')
                        ->options(AjusteTipoMovimiento::options())
                        ->required()
                        ->live()
                        ->helperText('Reclasificación = mueve huevos entre lotes. Merma residual = los pierde sin reclasificar.'),

                    Forms\Components\Select::make('motivo')
                        ->label('Motivo')
                        ->options(AjusteMotivo::options())
                        ->required()
                        ->helperText('Justificación del ajuste — queda en bitácora.'),
                ]),

            Forms\Components\Section::make('Lote origen')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('bodega_id')
                        ->label('Bodega')
                        ->options(Bodega::query()->pluck('nombre', 'id'))
                        ->required()
                        ->live()
                        ->default(fn () => Bodega::query()->value('id')),

                    Forms\Components\Select::make('lote_id')
                        ->label('Lote origen (de dónde salen los huevos)')
                        ->options(function (Forms\Get $get) {
                            $bodegaId = $get('bodega_id');
                            if (! $bodegaId) {
                                return [];
                            }
                            return Lote::query()
                                ->where('bodega_id', $bodegaId)
                                ->where('estado', 'disponible')
                                ->with('producto:id,nombre')
                                ->get()
                                ->mapWithKeys(fn ($l) => [
                                    $l->id => "{$l->numero_lote} — {$l->producto?->nombre} ({$l->cantidad_huevos_remanente} huevos disp.)"
                                ])->toArray();
                        })
                        ->searchable()
                        ->required()
                        ->live(),
                ]),

            Forms\Components\Section::make('Destino (solo para reclasificación)')
                ->columns(2)
                ->visible(fn (Forms\Get $get) =>
                    $get('tipo_movimiento') === AjusteTipoMovimiento::SalidaReclasificacion->value
                )
                ->schema([
                    Forms\Components\Select::make('lote_destino_id')
                        ->label('Lote destino')
                        ->options(function (Forms\Get $get) {
                            $bodegaId = $get('bodega_id');
                            $loteOrigenId = $get('lote_id');
                            if (! $bodegaId) {
                                return [];
                            }
                            return Lote::query()
                                ->where('bodega_id', $bodegaId)
                                ->where('estado', 'disponible')
                                ->when($loteOrigenId, fn ($q) => $q->where('id', '!=', $loteOrigenId))
                                ->with('producto:id,nombre')
                                ->get()
                                ->mapWithKeys(fn ($l) => [
                                    $l->id => "{$l->numero_lote} — {$l->producto?->nombre}"
                                ])->toArray();
                        })
                        ->searchable()
                        ->live()
                        ->required(fn (Forms\Get $get) =>
                            $get('tipo_movimiento') === AjusteTipoMovimiento::SalidaReclasificacion->value
                        ),

                    Forms\Components\TextInput::make('costo_unitario_aplicado')
                        ->label('Costo unitario aplicado (L / huevo)')
                        ->numeric()
                        ->step(0.0001)
                        ->helperText('Por defecto = costo del lote ORIGEN. Los huevos viajan con su costo original — no se marca pérdida. Cambialo solo si querés materializar una pérdida valorativa al momento del ajuste (caso raro: ajuste por calidad).')
                        ->default(function (Forms\Get $get) {
                            $loteOrigenId = $get('lote_id');
                            if (! $loteOrigenId) {
                                return null;
                            }
                            $lote = Lote::find($loteOrigenId);
                            return $lote?->costo_por_huevo;
                        }),
                ]),

            Forms\Components\Section::make('Cantidad y justificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('huevos_a_mover')
                        ->label('Huevos a mover/mermar')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->helperText('Cantidad de huevos individuales (no cartones).'),

                    Forms\Components\Placeholder::make('cartones_equiv')
                        ->label('Equivalente en cartones 1x30')
                        ->content(fn (Forms\Get $get) =>
                            $get('huevos_a_mover')
                                ? round((float) $get('huevos_a_mover') / 30, 2) . ' cart'
                                : '—'
                        ),

                    Forms\Components\Textarea::make('descripcion')
                        ->label('Descripción / Justificación')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText('Obligatoria. Explica por qué se hace el ajuste.'),

                    Forms\Components\FileUpload::make('evidencia_path')
                        ->label('Evidencia (foto del conteo físico, opcional)')
                        ->image()
                        ->directory('ajustes-inventario/evidencias')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('producto.nombre')->label('Producto')->searchable(),
                Tables\Columns\TextColumn::make('lote.numero_lote')->label('Lote')->searchable(),
                Tables\Columns\TextColumn::make('tipo_movimiento')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof AjusteTipoMovimiento ? $state->label() : (string) $state)
                    ->color(fn ($state) => $state instanceof AjusteTipoMovimiento ? $state->color() : 'gray'),
                Tables\Columns\TextColumn::make('motivo')
                    ->label('Motivo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof AjusteMotivo ? $state->label() : (string) $state)
                    ->color(fn ($state) => $state instanceof AjusteMotivo ? $state->color() : 'gray'),
                Tables\Columns\TextColumn::make('delta_huevos')
                    ->label('Δ huevos')
                    ->numeric(decimalPlaces: 0)
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('valor_contable_afectado')
                    ->label('Valor (L)')
                    ->money('HNL', 0),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof AjusteEstado ? $state->label() : (string) $state)
                    ->color(fn ($state) => $state instanceof AjusteEstado ? $state->color() : 'gray')
                    ->icon(fn ($state) => $state instanceof AjusteEstado ? $state->icon() : null),
                Tables\Columns\TextColumn::make('creador.name')->label('Creado por')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('aprobador.name')->label('Aprobado por')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')->options(AjusteEstado::options()),
                Tables\Filters\SelectFilter::make('motivo')->options(AjusteMotivo::options()),
                Tables\Filters\SelectFilter::make('tipo_movimiento')->options(AjusteTipoMovimiento::options()),
                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->options(Bodega::query()->pluck('nombre', 'id')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('aprobar')
                    ->label('Aprobar')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (AjusteInventario $r) => Auth::user()?->can('aprobar', $r) ?? false)
                    ->requiresConfirmation()
                    ->action(function (AjusteInventario $r, AjusteInventarioService $svc) {
                        try {
                            $svc->aprobar($r, Auth::user());
                            Notification::make()->title('Ajuste aprobado')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('rechazar')
                    ->label('Rechazar')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (AjusteInventario $r) => Auth::user()?->can('rechazar', $r) ?? false)
                    ->form([
                        Forms\Components\Textarea::make('motivo_rechazo')->label('Motivo del rechazo')->required()->rows(3),
                    ])
                    ->action(function (AjusteInventario $r, array $data, AjusteInventarioService $svc) {
                        try {
                            $svc->rechazar($r, Auth::user(), $data['motivo_rechazo']);
                            Notification::make()->title('Ajuste rechazado')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('aplicar')
                    ->label('Aplicar')
                    ->color('primary')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (AjusteInventario $r) => Auth::user()?->can('aplicar', $r) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Esta acción modificará el saldo del lote y disparará la actualización del WAC. Es irreversible.')
                    ->action(function (AjusteInventario $r, AjusteInventarioService $svc) {
                        try {
                            $svc->aplicar($r, Auth::user());
                            Notification::make()->title('Ajuste aplicado')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al aplicar')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAjusteInventarios::route('/'),
            'create' => Pages\CreateAjusteInventario::route('/create'),
            'view'   => Pages\ViewAjusteInventario::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['producto:id,nombre', 'lote:id,numero_lote', 'creador:id,name', 'aprobador:id,name']);
    }
}
