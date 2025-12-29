<?php

namespace App\Filament\Resources\BodegaResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\AttachAction;
use Filament\Resources\RelationManagers\RelationManager;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class UsuariosRelationManager extends RelationManager
{
    protected static string $relationship = 'usuarios';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('activo')
                    ->label('Activo')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Usuario'),

                Tables\Columns\TextColumn::make('pivot.rol')
                    ->label('Rol'),

                Tables\Columns\ToggleColumn::make('pivot.activo')
                    ->label('Activo'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Asignar usuario')
                    ->recordTitle(fn (User $record) => $record->name)
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function ($query) {
                        $currentUser = Auth::user();

                        return $query
                            ->whereDoesntHave('bodegas')
                            ->where('users.created_by', '=', $currentUser->id)
                            ->whereDoesntHave('roles', function ($roleQuery) {
                                $roleQuery->where('name', 'super_admin');
                            })
                            ->whereDoesntHave('roles', function ($roleQuery) use ($currentUser) {
                                $currentUserRoles = $currentUser->roles->pluck('name');
                                $roleQuery->whereIn('name', $currentUserRoles);
                            });
                    })
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),

                        Forms\Components\Toggle::make('activo')
                            ->label('Activo')
                            ->default(true),

                        // ✅ Campos ocultos que se insertarán en el pivot
                        Forms\Components\Hidden::make('created_by')
                            ->default(Auth::id()),

                        Forms\Components\Hidden::make('updated_by')
                            ->default(Auth::id()),
                    ])
                    ->using(function ($livewire, array $data, User $record): void {
                        // Obtener el rol del usuario desde Spatie Permission
                        $userRole = $record->roles->first()?->name ?? 'sin_rol';

                        // Agregar el rol a los datos del pivot
                        $data['rol'] = $userRole;

                        // Adjuntar el usuario con todos los datos del pivot
                        $livewire->getOwnerRecord()->usuarios()->attach($record->id, [
                            'activo' => $data['activo'] ?? true,
                            'rol' => $data['rol'],
                            'created_by' => $data['created_by'],
                            'updated_by' => $data['updated_by'],
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Quitar'),
            ]);
    }
}
