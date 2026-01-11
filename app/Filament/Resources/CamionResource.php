<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CamionResource\Pages;
use App\Filament\Resources\CamionResource\RelationManagers;
use App\Models\Camion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CamionResource extends Resource
{
    protected static ?string $model = Camion::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Logística';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Camión';

    protected static ?string $pluralModelLabel = 'Camiones';

    /**
     * Verificar si el usuario actual es Super Admin o Jefe
     */
    protected static function esSuperAdminOJefe(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();
    }

    /**
     * Obtener la bodega del usuario actual (directa o desde bodega_user)
     */
    protected static function getBodegaUsuario(): ?int
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return null;
        }

        // Primero intentar bodega_id directo del usuario
        if ($currentUser->bodega_id) {
            return $currentUser->bodega_id;
        }

        // Si no tiene bodega_id directo, buscar en bodega_user
        $bodegaAsignada = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->where('activo', true)
            ->value('bodega_id');

        return $bodegaAsignada;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        // Super Admin y Jefe ven todos los camiones
        if (static::esSuperAdminOJefe()) {
            return $query;
        }

        // Otros usuarios solo ven camiones de su bodega
        $bodegaId = static::getBodegaUsuario();

        if ($bodegaId) {
            return $query->where('bodega_id', $bodegaId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        $puedeSeleccionarBodega = static::esSuperAdminOJefe();
        $bodegaUsuario = static::getBodegaUsuario();

        return $form
            ->schema([
                Forms\Components\Section::make('Información del Camión')
                    ->schema([
                        Forms\Components\TextInput::make('codigo')
                            ->label('Código')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20)
                            ->placeholder('CAM-001'),

                        Forms\Components\TextInput::make('placa')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(20)
                            ->placeholder('ABC-1234'),

                        Forms\Components\Select::make('bodega_id')
                            ->label('Bodega')
                            ->relationship('bodega', 'nombre')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->afterStateHydrated(function (Set $set, $state, $record) use ($bodegaUsuario) {
                                // Solo establecer default si es nuevo registro (no tiene state ni record)
                                if (is_null($state) && is_null($record) && $bodegaUsuario) {
                                    $set('bodega_id', $bodegaUsuario);
                                }
                            })
                            ->disabled(!$puedeSeleccionarBodega)
                            ->dehydrated(true),

                        Forms\Components\Toggle::make('activo')
                            ->label('Activo')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('placa')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('chofer_viaje_activo')
                    ->label('Chofer Actual')
                    ->getStateUsing(function ($record) {
                        // Obtener el chofer del viaje activo
                        $viajeActivo = $record->getViajeActivo();
                        return $viajeActivo?->chofer?->name;
                    })
                    ->badge()
                    ->color('success')
                    ->placeholder('Sin asignar'),

                Tables\Columns\TextColumn::make('viaje_estado')
                    ->label('Estado Viaje')
                    ->getStateUsing(function ($record) {
                        $viajeActivo = $record->getViajeActivo();
                        if (!$viajeActivo) {
                            return null;
                        }
                        return match ($viajeActivo->estado) {
                            'planificado' => 'Planificado',
                            'cargando' => 'Cargando',
                            'en_ruta' => 'En Ruta',
                            'regresando' => 'Regresando',
                            'descargando' => 'Descargando',
                            'liquidando' => 'Liquidando',
                            default => $viajeActivo->estado,
                        };
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'Planificado' => 'gray',
                        'Cargando' => 'info',
                        'En Ruta' => 'warning',
                        'Regresando' => 'primary',
                        'Descargando' => 'info',
                        'Liquidando' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('Disponible'),

                Tables\Columns\IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Filtro de bodega solo visible para Super Admin y Jefe
                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => static::esSuperAdminOJefe()),

                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
                
                Tables\Filters\Filter::make('con_viaje_activo')
                    ->label('Con viaje activo')
                    ->query(fn (Builder $query) => $query->whereHas('viajes', function ($q) {
                        $q->whereNotIn('estado', ['cerrado', 'cancelado']);
                    }))
                    ->toggle(),

                Tables\Filters\Filter::make('disponibles')
                    ->label('Disponibles')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('viajes', function ($q) {
                        $q->whereNotIn('estado', ['cerrado', 'cancelado']);
                    }))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('codigo');
    }

    public static function getRelations(): array
    {
        return [
            // RelationManagers\AsignacionesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCamions::route('/'),
            'create' => Pages\CreateCamion::route('/create'),
            'edit' => Pages\EditCamion::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return null;
        }

        $query = static::getModel()::where('activo', true);

        // Si no es Super Admin o Jefe, filtrar por su bodega
        if (!static::esSuperAdminOJefe()) {
            $bodegaId = static::getBodegaUsuario();
            if ($bodegaId) {
                $query->where('bodega_id', $bodegaId);
            }
        }

        return (string) $query->count();
    }
}