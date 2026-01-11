<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\ChoferComisionConfig;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ComisionesConfigRelationManager extends RelationManager
{
    protected static string $relationship = 'comisionesConfig';

    protected static ?string $title = 'Comisiones por Categoría';

    protected static ?string $modelLabel = 'Comisión';

    protected static ?string $pluralModelLabel = 'Comisiones';

    protected static ?string $icon = 'heroicon-o-currency-dollar';

    /**
     * Solo mostrar si el usuario tiene rol Chofer
     */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->hasRole('Chofer');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\Select::make('categoria_id')
                            ->label('Categoría')
                            ->relationship('categoria', 'nombre')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull()
                            ->helperText('La comisión aplica a todas las unidades de esta categoría. El factor de cada unidad (símbolo) se usa para calcular.'),

                        Forms\Components\TextInput::make('comision_normal')
                            ->label('Comisión Normal')
                            ->required()
                            ->numeric()
                            ->prefix('L')
                            ->step(0.01)
                            ->minValue(0)
                            ->helperText('Cuando vende ≥ precio sugerido'),

                        Forms\Components\TextInput::make('comision_reducida')
                            ->label('Comisión Reducida')
                            ->required()
                            ->numeric()
                            ->prefix('L')
                            ->step(0.01)
                            ->minValue(0)
                            ->default(0.50)
                            ->helperText('Cuando vende < precio sugerido'),

                        Forms\Components\DatePicker::make('vigente_desde')
                            ->label('Vigente Desde')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Forms\Components\DatePicker::make('vigente_hasta')
                            ->label('Vigente Hasta')
                            ->native(false)
                            ->placeholder('Indefinido'),

                        Forms\Components\Toggle::make('activo')
                            ->label('Activo')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('categoria.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('comision_normal')
                    ->label('Normal')
                    ->money('HNL')
                    ->sortable()
                    ->color('success')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('comision_reducida')
                    ->label('Reducida')
                    ->money('HNL')
                    ->sortable()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('vigente_desde')
                    ->label('Desde')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->label('Hasta')
                    ->date('d/m/Y')
                    ->placeholder('∞')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre'),

                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Comisión')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();
                        $data['unidad_id'] = null; // Ya no usamos unidad específica
                        return $data;
                    }),

                Tables\Actions\Action::make('copiar_de_otro')
                    ->label('Copiar de otro Chofer')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('chofer_origen')
                            ->label('Copiar comisiones de')
                            ->options(function () {
                                return \App\Models\User::whereHas('roles', fn($q) => $q->where('name', 'Chofer'))
                                    ->where('id', '!=', $this->getOwnerRecord()->id)
                                    ->whereHas('comisionesConfig')
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (array $data) {
                        $comisionesOrigen = ChoferComisionConfig::where('user_id', $data['chofer_origen'])
                            ->where('activo', true)
                            ->get();

                        $copiadas = 0;
                        foreach ($comisionesOrigen as $comision) {
                            // Verificar si ya existe para esta categoría
                            $existe = ChoferComisionConfig::where('user_id', $this->getOwnerRecord()->id)
                                ->where('categoria_id', $comision->categoria_id)
                                ->exists();

                            if (!$existe) {
                                ChoferComisionConfig::create([
                                    'user_id' => $this->getOwnerRecord()->id,
                                    'categoria_id' => $comision->categoria_id,
                                    'unidad_id' => null,
                                    'comision_normal' => $comision->comision_normal,
                                    'comision_reducida' => $comision->comision_reducida,
                                    'vigente_desde' => now(),
                                    'activo' => true,
                                    'created_by' => Auth::id(),
                                ]);
                                $copiadas++;
                            }
                        }

                        Notification::make()
                            ->title("Se copiaron {$copiadas} comisiones")
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                
                Tables\Actions\Action::make('toggle_activo')
                    ->label(fn ($record) => $record->activo ? 'Desactivar' : 'Activar')
                    ->icon(fn ($record) => $record->activo ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->activo ? 'danger' : 'success')
                    ->action(fn ($record) => $record->update(['activo' => !$record->activo])),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('categoria.nombre')
            ->emptyStateHeading('Sin comisiones configuradas')
            ->emptyStateDescription('Configure las comisiones que ganará este chofer por categoría de producto. El cálculo usará el factor (símbolo) de cada unidad.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }
}