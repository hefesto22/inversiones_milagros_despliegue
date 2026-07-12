<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\MovimientoInventarioTipo;
use App\Filament\Resources\MovimientoInventarioResource\Pages;
use App\Models\Bodega;
use App\Models\MovimientoInventario;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kardex de Inventario — vista de SOLO LECTURA del libro mayor de movimientos.
 *
 * El libro es inmutable: este resource no ofrece crear, editar ni borrar
 * (la Policy además lo bloquea a nivel de autorización). Los movimientos
 * nacen exclusivamente de los eventos de dominio.
 */
class MovimientoInventarioResource extends Resource
{
    protected static ?string $model = MovimientoInventario::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Kardex';

    protected static ?string $pluralModelLabel = 'Kardex de Inventario';

    protected static ?string $modelLabel = 'Movimiento de Inventario';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('ocurrido_en')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo')
                    ->label('Movimiento')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof MovimientoInventarioTipo ? $state->label() : (string) $state)
                    ->color(fn ($state) => $state instanceof MovimientoInventarioTipo ? $state->color() : 'gray'),

                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->limit(28),

                Tables\Columns\TextColumn::make('contenedor')
                    ->label('Lote / Bodega')
                    ->state(fn (MovimientoInventario $r) => $r->nivel === MovimientoInventario::NIVEL_LOTE
                        ? ($r->lote?->numero_lote ?? "Lote #{$r->lote_id}")
                        : ($r->bodega?->nombre ?? "Bodega #{$r->bodega_id}"))
                    ->description(fn (MovimientoInventario $r) => $r->nivel === MovimientoInventario::NIVEL_LOTE ? 'huevos' : 'unidades'),

                Tables\Columns\TextColumn::make('delta')
                    ->label('Δ Cantidad')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '') . number_format((float) $state, ((float) $state) == intval($state) ? 0 : 2)),

                Tables\Columns\TextColumn::make('saldo_despues')
                    ->label('Saldo')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('valor')
                    ->label('Valor (L)')
                    ->money('HNL')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('descripcion')
                    ->label('Detalle')
                    ->searchable()
                    ->limit(45)
                    ->tooltip(fn (MovimientoInventario $r) => $r->descripcion),

                Tables\Columns\TextColumn::make('creador.name')
                    ->label('Usuario')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo de movimiento')
                    ->options(MovimientoInventarioTipo::options()),

                Tables\Filters\SelectFilter::make('nivel')
                    ->label('Nivel')
                    ->options([
                        MovimientoInventario::NIVEL_LOTE   => 'Lotes (huevos)',
                        MovimientoInventario::NIVEL_BODEGA => 'Bodega (empacado / lácteos)',
                    ]),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->options(Bodega::query()->pluck('nombre', 'id')),

                Tables\Filters\SelectFilter::make('producto_id')
                    ->label('Producto')
                    ->relationship('producto', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('rango_fechas')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('ocurrido_en', '>=', $d))
                            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('ocurrido_en', '<=', $d));
                    })
                    ->indicateUsing(function (array $data) {
                        $indicadores = [];
                        if ($data['desde'] ?? null) {
                            $indicadores[] = 'Desde ' . $data['desde'];
                        }
                        if ($data['hasta'] ?? null) {
                            $indicadores[] = 'Hasta ' . $data['hasta'];
                        }
                        return $indicadores;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // El libro es inmutable — sin acciones bulk
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Movimiento')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('ocurrido_en')->label('Fecha')->dateTime('d/m/Y H:i:s'),
                    Infolists\Components\TextEntry::make('tipo')->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state instanceof MovimientoInventarioTipo ? $state->label() : (string) $state)
                        ->color(fn ($state) => $state instanceof MovimientoInventarioTipo ? $state->color() : 'gray'),
                    Infolists\Components\TextEntry::make('creador.name')->label('Usuario')->placeholder('Sistema'),
                    Infolists\Components\TextEntry::make('producto.nombre')->label('Producto'),
                    Infolists\Components\TextEntry::make('bodega.nombre')->label('Bodega'),
                    Infolists\Components\TextEntry::make('lote.numero_lote')->label('Lote')->placeholder('— (movimiento de bodega)'),
                ]),

            Infolists\Components\Section::make('Cantidades y valor')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('delta')->label('Δ Cantidad')
                        ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                    Infolists\Components\TextEntry::make('unidad')->label('Unidad'),
                    Infolists\Components\TextEntry::make('saldo_despues')->label('Saldo después'),
                    Infolists\Components\TextEntry::make('valor')->label('Valor')->money('HNL')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Trazabilidad')
                ->columns(1)
                ->schema([
                    Infolists\Components\TextEntry::make('descripcion')->label('Detalle')->placeholder('—'),
                    Infolists\Components\TextEntry::make('referencia_type')->label('Documento origen')
                        ->formatStateUsing(fn ($state, MovimientoInventario $r) => $state
                            ? class_basename($state) . " #{$r->referencia_id}"
                            : null)
                        ->placeholder('— (sin documento, ej. saldo inicial)'),
                    Infolists\Components\KeyValueEntry::make('contexto')->label('Contexto técnico')
                        ->visible(fn (MovimientoInventario $r) => filled($r->contexto)),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMovimientoInventarios::route('/'),
            'view'  => Pages\ViewMovimientoInventario::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'producto:id,nombre',
                'bodega:id,nombre',
                'lote:id,numero_lote',
                'creador:id,name',
            ]);
    }

    // El libro es inmutable — nunca se crea desde la UI
    public static function canCreate(): bool
    {
        return false;
    }
}
