<?php

namespace App\Filament\Widgets;

use App\Models\Venta;
use App\Models\ViajeVenta;
use App\Models\ViajeMerma;
use App\Models\Merma;
use App\Models\Reempaque;
use App\Models\Compra;
use App\Models\BodegaProducto;
use App\Models\CamionGasto;
use App\Models\BodegaGasto;
use App\Models\ChoferCuentaMovimiento;
use App\Filament\Widgets\Concerns\HasDateFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class StatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();
        $periodoLabel = $this->getPeriodLabel();

        // ============================================
        // 1. VENTAS DEL PERIODO (Ruta + Bodega)
        // ============================================

        $ventasRuta = (float) ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $ventasBodega = (float) Venta::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $ventasPeriodo = $ventasRuta + $ventasBodega;

        $ventasRutaAnt = (float) ViajeVenta::whereBetween('fecha_venta', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $ventasBodegaAnt = (float) Venta::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $ventasAnterior = $ventasRutaAnt + $ventasBodegaAnt;
        $cambioVentas = $this->calculatePercentageChange($ventasPeriodo, $ventasAnterior);

        $cantVentasRuta = (int) ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->count();

        $cantVentasBodega = (int) Venta::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->count();

        $cantVentas = $cantVentasRuta + $cantVentasBodega;

        // ============================================
        // 2. COMPRAS A PROVEEDORES
        // ============================================

        $comprasPeriodo = (float) Compra::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('total');

        $comprasAnt = (float) Compra::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('total');

        $cambioCompras = $this->calculatePercentageChange($comprasPeriodo, $comprasAnt);

        $cantCompras = (int) Compra::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->count();

        // ============================================
        // 3. GASTOS OPERATIVOS (Camion + Bodega, sin inversiones)
        // ============================================

        $gastosCamion = (float) CamionGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        $gastosBodega = (float) BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$dateRange['inicio'], $dateRange['fin']])
            ->where(function ($q) {
                $q->where('categoria_contable', '!=', 'inversion')
                  ->orWhereNull('categoria_contable');
            })
            ->sum('monto');

        $gastosOperativos = $gastosCamion + $gastosBodega;

        $gastosCamionAnt = (float) CamionGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('monto');

        $gastosBodegaAnt = (float) BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$previousRange['inicio'], $previousRange['fin']])
            ->where(function ($q) {
                $q->where('categoria_contable', '!=', 'inversion')
                  ->orWhereNull('categoria_contable');
            })
            ->sum('monto');

        $gastosOperativosAnt = $gastosCamionAnt + $gastosBodegaAnt;
        $cambioGastos = $this->calculatePercentageChange($gastosOperativos, $gastosOperativosAnt);

        // ============================================
        // 4. INVENTARIO EN BODEGA (valor actual)
        // ============================================

        $inventario = BodegaProducto::where('activo', true)
            ->where('stock', '>', 0)
            ->selectRaw('SUM(stock * costo_promedio_actual) as valor_total, COUNT(*) as productos')
            ->first();

        $valorInventario = (float) ($inventario->valor_total ?? 0);
        $cantProductos = (int) ($inventario->productos ?? 0);

        // ============================================
        // 5. MERMAS DEL PERIODO
        // ============================================

        $mermasViajes = (float) ViajeMerma::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('subtotal_costo');

        $mermasReempaques = (float) (Reempaque::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completado')
            ->where('total_huevos_usados', '>', 0)
            ->selectRaw('SUM(merma * (costo_total / total_huevos_usados)) as costo_merma')
            ->value('costo_merma') ?? 0);

        $mermasLotes = (float) Merma::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('perdida_real_lempiras');

        $mermasPeriodo = $mermasViajes + $mermasReempaques + $mermasLotes;

        $cantMermasViajes = (float) (ViajeMerma::whereBetween('viaje_mermas.created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->join('productos', 'viaje_mermas.producto_id', '=', 'productos.id')
            ->selectRaw('SUM(viaje_mermas.cantidad * COALESCE(productos.unidades_por_bulto, 1)) as total_unidades')
            ->value('total_unidades') ?? 0);

        $cantMermasReempaques = (float) Reempaque::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completado')
            ->where('merma', '>', 0)
            ->sum('merma');

        $cantMermasLotes = (float) Merma::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('cantidad_huevos');

        // ============================================
        // 6. VENTAS HOY
        // ============================================

        $ventasRutaHoy = (float) ViajeVenta::whereDate('fecha_venta', today())
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $ventasBodegaHoy = (float) Venta::whereDate('created_at', today())
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $ventasHoy = $ventasRutaHoy + $ventasBodegaHoy;

        // ============================================
        // STATS
        // ============================================

        return [
            // --- FILA 1: 4 cards principales ---

            Stat::make("Ventas ({$periodoLabel})", 'L ' . number_format($ventasPeriodo, 2))
                ->description($cantVentas . ' ventas | ' . $this->formatCambio($cambioVentas))
                ->descriptionIcon($cambioVentas >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($cambioVentas >= 0 ? 'success' : 'danger')
                ->chart($this->getChartDiario($dateRange, 'ventas')),

            Stat::make("Compras ({$periodoLabel})", 'L ' . number_format($comprasPeriodo, 2))
                ->description($cantCompras . ' compras | ' . $this->formatCambio($cambioCompras))
                ->descriptionIcon($cambioCompras >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color('warning')
                ->chart($this->getChartDiario($dateRange, 'compras')),

            Stat::make("Gastos Operativos ({$periodoLabel})", 'L ' . number_format($gastosOperativos, 2))
                ->description(
                    'Camion: L ' . number_format($gastosCamion, 0)
                    . ' | Bodega: L ' . number_format($gastosBodega, 0)
                )
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($cambioGastos <= 0 ? 'success' : 'danger'),

            Stat::make('Inventario en Bodega', 'L ' . number_format($valorInventario, 2))
                ->description($cantProductos . ' productos con stock')
                ->descriptionIcon('heroicon-m-cube')
                ->color('info'),

            // --- FILA 2: Detalle operativo ---

            Stat::make("Mermas ({$periodoLabel})", 'L ' . number_format($mermasPeriodo, 2))
                ->description($this->getMermasDescription(
                    $mermasViajes, $cantMermasViajes,
                    $mermasReempaques, $cantMermasReempaques,
                    $mermasLotes, $cantMermasLotes
                ))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($mermasPeriodo > 0 ? 'danger' : 'success')
                ->chart($this->getChartDiario($dateRange, 'mermas')),

            Stat::make('Ventas Hoy', 'L ' . number_format($ventasHoy, 2))
                ->description('Ruta: L ' . number_format($ventasRutaHoy, 0) . ' | Bodega: L ' . number_format($ventasBodegaHoy, 0))
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('info'),
        ];
    }

    // ============================================
    // HELPERS
    // ============================================

    private function formatCambio(float $cambio): string
    {
        $abs = number_format(abs($cambio), 1);
        return $cambio >= 0
            ? "{$abs}% mas que periodo anterior"
            : "{$abs}% menos que periodo anterior";
    }

    protected function getMermasDescription(
        float $costoViajes, float $unidadesViajes,
        float $costoReempaques, float $unidadesReempaques,
        float $costoLotes = 0, float $unidadesLotes = 0
    ): string {
        $partes = [];

        if ($costoViajes > 0 || $unidadesViajes > 0) {
            $partes[] = 'Viajes: L ' . number_format($costoViajes, 2) . ' (' . ((int) round($unidadesViajes)) . ' huevos)';
        }
        if ($costoReempaques > 0 || $unidadesReempaques > 0) {
            $partes[] = 'Reempaques: L ' . number_format($costoReempaques, 2) . ' (' . ((int) round($unidadesReempaques)) . ' huevos)';
        }
        if ($costoLotes > 0 || $unidadesLotes > 0) {
            $partes[] = 'Lotes: L ' . number_format($costoLotes, 2) . ' (' . ((int) round($unidadesLotes)) . ' huevos)';
        }

        return empty($partes) ? 'Sin mermas en el periodo' : implode(' | ', $partes);
    }

    private function getChartDiario(array $dateRange, string $tipo): array
    {
        $datos = [];
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);

        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();

            $datos[] = match ($tipo) {
                'ventas' => $this->getVentasDia($fecha),
                'compras' => (float) Compra::whereDate('created_at', $fecha)->sum('total'),
                'mermas' => $this->getMermasDia($fecha),
                default => 0,
            };
        }

        return $datos;
    }

    private function getVentasDia(string $fecha): float
    {
        $ruta = (float) ViajeVenta::whereDate('fecha_venta', $fecha)
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $bodega = (float) Venta::whereDate('created_at', $fecha)
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        return $ruta + $bodega;
    }

    private function getMermasDia(string $fecha): float
    {
        $viajes = (float) ViajeMerma::whereDate('created_at', $fecha)->sum('subtotal_costo');

        $reempaques = (float) (Reempaque::whereDate('created_at', $fecha)
            ->where('estado', 'completado')
            ->where('total_huevos_usados', '>', 0)
            ->selectRaw('SUM(merma * (costo_total / total_huevos_usados)) as costo_merma')
            ->value('costo_merma') ?? 0);

        $lotes = (float) Merma::whereDate('created_at', $fecha)->sum('perdida_real_lempiras');

        return $viajes + $reempaques + $lotes;
    }
}