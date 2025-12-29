<?php

namespace App\Filament\Resources\CategoriaResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\RelationManagers\RelationManager;
use App\Models\Unidad;

class UnidadesRelationManager extends RelationManager
{
    protected static string $relationship = 'unidades';
    protected static ?string $title = 'Unidades Permitidas';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Toggle::make('activo')
                ->label('Activo')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Unidad')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\ToggleColumn::make('pivot.activo')
                    ->label('Activo')
                    ->afterStateUpdated(function ($record, $state) {
                        $record->pivot->updated_by = Auth::id();
                        $record->pivot->save();
                    }),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Agregar unidad')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('unidades.activo', true))
                    ->recordTitle(fn (Unidad $record): string => $record->nombre)
                    ->recordSelectSearchColumns(['nombre'])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();
                        $data['updated_by'] = Auth::id();
                        $data['activo'] = $data['activo'] ?? true;
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->modalHeading('Actualizar Unidad')
                    ->form(fn (Form $form): Form => $form
                        ->schema([
                            Forms\Components\Toggle::make('pivot.activo')
                                ->label('Activo'),
                        ])
                    )
                    ->using(function ($record, array $data): void {
                        $this->getOwnerRecord()
                            ->unidades()
                            ->updateExistingPivot($record->id, [
                                'activo' => $data['pivot']['activo'] ?? true,
                                'updated_by' => Auth::id(),
                            ]);
                    }),

                Tables\Actions\DetachAction::make()
                    ->label('Quitar'),
            ]);
    }
}
