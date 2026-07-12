<?php

declare(strict_types=1);

namespace App\Filament\Resources\LoteResource\RelationManagers;

use App\Enums\MovimientoInventarioTipo;
use App\Models\MovimientoInventario;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Pestaña "Kardex" dentro del Lote — la historia completa del lote:
 * qué entró, qué salió, hacia dónde, con saldo corrido y documento origen.
 *
 * Solo lectura: el libro es inmutable.
 */
class KardexRelationManager extends RelationManager
{
    protected static string $relationship = 'movimientosKardex';

    protected static ?string $title = 'Kardex';

    protected static ?string $icon = 'heroicon-o-book-open';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
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

                Tables\Columns\TextColumn::make('delta')
                    ->label('Δ Huevos')
                    ->alignEnd()
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '') . number_format((float) $state, 0)),

                Tables\Columns\TextColumn::make('cartones_equiv')
                    ->label('≈ Cart 1×30')
                    ->alignEnd()
                    ->state(fn (MovimientoInventario $r) => $r->cartones_equiv !== null
                        ? number_format($r->cartones_equiv, 1)
                        : '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('saldo_despues')
                    ->label('Saldo (huevos)')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('valor')
                    ->label('Valor (L)')
                    ->money('HNL')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('descripcion')
                    ->label('Detalle')
                    ->limit(50)
                    ->tooltip(fn (MovimientoInventario $r) => $r->descripcion),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(MovimientoInventarioTipo::options()),
            ])
            ->headerActions([
                // Inmutable — sin crear
            ])
            ->actions([
                // El detalle completo vive en el Kardex general
            ])
            ->bulkActions([]);
    }
}
