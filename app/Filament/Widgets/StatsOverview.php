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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class StatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use HasDateFilters;

    protected static ?int $sort = 1;

    // Polling cada 60 segundos en vez de tiempo real
    protected static ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();
        $periodoLabel = $this->getPeriodLabel();

        // Cache key basada en el rango de fechas (se invalida al cambiar periodo)
        $cacheKey = 'dashboard_stats_' . $dateRange['inicio']->format('Ymd') . '_' . $dateRange['fin']->format('Ymd');
        $cacheTTL = 300; // 5 minutos

        $data = Cache::remember($cacheKey, $cacheTTL, function () use ($dateRange, $previousRange) {
            return $this->calculateAllStats($dateRange, $previousRange);
        });

        // Charts se cachean por separado (cambian menos)
        $chartKey = 'dashboard_charts_' . $dateRange['inicio']->format('Ymd') . '_' . $dateRange['fin']->format('Ymd');
        $charts = Cache::remember($chartKey, $cacheTTL, function () use ($dateRange) {
            return $this->calculateAllCharts($dateRange);
        });

        return $this->buildStats($data, $charts, $periodoLabel);
    }

    /**
     * Calcula todas las estadísticas en queries optimizadas
     */
    private function calculateAllStats(array $dateRange, array $previousRange): array
    {
        // ============================================
        // VENTAS: 1 query para actual + conteo, 1 para anterior (por tabla)
        // Antes: 6 queries → Ahora: 4 queries
        // ============================================

        $ventasRutaData = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->selectRaw('COALESCE(SUM(total), 0) as total, COUNT(*) as cantidad')
            ->first();

        $ventasBodegaData = Venta::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->selectRaw('COALESCE(SUM(total), 0) as total, COUNT(*) as cantidad')
            ->first();

        $ventasRutaAnt = (float) ViajeVenta::whereBetween('fecha_venta', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $ventasBodegaAnt = (float) Venta::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $ventasPeriodo = (float) $ventasRutaData->total + (float) $ventasBodegaData->total;
        $cantVentas = (int) $ventasRutaData->cantidad + (int) $ventasBodegaData->cantidad;
        $ventasAnterior = $ventasRutaAnt + $ventasBodegaAnt;

        // ============================================
        // COMPRAS: 1 query combinada (antes: 3 queries → ahora: 2)
        // ============================================

        $comprasData = Compra::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->selectRaw('COALESCE(SUM(total), 0) as total, COUNT(*) as cantidad')
            ->first();

        $comprasAnt = (float) Compra::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('total');

        // ============================================
        // GASTOS OPERATIVOS: 2 queries (antes: 4)
        // ============================================

        $gastosActual = $this->getGastosOperativos($dateRange);
        $gastosAntData = $this->getGastosOperativos($previousRange);

        // ============================================
        // INVENTARIO: 1 query (sin cambios)
        // ============================================

        $inventario = BodegaProducto::where('activo', true)
            ->where('stock', '>', 0)
            ->selectRaw('COALESCE(SUM(stock * costo_promedio_actual), 0) as valor_total, COUNT(*) as productos')
            ->first();

        // ============================================
        // MERMAS: 1 query combinada con subqueries (antes: 6 → ahora: 3)
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

        // Conteos de mermas en queries separadas (necesitan joins)
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
        // COMISIONES: 2 queries (sin cambios)
        // ============================================

        $comisionesPeriodo = (float) ChoferCuentaMovimiento::where('tipo', 'comision')
            ->whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('monto');

        $comisionesAnt = (float) ChoferCuentaMovimiento::where('tipo', 'comision')
            ->whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('monto');

        return [
            'ventasPeriodo' => $ventasPeriodo,
            'cantVentas' => $cantVentas,
            'cambioVentas' => $this->calculatePercentageChange($ventasPeriodo, $ventasAnterior),
            'comprasPeriodo' => (float) $comprasData->total,
            'cantCompras' => (int) $comprasData->cantidad,
            'cambioCompras' => $this->calculatePercentageChange((float) $comprasData->total, $comprasAnt),
            'gastosOperativos' => $gastosActual['total'],
            'gastosCamion' => $gastosActual['camion'],
            'gastosBodega' => $gastosActual['bodega'],
            'cambioGastos' => $this->calculatePercentageChange($gastosActual['total'], $gastosAntData['total']),
            'valorInventario' => (float) ($inventario->valor_total ?? 0),
            'cantProductos' => (int) ($inventario->productos ?? 0),
            'mermasPeriodo' => $mermasViajes + $mermasReempaques + $mermasLotes,
            'mermasViajes' => $mermasViajes,
            'mermasReempaques' => $mermasReempaques,
            'mermasLotes' => $mermasLotes,
            'cantMermasViajes' => $cantMermasViajes,
            'cantMermasReempaques' => $cantMermasReempaques,
            'cantMermasLotes' => $cantMermasLotes,
            'comisionesPeriodo' => $comisionesPeriodo,
            'cambioComisiones' => $this->calculatePercentageChange($comisionesPeriodo, $comisionesAnt),
        ];
    }

    /**
     * Obtiene gastos operativos en 2 queries (camión + bodega)
     */
    private function getGastosOperativos(array $range): array
    {
        $camion = (float) CamionGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$range['inicio'], $range['fin']])
            ->sum('monto');

        $bodega = (float) BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$range['inicio'], $range['fin']])
            ->where(function ($q) {
                $q->where('categoria_contable', '!=', 'inversion')
                  ->orWhereNull('categoria_contable');
            })
            ->sum('monto');

        return ['camion' => $camion, 'bodega' => $bodega, 'total' => $camion + $bodega];
    }

    /**
     * Calcula todos los mini charts en batch
     */
    private function calculateAllCharts(array $dateRange): array
    {
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        $charts = ['ventas' => [], 'compras' => [], 'mermas' => [], 'comisiones' => []];

        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();

            // Ventas del día (2 queries por iteración)
            $ventasRutaDia = (float) ViajeVenta::whereDate('fecha_venta', $fecha)
                ->whereIn('estado', ['confirmada', 'completada'])
                ->sum('total');
            $ventasBodegaDia = (float) Venta::whereDate('created_at', $fecha)
                ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                ->sum('total');
            $charts['ventas'][] = $ventasRutaDia + $ventasBodegaDia;

            // Compras (1 query)
            $charts['compras'][] = (float) Compra::whereDate('created_at', $fecha)->sum('total');

            // Mermas (3 queries)
            $mermaV = (float) ViajeMerma::whereDate('created_at', $fecha)->sum('subtotal_costo');
            $mermaR = (float) (Reempaque::whereDate('created_at', $fecha)
                ->where('estado', 'completado')
                ->where('total_huevos_usados', '>', 0)
                ->selectRaw('SUM(merma * (costo_total / total_huevos_usados)) as costo_merma')
                ->value('costo_merma') ?? 0);
            $mermaL = (float) Merma::whereDate('created_at', $fecha)->sum('perdida_real_lempiras');
            $charts['mermas'][] = $mermaV + $mermaR + $mermaL;

            // Comisiones (1 query)
            $charts['comisiones'][] = (float) ChoferCuentaMovimiento::where('tipo', 'comision')
                ->whereDate('created_at', $fecha)->sum('monto');
        }

        return $charts;
    }

    /**
     * Construye los Stat cards con datos pre-calculados
     */
    private function buildStats(array $d, array $charts, string $periodoLabel): array
    {
        return [
            Stat::make("Ventas ({$periodoLabel})", 'L ' . number_format($d['ventasPeriodo'], 2))
                ->description($d['cantVentas'] . ' ventas | ' . $this->formatCambio($d['cambioVentas']))
                ->descriptionIcon($d['cambioVentas'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($d['cambioVentas'] >= 0 ? 'success' : 'danger')
                ->chart($charts['ventas']),

            Stat::make("Compras ({$periodoLabel})", 'L ' . number_format($d['comprasPeriodo'], 2))
                ->description($d['cantCompras'] . ' compras | ' . $this->formatCambio($d['cambioCompras']))
                ->descriptionIcon($d['cambioCompras'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color('warning')
                ->chart($charts['compras']),

            Stat::make("Gastos Operativos ({$periodoLabel})", 'L ' . number_format($d['gastosOperativos'], 2))
                ->description(
                    'Camion: L ' . number_format($d['gastosCamion'], 0)
                    . ' | Bodega: L ' . number_format($d['gastosBodega'], 0)
                )
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($d['cambioGastos'] <= 0 ? 'success' : 'danger'),

            Stat::make('Inventario en Bodega', 'L ' . number_format($d['valorInventario'], 2))
                ->description($d['cantProductos'] . ' productos con stock')
                ->descriptionIcon('heroicon-m-cube')
                ->color('info'),

            Stat::make("Mermas ({$periodoLabel})", 'L ' . number_format($d['mermasPeriodo'], 2))
                ->description($this->getMermasDescription(
                    $d['mermasViajes'], $d['cantMermasViajes'],
                    $d['mermasReempaques'], $d['cantMermasReempaques'],
                    $d['mermasLotes'], $d['cantMermasLotes']
                ))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($d['mermasPeriodo'] > 0 ? 'danger' : 'success')
                ->chart($charts['mermas']),

            Stat::make("Comisiones ({$periodoLabel})", 'L ' . number_format($d['comisionesPeriodo'], 2))
                ->description($this->formatCambio($d['cambioComisiones']))
                ->descriptionIcon($d['cambioComisiones'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($d['comisionesPeriodo'] > 0 ? 'warning' : 'success')
                ->chart($charts['comisiones']),
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
}
