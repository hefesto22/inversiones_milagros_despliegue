<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user';
    protected static ?string $navigationLabel = 'Usuarios';
    protected static ?string $pluralModelLabel = 'Usuarios';
    protected static ?string $modelLabel = 'Usuario';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Usuario')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->dehydrateStateUsing(fn($state) => filled($state) ? Hash::make($state) : null)
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context) => $context === 'create')
                            ->maxLength(255)
                            ->helperText(fn(string $context) => $context === 'edit' ? 'Dejar vacío para mantener la contraseña actual' : null),

                        Forms\Components\Select::make('roles')
                            ->label('Rol')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->required()
                            ->options(function () {
                                $query = Role::query();
                                $user = Auth::user();

                                if ($user?->roles->contains('name', 'Jefe')) {
                                    $query->whereNotIn('name', ['super_admin']);
                                }

                                if ($user?->roles->contains('name', 'Encargado')) {
                                    $query->whereNotIn('name', ['super_admin', 'Jefe', 'Encargado']);
                                }

                                return $query->pluck('name', 'id');
                            }),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Información adicional')
                    ->schema([
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Creado el')
                            ->disabled(),

                        Forms\Components\DateTimePicker::make('updated_at')
                            ->label('Actualizado el')
                            ->disabled(),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->collapsible()
                    ->visible(fn (string $context) => $context === 'edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'super_admin', 'Super Admin' => 'danger',
                        'Jefe' => 'warning',
                        'Encargado' => 'info',
                        'Chofer' => 'success',
                        default => 'gray',
                    })
                    ->separator(', '),

                // Mostrar cantidad de comisiones configuradas (solo para choferes)
                Tables\Columns\TextColumn::make('comisiones_count')
                    ->label('Comisiones')
                    ->badge()
                    ->color('success')
                    ->getStateUsing(function ($record) {
                        if (!$record->hasRole('Chofer')) {
                            return null;
                        }
                        return $record->comisionesConfig()->where('activo', true)->count();
                    })
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Rol')
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        // Si es super_admin, ve todo
        if ($user->roles->contains('name', 'super_admin') || $user->roles->contains('name', 'Super Admin')) {
            return $query;
        }

        // Si es Jefe
        if ($user->roles->contains('name', 'Jefe')) {
            $encargadosIds = User::where('created_by', $user->id)->pluck('id');

            return $query->where(function ($q) use ($user, $encargadosIds) {
                $q->where('id', $user->id)
                    ->orWhere('created_by', $user->id)
                    ->orWhereIn('created_by', $encargadosIds);
            });
        }

        // Si es Encargado
        if ($user->roles->contains('name', 'Encargado')) {
            return $query->where(function ($q) use ($user) {
                $q->where('id', $user->id)
                    ->orWhere('created_by', $user->id);
            });
        }

        // Otros roles, solo se ven a sí mismos
        return $query->where('id', $user->id);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ComisionesConfigRelationManager::class,
            RelationManagers\ComisionesProductoRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}