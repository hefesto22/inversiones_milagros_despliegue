<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ComisionChoferResource\Pages;
use App\Models\ComisionChofer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComisionChoferResource extends Resource
{
    protected static ?string $model = ComisionChofer::class;
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Logística';
    protected static ?string $navigationLabel = 'Comisiones Chofer';
    protected static ?string $modelLabel = 'Comisión';
    protected static ?string $pluralModelLabel = 'Comisiones de Choferes';
    protected static ?int $navigationSort = 51;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\Select::make('chofer_user_id')
                        ->label('Chofer')
                        ->relationship('chofer', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('aplica_a')
                        ->label('Aplica a')
                        ->options([
                            'carton_30' => 'Solo Cartón 30',
                            'carton_15' => 'Solo Cartón 15',
                            'ambos' => 'Ambos (30 y 15)',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Define a qué tipo de cartón aplica esta comisión')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('monto_por_carton')
                        ->label('Monto por Cartón (HNL)')
                        ->numeric()
                        ->rules(['decimal:0,4'])
                        ->required()
                        ->prefix('L')
                        ->minValue(0)
                        ->step(0.01)
                        ->helperText('Comisión en Lempiras por cada cartón vendido')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('vigente_desde')
                        ->label('Vigente Desde')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('vigente_hasta')
                        ->label('Vigente Hasta')
                        ->afterOrEqual('vigente_desde')
                        ->native(false)
                        ->helperText('Déjalo vacío para que sea indefinido')
                        ->columnSpan(2),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('chofer.name')
                    ->label('Chofer')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('aplica_a')
                    ->label('Aplica a')
                    ->colors([
                        'primary' => 'carton_30',
                        'warning' => 'carton_15',
                        'success' => 'ambos',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'carton_30' => 'Cartón 30',
                        'carton_15' => 'Cartón 15',
                        'ambos' => 'Ambos',
                        default => ucfirst($state)
                    }),

                Tables\Columns\TextColumn::make('monto_por_carton')
                    ->label('Monto/Cartón')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                Tables\Columns\TextColumn::make('vigente_desde')
                    ->label('Vigente Desde')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->label('Vigente Hasta')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('Indefinido')
                    ->color(fn ($state) => is_null($state) ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->getStateUsing(fn ($record) => $record->estaVigente() ? 'Vigente' : 'Histórico')
                    ->colors([
                        'success' => 'Vigente',
                        'gray' => 'Histórico',
                    ])
                    ->icons([
                        'heroicon-o-check-circle' => 'Vigente',
                        'heroicon-o-clock' => 'Histórico',
                    ]),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha creación')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->defaultSort('vigente_desde', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('chofer_user_id')
                    ->label('Chofer')
                    ->relationship('chofer', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('aplica_a')
                    ->label('Aplica a')
                    ->options([
                        'carton_30' => 'Cartón 30',
                        'carton_15' => 'Cartón 15',
                        'ambos' => 'Ambos',
                    ])
                    ->native(false),

                Tables\Filters\TernaryFilter::make('vigente')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Solo vigentes')
                    ->falseLabel('Solo históricas')
                    ->queries(
                        true: fn (Builder $q) => $q->vigentes(),
                        false: fn (Builder $q) => $q->whereNotNull('vigente_hasta')
                            ->whereDate('vigente_hasta', '<', now()),
                        blank: fn (Builder $q) => $q
                    ),

                Tables\Filters\Filter::make('vigencia')
                    ->form([
                        Forms\Components\DatePicker::make('desde')
                            ->label('Vigente desde'),
                        Forms\Components\DatePicker::make('hasta')
                            ->label('Vigente hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['desde'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('vigente_desde', '>=', $date),
                            )
                            ->when(
                                $data['hasta'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('vigente_desde', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['desde'] ?? null) {
                            $indicators[] = 'Desde: ' . \Carbon\Carbon::parse($data['desde'])->format('d/m/Y');
                        }
                        if ($data['hasta'] ?? null) {
                            $indicators[] = 'Hasta: ' . \Carbon\Carbon::parse($data['hasta'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Sin comisiones configuradas')
            ->emptyStateDescription('Comienza configurando las comisiones para tus choferes.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComisionChofers::route('/'),
            'create' => Pages\CreateComisionChofer::route('/create'),
            'edit' => Pages\EditComisionChofer::route('/{record}/edit'),
            // 'view' => Pages\ViewComisionChofer::route('/{record}'), // TODO: Descomentar cuando se cree la clase Pages\ViewComisionChofer
        ];
    }

    /**
     * Navegación con badge de vigentes
     */
    public static function getNavigationBadge(): ?string
    {
        $vigentes = static::getModel()::vigentes()->count();
        return $vigentes > 0 ? (string) $vigentes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
