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

        // Verificar si es super_admin o jefe
        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        // Super Admin y Jefe ven todos los lotes
        if ($esSuperAdminOJefe) {
            return $query;
        }

        // Otros usuarios solo ven lotes de sus bodegas asignadas
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

                                Forms\Components\Select::make('compra_id')
                                    ->label('Compra')
                                    ->relationship('compra', 'numero_compra')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder('Reempaque (LS-*)'),

                                Forms\Components\Select::make('proveedor_id')
                                    ->label('Proveedor')
                                    ->relationship('proveedor', 'nombre')
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
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('cantidad_cartones_facturados')
                                    ->label('Cartones Facturados')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('cartones'),

                                Forms\Components\TextInput::make('cantidad_cartones_regalo')
                                    ->label('Cartones Regalo 🎁')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('cartones'),

                                Forms\Components\TextInput::make('cantidad_cartones_recibidos')
                                    ->label('Total Recibidos')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('cartones'),

                                Forms\Components\TextInput::make('cantidad_huevos_original')
                                    ->label('Huevos Originales')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('huevos'),

                                Forms\Components\TextInput::make('cantidad_huevos_remanente')
                                    ->label('Huevos Disponibles')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('huevos'),

                                Forms\Components\Placeholder::make('huevos_desglose')
                                    ->label('Desglose Disponible')
                                    ->content(function ($record) {
                                        if (!$record) return '-';
                                        $facturados = $record->getHuevosFacturadosRestantes();
                                        $regalos = $record->getHuevosRegaloRestantes();
                                        return number_format($facturados, 0) . ' facturados + ' . number_format($regalos, 0) . ' 🎁 regalos';
                                    }),
                            ]),
                    ]),

                Forms\Components\Section::make('Costos')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('costo_por_carton_facturado')
                                    ->label('Costo por Cartón (Facturado)')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->prefix('L'),

                                Forms\Components\TextInput::make('costo_por_huevo')
                                    ->label('Costo por Huevo')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->prefix('L')
                                    ->helperText('Basado solo en huevos facturados'),

                                Forms\Components\Placeholder::make('costo_total')
                                    ->label('Costo Total Remanente')
                                    ->content(function ($record) {
                                        if (!$record) return 'L 0.00';
                                        $costoTotal = $record->getCostoRemanente();
                                        return 'L ' . number_format($costoTotal, 2);
                                    }),

                                Forms\Components\Placeholder::make('beneficio_regalos')
                                    ->label('Beneficio por Regalos 🎁')
                                    ->content(function ($record) {
                                        if (!$record) return 'L 0.00';
                                        $beneficio = $record->getBeneficioRegalos();
                                        return 'L ' . number_format($beneficio, 2);
                                    })
                                    ->visible(fn($record) => $record && $record->cantidad_cartones_regalo > 0),
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
                    ->icon(fn($record) => str_starts_with($record->numero_lote, 'LS-') ? 'heroicon-o-arrow-path' : 'heroicon-o-archive-box')
                    ->description(fn($record) => str_starts_with($record->numero_lote, 'LS-') ? 'Lote de Sueltos' : null),

                Tables\Columns\TextColumn::make('compra.numero_compra')
                    ->label('Compra')
                    ->searchable()
                    ->sortable()
                    ->url(fn($record) => $record->compra_id
                        ? route('filament.admin.resources.compras.view', ['record' => $record->compra_id])
                        : null)
                    ->color('info')
                    ->placeholder('Reempaque'),

                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

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
                    ->description(fn($record) => number_format($record->cantidad_huevos_original, 0) . ' originales'),

                Tables\Columns\TextColumn::make('cartones_info')
                    ->label('Facturados / Regalos')
                    ->getStateUsing(function ($record) {
                        if ($record->esLoteSueltos()) {
                            return 'Lote Sueltos';
                        }

                        $facturados = number_format($record->cantidad_cartones_facturados, 0);
                        $regalos = $record->cantidad_cartones_regalo > 0
                            ? ' + ' . number_format($record->cantidad_cartones_regalo, 0) . ' 🎁'
                            : '';

                        return $facturados . $regalos;
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('porcentaje_usado')
                    ->label('% Usado')
                    ->getStateUsing(fn($record) => $record->getPorcentajeUsado())
                    ->suffix('%')
                    ->sortable(false)
                    ->color(fn($state) => match (true) {
                        $state < 25 => 'success',
                        $state < 75 => 'warning',
                        default => 'danger',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('costo_por_huevo')
                    ->label('Costo/Huevo')
                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                    ->sortable()
                    ->description('Solo facturados')
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

                // 🎯 FILTRO DE BODEGA - SOLO PARA SUPER ADMIN Y JEFE
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

                Tables\Filters\SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('con_remanente')
                    ->label('Con Huevos Disponibles')
                    ->query(fn($query) => $query->where('cantidad_huevos_remanente', '>', 0))
                    ->default(),

                Tables\Filters\Filter::make('tipo_lote')
                    ->form([
                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de Lote')
                            ->options([
                                'normales' => 'Lotes Normales (L-*)',
                                'sueltos' => 'Lotes de Sueltos (LS-*)',
                            ])
                            ->placeholder('Todos'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (!isset($data['tipo']) || empty($data['tipo'])) {
                            return $query;
                        }

                        return match ($data['tipo']) {
                            'normales' => $query->where('numero_lote', 'LIKE', 'L-%')
                                               ->where('numero_lote', 'NOT LIKE', 'LS-%'),
                            'sueltos' => $query->where('numero_lote', 'LIKE', 'LS-%'),
                            default => $query,
                        };
                    }),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false),
                        Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'] ?? null, fn($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['hasta'] ?? null, fn($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('reempacar')
                    ->label('Reempacar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->url(fn() => route('filament.admin.resources.reempaques.create'))
                    ->visible(fn($record) => $record->estado === 'disponible' && $record->cantidad_huevos_remanente > 0),
            ])
            ->bulkActions([
                // Sin acciones bulk por ahora
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s'); // Auto-refresh cada 30 segundos
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

        // Verificar si es Super Admin o Jefe
        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        $query = static::getModel()::where('estado', 'disponible')
            ->where('cantidad_huevos_remanente', '>', 0);

        // Si no es Super Admin o Jefe, filtrar por sus bodegas
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
