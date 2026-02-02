<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Liquidacion {{ $viaje->numero_viaje }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.3;
            color: #333;
        }

        .container {
            padding: 15px;
        }

        .header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
        }

        .company-info {
            font-size: 9px;
            color: #666;
        }

        .document-title {
            font-size: 16px;
            font-weight: bold;
            color: #1e40af;
            text-align: right;
            margin-top: -40px;
        }

        .document-number {
            font-size: 12px;
            text-align: right;
            color: #333;
        }

        .info-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 12px;
        }

        .info-grid {
            width: 100%;
        }

        .info-grid td {
            padding: 3px 8px;
            vertical-align: top;
        }

        .info-label {
            font-weight: bold;
            color: #64748b;
            font-size: 9px;
            width: 100px;
        }

        .info-value {
            color: #1e293b;
        }

        .section {
            margin-bottom: 12px;
        }

        .section-title {
            background: #1e40af;
            color: white;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 0;
        }

        .section-title-green {
            background: #166534;
        }

        .section-title-orange {
            background: #c2410c;
        }

        .section-title-purple {
            background: #7c3aed;
        }

        .section-content {
            border: 1px solid #e2e8f0;
            border-top: none;
            padding: 8px;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        table.data-table th {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 5px;
            text-align: left;
            font-weight: bold;
            color: #475569;
        }

        table.data-table td {
            border: 1px solid #e2e8f0;
            padding: 4px 5px;
        }

        table.data-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .financial-summary {
            width: 100%;
            margin-top: 8px;
        }

        .financial-summary td {
            padding: 4px 8px;
        }

        .summary-label {
            font-weight: bold;
            color: #475569;
        }

        .summary-value {
            text-align: right;
            font-weight: bold;
        }

        .color-green {
            color: #16a34a;
        }

        .color-red {
            color: #dc2626;
        }

        .color-orange {
            color: #d97706;
        }

        .color-blue {
            color: #0284c7;
        }

        .color-gray {
            color: #64748b;
        }

        .color-purple {
            color: #7c3aed;
        }

        .bg-green {
            background: #dcfce7;
        }

        .bg-red {
            background: #fee2e2;
        }

        .bg-yellow {
            background: #fef3c7;
        }

        .bg-gray {
            background: #e2e8f0;
        }

        .bg-purple {
            background: #f3e8ff;
        }

        .totals-box {
            background: #f0f9ff;
            border: 2px solid #0284c7;
            border-radius: 4px;
            padding: 10px;
            margin-top: 10px;
        }

        .totals-box h4 {
            color: #0369a1;
            margin-bottom: 8px;
            font-size: 11px;
        }

        .total-final {
            background: #166534;
            color: white;
            padding: 6px;
            margin-top: 8px;
            border-radius: 3px;
        }

        .two-columns {
            width: 100%;
        }

        .two-columns td {
            width: 50%;
            vertical-align: top;
            padding: 0 5px;
        }

        .three-columns {
            width: 100%;
        }

        .three-columns td {
            width: 33.33%;
            vertical-align: top;
            padding: 0 3px;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: bold;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 8px;
            color: #94a3b8;
            text-align: center;
        }

        .signatures {
            margin-top: 40px;
            width: 100%;
        }

        .signatures td {
            width: 33%;
            text-align: center;
            padding-top: 40px;
        }

        .signature-line {
            border-top: 1px solid #333;
            width: 80%;
            margin: 0 auto;
            padding-top: 5px;
        }

        .big-summary {
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .big-number {
            font-size: 16px;
            font-weight: bold;
        }

        .small-label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
        }

        .comparativo-box {
            margin-top: 12px;
            padding: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }

        .descuento-box {
            background: #faf5ff;
            border: 1px solid #c4b5fd;
            border-radius: 4px;
            padding: 8px;
            margin-top: 8px;
        }

        .comision-box {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 8px;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-name">{{ $empresa['nombre'] ?? 'EMPRESA' }}</div>
            <div class="company-info">
                RTN: {{ $empresa['rtn'] ?? 'N/A' }}<br>
                {{ $empresa['direccion'] ?? '' }}<br>
                Tel: {{ $empresa['telefono'] ?? '' }}
            </div>
            <div class="document-title">LIQUIDACION DE VIAJE</div>
            <div class="document-number">{{ $viaje->numero_viaje }}</div>
        </div>

        <!-- Info del viaje -->
        <div class="info-section">
            <table class="info-grid">
                <tr>
                    <td class="info-label">CAMION:</td>
                    <td class="info-value">{{ $viaje->camion->placa ?? 'N/A' }}</td>
                    <td class="info-label">CHOFER:</td>
                    <td class="info-value">{{ $viaje->chofer->name ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="info-label">BODEGA:</td>
                    <td class="info-value">{{ $viaje->bodegaOrigen->nombre ?? 'N/A' }}</td>
                    <td class="info-label">ESTADO:</td>
                    <td class="info-value">
                        @if($viaje->estado == 'cerrado')
                            <span class="badge badge-success">CERRADO</span>
                        @else
                            <span class="badge badge-info">{{ strtoupper($viaje->estado) }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="info-label">SALIDA:</td>
                    <td class="info-value">{{ $viaje->fecha_salida ? \Carbon\Carbon::parse($viaje->fecha_salida)->format('d/m/Y H:i') : 'N/A' }}</td>
                    <td class="info-label">REGRESO:</td>
                    <td class="info-value">{{ $viaje->fecha_regreso ? \Carbon\Carbon::parse($viaje->fecha_regreso)->format('d/m/Y H:i') : 'En curso' }}</td>
                </tr>
            </table>
        </div>

        <!-- RESUMEN EJECUTIVO -->
        <div class="big-summary">
            <table class="three-columns">
                <tr>
                    <td style="text-align: center; border-right: 1px solid #cbd5e1;">
                        <div class="small-label">Costo de Carga</div>
                        <div class="big-number color-gray">L {{ number_format($datos['total_cargado_costo'], 2) }}</div>
                    </td>
                    <td style="text-align: center; border-right: 1px solid #cbd5e1;">
                        <div class="small-label">Venta Esperada</div>
                        <div class="big-number color-blue">L {{ number_format($datos['total_cargado_venta'], 2) }}</div>
                    </td>
                    <td style="text-align: center;">
                        <div class="small-label">Ganancia Esperada</div>
                        <div class="big-number color-green">L {{ number_format($datos['total_cargado_venta'] - $datos['total_cargado_costo'], 2) }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Resumen de Ventas y Efectivo -->
        <table class="two-columns">
            <tr>
                <td>
                    <!-- Resumen de Ventas -->
                    <div class="section">
                        <div class="section-title section-title-green">RESULTADO DE VENTAS</div>
                        <div class="section-content">
                            <table class="financial-summary">
                                <tr>
                                    <td class="summary-label">Ventas de Contado:</td>
                                    <td class="summary-value color-green">L {{ number_format($datos['ventas_contado'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Ventas a Credito:</td>
                                    <td class="summary-value color-blue">L {{ number_format($datos['ventas_credito'], 2) }}</td>
                                </tr>
                                <tr class="bg-green">
                                    <td style="padding: 6px 8px;"><strong>TOTAL VENDIDO:</strong></td>
                                    <td class="summary-value" style="padding: 6px 8px; font-size: 12px;">L {{ number_format($datos['total_ventas'], 2) }}</td>
                                </tr>
                            </table>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1;">
                                <table class="financial-summary">
                                    <tr>
                                        <td class="summary-label">Devuelto (no vendido):</td>
                                        <td class="summary-value color-orange">L {{ number_format($datos['total_devuelto_costo'], 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="summary-label">% Vendido:</td>
                                        <td class="summary-value">{{ number_format($datos['porcentaje_vendido'], 1) }}%</td>
                                    </tr>
                                </table>
                            </div>
                            @if($datos['total_descuentos'] > 0)
                            <div class="descuento-box">
                                <table class="financial-summary">
                                    <tr>
                                        <td class="summary-label color-purple">Descuentos Otorgados:</td>
                                        <td class="summary-value color-purple">L {{ number_format($datos['total_descuentos'], 2) }}</td>
                                    </tr>
                                </table>
                                <div style="font-size: 8px; color: #7c3aed; margin-top: 4px;">
                                    (Vendio por debajo del precio sugerido)
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </td>
                <td>
                    <!-- Entrega de Efectivo -->
                    <div class="section">
                        <div class="section-title section-title-orange">ENTREGA DE EFECTIVO</div>
                        <div class="section-content">
                            <table class="financial-summary">
                                <tr>
                                    <td class="summary-label">Debe Entregar (contado):</td>
                                    <td class="summary-value">L {{ number_format($datos['efectivo_debe_entregar'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td class="summary-label">Entrego:</td>
                                    <td class="summary-value">L {{ number_format($datos['efectivo_entregado'], 2) }}</td>
                                </tr>
                                @if($datos['diferencia_efectivo'] >= 0)
                                <tr class="bg-green">
                                @else
                                <tr class="bg-red">
                                @endif
                                    <td style="padding: 6px 8px;"><strong>DIFERENCIA:</strong></td>
                                    <td class="summary-value" style="padding: 6px 8px; font-size: 12px;">
                                        L {{ number_format($datos['diferencia_efectivo'], 2) }}
                                    </td>
                                </tr>
                            </table>
                            <div style="margin-top: 8px; text-align: center;">
                                @if($datos['estado_efectivo'] == 'Cuadrado')
                                    <span class="badge badge-success" style="font-size: 11px; padding: 4px 12px;">CUADRADO</span>
                                @elseif($datos['estado_efectivo'] == 'Faltante')
                                    <span class="badge badge-danger" style="font-size: 11px; padding: 4px 12px;">FALTANTE</span>
                                @elseif($datos['estado_efectivo'] == 'Sobrante')
                                    <span class="badge badge-success" style="font-size: 11px; padding: 4px 12px;">SOBRANTE</span>
                                @else
                                    <span class="badge badge-warning" style="font-size: 11px; padding: 4px 12px;">PENDIENTE</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Detalle de Descuentos (si hay) -->
        @if($datos['total_descuentos'] > 0 && $datos['detalle_descuentos']->count() > 0)
        <div class="section">
            <div class="section-title section-title-purple">DETALLE DE DESCUENTOS OTORGADOS</div>
            <div class="section-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-center">Cantidad</th>
                            <th class="text-right">P. Sugerido</th>
                            <th class="text-right">P. Vendido</th>
                            <th class="text-right">Desc. Unit.</th>
                            <th class="text-right">Desc. Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($datos['detalle_descuentos'] as $desc)
                        <tr>
                            <td>{{ $desc['producto'] }}</td>
                            <td class="text-center">{{ number_format($desc['cantidad'], 2) }}</td>
                            <td class="text-right">L {{ number_format($desc['precio_sugerido'], 2) }}</td>
                            <td class="text-right">L {{ number_format($desc['precio_vendido'], 2) }}</td>
                            <td class="text-right color-purple">L {{ number_format($desc['descuento_unitario'], 2) }}</td>
                            <td class="text-right color-purple"><strong>L {{ number_format($desc['descuento_total'], 2) }}</strong></td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-purple">
                            <td colspan="5"><strong>TOTAL DESCUENTOS OTORGADOS</strong></td>
                            <td class="text-right"><strong>L {{ number_format($datos['total_descuentos'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
                <div style="font-size: 8px; color: #64748b; margin-top: 6px;">
                    * Estos descuentos representan la diferencia entre el precio sugerido y el precio al que realmente se vendio.
                    <br>Si hubiera vendido al precio sugerido, habria cobrado L {{ number_format($datos['venta_esperada_de_lo_vendido'], 2) }} en lugar de L {{ number_format($datos['total_ventas'], 2) }}.
                </div>
            </div>
        </div>
        @endif

        <!-- Detalle de Carga Inicial -->
        <div class="section">
            <div class="section-title">DETALLE DE CARGA INICIAL</div>
            <div class="section-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Unidad</th>
                            <th class="text-center">Cargado</th>
                            <th class="text-center">Vendido</th>
                            <th class="text-center">Devuelto</th>
                            <th class="text-right">Costo Unit.</th>
                            <th class="text-right">P. Sugerido</th>
                            <th class="text-right">P. Vendido</th>
                            <th class="text-right">Costo Total</th>
                            <th class="text-right">Venta Real</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($datos['carga_inicial'] as $carga)
                        <tr>
                            <td>{{ $carga['producto'] }}</td>
                            <td class="text-center">{{ $carga['unidad'] }}</td>
                            <td class="text-center"><strong>{{ number_format($carga['cantidad'], 2) }}</strong></td>
                            <td class="text-center color-green">{{ number_format($carga['vendida'], 2) }}</td>
                            <td class="text-center color-orange">{{ number_format($carga['devuelta'], 2) }}</td>
                            <td class="text-right">L {{ number_format($carga['costo_con_isv'], 2) }}</td>
                            <td class="text-right">L {{ number_format($carga['precio_venta_con_isv'], 2) }}</td>
                            <td class="text-right">
                                @if($carga['vendida'] > 0)
                                    @if($carga['diferencia_precio'] > 0.01)
                                        <span style="color: #16a34a;">L {{ number_format($carga['precio_real_venta_con_isv'], 2) }}</span>
                                    @elseif($carga['diferencia_precio'] < -0.01)
                                        <span style="color: #dc2626;">L {{ number_format($carga['precio_real_venta_con_isv'], 2) }}</span>
                                    @else
                                        L {{ number_format($carga['precio_real_venta_con_isv'], 2) }}
                                    @endif
                                @else
                                    <span style="color: #94a3b8;">-</span>
                                @endif
                            </td>
                            <td class="text-right">L {{ number_format($carga['costo_total'], 2) }}</td>
                            <td class="text-right color-green">
                                @if($carga['vendida'] > 0)
                                    L {{ number_format($carga['venta_real'], 2) }}
                                @else
                                    <span style="color: #94a3b8;">L 0.00</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="text-center">Sin carga registrada</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray">
                            <td colspan="8"><strong>TOTALES</strong></td>
                            <td class="text-right"><strong>L {{ number_format($datos['total_cargado_costo'], 2) }}</strong></td>
                            <td class="text-right color-green"><strong>L {{ number_format($datos['total_ventas'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
                <div style="font-size: 8px; color: #64748b; margin-top: 6px;">
                    <span style="color: #16a34a;">■</span> Vendio mas caro que el precio sugerido &nbsp;&nbsp;
                    <span style="color: #dc2626;">■</span> Vendio mas barato que el precio sugerido
                </div>
            </div>
        </div>

        <!-- Detalle de Ventas por Cliente -->
        <div class="section">
            <div class="section-title">DETALLE DE VENTAS ({{ $datos['ventas_por_cliente']->count() }} ventas)</div>
            <div class="section-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No. Venta</th>
                            <th>Cliente</th>
                            <th>Tipo Pago</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-right">ISV</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($datos['ventas_por_cliente'] as $venta)
                        <tr>
                            <td>{{ $venta['numero'] }}</td>
                            <td>{{ $venta['cliente'] }}</td>
                            <td class="text-center">
                                @if($venta['tipo_pago'] == 'Credito')
                                    <span class="badge badge-warning">Credito</span>
                                @else
                                    <span class="badge badge-success">Contado</span>
                                @endif
                            </td>
                            <td class="text-right">L {{ number_format($venta['subtotal'], 2) }}</td>
                            <td class="text-right">L {{ number_format($venta['impuesto'], 2) }}</td>
                            <td class="text-right"><strong>L {{ number_format($venta['total'], 2) }}</strong></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center">No hay ventas registradas</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray">
                            <td colspan="3"><strong>TOTALES</strong></td>
                            <td class="text-right"><strong>L {{ number_format($datos['ventas_por_cliente']->sum('subtotal'), 2) }}</strong></td>
                            <td class="text-right"><strong>L {{ number_format($datos['ventas_por_cliente']->sum('impuesto'), 2) }}</strong></td>
                            <td class="text-right"><strong>L {{ number_format($datos['total_ventas'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Gastos del Viaje -->
        @if($datos['gastos_aprobados']->count() > 0)
        <div class="section">
            <div class="section-title">GASTOS OPERATIVOS</div>
            <div class="section-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Concepto</th>
                            <th>Descripcion</th>
                            <th class="text-right">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($datos['gastos_aprobados'] as $gasto)
                        <tr>
                            <td>{{ $gasto->concepto ?? 'Gasto' }}</td>
                            <td>{{ $gasto->descripcion ?? '-' }}</td>
                            <td class="text-right">L {{ number_format($gasto->monto, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-yellow">
                            <td colspan="2"><strong>Total Gastos Aprobados</strong></td>
                            <td class="text-right"><strong>L {{ number_format($datos['total_gastos'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        @endif

        <!-- Rentabilidad -->
        <div class="totals-box">
            <h4>RENTABILIDAD DEL VIAJE</h4>
            <table class="financial-summary">
                <tr>
                    <td class="summary-label">Total Vendido:</td>
                    <td class="summary-value">L {{ number_format($datos['total_ventas'], 2) }}</td>
                </tr>
                @if($datos['total_descuentos'] > 0)
                <tr>
                    <td class="summary-label color-purple">(+) Descuentos Otorgados:</td>
                    <td class="summary-value color-purple">L {{ number_format($datos['total_descuentos'], 2) }}</td>
                </tr>
                <tr>
                    <td class="summary-label">(=) Venta a Precio Sugerido:</td>
                    <td class="summary-value">L {{ number_format($datos['venta_esperada_de_lo_vendido'], 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td class="summary-label">(-) Costo de lo Vendido:</td>
                    <td class="summary-value">L {{ number_format($datos['costo_vendido'], 2) }}</td>
                </tr>
                <tr style="border-top: 1px solid #0284c7;">
                    <td class="summary-label"><strong>= Margen Bruto:</strong></td>
                    @if($datos['margen_bruto'] >= 0)
                    <td class="summary-value color-green"><strong>L {{ number_format($datos['margen_bruto'], 2) }}</strong></td>
                    @else
                    <td class="summary-value color-red"><strong>L {{ number_format($datos['margen_bruto'], 2) }}</strong></td>
                    @endif
                </tr>
                <tr>
                    <td class="summary-label">(-) Gastos Operativos:</td>
                    <td class="summary-value color-orange">L {{ number_format($datos['total_gastos'], 2) }}</td>
                </tr>
            </table>
            <div class="total-final">
                <table style="width: 100%;">
                    <tr>
                        <td><strong>UTILIDAD NETA DEL VIAJE:</strong></td>
                        <td class="text-right" style="font-size: 14px;">
                            <strong>L {{ number_format($datos['utilidad_neta'], 2) }}</strong>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Comisión del Chofer (informativo) -->
        @if($datos['comision_ganada'] > 0)
        <div class="comision-box">
            <table class="financial-summary">
                <tr>
                    <td class="summary-label" style="color: #92400e;">Comision Generada (se paga a fin de mes):</td>
                    <td class="summary-value" style="color: #92400e;">L {{ number_format($datos['comision_ganada'], 2) }}</td>
                </tr>
            </table>
        </div>
        @endif

        <!-- Comparativo de Ganancia -->
        @php
            $gananciaEsperada = $datos['total_cargado_venta'] - $datos['total_cargado_costo'];
            $diferencia = $datos['utilidad_neta'] - $gananciaEsperada;
        @endphp
        <div style="margin-top: 12px; padding: 12px; background: #f8fafc; border: 2px solid #cbd5e1; border-radius: 6px;">
            <div style="text-align: center; margin-bottom: 10px; font-size: 10px; font-weight: bold; color: #475569;">
                COMPARATIVO DE GANANCIA
            </div>
            <table style="width: 100%; font-size: 10px;">
                <tr>
                    <td style="width: 33%; padding: 8px; text-align: center; border-right: 1px solid #cbd5e1;">
                        <div style="color: #64748b; font-size: 9px; margin-bottom: 4px;">GANANCIA ESPERADA</div>
                        <div style="font-size: 14px; font-weight: bold;">L {{ number_format($gananciaEsperada, 2) }}</div>
                        <div style="font-size: 8px; color: #94a3b8;">(Venta Esperada - Costo de Carga)</div>
                    </td>
                    <td style="width: 33%; padding: 8px; text-align: center; border-right: 1px solid #cbd5e1;">
                        <div style="color: #64748b; font-size: 9px; margin-bottom: 4px;">DIFERENCIA</div>
                        @if($diferencia >= 0)
                        <div style="font-size: 14px; font-weight: bold; color: #16a34a;">+ L {{ number_format($diferencia, 2) }}</div>
                        @else
                        <div style="font-size: 14px; font-weight: bold; color: #dc2626;">- L {{ number_format(abs($diferencia), 2) }}</div>
                        @endif
                    </td>
                    <td style="width: 33%; padding: 8px; text-align: center;">
                        <div style="color: #64748b; font-size: 9px; margin-bottom: 4px;">UTILIDAD REAL</div>
                        @if($datos['utilidad_neta'] >= 0)
                        <div style="font-size: 14px; font-weight: bold; color: #16a34a;">L {{ number_format($datos['utilidad_neta'], 2) }}</div>
                        @else
                        <div style="font-size: 14px; font-weight: bold; color: #dc2626;">L {{ number_format($datos['utilidad_neta'], 2) }}</div>
                        @endif
                        <div style="font-size: 8px; color: #94a3b8;">(Total Vendido - Costo - Gastos)</div>
                    </td>
                </tr>
            </table>
            
            <!-- Explicación de la diferencia -->
            <div style="margin-top: 10px; padding: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; font-size: 8px;">
                @if($diferencia >= 0)
                <strong style="color: #16a34a;">¿Por que gano L {{ number_format($diferencia, 2) }} mas de lo esperado?</strong><br>
                @else
                <strong style="color: #dc2626;">¿Por que gano L {{ number_format(abs($diferencia), 2) }} menos de lo esperado?</strong><br>
                @endif
                <span style="color: #475569;">
                    La diferencia se debe a que algunos productos se vendieron a un precio 
                    @if($diferencia >= 0)
                    <strong>mayor</strong>
                    @else
                    <strong>menor</strong>
                    @endif
                    al precio sugerido, o hubo gastos operativos durante el viaje.
                    @if($datos['total_descuentos'] > 0)
                    <br>• Descuentos otorgados (vendio mas barato): <strong style="color: #7c3aed;">-L {{ number_format($datos['total_descuentos'], 2) }}</strong>
                    @endif
                    @if($datos['total_gastos'] > 0)
                    <br>• Gastos operativos del viaje: <strong style="color: #ea580c;">-L {{ number_format($datos['total_gastos'], 2) }}</strong>
                    @endif
                </span>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- SECCION SEPARADA: ISV PARA EL SAR -->
        <!-- ============================================== -->
        @if($datos['isv_cobrado'] > 0)
        <div style="margin-top: 20px; padding-top: 15px; border-top: 3px solid #0891b2;">
            <div style="text-align: center; margin-bottom: 10px;">
                <span style="background: #0891b2; color: white; padding: 4px 15px; border-radius: 15px; font-size: 10px; font-weight: bold;">
                    SECCION FISCAL - ISV
                </span>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title" style="background: #0891b2;">DESGLOSE DE ISV - IMPUESTO SOBRE VENTAS (15%)</div>
            <div class="section-content">
                <div style="font-size: 8px; color: #64748b; margin-bottom: 8px; font-style: italic;">
                    * Esta seccion es solo para efectos fiscales. El ISV no afecta la ganancia del viaje.
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-center">Vendidos</th>
                            <th class="text-right">Costo c/ISV</th>
                            <th class="text-right">ISV Pagado</th>
                            <th class="text-right">Venta c/ISV</th>
                            <th class="text-right">ISV Cobrado</th>
                            <th class="text-right">ISV al SAR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($datos['carga_inicial'] as $item)
                        @if($item['aplica_isv'] && $item['vendida'] > 0)
                        <tr>
                            <td>{{ $item['producto'] }}</td>
                            <td class="text-center">{{ number_format($item['vendida'], 2) }}</td>
                            <td class="text-right">L {{ number_format($item['costo_con_isv'], 2) }}</td>
                            <td class="text-right color-green">L {{ number_format($item['total_isv_costo_vendido'], 2) }}</td>
                            <td class="text-right">L {{ number_format($item['precio_venta_con_isv'], 2) }}</td>
                            <td class="text-right">L {{ number_format($item['total_isv_venta_vendido'], 2) }}</td>
                            <td class="text-right" style="color: #0891b2;"><strong>L {{ number_format($item['total_isv_a_pagar'], 2) }}</strong></td>
                        </tr>
                        @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background: #ecfeff;">
                            <td colspan="3"><strong>TOTALES</strong></td>
                            <td class="text-right color-green"><strong>L {{ number_format($datos['isv_credito_fiscal'], 2) }}</strong></td>
                            <td></td>
                            <td class="text-right"><strong>L {{ number_format($datos['isv_cobrado'], 2) }}</strong></td>
                            <td class="text-right" style="color: #0891b2;"><strong>L {{ number_format($datos['isv_a_pagar_sar'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
                
                <!-- Resumen de ISV -->
                <div style="margin-top: 12px; padding: 12px; background: #f0fdfa; border: 2px solid #0891b2; border-radius: 6px;">
                    <table style="width: 100%; font-size: 10px;">
                        <tr>
                            <td style="width: 40%;">
                                <strong>ISV Cobrado a clientes:</strong><br>
                                <span style="font-size: 12px;">L {{ number_format($datos['isv_cobrado'], 2) }}</span>
                            </td>
                            <td style="width: 30%; text-align: center;">
                                <strong>(-) Credito Fiscal:</strong><br>
                                <span style="font-size: 12px; color: #16a34a;">L {{ number_format($datos['isv_credito_fiscal'], 2) }}</span>
                            </td>
                            <td style="width: 30%; text-align: right;">
                                <strong>= ISV a Pagar:</strong><br>
                                <span style="font-size: 14px; font-weight: bold; color: #0891b2;">L {{ number_format($datos['isv_a_pagar_sar'], 2) }}</span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Ejemplo detallado -->
                @php
                    $ejemploProducto = $datos['carga_inicial']->first(fn($item) => $item['aplica_isv'] && $item['vendida'] > 0);
                @endphp
                @if($ejemploProducto)
                <div style="margin-top: 10px; padding: 8px; background: #fefce8; border: 1px solid #fde047; border-radius: 4px; font-size: 8px;">
                    <strong>Ejemplo con {{ $ejemploProducto['producto'] }} (1 unidad):</strong><br>
                    <table style="width: 100%; margin-top: 4px;">
                        <tr>
                            <td style="width: 50%;">
                                <strong>COMPRA:</strong><br>
                                Pagaste: L {{ number_format($ejemploProducto['costo_con_isv'], 2) }} (incluye ISV)<br>
                                Costo real: L {{ number_format($ejemploProducto['costo_sin_isv'], 2) }}<br>
                                ISV pagado: L {{ number_format($ejemploProducto['isv_costo'], 2) }} <span style="color: #16a34a;">(credito fiscal)</span>
                            </td>
                            <td style="width: 50%;">
                                <strong>VENTA:</strong><br>
                                Vendiste a: L {{ number_format($ejemploProducto['precio_venta_con_isv'], 2) }} (incluye ISV)<br>
                                Precio real: L {{ number_format($ejemploProducto['precio_venta_sin_isv'], 2) }}<br>
                                ISV cobrado: L {{ number_format($ejemploProducto['isv_venta'], 2) }}
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding-top: 6px; border-top: 1px dashed #fde047;">
                                <table style="width: 100%;">
                                    <tr>
                                        <td><strong>Tu ganancia real:</strong> L {{ number_format($ejemploProducto['precio_venta_sin_isv'], 2) }} - L {{ number_format($ejemploProducto['costo_sin_isv'], 2) }} = <strong style="color: #16a34a;">L {{ number_format($ejemploProducto['ganancia_unitaria'], 2) }}</strong></td>
                                        <td style="text-align: right;"><strong>ISV al SAR:</strong> L {{ number_format($ejemploProducto['isv_venta'], 2) }} - L {{ number_format($ejemploProducto['isv_costo'], 2) }} = <strong style="color: #0891b2;">L {{ number_format($ejemploProducto['isv_a_pagar_unitario'], 2) }}</strong></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </div>
                @endif
            </div>
        </div>
        @endif

        <!-- Firmas -->
        <table class="signatures">
            <tr>
                <td>
                    <div class="signature-line">
                        <strong>Chofer</strong><br>
                        {{ $viaje->chofer->name ?? 'N/A' }}
                    </div>
                </td>
                <td>
                    <div class="signature-line">
                        <strong>Encargado de Bodega</strong><br>
                        &nbsp;
                    </div>
                </td>
                <td>
                    <div class="signature-line">
                        <strong>Autorizado Por</strong><br>
                        &nbsp;
                    </div>
                </td>
            </tr>
        </table>

        <!-- Footer -->
        <div class="footer">
            Documento generado el {{ $fechaImpresion }} | {{ $empresa['nombre'] ?? 'OPOA' }} - Sistema de Gestion
        </div>
    </div>
</body>
</html>