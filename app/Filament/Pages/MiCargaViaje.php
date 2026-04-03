<?php

namespace App\Filament\Pages;

use App\Models\Viaje;
use App\Models\ViajeCarga;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;

class MiCargaViaje extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Mi Carga';
    protected static ?string $title = 'Mi Carga del Viaje';
    protected static ?string $slug = 'mi-carga-viaje';
    protected static ?string $navigationGroup = 'Mi Trabajo';
    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.mi-carga-viaje';

    /**
     * Solo choferes pueden ver esta página
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($user))
            ->where('model_has_roles.model_id', '=', $user->id)
            ->where('roles.name', 'Chofer')
            ->exists();
    }

    /**
     * Obtener el viaje activo del chofer
     */
    #[Computed]
    public function viajeActivo(): ?Viaje
    {
        $user = Auth::user();
        if (!$user) return null;

        return Viaje::where('chofer_id', $user->id)
            ->whereNotIn('estado', [Viaje::ESTADO_CERRADO, Viaje::ESTADO_CANCELADO])
            ->with(['camion', 'bodegaOrigen'])
            ->latest('fecha_salida')
            ->first();
    }

    /**
     * Tabla de cargas del viaje activo
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                ViajeCarga::query()
                    ->with(['producto', 'unidad'])
                    ->when($this->viajeActivo, function (Builder $query) {
                        $query->where('viaje_id', $this->viajeActivo->id);
                    }, function (Builder $query) {
                        // Sin viaje activo, no mostrar nada
                        $query->whereRaw('1 = 0');
                    })
            )
            ->columns([
                TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('unidad.nombre')
                    ->label('Presentación')
                    ->badge()
                    ->color('info'),

                TextColumn::make('cantidad')
                    ->label('Cantidad Cargada')
                    ->numeric(0)
                    ->alignCenter()
                    ->size('lg')
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('cantidad_vendida')
                    ->label('Vendido')
                    ->numeric(0)
                    ->alignCenter()
                    ->color('warning')
                    ->placeholder('0'),

                TextColumn::make('disponible')
                    ->label('Disponible')
                    ->alignCenter()
                    ->weight('bold')
                    ->state(function (ViajeCarga $record): string {
                        $disponible = $record->cantidad - $record->cantidad_vendida - $record->cantidad_merma - $record->cantidad_devuelta;
                        return number_format(max(0, $disponible), 0);
                    })
                    ->color(function (ViajeCarga $record): string {
                        $disponible = $record->cantidad - $record->cantidad_vendida - $record->cantidad_merma - $record->cantidad_devuelta;
                        return $disponible > 0 ? 'primary' : 'danger';
                    }),

                TextColumn::make('precio_venta_sugerido')
                    ->label('Precio Sugerido')
                    ->money('HNL')
                    ->alignEnd(),

                TextColumn::make('subtotal_costo')
                    ->label('Costo Total')
                    ->money('HNL')
                    ->alignEnd(),
            ])
            ->defaultSort('producto_id')
            ->striped()
            ->paginated(false)
            ->emptyStateHeading('Sin productos cargados')
            ->emptyStateDescription('No hay productos en la carga de este viaje.');
    }

    /**
     * Resumen de la carga para la vista
     */
    #[Computed]
    public function resumenCarga(): array
    {
        $viaje = $this->viajeActivo;
        if (!$viaje) {
            return [
                'total_items' => 0,
                'total_costo' => 0,
                'total_venta' => 0,
                'total_productos' => 0,
            ];
        }

        $cargas = ViajeCarga::where('viaje_id', $viaje->id);

        return [
            'total_items' => (int) $cargas->sum('cantidad'),
            'total_costo' => (float) $cargas->sum('subtotal_costo'),
            'total_venta' => (float) $cargas->sum('subtotal_venta'),
            'total_productos' => $cargas->count(),
        ];
    }
}
