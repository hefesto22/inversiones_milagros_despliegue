<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProveedorResource\Pages;
use App\Models\Proveedor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProveedorResource extends Resource
{
    protected static ?string $model = Proveedor::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Compras';
    protected static ?string $pluralModelLabel = 'Proveedores';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información General')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([

                        Forms\Components\TextInput::make('nombre')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('rtn')
                            ->label('RTN')
                            ->unique(ignoreRecord: true)
                            ->maxLength(30),

                        Forms\Components\TextInput::make('telefono')
                            ->tel()
                            ->maxLength(30),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->maxLength(100),

                        Forms\Components\Toggle::make('estado')
                            ->label('Activo')
                            ->default(true),
                    ]),

                    Forms\Components\Textarea::make('direccion')
                        ->maxLength(255)
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([

            Tables\Columns\TextColumn::make('nombre')
                ->searchable()
                ->sortable()
                ->weight('bold'),

            Tables\Columns\TextColumn::make('rtn')
                ->label('RTN')
                ->searchable(),

            Tables\Columns\TextColumn::make('telefono')
                ->icon('heroicon-o-phone')
                ->searchable(),

            Tables\Columns\TextColumn::make('email')
                ->icon('heroicon-o-envelope')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),

            Tables\Columns\ToggleColumn::make('estado')
                ->label('Activo'),

            Tables\Columns\TextColumn::make('created_at')
                ->label('Creado')
                ->dateTime('d/m/Y')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([
            Tables\Filters\TernaryFilter::make('estado')
                ->label('Estado')
                ->placeholder('Todos')
                ->trueLabel('Activos')
                ->falseLabel('Inactivos'),
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])
        ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ])
        ->defaultSort('nombre');
    }

    public static function getRelations(): array
    {
        return [
            // Aquí solo relaciones reales:
            \App\Filament\Resources\ProveedorResource\RelationManagers\ComprasRelationManager::class,
            \App\Filament\Resources\ProveedorResource\RelationManagers\ProveedorProductoRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProveedors::route('/'),
            'create' => Pages\CreateProveedor::route('/create'),
            'view'   => Pages\ViewProveedor::route('/{record}'),
            'edit'   => Pages\EditProveedor::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('estado', true)->count();
    }
}
