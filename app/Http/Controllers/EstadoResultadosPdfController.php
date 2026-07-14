<?php

namespace App\Http\Controllers;

use App\Models\BodegaGasto;
use App\Models\CamionGasto;
use App\Models\ChoferCuentaMovimiento;
use App\Models\Empresa;
use App\Models\Merma;
use App\Models\Reempaque;
use App\Models\Venta;
use App\Models\ViajeMerma;
use App\Models\ViajeVenta;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class EstadoResultadosPdfController extends Controller
{
    /**
     * Tasa ISR personas juridicas Honduras
     * Art. 22 literal a) Ley ISR (Decreto-Ley No. 25)
     */
    private const TASA_ISR = 0.25;

    public function generate(Request $request): Response
    {
        $data = $this->prepararDatos($request);

        $pdf = Pdf::loadView('pdf.estado-resultados', $data)
            ->setOption(['chroot' => base_path()])
            ->setPaper('letter', 'portrait');

        return $pdf->stream("Estado_Resultados_{$data['periodoArchivo']}.pdf");
    }

    public function download(Request $request): Response
    {
        $data = $this->prepararDatos($request);

        $pdf = Pdf::loadView('pdf.estado-resultados', $data)
            ->setOption(['chroot' => base_path()])
            ->setPaper('letter', 'portrait');

        return $pdf->download("Estado_Resultados_{$data['periodoArchivo']}.pdf");
    }

    private function prepararDatos(Request $request): array
    {
        Carbon::setLocale('es');

        $periodo = $request->get('periodo', 'este_mes');

        $fechas = $this->resolverFechas($periodo, $request);
        $fechasAnteriores = $this->resolverFechasAnteriores($periodo, $fechas['inicio'], $fechas['fin']);

        $actual = $this->calcularPeriodo($fechas['inicio'], $fechas['fin']);
        $anterior = $this->calcularPeriodo($fechasAnteriores['inicio'], $fechasAnteriores['fin']);

        $variaciones = [];
        foreach ($actual as $key => $value) {
            if (is_numeric($value) && isset($anterior[$key]) && is_numeric($anterior[$key]) && (float) $anterior[$key] != 0) {
                $variaciones[$key] = round((((float) $value - (float) $anterior[$key]) / abs((float) $anterior[$key])) * 100, 1);
            }
        }

        // Desglose comparativo de comisiones por chofer (actual vs anterior).
        // Nota: el loop de variaciones de arriba ignora esta clave porque
        // is_numeric() descarta arrays; el emparejamiento se hace aqui.
        $comisionesChoferes = $this->combinarComisionesPorChofer(
            $actual['comisiones_por_chofer'],
            $anterior['comisiones_por_chofer']
        );

        $empresa = Empresa::getData();

        $logoPath = null;
        if ($empresa?->logo) {
            $path = base_path('../public_html/storage/'.$empresa->logo);
            if (! file_exists($path)) {
                $path = storage_path('app/public/'.$empresa->logo);
            }
            if (file_exists($path)) {
                $logoPath = $path;
            }
        }

        $periodoLabel = $this->getPeriodoLabel($periodo, $fechas);

        return [
            'actual' => $actual,
            'anterior' => $anterior,
            'variaciones' => $variaciones,
            'comisionesChoferes' => $comisionesChoferes,
            'empresa' => $empresa,
            'logoPath' => $logoPath,
            'periodoLabel' => $periodoLabel,
            'periodoArchivo' => str_replace([' ', '/'], '_', $periodoLabel),
            'fechaInicio' => Carbon::parse($fechas['inicio'])->format('d/m/Y'),
            'fechaFin' => Carbon::parse($fechas['fin'])->format('d/m/Y'),
            'fechaGeneracion' => now()->format('d/m/Y H:i'),
            'periodoAnteriorLabel' => $this->getPeriodoAnteriorLabel($periodo, $fechasAnteriores),
            'tasaISR' => self::TASA_ISR,
        ];
    }

    private function resolverFechas(string $periodo, Request $request): array
    {
        return match ($periodo) {
            'hoy' => [
                'inicio' => now()->startOfDay()->format('Y-m-d'),
                'fin' => now()->endOfDay()->format('Y-m-d'),
            ],
            'esta_semana' => [
                'inicio' => now()->startOfWeek()->format('Y-m-d'),
                'fin' => now()->endOfWeek()->format('Y-m-d'),
            ],
            'ultimos_7_dias' => [
                'inicio' => now()->subDays(6)->startOfDay()->format('Y-m-d'),
                'fin' => now()->endOfDay()->format('Y-m-d'),
            ],
            'ultimos_30_dias' => [
                'inicio' => now()->subDays(29)->startOfDay()->format('Y-m-d'),
                'fin' => now()->endOfDay()->format('Y-m-d'),
            ],
            'mes_anterior' => [
                'inicio' => now()->subMonth()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            'este_ano', 'este_año' => [
                'inicio' => now()->startOfYear()->format('Y-m-d'),
                'fin' => now()->endOfYear()->format('Y-m-d'),
            ],
            'personalizado' => [
                'inicio' => $request->get('fecha_inicio', now()->startOfMonth()->format('Y-m-d')),
                'fin' => $request->get('fecha_fin', now()->endOfMonth()->format('Y-m-d')),
            ],
            default => [ // este_mes
                'inicio' => now()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->endOfMonth()->format('Y-m-d'),
            ],
        };
    }

    private function resolverFechasAnteriores(string $periodo, string $inicio, string $fin): array
    {
        $fechaInicio = Carbon::parse($inicio);
        $fechaFin = Carbon::parse($fin);
        $dias = $fechaInicio->diffInDays($fechaFin) + 1;

        return match ($periodo) {
            'hoy' => [
                'inicio' => now()->subDay()->startOfDay()->format('Y-m-d'),
                'fin' => now()->subDay()->endOfDay()->format('Y-m-d'),
            ],
            'esta_semana' => [
                'inicio' => now()->subWeek()->startOfWeek()->format('Y-m-d'),
                'fin' => now()->subWeek()->endOfWeek()->format('Y-m-d'),
            ],
            'mes_anterior' => [
                'inicio' => now()->subMonths(2)->startOfMonth()->format('Y-m-d'),
                'fin' => now()->subMonths(2)->endOfMonth()->format('Y-m-d'),
            ],
            'este_ano', 'este_año' => [
                'inicio' => now()->subYear()->startOfYear()->format('Y-m-d'),
                'fin' => now()->subYear()->endOfYear()->format('Y-m-d'),
            ],
            'personalizado' => [
                'inicio' => $fechaInicio->copy()->subDays($dias)->format('Y-m-d'),
                'fin' => $fechaFin->copy()->subDays($dias)->format('Y-m-d'),
            ],
            default => [ // este_mes, ultimos_7_dias, ultimos_30_dias
                'inicio' => now()->subMonth()->startOfMonth()->format('Y-m-d'),
                'fin' => now()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
        };
    }

    private function getPeriodoLabel(string $periodo, array $fechas): string
    {
        return match ($periodo) {
            'hoy' => 'Hoy '.now()->format('d/m/Y'),
            'esta_semana' => 'Semana del '.now()->startOfWeek()->format('d/m').' al '.now()->endOfWeek()->format('d/m/Y'),
            'ultimos_7_dias' => 'Ultimos 7 dias',
            'ultimos_30_dias' => 'Ultimos 30 dias',
            'mes_anterior' => now()->subMonth()->translatedFormat('F Y'),
            'este_ano', 'este_año' => 'Ano '.now()->format('Y'),
            'personalizado' => Carbon::parse($fechas['inicio'])->format('d/m/Y').' - '.Carbon::parse($fechas['fin'])->format('d/m/Y'),
            default => now()->translatedFormat('F Y'), // este_mes
        };
    }

    private function getPeriodoAnteriorLabel(string $periodo, array $fechasAnteriores): string
    {
        return match ($periodo) {
            'hoy' => 'Dia anterior',
            'esta_semana' => 'Semana anterior',
            'mes_anterior' => now()->subMonths(2)->translatedFormat('F Y'),
            'este_ano', 'este_año' => 'Ano '.now()->subYear()->format('Y'),
            'personalizado' => Carbon::parse($fechasAnteriores['inicio'])->format('d/m/Y').' - '.Carbon::parse($fechasAnteriores['fin'])->format('d/m/Y'),
            default => now()->subMonth()->translatedFormat('F Y'),
        };
    }

    /**
     * Calcular todos los datos financieros para un periodo.
     *
     * NIIF para PYMES - Seccion 5:
     * I.   Ingresos por Actividades Ordinarias
     * II.  Costo de Ventas
     * III. Gastos de Venta
     * IV.  Gastos de Administracion
     * V.   ISR estimado (Art. 22 Ley ISR Honduras)
     *
     * ISV: Huevos (SAC 0407.00.90) y lacteos exentos
     * Art. 15 inciso a) Ley ISV (Decreto-Ley No. 24)
     */
    private function calcularPeriodo(string $fechaInicio, string $fechaFin): array
    {
        // Limites con hora para columnas DATETIME (fecha_venta, created_at).
        // Antes se filtraba con 'Y-m-d' pelado: MySQL interpreta el limite
        // superior como medianoche y el ULTIMO dia del periodo quedaba fuera.
        // Las columnas DATE (camion_gastos.fecha, bodega_gastos.fecha) siguen
        // usando $fechaInicio/$fechaFin sin hora, que ahi si es correcto.
        $inicioDia = substr($fechaInicio, 0, 10).' 00:00:00';
        $finDia = substr($fechaFin, 0, 10).' 23:59:59';

        // ==========================================================
        // I. INGRESOS POR ACTIVIDADES ORDINARIAS
        // ==========================================================

        $ventasRuta = ViajeVenta::whereBetween('fecha_venta', [$inicioDia, $finDia])
            ->whereIn('estado', ['confirmada', 'completada'])
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(impuesto), 0) as isv,
                COALESCE(SUM(descuento), 0) as descuentos,
                COALESCE(SUM(total), 0) as total,
                COUNT(*) as cantidad
            ')
            ->first();

        $ventasBodega = Venta::whereBetween('created_at', [$inicioDia, $finDia])
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->selectRaw('
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(total_isv), 0) as isv,
                COALESCE(SUM(descuento), 0) as descuentos,
                COALESCE(SUM(total), 0) as total,
                COUNT(*) as cantidad
            ')
            ->first();

        $ventasBrutas = (float) $ventasRuta->total + (float) $ventasBodega->total;
        $totalISV = (float) $ventasRuta->isv + (float) $ventasBodega->isv;
        $totalDescuentos = (float) $ventasRuta->descuentos + (float) $ventasBodega->descuentos;
        $subtotalSinISV = (float) $ventasRuta->subtotal + (float) $ventasBodega->subtotal;
        $cantidadVentas = (int) $ventasRuta->cantidad + (int) $ventasBodega->cantidad;

        $ventasNetas = $subtotalSinISV - $totalDescuentos;

        // ==========================================================
        // II. COSTO DE VENTAS
        // ==========================================================

        $costoRuta = (float) DB::table('viaje_venta_detalles')
            ->join('viaje_ventas', 'viaje_venta_detalles.viaje_venta_id', '=', 'viaje_ventas.id')
            ->whereBetween('viaje_ventas.fecha_venta', [$inicioDia, $finDia])
            ->whereIn('viaje_ventas.estado', ['confirmada', 'completada'])
            ->whereNull('viaje_ventas.deleted_at')
            ->selectRaw('COALESCE(SUM(viaje_venta_detalles.costo_unitario * viaje_venta_detalles.cantidad), 0) as total')
            ->value('total');

        $costoBodega = (float) DB::table('venta_detalles')
            ->join('ventas', 'venta_detalles.venta_id', '=', 'ventas.id')
            ->whereBetween('ventas.created_at', [$inicioDia, $finDia])
            ->whereIn('ventas.estado', ['completada', 'pendiente_pago', 'pagada'])
            ->selectRaw('COALESCE(SUM(venta_detalles.costo_unitario * venta_detalles.cantidad), 0) as total')
            ->value('total');

        $costoVentas = $costoRuta + $costoBodega;
        $utilidadBruta = $ventasNetas - $costoVentas;
        $margenBruto = $ventasNetas > 0 ? ($utilidadBruta / $ventasNetas) * 100 : 0;

        // ==========================================================
        // III. GASTOS DE VENTA
        // ==========================================================

        $gastosCamion = CamionGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->selectRaw('
                COALESCE(SUM(monto), 0) as total,
                COALESCE(SUM(CASE WHEN tipo_gasto IN ("gasolina", "diesel") THEN monto ELSE 0 END), 0) as combustible,
                COALESCE(SUM(CASE WHEN tipo_gasto = "gasolina" THEN monto ELSE 0 END), 0) as gasolina,
                COALESCE(SUM(CASE WHEN tipo_gasto = "diesel" THEN monto ELSE 0 END), 0) as diesel,
                COALESCE(SUM(CASE WHEN tipo_gasto = "mantenimiento" THEN monto ELSE 0 END), 0) as mantenimiento,
                COALESCE(SUM(CASE WHEN tipo_gasto = "reparacion" THEN monto ELSE 0 END), 0) as reparacion,
                COALESCE(SUM(CASE WHEN tipo_gasto = "viaticos" THEN monto ELSE 0 END), 0) as viaticos,
                COALESCE(SUM(CASE WHEN tipo_gasto NOT IN ("gasolina", "diesel", "mantenimiento", "reparacion", "viaticos") THEN monto ELSE 0 END), 0) as otros
            ')
            ->first();

        $gastosBodegaVenta = BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->where(function ($q) {
                $q->where('categoria_contable', 'gasto_venta')
                    ->orWhereNull('categoria_contable');
            })
            ->selectRaw('
                COALESCE(SUM(monto), 0) as total,
                COALESCE(SUM(CASE WHEN tipo_gasto = "empaque" THEN monto ELSE 0 END), 0) as empaque,
                COALESCE(SUM(CASE WHEN tipo_gasto = "cartones" THEN monto ELSE 0 END), 0) as cartones,
                COALESCE(SUM(CASE WHEN tipo_gasto NOT IN ("empaque", "cartones") THEN monto ELSE 0 END), 0) as otros
            ')
            ->first();

        $comisiones = (float) ChoferCuentaMovimiento::where('tipo', 'comision')
            ->whereBetween('created_at', [$inicioDia, $finDia])
            ->sum('monto');

        $comisionesPagadas = (float) ChoferCuentaMovimiento::where('tipo', 'pago_liquidacion')
            ->whereBetween('created_at', [$inicioDia, $finDia])
            ->sum('monto');

        // Desglose de comisiones por chofer. Usa EXACTAMENTE los mismos
        // filtros que $comisiones para que la suma del desglose siempre
        // cuadre con la linea total del reporte.
        $comisionesPorChofer = ChoferCuentaMovimiento::query()
            ->leftJoin('users', 'users.id', '=', 'chofer_cuenta_movimientos.user_id')
            ->where('chofer_cuenta_movimientos.tipo', 'comision')
            ->whereBetween('chofer_cuenta_movimientos.created_at', [$inicioDia, $finDia])
            ->groupBy('chofer_cuenta_movimientos.user_id', 'users.name')
            ->selectRaw("
                chofer_cuenta_movimientos.user_id,
                COALESCE(users.name, CONCAT('Chofer #', chofer_cuenta_movimientos.user_id)) as nombre,
                COALESCE(SUM(chofer_cuenta_movimientos.monto), 0) as total
            ")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($fila) => [
                'user_id' => (int) $fila->user_id,
                'nombre' => (string) $fila->nombre,
                'total' => (float) $fila->total,
            ])
            ->values()
            ->all();

        $mermasViajes = (float) ViajeMerma::whereBetween('created_at', [$inicioDia, $finDia])
            ->sum('subtotal_costo');

        $mermasReempaques = (float) Reempaque::whereBetween('created_at', [$inicioDia, $finDia])
            ->where('estado', 'completado')
            ->where('total_huevos_usados', '>', 0)
            ->selectRaw('COALESCE(SUM(merma * (costo_total / total_huevos_usados)), 0) as costo_merma')
            ->value('costo_merma');

        $mermasLotes = (float) Merma::whereBetween('created_at', [$inicioDia, $finDia])
            ->sum('perdida_real_lempiras');

        $totalMermas = $mermasViajes + $mermasReempaques + $mermasLotes;
        $materialEmpaque = (float) $gastosBodegaVenta->empaque + (float) $gastosBodegaVenta->cartones;
        $otrosGastosBodegaVenta = (float) $gastosBodegaVenta->otros;

        $totalGastosVenta = (float) $gastosCamion->total
            + (float) $gastosBodegaVenta->total
            + $comisiones
            + $totalMermas;

        // ==========================================================
        // IV. GASTOS DE ADMINISTRACION
        // ==========================================================

        $gastosAdmin = BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->where('categoria_contable', 'gasto_admin')
            ->selectRaw('
                COALESCE(SUM(monto), 0) as total,
                COALESCE(SUM(CASE WHEN tipo_gasto = "servicios" THEN monto ELSE 0 END), 0) as servicios,
                COALESCE(SUM(CASE WHEN tipo_gasto IN ("honorarios", "fumigacion") THEN monto ELSE 0 END), 0) as honorarios,
                COALESCE(SUM(CASE WHEN tipo_gasto NOT IN ("servicios", "honorarios", "fumigacion") THEN monto ELSE 0 END), 0) as otros
            ')
            ->first();

        $totalGastosAdmin = (float) $gastosAdmin->total;

        // ==========================================================
        // INVERSIONES (informativo, NO entra al Estado de Resultados)
        // ==========================================================

        $inversiones = (float) BodegaGasto::where('estado', 'aprobado')
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->where('categoria_contable', 'inversion')
            ->sum('monto');

        // ==========================================================
        // V. RESULTADO
        // ==========================================================

        $totalGastosOperativos = $totalGastosVenta + $totalGastosAdmin;
        $utilidadOperativa = $utilidadBruta - $totalGastosOperativos;
        $margenOperativo = $ventasNetas > 0 ? ($utilidadOperativa / $ventasNetas) * 100 : 0;

        $utilidadAntesISR = $utilidadOperativa;
        $isrEstimado = $utilidadAntesISR > 0 ? $utilidadAntesISR * self::TASA_ISR : 0;
        $utilidadNeta = $utilidadAntesISR - $isrEstimado;
        $margenNeto = $ventasNetas > 0 ? ($utilidadNeta / $ventasNetas) * 100 : 0;

        $ticketPromedio = $cantidadVentas > 0 ? $ventasBrutas / $cantidadVentas : 0;
        $costoSobreIngreso = $ventasNetas > 0 ? ($costoVentas / $ventasNetas) * 100 : 0;

        $cuentasPorCobrarRuta = (float) ViajeVenta::whereBetween('fecha_venta', [$inicioDia, $finDia])
            ->where('tipo_pago', 'credito')
            ->whereIn('estado', ['confirmada', 'completada'])
            ->sum('saldo_pendiente');

        $cuentasPorCobrarBodega = (float) Venta::whereBetween('created_at', [$inicioDia, $finDia])
            ->where('tipo_pago', 'credito')
            ->whereIn('estado', ['completada', 'pendiente_pago'])
            ->sum('saldo_pendiente');

        $cuentasPorCobrar = $cuentasPorCobrarRuta + $cuentasPorCobrarBodega;

        // Días promedio de cobro: (CxC / Ventas netas) * días del período
        $diasPeriodo = max(1, Carbon::parse($fechaInicio)->diffInDays(Carbon::parse($fechaFin)) + 1);
        $diasPromedioCobro = $ventasNetas > 0
            ? round(($cuentasPorCobrar / $ventasNetas) * $diasPeriodo, 1)
            : 0;

        return [
            'ventas_brutas' => $ventasBrutas,
            'ventas_ruta' => (float) $ventasRuta->total,
            'ventas_bodega' => (float) $ventasBodega->total,
            'isv_ventas' => $totalISV,
            'descuentos' => $totalDescuentos,
            'ventas_netas' => $ventasNetas,
            'cantidad_ventas' => $cantidadVentas,
            'cantidad_ventas_ruta' => (int) $ventasRuta->cantidad,
            'cantidad_ventas_bodega' => (int) $ventasBodega->cantidad,
            'costo_ventas' => $costoVentas,
            'costo_ruta' => $costoRuta,
            'costo_bodega' => $costoBodega,
            'utilidad_bruta' => $utilidadBruta,
            'margen_bruto' => $margenBruto,
            'gastos_camion_total' => (float) $gastosCamion->total,
            'gastos_camion_combustible' => (float) $gastosCamion->combustible,
            'gastos_camion_gasolina' => (float) $gastosCamion->gasolina,
            'gastos_camion_diesel' => (float) $gastosCamion->diesel,
            'gastos_camion_mantenimiento' => (float) $gastosCamion->mantenimiento,
            'gastos_camion_reparacion' => (float) $gastosCamion->reparacion,
            'gastos_camion_viaticos' => (float) $gastosCamion->viaticos,
            'gastos_camion_otros' => (float) $gastosCamion->otros,
            'material_empaque' => $materialEmpaque,
            'otros_gastos_bodega_venta' => $otrosGastosBodegaVenta,
            'comisiones' => $comisiones,
            'comisiones_pagadas' => $comisionesPagadas,
            'comisiones_por_chofer' => $comisionesPorChofer,
            'mermas_total' => $totalMermas,
            'mermas_viajes' => $mermasViajes,
            'mermas_reempaques' => $mermasReempaques,
            'mermas_lotes' => $mermasLotes,
            'total_gastos_venta' => $totalGastosVenta,
            'gastos_admin_total' => $totalGastosAdmin,
            'gastos_admin_servicios' => (float) $gastosAdmin->servicios,
            'gastos_admin_honorarios' => (float) $gastosAdmin->honorarios,
            'gastos_admin_otros' => (float) $gastosAdmin->otros,
            'total_gastos_operativos' => $totalGastosOperativos,
            'utilidad_operativa' => $utilidadOperativa,
            'margen_operativo' => $margenOperativo,
            'utilidad_antes_isr' => $utilidadAntesISR,
            'isr_estimado' => $isrEstimado,
            'utilidad_neta' => $utilidadNeta,
            'margen_neto' => $margenNeto,
            'ticket_promedio' => $ticketPromedio,
            'cuentas_por_cobrar' => $cuentasPorCobrar,
            'dias_promedio_cobro' => $diasPromedioCobro,
            'costo_sobre_ingreso' => $costoSobreIngreso,
            'inversiones_periodo' => $inversiones,
        ];
    }

    /**
     * Unir el desglose de comisiones por chofer de ambos periodos en una
     * sola lista para el comparativo del reporte.
     *
     * Incluye choferes con comision solo en el periodo anterior (actual 0.00,
     * var -100%) para que el comparativo no quede cojo. Orden: comision del
     * periodo actual desc, luego anterior desc, luego nombre asc.
     *
     * @param  array<int, array{user_id: int, nombre: string, total: float}>  $actuales
     * @param  array<int, array{user_id: int, nombre: string, total: float}>  $anteriores
     * @return array<int, array{nombre: string, actual: float, anterior: float, var: float|null}>
     */
    private function combinarComisionesPorChofer(array $actuales, array $anteriores): array
    {
        $anterioresPorId = [];
        foreach ($anteriores as $fila) {
            $anterioresPorId[$fila['user_id']] = $fila;
        }

        $filas = [];

        foreach ($actuales as $fila) {
            $montoAnterior = isset($anterioresPorId[$fila['user_id']])
                ? $anterioresPorId[$fila['user_id']]['total']
                : 0.0;
            unset($anterioresPorId[$fila['user_id']]);

            $filas[] = [
                'nombre' => $fila['nombre'],
                'actual' => $fila['total'],
                'anterior' => $montoAnterior,
                'var' => $montoAnterior != 0.0
                    ? round((($fila['total'] - $montoAnterior) / abs($montoAnterior)) * 100, 1)
                    : null,
            ];
        }

        // Choferes con comision solo en el periodo anterior
        foreach ($anterioresPorId as $fila) {
            $filas[] = [
                'nombre' => $fila['nombre'],
                'actual' => 0.0,
                'anterior' => $fila['total'],
                'var' => -100.0,
            ];
        }

        usort($filas, function (array $a, array $b) {
            return [$b['actual'], $b['anterior'], $a['nombre']]
                <=> [$a['actual'], $a['anterior'], $b['nombre']];
        });

        return $filas;
    }
}
