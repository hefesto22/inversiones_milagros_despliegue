<?php

namespace App\Filament\Resources\ProductoResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\ProductoPrecioTipo;

class PreciosTipoRelationManager extends RelationManager
{
    protected static string $relationship = 'preciosTipo';

    protected static ?string $title = 'Descuento Máximo por Tipo de Cliente';

    protected static ?string $modelLabel = 'Regla de Precio';

    protected static ?string $pluralModelLabel = 'Reglas de Precio';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('tipo_cliente')
                    ->label('Tipo de Cliente')
                    ->options([
                        'mayorista' => 'Mayorista',
                        'minorista' => 'Minorista',
                        'distribuidor' => 'Distribuidor',
                        'ruta' => 'Ruta',
                    ])
                    ->required()
                    ->native(false)
                    ->disableOptionWhen(function (string $value, ?string $operation) {
                        // Al crear, deshabilitar tipos que ya tienen regla para este producto
                        if ($operation === 'create') {
                            $productoId = $this->getOwnerRecord()->id;
                            return ProductoPrecioTipo::where('producto_id', $productoId)
                                ->where('tipo_cliente', $value)
                                ->exists();
                        }
                        return false;
                    })
                    ->helperText(function (?string $operation) {
                        if ($operation === 'create') {
                            $productoId = $this->getOwnerRecord()->id;
                            $existentes = ProductoPrecioTipo::where('producto_id', $productoId)
                                ->pluck('tipo_cliente')
                                ->toArray();

                            if (!empty($existentes)) {
                                $tipos = implode(', ', array_map('ucfirst', $existentes));
                                return "Ya configurados: {$tipos}";
                            }
                        }
                        return null;
                    }),

                Forms\Components\TextInput::make('descuento_maximo')
                    ->label('Descuento Máximo (L)')
                    ->prefix('L')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->maxValue(999999)
                    ->step(0.0001)
                    ->helperText(function () {
                        $producto = $this->getOwnerRecord();
                        $precioVenta = $producto->precio_venta_maximo;

                        if ($precioVenta && $precioVenta > 0) {
                            return "Precio de venta actual: L " . number_format($precioVenta, 2);
                        }

                        return 'Máximo que se puede rebajar del precio de venta';
                    }),

                Forms\Components\TextInput::make('precio_minimo_fijo')
                    ->label('Precio Mínimo Fijo (opcional)')
                    ->prefix('L')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999)
                    ->step(0.0001)
                    ->helperText('Si se define, se usa este precio como piso en vez de calcular (precio - descuento). Dejar vacío para usar el descuento.'),

                Forms\Components\Toggle::make('activo')
                    ->label('Activo')
                    ->default(true)
                    ->helperText('Desactivar para suspender temporalmente esta regla'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tipo_cliente')
            ->columns([
                Tables\Columns\TextColumn::make('tipo_cliente')
                    ->label('Tipo de Cliente')
                    ->badge()
                    ->formatStateUsing(fn(string $state) => ucfirst($state))
                    ->color(fn(string $state) => match ($state) {
                        'mayorista' => 'success',
                        'minorista' => 'info',
                        'distribuidor' => 'warning',
                        'ruta' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('descuento_maximo')
                    ->label('Descuento Máx.')
                    ->prefix('L ')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('precio_minimo_fijo')
                    ->label('Precio Mínimo Fijo')
                    ->prefix('L ')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('Calculado')
                    ->color('warning'),

                Tables\Columns\TextColumn::make('precio_minimo_calculado')
                    ->label('Precio Mínimo Efectivo')
                    ->getStateUsing(function ($record) {
                        $producto = $this->getOwnerRecord();
                        $precioVenta = (float) ($producto->precio_venta_maximo ?? 0);

                        if ($precioVenta <= 0) {
                            return null;
                        }

                        if (!is_null($record->precio_minimo_fijo) && $record->precio_minimo_fijo > 0) {
                            return $record->precio_minimo_fijo;
                        }

                        return $precioVenta - (float) $record->descuento_maximo;
                    })
                    ->prefix('L ')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('Sin precio de venta')
                    ->color('success')
                    ->weight('bold'),

                Tables\Columns\IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Regla')
                    ->icon('heroicon-o-plus-circle')
                    ->visible(function () {
                        // Ocultar si ya existen los 4 tipos
                        $productoId = $this->getOwnerRecord()->id;
                        $count = ProductoPrecioTipo::where('producto_id', $productoId)->count();
                        return $count < 4;
                    })
                    ->after(function () {
                        // Refrescar la tabla después de crear
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
            ->emptyStateHeading('Sin reglas de precio configuradas')
            ->emptyStateDescription('Agrega reglas para controlar el descuento máximo que se puede aplicar a cada tipo de cliente para este producto.')
            ->emptyStateIcon('heroicon-o-currency-dollar')
            ->defaultSort('tipo_cliente', 'asc');
    }
}