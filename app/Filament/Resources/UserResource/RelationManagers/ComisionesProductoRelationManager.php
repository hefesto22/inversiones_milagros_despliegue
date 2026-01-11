<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\ChoferComisionProducto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ComisionesProductoRelationManager extends RelationManager
{
    protected static string $relationship = 'comisionesProducto';

    protected static ?string $title = 'Excepciones por Producto';

    protected static ?string $modelLabel = 'Excepción';

    protected static ?string $pluralModelLabel = 'Excepciones';

    protected static ?string $icon = 'heroicon-o-star';

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
                        Forms\Components\Select::make('producto_id')
                            ->label('Producto')
                            ->relationship('producto', 'nombre')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Este producto tendrá una comisión diferente')
                            ->columnSpanFull(),

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
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('producto.categoria.nombre')
                    ->label('Categoría')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

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
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Excepción')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = Auth::id();
                        return $data;
                    }),
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
            ->defaultSort('producto.nombre')
            ->emptyStateHeading('Sin excepciones')
            ->emptyStateDescription('Agregue excepciones para productos que tengan una comisión diferente a su categoría.')
            ->emptyStateIcon('heroicon-o-star');
    }
}