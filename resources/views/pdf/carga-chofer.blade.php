<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Carga Chofer {{ $viaje->numero_viaje }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
        }

        .container {
            padding: 20px;
        }

        .header {
            border-bottom: 3px solid #1e40af;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #1e40af;
        }

        .company-info {
            font-size: 10px;
            color: #666;
        }

        .document-title {
            font-size: 18px;
            font-weight: bold;
            color: #1e40af;
            text-align: right;
            margin-top: -45px;
        }

        .document-number {
            font-size: 14px;
            text-align: right;
            color: #333;
            font-weight: bold;
        }

        .info-box {
            background: #f0f9ff;
            border: 2px solid #0284c7;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .info-grid {
            width: 100%;
        }

        .info-grid td {
            padding: 5px 10px;
            vertical-align: top;
        }

        .info-label {
            font-weight: bold;
            color: #0369a1;
            font-size: 10px;
            width: 80px;
        }

        .info-value {
            color: #1e293b;
            font-size: 12px;
            font-weight: bold;
        }

        .section {
            margin-bottom: 20px;
        }

        .section-title {
            background: #1e40af;
            color: white;
            padding: 10px 15px;
            font-size: 13px;
            font-weight: bold;
            border-radius: 6px 6px 0 0;
        }

        .section-title-green {
            background: #166534;
        }

        .section-title-orange {
            background: #ea580c;
        }

        .section-content {
            border: 2px solid #e2e8f0;
            border-top: none;
            border-radius: 0 0 6px 6px;
            padding: 15px;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        table.data-table th {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
            font-weight: bold;
            color: #475569;
        }

        table.data-table td {
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
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

        .text-bold {
            font-weight: bold;
        }

        .color-green {
            color: #16a34a;
        }

        .color-blue {
            color: #0284c7;
        }

        .color-orange {
            color: #ea580c;
        }

        .bg-green {
            background: #dcfce7 !important;
        }

        .bg-blue {
            background: #dbeafe !important;
        }

        .bg-gray {
            background: #f1f5f9 !important;
        }

        .summary-box {
            background: #166534;
            color: white;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .summary-box h3 {
            margin-bottom: 15px;
            font-size: 14px;
            border-bottom: 1px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }

        .summary-grid {
            width: 100%;
        }

        .summary-grid td {
            padding: 8px;
            vertical-align: middle;
        }

        .summary-label {
            font-size: 11px;
            opacity: 0.9;
        }

        .summary-value {
            font-size: 16px;
            font-weight: bold;
            text-align: right;
        }

        .big-total {
            background: rgba(255,255,255,0.2);
            border-radius: 6px;
            padding: 15px;
            margin-top: 10px;
        }

        .big-total-label {
            font-size: 12px;
        }

        .big-total-value {
            font-size: 24px;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
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

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .venta-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .venta-header {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .venta-cliente {
            font-weight: bold;
            font-size: 12px;
        }

        .venta-total {
            font-weight: bold;
            font-size: 14px;
            color: #166534;
        }

        .signatures {
            margin-top: 50px;
            width: 100%;
        }

        .signatures td {
            width: 50%;
            text-align: center;
            padding-top: 50px;
        }

        .signature-line {
            border-top: 2px solid #333;
            width: 70%;
            margin: 0 auto;
            padding-top: 8px;
            font-weight: bold;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            font-size: 9px;
            color: #94a3b8;
            text-align: center;
        }

        .check-row {
            border-left: 4px solid #e2e8f0;
        }

        .check-row td:first-child::before {
            content: "☐ ";
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-name">{{ $empresa['nombre'] ?? 'EMPRESA' }}</div>
            <div class="company-info">
                Tel: {{ $empresa['telefono'] ?? '' }}
            </div>
            <div class="document-title">HOJA DE CARGA</div>
            <div class="document-number">{{ $viaje->numero_viaje }}</div>
        </div>

        <!-- Info del viaje -->
        <div class="info-box">
            <table class="info-grid">
                <tr>
                    <td class="info-label">CHOFER:</td>
                    <td class="info-value">{{ $viaje->chofer->name ?? 'N/A' }}</td>
                    <td class="info-label">CAMION:</td>
                    <td class="info-value">{{ $viaje->camion->placa ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="info-label">FECHA:</td>
                    <td class="info-value">{{ $viaje->fecha_salida ? \Carbon\Carbon::parse($viaje->fecha_salida)->format('d/m/Y') : now()->format('d/m/Y') }}</td>
                    <td class="info-label">BODEGA:</td>
                    <td class="info-value">{{ $viaje->bodegaOrigen->nombre ?? 'N/A' }}</td>
                </tr>
            </table>
        </div>

        <!-- Productos Cargados -->
        <div class="section">
            <div class="section-title">PRODUCTOS CARGADOS ({{ $datos['carga']->count() }} productos)</div>
            <div class="section-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Producto</th>
                            <th class="text-center" style="width: 12%;">Unidad</th>
                            <th class="text-center" style="width: 12%;">Cantidad</th>
                            <th class="text-right" style="width: 18%;">Precio Venta</th>
                            <th class="text-right" style="width: 18%;">Total Esperado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($datos['carga'] as $carga)
                        <tr class="check-row">
                            <td>{{ $carga['producto'] }}</td>
                            <td class="text-center">{{ $carga['unidad'] }}</td>
                            <td class="text-center text-bold">{{ number_format($carga['cantidad'], 2) }}</td>
                            <td class="text-right">L {{ number_format($carga['precio_venta'], 2) }}</td>
                            <td class="text-right text-bold color-blue">L {{ number_format($carga['total_esperado'], 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center">Sin productos cargados</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="bg-blue">
                            <td colspan="2"><strong>TOTALES</strong></td>
                            <td class="text-center"><strong>{{ number_format($datos['total_productos_cargados'], 2) }}</strong></td>
                            <td></td>
                            <td class="text-right" style="font-size: 13px;"><strong>L {{ number_format($datos['total_esperado'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Ventas Realizadas -->
        @if($datos['ventas']->count() > 0)
        <div class="section">
            <div class="section-title section-title-green">VENTAS REALIZADAS ({{ $datos['cantidad_ventas'] }} ventas)</div>
            <div class="section-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 15%;">No. Venta</th>
                            <th style="width: 35%;">Cliente</th>
                            <th class="text-center" style="width: 15%;">Tipo Pago</th>
                            <th class="text-right" style="width: 20%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($datos['ventas'] as $venta)
                        <tr>
                            <td>{{ $venta['numero'] }}</td>
                            <td>{{ $venta['cliente'] }}</td>
                            <td class="text-center">
                                @if($venta['tipo_pago'] == 'Crédito')
                                    <span class="badge badge-warning">Crédito</span>
                                @else
                                    <span class="badge badge-success">Contado</span>
                                @endif
                            </td>
                            <td class="text-right text-bold">L {{ number_format($venta['total'], 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-green">
                            <td colspan="3"><strong>TOTAL VENDIDO</strong></td>
                            <td class="text-right" style="font-size: 13px;"><strong>L {{ number_format($datos['total_vendido'], 2) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Desglose por tipo de pago -->
                <table style="width: 50%; margin-top: 15px; margin-left: auto;">
                    <tr>
                        <td style="padding: 5px 10px;">Ventas de Contado:</td>
                        <td style="padding: 5px 10px; text-align: right; font-weight: bold;" class="color-green">L {{ number_format($datos['total_vendido_contado'], 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 5px 10px;">Ventas a Crédito:</td>
                        <td style="padding: 5px 10px; text-align: right; font-weight: bold;" class="color-orange">L {{ number_format($datos['total_vendido_credito'], 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>
        @endif

        <!-- Resumen de Entrega -->
        <div class="summary-box">
            <h3>RESUMEN DE ENTREGA</h3>
            <table class="summary-grid">
                <tr>
                    <td class="summary-label">Total Vendido (Contado):</td>
                    <td class="summary-value">L {{ number_format($datos['total_vendido_contado'], 2) }}</td>
                </tr>
                <tr>
                    <td class="summary-label">Total Vendido (Crédito):</td>
                    <td class="summary-value" style="opacity: 0.8;">L {{ number_format($datos['total_vendido_credito'], 2) }}</td>
                </tr>
            </table>
            <div class="big-total">
                <table style="width: 100%;">
                    <tr>
                        <td class="big-total-label">EFECTIVO A ENTREGAR:</td>
                        <td class="big-total-value text-right">L {{ number_format($datos['efectivo_entregar'], 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Firmas -->
        <table class="signatures">
            <tr>
                <td>
                    <div class="signature-line">
                        CHOFER<br>
                        <span style="font-weight: normal; font-size: 10px;">{{ $viaje->chofer->name ?? '' }}</span>
                    </div>
                </td>
                <td>
                    <div class="signature-line">
                        ENCARGADO DE BODEGA<br>
                        <span style="font-weight: normal; font-size: 10px;">&nbsp;</span>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Footer -->
        <div class="footer">
            Documento generado el {{ $fechaImpresion }} | {{ $empresa['nombre'] ?? 'OPOA' }}
        </div>
    </div>
</body>
</html>