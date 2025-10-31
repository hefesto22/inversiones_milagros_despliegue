<?php

namespace App\Filament\Resources\ProveedorResource\RelationManagers;

use App\Models\Producto;
use App\Models\Unidad;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PreciosRelationManager extends RelationManager
{
    protected static string $relationship = 'precios';

    protected static ?string $title = 'Precios de Compra';

    protected static ?string $modelLabel = 'Precio de Compra';

    protected static ?string $pluralModelLabel = 'Precios de Compra';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Precio')
                    ->columns(12)
                    ->schema([
                        Forms\Components\Select::make('producto_id')
                            ->label('Producto')
                            ->options(Producto::pluck('nombre', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(6),

                        Forms\Components\Select::make('unidad_id')
                            ->label('Unidad')
                            ->options(Unidad::pluck('nombre', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpan(6),

                        Forms\Components\TextInput::make('precio_compra')
                            ->label('Precio de Compra')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('L')
                            ->rules(['decimal:0,4'])
                            ->columnSpan(4),

                        Forms\Components\DatePicker::make('vigente_desde')
                            ->label('Vigente Desde')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->displayFormat('Y-m-d')
                            ->columnSpan(4),

                        Forms\Components\DatePicker::make('vigente_hasta')
                            ->label('Vigente Hasta')
                            ->nullable()
                            ->native(false)
                            ->displayFormat('Y-m-d')
                            ->columnSpan(4),
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
                    ->sortable(),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('precio_compra')
                    ->label('Precio de Compra')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vigente_desde')
                    ->label('Vigente Desde')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->label('Vigente Hasta')
                    ->date('Y-m-d')
                    ->placeholder('Vigente')
                    ->sortable(),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if (is_null($record->vigente_hasta)) {
                            return 'Vigente';
                        }
                        return $record->vigente_hasta->isFuture() ? 'Vigente' : 'Histórico';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Vigente' => 'success',
                        'Histórico' => 'gray',
                    }),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de Creación')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->options(Producto::pluck('nombre', 'id'))
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('unidad_id')
                    ->label('Unidad')
                    ->options(Unidad::pluck('nombre', 'id'))
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('estado')
                    ->label('Estado')
                    ->form([
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'vigente' => 'Vigente',
                                'historico' => 'Histórico',
                            ])
                            ->placeholder('Todos'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['estado'] === 'vigente',
                            fn (Builder $q) => $q->whereNull('vigente_hasta')
                                ->orWhere('vigente_hasta', '>=', now())
                        )->when(
                            $data['estado'] === 'historico',
                            fn (Builder $q) => $q->whereNotNull('vigente_hasta')
                                ->where('vigente_hasta', '<', now())
                        );
                    }),

                Tables\Filters\Filter::make('vigente_desde')
                    ->label('Rango de Fechas')
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
                            ->when(
                                $data['desde'],
                                fn (Builder $query, $date): Builder => $query->whereDate('vigente_desde', '>=', $date),
                            )
                            ->when(
                                $data['hasta'],
                                fn (Builder $query, $date): Builder => $query->whereDate('vigente_desde', '<=', $date),
                            );
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = Auth::id();
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
            ->defaultSort('vigente_desde', 'desc');
    }
}
