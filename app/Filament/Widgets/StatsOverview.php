<?php

namespace App\Filament\Widgets;

use App\Models\Venta;
use App\Models\ViajeVenta;
use App\Models\ViajeMerma;
use App\Models\Reempaque;
use App\Models\Compra;
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
    
    protected function getStats(): array
    {
        // Obtener rango de fechas del filtro
        $dateRange = $this->getFilteredDateRange();
        $previousRange = $this->getPreviousPeriodDateRange();

        // ============================================
        // VENTAS DEL PERÍODO (BODEGA + RUTA)
        // ============================================
        
        // Ventas en RUTA (ViajeVenta)
        $ventasRutaPeriodo = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        // Ventas en BODEGA (Venta)
        $ventasBodegaPeriodo = Venta::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        // TOTAL VENTAS = Ruta + Bodega
        $ventasPeriodo = $ventasRutaPeriodo + $ventasBodegaPeriodo;

        // Ventas del período anterior para comparación
        $ventasRutaAnterior = ViajeVenta::whereBetween('fecha_venta', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $ventasBodegaAnterior = Venta::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $ventasPeriodoAnterior = $ventasRutaAnterior + $ventasBodegaAnterior;

        // Calcular porcentaje de cambio en ventas
        $cambioVentas = $this->calculatePercentageChange($ventasPeriodo, $ventasPeriodoAnterior);

        // ============================================
        // COMPRAS DEL PERÍODO
        // ============================================
        
        $comprasPeriodo = Compra::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('total');

        $comprasPeriodoAnterior = Compra::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('total');

        $cambioCompras = $this->calculatePercentageChange($comprasPeriodo, $comprasPeriodoAnterior);

        // ============================================
        // MERMAS DEL PERÍODO (VIAJES + REEMPAQUES)
        // ============================================
        
        // Mermas de VIAJES (ya tienen costo calculado)
        $mermasViajesPeriodo = ViajeMerma::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->sum('subtotal_costo');

        // Mermas de REEMPAQUES (huevos × costo ORIGINAL, no inflado)
        $mermasReempaquesPeriodo = Reempaque::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completado')
            ->where('total_huevos_usados', '>', 0)
            ->selectRaw('SUM(merma * (costo_total / total_huevos_usados)) as costo_merma')
            ->value('costo_merma') ?? 0;

        // TOTAL MERMAS
        $mermasPeriodo = $mermasViajesPeriodo + $mermasReempaquesPeriodo;

        // Mermas del período anterior
        $mermasViajesAnterior = ViajeMerma::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->sum('subtotal_costo');

        $mermasReempaquesAnterior = Reempaque::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->where('estado', 'completado')
            ->where('total_huevos_usados', '>', 0)
            ->selectRaw('SUM(merma * (costo_total / total_huevos_usados)) as costo_merma')
            ->value('costo_merma') ?? 0;

        $mermasPeriodoAnterior = $mermasViajesAnterior + $mermasReempaquesAnterior;

        $cambioMermas = $this->calculatePercentageChange($mermasPeriodo, $mermasPeriodoAnterior);

        // ============================================
        // CANTIDADES DE MERMAS EN UNIDADES INDIVIDUALES
        // ============================================
        
        // Viajes: convertir a unidades individuales (huevos)
        // Multiplicar cantidad × unidades_por_bulto del producto
        $cantidadMermasViajes = (float) ViajeMerma::whereBetween('viaje_mermas.created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->join('productos', 'viaje_mermas.producto_id', '=', 'productos.id')
            ->selectRaw('SUM(viaje_mermas.cantidad * COALESCE(productos.unidades_por_bulto, 1)) as total_unidades')
            ->value('total_unidades') ?? 0;
        
        // Reempaques: ya son huevos individuales
        $cantidadMermasReempaques = (float) Reempaque::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->where('estado', 'completado')
            ->where('merma', '>', 0)
            ->sum('merma');

        // ============================================
        // GANANCIAS (VENTAS - COSTO)
        // ============================================
        
        // Costo de ventas en RUTA
        $costoVentasRuta = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with('detalles')
            ->get()
            ->sum(function ($venta) {
                return $venta->detalles->sum(function ($detalle) {
                    return $detalle->costo_unitario * $detalle->cantidad;
                });
            });

        // Costo de ventas en BODEGA
        $costoVentasBodega = Venta::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->with('detalles')
            ->get()
            ->sum(function ($venta) {
                return $venta->detalles->sum(function ($detalle) {
                    return $detalle->costo_unitario * $detalle->cantidad;
                });
            });

        $costoVentasPeriodo = $costoVentasRuta + $costoVentasBodega;
        $gananciasPeriodo = $ventasPeriodo - $costoVentasPeriodo;

        // Ganancias del período anterior
        $costoVentasRutaAnterior = ViajeVenta::whereBetween('fecha_venta', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->with('detalles')
            ->get()
            ->sum(function ($venta) {
                return $venta->detalles->sum(function ($detalle) {
                    return $detalle->costo_unitario * $detalle->cantidad;
                });
            });

        $costoVentasBodegaAnterior = Venta::whereBetween('created_at', [$previousRange['inicio'], $previousRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->with('detalles')
            ->get()
            ->sum(function ($venta) {
                return $venta->detalles->sum(function ($detalle) {
                    return $detalle->costo_unitario * $detalle->cantidad;
                });
            });

        $costoVentasPeriodoAnterior = $costoVentasRutaAnterior + $costoVentasBodegaAnterior;
        $gananciasPeriodoAnterior = $ventasPeriodoAnterior - $costoVentasPeriodoAnterior;

        $cambioGanancias = $this->calculatePercentageChange($gananciasPeriodo, $gananciasPeriodoAnterior);

        // ============================================
        // VENTAS HOY (RUTA + BODEGA)
        // ============================================
        
        $ventasRutaHoy = ViajeVenta::whereDate('fecha_venta', today())
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('total');

        $ventasBodegaHoy = Venta::whereDate('created_at', today())
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->sum('total');

        $ventasHoy = $ventasRutaHoy + $ventasBodegaHoy;

        // Cantidad de ventas del período (ambos modelos)
        $cantidadVentasRuta = ViajeVenta::whereBetween('fecha_venta', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->count();

        $cantidadVentasBodega = Venta::whereBetween('created_at', [$dateRange['inicio'], $dateRange['fin']])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->count();

        $cantidadVentasPeriodo = $cantidadVentasRuta + $cantidadVentasBodega;

        $periodoLabel = $this->getPeriodLabel();

        return [
            Stat::make("Ventas ({$periodoLabel})", 'L ' . number_format($ventasPeriodo, 2))
                ->description($cambioVentas >= 0 
                    ? number_format(abs($cambioVentas), 1) . '% más que período anterior' 
                    : number_format(abs($cambioVentas), 1) . '% menos que período anterior')
                ->descriptionIcon($cambioVentas >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($cambioVentas >= 0 ? 'success' : 'danger')
                ->chart($this->getVentasChartData()),

            Stat::make("Compras ({$periodoLabel})", 'L ' . number_format($comprasPeriodo, 2))
                ->description($cambioCompras >= 0 
                    ? number_format(abs($cambioCompras), 1) . '% más que período anterior' 
                    : number_format(abs($cambioCompras), 1) . '% menos que período anterior')
                ->descriptionIcon($cambioCompras >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($cambioCompras <= 0 ? 'success' : 'warning')
                ->chart($this->getComprasChartData()),

            Stat::make("Ganancias ({$periodoLabel})", 'L ' . number_format($gananciasPeriodo, 2))
                ->description($cambioGanancias >= 0 
                    ? number_format(abs($cambioGanancias), 1) . '% más que período anterior' 
                    : number_format(abs($cambioGanancias), 1) . '% menos que período anterior')
                ->descriptionIcon($cambioGanancias >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($gananciasPeriodo >= 0 ? 'success' : 'danger'),

            Stat::make("Mermas ({$periodoLabel})", 'L ' . number_format($mermasPeriodo, 2))
                ->description($this->getMermasDescription(
                    $mermasViajesPeriodo, 
                    $cantidadMermasViajes, 
                    $mermasReempaquesPeriodo, 
                    $cantidadMermasReempaques
                ))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($mermasPeriodo > 0 ? 'danger' : 'success')
                ->chart($this->getMermasChartData()),

            Stat::make('Ventas Hoy', 'L ' . number_format($ventasHoy, 2))
                ->description($cantidadVentasPeriodo . ' ventas en el período')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('info'),
        ];
    }

    /**
     * Obtener descripción para el stat de mermas
     * Muestra: Viajes: L X (Y huevos) | Reempaques: L X (Y huevos)
     */
    protected function getMermasDescription(
        float $costoViajes, 
        float $unidadesViajes, 
        float $costoReempaques, 
        float $unidadesReempaques
    ): string {
        $partes = [];
        
        if ($costoViajes > 0 || $unidadesViajes > 0) {
            $unidadesFormateadas = (int) round($unidadesViajes);
            $partes[] = "Viajes: L " . number_format($costoViajes, 2) . " ({$unidadesFormateadas} huevos)";
        }
        
        if ($costoReempaques > 0 || $unidadesReempaques > 0) {
            $unidadesFormateadas = (int) round($unidadesReempaques);
            $partes[] = "Reempaques: L " . number_format($costoReempaques, 2) . " ({$unidadesFormateadas} huevos)";
        }
        
        if (empty($partes)) {
            return 'Sin mermas en el período 🎉';
        }
        
        return implode(' | ', $partes);
    }

    /**
     * Obtener datos para el mini chart de mermas
     */
    protected function getMermasChartData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $mermas = [];
        
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        
        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();
            
            // Mermas de viajes del día
            $mermasViajes = ViajeMerma::whereDate('created_at', $fecha)
                ->sum('subtotal_costo');
            
            // Mermas de reempaques del día (usando costo ORIGINAL)
            $mermasReempaques = Reempaque::whereDate('created_at', $fecha)
                ->where('estado', 'completado')
                ->where('total_huevos_usados', '>', 0)
                ->selectRaw('SUM(merma * (costo_total / total_huevos_usados)) as costo_merma')
                ->value('costo_merma') ?? 0;
            
            $mermas[] = (float) ($mermasViajes + $mermasReempaques);
        }

        return $mermas;
    }

    /**
     * Obtener datos para el mini chart de ventas (RUTA + BODEGA)
     */
    protected function getVentasChartData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $ventas = [];
        
        // Limitar a máximo 7 puntos para el mini chart
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        
        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();
            
            // Ventas Ruta del día
            $totalRuta = ViajeVenta::whereDate('fecha_venta', $fecha)
                ->whereIn('estado', ['confirmada', 'completada'])
                ->sum('total');
            
            // Ventas Bodega del día
            $totalBodega = Venta::whereDate('created_at', $fecha)
                ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
                ->sum('total');
            
            $ventas[] = (float) ($totalRuta + $totalBodega);
        }

        return $ventas;
    }

    /**
     * Obtener datos para el mini chart de compras
     */
    protected function getComprasChartData(): array
    {
        $dateRange = $this->getFilteredDateRange();
        $compras = [];
        
        $diffDays = min($dateRange['inicio']->diffInDays($dateRange['fin']), 6);
        
        for ($i = $diffDays; $i >= 0; $i--) {
            $fecha = $dateRange['fin']->copy()->subDays($i)->toDateString();
            $total = Compra::whereDate('created_at', $fecha)->sum('total');
            $compras[] = (float) $total;
        }

        return $compras;
    }
}