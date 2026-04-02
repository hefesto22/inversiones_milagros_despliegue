@php
    /*
    |--------------------------------------------------------------------------
    | Estado de Resultados - PDF Template
    |--------------------------------------------------------------------------
    | Conforme NIIF para PYMES - Seccion 5
    | Metodo: Funcion de los gastos
    |--------------------------------------------------------------------------
    */

    $lps = fn($v) => 'L ' . number_format((float)$v, 2);
    $d = $actual;
    $a = $anterior;
    $v = $variaciones;
    $vn = (float) $d['ventas_netas'];

    $pct = function($valor) use ($vn) {
        if ($vn == 0) return '-';
        return number_format(((float)$valor / $vn) * 100, 1) . '%';
    };

    $varHtml = function($key, $invertido = false) use ($v) {
        if (!isset($v[$key]) || $v[$key] == 0) return '-';
        $val = $v[$key];
        $arrow = $val > 0 ? '+' : '';
        if ($invertido) {
            $class = $val > 0 ? 'var-cost-up' : 'var-cost-down';
        } else {
            $class = $val > 0 ? 'var-positive' : 'var-negative';
        }
        return '<span class="'.$class.'">'.$arrow.number_format($val, 1).'%</span>';
    };

    $claseMargenBruto  = $d['margen_bruto'] >= 15 ? 'color-green' : 'color-yellow';
    $claseMargenOp     = $d['margen_operativo'] >= 8 ? 'color-green' : ($d['margen_operativo'] >= 0 ? 'color-yellow' : 'color-red');
    $claseCxC          = $d['cuentas_por_cobrar'] > 0 ? 'color-yellow' : 'color-green';
    $claseDPC          = ($d['dias_promedio_cobro'] ?? 0) <= 15 ? 'color-green' : (($d['dias_promedio_cobro'] ?? 0) <= 30 ? 'color-yellow' : 'color-red');
    $claseCostoIng     = $d['costo_sobre_ingreso'] <= 85 ? 'color-green' : 'color-red';

    $claseUtilidadBruta   = 'total-row ' . ($d['utilidad_bruta'] >= 0 ? 'total-row-positive' : 'total-row-negative');
    $clasePctBruta        = 'pct-col ' . ($d['utilidad_bruta'] >= 0 ? 'color-green' : 'color-red');
    $claseUtilidadOp      = 'total-row ' . ($d['utilidad_operativa'] >= 0 ? 'total-row-positive' : 'total-row-negative');
    $clasePctOp           = 'pct-col ' . ($d['utilidad_operativa'] >= 0 ? 'color-green' : 'color-red');
    $claseResultadoFinal  = 'final-result ' . ($d['utilidad_neta'] >= 0 ? 'final-positive' : 'final-negative');
    $clasePctFinal        = 'pct-col pct-final ' . ($d['utilidad_neta'] >= 0 ? 'color-green' : 'color-red');

    $varVentasRuta    = $varHtml('ventas_ruta');
    $varVentasBodega  = $varHtml('ventas_bodega');
    $varVentasBrutas  = $varHtml('ventas_brutas');
    $varVentasNetas   = $varHtml('ventas_netas');
    $varCostoVentas   = $varHtml('costo_ventas', true);
    $varUtilidadBruta = $varHtml('utilidad_bruta');
    $varCamionTotal       = $varHtml('gastos_camion_total', true);
    $varCombustible       = $varHtml('gastos_camion_combustible', true);
    $varGasolina          = $varHtml('gastos_camion_gasolina', true);
    $varDiesel            = $varHtml('gastos_camion_diesel', true);
    $varMantenimiento     = $varHtml('gastos_camion_mantenimiento', true);
    $varReparacion        = $varHtml('gastos_camion_reparacion', true);
    $varViaticos          = $varHtml('gastos_camion_viaticos', true);
    $varCamionOtros       = $varHtml('gastos_camion_otros', true);
    $varComisiones        = $varHtml('comisiones', true);
    $varMermas            = $varHtml('mermas_total', true);
    $varMermasViajes      = $varHtml('mermas_viajes', true);
    $varMermasReempaques  = $varHtml('mermas_reempaques', true);
    $varMermasLotes       = $varHtml('mermas_lotes', true);
    $varGastosVenta   = $varHtml('total_gastos_venta', true);
    $varUtilidadOp    = $varHtml('utilidad_operativa');
    $varUtilidadNeta  = $varHtml('utilidad_neta');

    $empresaNombre    = $empresa?->nombre ?? 'OPOA';
    $empresaRtn       = $empresa?->rtn ?? '';
    $empresaDireccion = $empresa?->direccion ?? '';
    $empresaTelefono  = $empresa?->telefono ?? '';
    $empresaCorreo    = $empresa?->correo_electronico ?? '';

    $inversionesPeriodo             = $d['inversiones_periodo'] ?? 0;
    $tasaISRPct                     = number_format($tasaISR * 100);
    $materialEmpaqueAnterior        = $a['material_empaque'] ?? 0;
    $otrosGastosBodegaVentaAnterior = $a['otros_gastos_bodega_venta'] ?? 0;

    $mostrarISV              = ($d['isv_ventas'] > 0 || $a['isv_ventas'] > 0);
    $mostrarDescuentos       = ($d['descuentos'] > 0 || $a['descuentos'] > 0);
    $mostrarCombustible      = ($d['gastos_camion_combustible'] > 0 || $a['gastos_camion_combustible'] > 0);
    $mostrarGasolina         = ($d['gastos_camion_gasolina'] > 0 || $a['gastos_camion_gasolina'] > 0);
    $mostrarDiesel           = ($d['gastos_camion_diesel'] > 0 || $a['gastos_camion_diesel'] > 0);
    $mostrarMantenimiento    = ($d['gastos_camion_mantenimiento'] > 0 || $a['gastos_camion_mantenimiento'] > 0);
    $mostrarReparacion       = ($d['gastos_camion_reparacion'] > 0 || $a['gastos_camion_reparacion'] > 0);
    $mostrarViaticos         = ($d['gastos_camion_viaticos'] > 0 || $a['gastos_camion_viaticos'] > 0);
    $mostrarCamionOtros      = ($d['gastos_camion_otros'] > 0 || $a['gastos_camion_otros'] > 0);
    $mostrarMaterialEmpaque  = ($d['material_empaque'] > 0 || $materialEmpaqueAnterior > 0);
    $mostrarOtrosBodegaVenta = ($d['otros_gastos_bodega_venta'] > 0);
    $mostrarComisionesPagadas = ($d['comisiones_pagadas'] > 0 || $a['comisiones_pagadas'] > 0);
    $mostrarMermasViajes     = ($d['mermas_viajes'] > 0 || $a['mermas_viajes'] > 0);
    $mostrarMermasReempaques = ($d['mermas_reempaques'] > 0 || $a['mermas_reempaques'] > 0);
    $mostrarMermasLotes      = ($d['mermas_lotes'] > 0 || $a['mermas_lotes'] > 0);
    $mostrarAdminHonorarios  = ($d['gastos_admin_honorarios'] > 0 || $a['gastos_admin_honorarios'] > 0);
    $mostrarAdminServicios   = ($d['gastos_admin_servicios'] > 0 || $a['gastos_admin_servicios'] > 0);
    $mostrarAdminOtros       = ($d['gastos_admin_otros'] > 0 || $a['gastos_admin_otros'] > 0);
    $sinGastosAdmin          = ($d['gastos_admin_total'] == 0 && $a['gastos_admin_total'] == 0);
    $mostrarISR              = ($d['isr_estimado'] > 0 || $a['isr_estimado'] > 0);
    $mostrarInversiones      = ($inversionesPeriodo > 0);

    $margenBrutoTexto = 'Margen Bruto: ' . number_format($d['margen_bruto'], 1) . '%';
    if ($a['margen_bruto'] > 0) $margenBrutoTexto .= ' | Anterior: ' . number_format($a['margen_bruto'], 1) . '%';
    $margenOpTexto = 'Margen Operativo: ' . number_format($d['margen_operativo'], 1) . '%';
    if ($a['margen_operativo'] != 0) $margenOpTexto .= ' | Anterior: ' . number_format($a['margen_operativo'], 1) . '%';
    $margenNetoTexto = 'Margen Neto: ' . number_format($d['margen_neto'], 1) . '%';

    $footerInversiones = $mostrarInversiones ? ' | Inversiones excluidas: L ' . number_format($inversionesPeriodo, 2) : '';

    $headerInfo = '';
    if ($empresaRtn) $headerInfo .= 'RTN: ' . $empresaRtn . "\n";
    if ($empresaDireccion) $headerInfo .= $empresaDireccion . "\n";
    if ($empresaTelefono) $headerInfo .= 'Tel: ' . $empresaTelefono;
    if ($empresaCorreo) $headerInfo .= ' | ' . $empresaCorreo;

    $isrLabel = '(-) ISR estimado (' . $tasaISRPct . '% - Art. 22 Ley ISR)';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estado de Resultados - {{ $periodoLabel }}</title>
    <style>
        @page { margin: 18px 25px 38px 25px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 7.5pt; color: #1a1a1a; line-height: 1.25; }

        .header { border-bottom: 2px solid #1a1a1a; padding-bottom: 6px; margin-bottom: 6px; }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .company-logo { max-height: 38px; max-width: 85px; }
        .company-name { font-size: 11pt; font-weight: bold; color: #1a1a1a; }
        .company-info { font-size: 6.5pt; color: #444; line-height: 1.2; white-space: pre-line; }
        .report-title { font-size: 10pt; font-weight: bold; text-align: right; color: #1a1a1a; letter-spacing: 1px; text-transform: uppercase; }
        .report-subtitle { font-size: 7.5pt; text-align: right; color: #555; margin-top: 1px; }
        .report-niif { font-size: 5.5pt; text-align: right; color: #999; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.4px; }

        .period-info { background-color: #f5f5f5; border: 1px solid #ddd; padding: 3px 7px; margin-bottom: 6px; font-size: 6.5pt; }
        .period-info table { width: 100%; }
        .period-info td { padding: 1px 0; }
        .period-label { font-weight: bold; width: 100px; }

        .main-table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
        .main-table th { background-color: #1a1a1a; color: #fff; font-size: 6pt; font-weight: bold; padding: 3px 4px; text-align: right; text-transform: uppercase; letter-spacing: 0.4px; }
        .main-table th:first-child { text-align: left; width: 40%; }
        .main-table th.col-pct { width: 6%; }
        .main-table td { padding: 2px 4px; font-size: 7pt; border-bottom: 1px solid #e5e5e5; }
        .main-table td:not(:first-child) { text-align: right; font-family: 'Courier New', monospace; font-size: 7pt; }

        .pct-col { font-size: 6pt; color: #888; }
        .pct-final { font-size: 7pt; }

        .section-header td { background-color: #e8e8e8; font-weight: bold; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.4px; padding: 3px 4px; border-bottom: 1px solid #bbb; color: #333; font-family: 'Helvetica', 'Arial', sans-serif; }
        .detail-row td:first-child { padding-left: 18px; }
        .sub-detail-row td:first-child { padding-left: 32px; font-size: 6pt; color: #777; }
        .sub-detail-row td { font-size: 6pt; color: #777; border-bottom: 1px solid #f0f0f0; }
        .subtotal-row td { font-weight: bold; border-bottom: 1.5px solid #999; padding-top: 2px; padding-bottom: 2px; }
        .deduction-row td:not(:first-child) { color: #c00; }
        .deduction-row td:first-child { padding-left: 18px; color: #c00; }
        .total-row td { font-weight: bold; font-size: 8pt; border-top: 2px solid #1a1a1a; border-bottom: 2px solid #1a1a1a; padding: 3px 4px; }
        .total-row-positive td { color: #006600; }
        .total-row-negative td { color: #cc0000; }
        .margin-row td { font-size: 6pt; color: #777; font-style: italic; border-bottom: none; padding: 1px 4px; font-family: 'Helvetica', sans-serif; }
        .final-result td { font-weight: bold; font-size: 8.5pt; border-top: 3px double #1a1a1a; border-bottom: 3px double #1a1a1a; padding: 4px; background-color: #f9f9f9; }
        .final-positive td { color: #006600; }
        .final-negative td { color: #cc0000; }

        .var-positive { color: #006600; font-size: 6pt; }
        .var-negative { color: #cc0000; font-size: 6pt; }
        .var-cost-up { color: #cc0000; font-size: 6pt; }
        .var-cost-down { color: #006600; font-size: 6pt; }
        .color-green { color: #006600; }
        .color-yellow { color: #cc6600; }
        .color-red { color: #cc0000; }
        .color-deduction { color: #c00; }

        .page-break { page-break-before: always; }

        .kpi-section { margin-top: 20px; border-top: 2px solid #1a1a1a; padding-top: 10px; }
        .kpi-title { font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .kpi-table { width: 100%; border-collapse: collapse; }
        .kpi-table td { padding: 5px 5px; border: 1px solid #ddd; text-align: center; font-size: 7.5pt; }
        .kpi-label { background-color: #f5f5f5; font-weight: bold; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.3px; }
        .kpi-value { font-family: 'Courier New', monospace; font-size: 10pt; font-weight: bold; }
        .kpi-sub { font-size: 7pt; color: #888; }

        .signatures-table { width: 100%; margin-top: 40px; }
        .signatures-table td { width: 33%; text-align: center; vertical-align: bottom; padding: 0 20px; }
        .sig-line { border-top: 1px solid #333; padding-top: 5px; margin-top: 30px; }
        .sig-name { font-size: 8pt; font-weight: bold; }
        .sig-title { font-size: 7pt; color: #777; }

        .notes-section { margin-top: 25px; padding: 8px 10px; background-color: #fafafa; border: 1px solid #e0e0e0; font-size: 7pt; color: #666; line-height: 1.6; }
        .notes-title { font-weight: bold; font-size: 7.5pt; color: #444; margin-bottom: 4px; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 5.5pt; color: #999; border-top: 1px solid #ddd; padding-top: 2px; padding-bottom: 2px; }
        .footer-company { font-weight: bold; color: #666; }
        .text-muted { color: #999; font-style: italic; }
    </style>
</head>
<body>

    <div class="footer">
        <span class="footer-company">{{ $empresaNombre }}</span>
        {{ $empresaRtn ? '| RTN: ' . $empresaRtn : '' }}<br>
        Generado el {{ $fechaGeneracion }} | Uso interno y confidencial | Conforme NIIF para PYMES - Seccion 5{{ $footerInversiones }}
    </div>

    {{-- ==================== PAGINA 1: Estado Financiero ==================== --}}

    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 55%">
                    @if($logoPath)<img src="{{ $logoPath }}" class="company-logo" alt="Logo"><br>@endif
                    <span class="company-name">{{ $empresaNombre }}</span><br>
                    <span class="company-info">{{ $headerInfo }}</span>
                </td>
                <td style="width: 45%">
                    <div class="report-title">Estado de Resultados</div>
                    <div class="report-subtitle">{{ $periodoLabel }}</div>
                    <div class="report-subtitle">Del {{ $fechaInicio }} al {{ $fechaFin }}</div>
                    <div class="report-niif">Conforme NIIF para PYMES - Seccion 5</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="period-info">
        <table>
            <tr>
                <td class="period-label">Periodo:</td>
                <td>{{ $periodoLabel }} ({{ $fechaInicio }} - {{ $fechaFin }})</td>
                <td class="period-label">Comparativo:</td>
                <td>{{ $periodoAnteriorLabel }}</td>
            </tr>
            <tr>
                <td class="period-label">Moneda:</td>
                <td>Lempiras (L)</td>
                <td class="period-label">Metodo:</td>
                <td>Funcion de los gastos</td>
            </tr>
        </table>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="col-pct">%</th>
                <th>Periodo Actual</th>
                <th>Periodo Anterior</th>
                <th>Var.</th>
            </tr>
        </thead>
        <tbody>
            <tr class="section-header"><td colspan="5">I. Ingresos por Actividades Ordinarias</td></tr>
            <tr class="detail-row"><td>Ventas en Ruta ({{ number_format($d['cantidad_ventas_ruta']) }} op.)</td><td class="pct-col">{{ $pct($d['ventas_ruta']) }}</td><td>{{ $lps($d['ventas_ruta']) }}</td><td>{{ $lps($a['ventas_ruta']) }}</td><td>{!! $varVentasRuta !!}</td></tr>
            <tr class="detail-row"><td>Ventas en Bodega ({{ number_format($d['cantidad_ventas_bodega']) }} op.)</td><td class="pct-col">{{ $pct($d['ventas_bodega']) }}</td><td>{{ $lps($d['ventas_bodega']) }}</td><td>{{ $lps($a['ventas_bodega']) }}</td><td>{!! $varVentasBodega !!}</td></tr>
            <tr class="subtotal-row"><td>Ingresos Brutos</td><td class="pct-col"></td><td>{{ $lps($d['ventas_brutas']) }}</td><td>{{ $lps($a['ventas_brutas']) }}</td><td>{!! $varVentasBrutas !!}</td></tr>
            @if($mostrarISV)
            <tr class="deduction-row"><td>(-) ISV sobre productos gravados</td><td class="pct-col">{{ $pct($d['isv_ventas']) }}</td><td>{{ $lps($d['isv_ventas']) }}</td><td>{{ $lps($a['isv_ventas']) }}</td><td>-</td></tr>
            @endif
            @if($mostrarDescuentos)
            <tr class="deduction-row"><td>(-) Descuentos Otorgados</td><td class="pct-col">{{ $pct($d['descuentos']) }}</td><td>{{ $lps($d['descuentos']) }}</td><td>{{ $lps($a['descuentos']) }}</td><td>-</td></tr>
            @endif
            <tr class="total-row total-row-positive"><td>INGRESOS NETOS</td><td class="pct-col color-green">100.0%</td><td>{{ $lps($d['ventas_netas']) }}</td><td>{{ $lps($a['ventas_netas']) }}</td><td>{!! $varVentasNetas !!}</td></tr>

            <tr class="section-header"><td colspan="5">II. Costo de Ventas</td></tr>
            <tr class="detail-row"><td>Costo de mercancia vendida - Ruta</td><td class="pct-col">{{ $pct($d['costo_ruta']) }}</td><td>{{ $lps($d['costo_ruta']) }}</td><td>{{ $lps($a['costo_ruta']) }}</td><td>-</td></tr>
            <tr class="detail-row"><td>Costo de mercancia vendida - Bodega</td><td class="pct-col">{{ $pct($d['costo_bodega']) }}</td><td>{{ $lps($d['costo_bodega']) }}</td><td>{{ $lps($a['costo_bodega']) }}</td><td>-</td></tr>
            <tr class="subtotal-row deduction-row"><td>(-) Total Costo de Ventas</td><td class="pct-col color-deduction">{{ $pct($d['costo_ventas']) }}</td><td>{{ $lps($d['costo_ventas']) }}</td><td>{{ $lps($a['costo_ventas']) }}</td><td>{!! $varCostoVentas !!}</td></tr>
            <tr class="{{ $claseUtilidadBruta }}"><td>UTILIDAD BRUTA</td><td class="{{ $clasePctBruta }}">{{ $pct($d['utilidad_bruta']) }}</td><td>{{ $lps($d['utilidad_bruta']) }}</td><td>{{ $lps($a['utilidad_bruta']) }}</td><td>{!! $varUtilidadBruta !!}</td></tr>
            <tr class="margin-row"><td colspan="5">{{ $margenBrutoTexto }}</td></tr>

            <tr class="section-header"><td colspan="5">III. Gastos de Venta</td></tr>
            <tr class="detail-row"><td><strong>Transporte y distribucion</strong></td><td class="pct-col">{{ $pct($d['gastos_camion_total']) }}</td><td><strong>{{ $lps($d['gastos_camion_total']) }}</strong></td><td>{{ $lps($a['gastos_camion_total']) }}</td><td>{!! $varCamionTotal !!}</td></tr>
            @if($mostrarCombustible)
            <tr class="sub-detail-row"><td>Combustible</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_combustible']) }}</td><td>{{ $lps($a['gastos_camion_combustible']) }}</td><td>{!! $varCombustible !!}</td></tr>
            @endif
            @if($mostrarGasolina && $mostrarDiesel)
            {{-- Solo mostrar desglose si hay ambos tipos --}}
            <tr class="sub-detail-row"><td>&nbsp;&nbsp;&nbsp;&nbsp;Gasolina</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_gasolina']) }}</td><td>{{ $lps($a['gastos_camion_gasolina']) }}</td><td>{!! $varGasolina !!}</td></tr>
            <tr class="sub-detail-row"><td>&nbsp;&nbsp;&nbsp;&nbsp;Di&eacute;sel</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_diesel']) }}</td><td>{{ $lps($a['gastos_camion_diesel']) }}</td><td>{!! $varDiesel !!}</td></tr>
            @endif
            @if($mostrarMantenimiento)
            <tr class="sub-detail-row"><td>Mantenimiento preventivo</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_mantenimiento']) }}</td><td>{{ $lps($a['gastos_camion_mantenimiento']) }}</td><td>{!! $varMantenimiento !!}</td></tr>
            @endif
            @if($mostrarReparacion)
            <tr class="sub-detail-row"><td>Reparaciones</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_reparacion']) }}</td><td>{{ $lps($a['gastos_camion_reparacion']) }}</td><td>{!! $varReparacion !!}</td></tr>
            @endif
            @if($mostrarViaticos)
            <tr class="sub-detail-row"><td>Vi&aacute;ticos</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_viaticos']) }}</td><td>{{ $lps($a['gastos_camion_viaticos']) }}</td><td>{!! $varViaticos !!}</td></tr>
            @endif
            @if($mostrarCamionOtros)
            <tr class="sub-detail-row"><td>Peajes, lavado y otros</td><td class="pct-col"></td><td>{{ $lps($d['gastos_camion_otros']) }}</td><td>{{ $lps($a['gastos_camion_otros']) }}</td><td>{!! $varCamionOtros !!}</td></tr>
            @endif
            @if($mostrarMaterialEmpaque)
            <tr class="detail-row"><td><strong>Material de empaque</strong></td><td class="pct-col">{{ $pct($d['material_empaque']) }}</td><td><strong>{{ $lps($d['material_empaque']) }}</strong></td><td>{{ $lps($materialEmpaqueAnterior) }}</td><td>-</td></tr>
            @endif
            @if($mostrarOtrosBodegaVenta)
            <tr class="detail-row"><td><strong>Otros gastos de bodega</strong></td><td class="pct-col">{{ $pct($d['otros_gastos_bodega_venta']) }}</td><td><strong>{{ $lps($d['otros_gastos_bodega_venta']) }}</strong></td><td>{{ $lps($otrosGastosBodegaVentaAnterior) }}</td><td>-</td></tr>
            @endif
            <tr class="detail-row"><td><strong>Comisiones a choferes</strong></td><td class="pct-col">{{ $pct($d['comisiones']) }}</td><td><strong>{{ $lps($d['comisiones']) }}</strong></td><td>{{ $lps($a['comisiones']) }}</td><td>{!! $varComisiones !!}</td></tr>
            @if($mostrarComisionesPagadas)
            <tr class="sub-detail-row"><td>Comisiones liquidadas</td><td class="pct-col"></td><td>{{ $lps($d['comisiones_pagadas']) }}</td><td>{{ $lps($a['comisiones_pagadas']) }}</td><td>-</td></tr>
            @endif
            <tr class="detail-row"><td><strong>Mermas y perdidas</strong></td><td class="pct-col">{{ $pct($d['mermas_total']) }}</td><td><strong>{{ $lps($d['mermas_total']) }}</strong></td><td>{{ $lps($a['mermas_total']) }}</td><td>{!! $varMermas !!}</td></tr>
            @if($mostrarMermasViajes)
            <tr class="sub-detail-row"><td>Mermas en transporte</td><td class="pct-col"></td><td>{{ $lps($d['mermas_viajes']) }}</td><td>{{ $lps($a['mermas_viajes']) }}</td><td>{!! $varMermasViajes !!}</td></tr>
            @endif
            @if($mostrarMermasReempaques)
            <tr class="sub-detail-row"><td>Mermas en reempaque</td><td class="pct-col"></td><td>{{ $lps($d['mermas_reempaques']) }}</td><td>{{ $lps($a['mermas_reempaques']) }}</td><td>{!! $varMermasReempaques !!}</td></tr>
            @endif
            @if($mostrarMermasLotes)
            <tr class="sub-detail-row"><td>Mermas en lotes (bodega)</td><td class="pct-col"></td><td>{{ $lps($d['mermas_lotes']) }}</td><td>{{ $lps($a['mermas_lotes']) }}</td><td>{!! $varMermasLotes !!}</td></tr>
            @endif
            <tr class="subtotal-row deduction-row"><td>(-) Total Gastos de Venta</td><td class="pct-col color-deduction">{{ $pct($d['total_gastos_venta']) }}</td><td>{{ $lps($d['total_gastos_venta']) }}</td><td>{{ $lps($a['total_gastos_venta']) }}</td><td>{!! $varGastosVenta !!}</td></tr>

            <tr class="section-header"><td colspan="5">IV. Gastos de Administracion</td></tr>
            @if($mostrarAdminHonorarios)
            <tr class="detail-row"><td>Honorarios profesionales</td><td class="pct-col">{{ $pct($d['gastos_admin_honorarios']) }}</td><td>{{ $lps($d['gastos_admin_honorarios']) }}</td><td>{{ $lps($a['gastos_admin_honorarios']) }}</td><td>-</td></tr>
            @endif
            @if($mostrarAdminServicios)
            <tr class="detail-row"><td>Servicios basicos (internet, comunicaciones)</td><td class="pct-col">{{ $pct($d['gastos_admin_servicios']) }}</td><td>{{ $lps($d['gastos_admin_servicios']) }}</td><td>{{ $lps($a['gastos_admin_servicios']) }}</td><td>-</td></tr>
            @endif
            @if($mostrarAdminOtros)
            <tr class="detail-row"><td>Otros gastos administrativos</td><td class="pct-col">{{ $pct($d['gastos_admin_otros']) }}</td><td>{{ $lps($d['gastos_admin_otros']) }}</td><td>{{ $lps($a['gastos_admin_otros']) }}</td><td>-</td></tr>
            @endif
            @if($sinGastosAdmin)
            <tr class="detail-row"><td class="text-muted">Sin gastos administrativos en el periodo</td><td class="pct-col">-</td><td class="text-muted">{{ $lps(0) }}</td><td class="text-muted">{{ $lps(0) }}</td><td>-</td></tr>
            @endif
            <tr class="subtotal-row deduction-row"><td>(-) Total Gastos de Administracion</td><td class="pct-col color-deduction">{{ $pct($d['gastos_admin_total']) }}</td><td>{{ $lps($d['gastos_admin_total']) }}</td><td>{{ $lps($a['gastos_admin_total']) }}</td><td>-</td></tr>

            <tr class="{{ $claseUtilidadOp }}"><td>UTILIDAD OPERATIVA (EBIT)</td><td class="{{ $clasePctOp }}">{{ $pct($d['utilidad_operativa']) }}</td><td>{{ $lps($d['utilidad_operativa']) }}</td><td>{{ $lps($a['utilidad_operativa']) }}</td><td>{!! $varUtilidadOp !!}</td></tr>
            <tr class="margin-row"><td colspan="5">{{ $margenOpTexto }}</td></tr>

            <tr class="section-header"><td colspan="5">V. Otros Ingresos y Gastos No Operativos</td></tr>
            <tr class="detail-row"><td class="text-muted">Sin movimientos no operativos en el periodo</td><td class="pct-col">-</td><td class="text-muted">{{ $lps(0) }}</td><td class="text-muted">{{ $lps(0) }}</td><td>-</td></tr>

            <tr class="section-header"><td colspan="5">VI. Impuesto Sobre la Renta</td></tr>
            <tr class="detail-row"><td>Utilidad antes de ISR</td><td class="pct-col">{{ $pct($d['utilidad_antes_isr']) }}</td><td>{{ $lps($d['utilidad_antes_isr']) }}</td><td>{{ $lps($a['utilidad_antes_isr']) }}</td><td>-</td></tr>
            @if($mostrarISR)
            <tr class="deduction-row"><td>{{ $isrLabel }}</td><td class="pct-col">{{ $pct($d['isr_estimado']) }}</td><td>{{ $lps($d['isr_estimado']) }}</td><td>{{ $lps($a['isr_estimado']) }}</td><td>-</td></tr>
            @endif

            <tr class="section-header"><td colspan="5">VII. Resultado del Periodo</td></tr>
            <tr class="{{ $claseResultadoFinal }}"><td>UTILIDAD (PERDIDA) NETA</td><td class="{{ $clasePctFinal }}">{{ $pct($d['utilidad_neta']) }}</td><td>{{ $lps($d['utilidad_neta']) }}</td><td>{{ $lps($a['utilidad_neta']) }}</td><td>{!! $varUtilidadNeta !!}</td></tr>
            <tr class="margin-row"><td colspan="5">{{ $margenNetoTexto }}</td></tr>
        </tbody>
    </table>

    {{-- ==================== PAGINA 2: Indicadores + Firmas + Notas ==================== --}}
    <div class="page-break"></div>

    <div class="kpi-section">
        <div class="kpi-title">Indicadores Clave</div>
        <table class="kpi-table">
            <tr>
                <td class="kpi-label">Ticket Promedio</td>
                <td class="kpi-label">Margen Bruto</td>
                <td class="kpi-label">Margen Operativo</td>
                <td class="kpi-label">Cuentas por Cobrar</td>
                <td class="kpi-label">Dias Prom. Cobro</td>
                <td class="kpi-label">Costo / Ingreso</td>
            </tr>
            <tr>
                <td><div class="kpi-value">{{ $lps($d['ticket_promedio']) }}</div><div class="kpi-sub">{{ number_format($d['cantidad_ventas']) }} operaciones</div></td>
                <td><div class="kpi-value {{ $claseMargenBruto }}">{{ number_format($d['margen_bruto'], 1) }}%</div><div class="kpi-sub">Meta: mayor a 15%</div></td>
                <td><div class="kpi-value {{ $claseMargenOp }}">{{ number_format($d['margen_operativo'], 1) }}%</div><div class="kpi-sub">Meta: mayor a 8%</div></td>
                <td><div class="kpi-value {{ $claseCxC }}">{{ $lps($d['cuentas_por_cobrar']) }}</div><div class="kpi-sub">Del periodo</div></td>
                <td><div class="kpi-value {{ $claseDPC }}">{{ number_format($d['dias_promedio_cobro'] ?? 0, 0) }}</div><div class="kpi-sub">Meta: menor a 15 dias</div></td>
                <td><div class="kpi-value {{ $claseCostoIng }}">{{ number_format($d['costo_sobre_ingreso'], 1) }}%</div><div class="kpi-sub">Meta: menor a 85%</div></td>
            </tr>
        </table>
    </div>

    <table class="signatures-table">
        <tr>
            <td><div class="sig-line"><div class="sig-name">Elaborado por</div><div class="sig-title">Administracion</div></div></td>
            <td><div class="sig-line"><div class="sig-name">Revisado por</div><div class="sig-title">Contabilidad</div></div></td>
            <td><div class="sig-line"><div class="sig-name">Autorizado por</div><div class="sig-title">Gerencia General</div></div></td>
        </tr>
    </table>

    <div class="notes-section">
        <div class="notes-title">Notas a los Estados Financieros:</div>
        1. <strong>Base de preparacion:</strong> Conforme NIIF para PYMES (Seccion 5), metodo de funcion de los gastos. Decreto 189-2004.<br>
        2. <strong>ISV:</strong> Los huevos (SAC 0407.00.90) y productos lacteos estan exentos del ISV conforme Art. 15 inciso a) Ley del ISV (Decreto-Ley No. 24) - canasta basica. Solo se cobra ISV a productos gravados.<br>
        3. <strong>Costo de ventas:</strong> Valuado al costo promedio ponderado (Seccion 13 NIIF para PYMES).<br>
        4. <strong>ISR:</strong> Estimado al {{ $tasaISRPct }}% sobre renta neta gravable, conforme Art. 22 literal a) Ley ISR (Decreto-Ley No. 25). Declaracion anual en Formulario SAR-357.<br>
        5. <strong>Mermas:</strong> Incluyen perdidas por rotura en transporte, en proceso de reempaque, y perdidas en lotes de bodega.<br>
        6. <strong>Comisiones:</strong> Se registran al momento de generarse (base devengado), independientemente de su liquidacion.<br>
        7. <strong>Inversiones:</strong> Las compras de activos fijos se excluyen del Estado de Resultados y se registran en el Balance General.<br>
        8. Las variaciones porcentuales comparan contra el periodo inmediato anterior.
    </div>

</body>
</html>