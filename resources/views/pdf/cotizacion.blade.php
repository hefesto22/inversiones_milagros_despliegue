<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Cotización {{ $numeroCotizacion }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #1a1a2e;
            background: #fff;
        }

        .container {
            padding: 15px 30px;
        }

        /* HEADER */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .header-table td {
            vertical-align: top;
            padding: 0;
        }

        .header-left {
            width: 55%;
        }

        .header-right {
            width: 45%;
            text-align: right;
        }

        .company-logo {
            max-height: 110px;
            max-width: 260px;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #0f1b3d;
            margin-bottom: 2px;
            letter-spacing: 0.5px;
        }

        .company-slogan {
            font-size: 9px;
            color: #6b7280;
            font-style: italic;
            margin-bottom: 3px;
        }

        .company-info-line {
            font-size: 9px;
            color: #4b5563;
            line-height: 1.5;
        }

        .doc-title {
            font-size: 22px;
            font-weight: bold;
            color: #0f1b3d;
            margin-bottom: 2px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .doc-number {
            font-size: 13px;
            color: #8b7332;
            font-weight: bold;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }

        .estado-badge {
            display: inline-block;
            padding: 3px 14px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f8f5ec;
            color: #8b7332;
            border: 1px solid #d4c48a;
        }

        .header-divider {
            border: none;
            border-top: 2px solid #0f1b3d;
            margin: 12px 0 4px 0;
        }

        .header-divider-gold {
            border: none;
            border-top: 1px solid #c9a84c;
            margin: 0 0 12px 0;
        }

        /* VALIDITY NOTICE */
        .validity-notice {
            background: #f8f5ec;
            border: 1px solid #d4c48a;
            border-radius: 3px;
            padding: 6px 15px;
            margin-bottom: 12px;
            text-align: center;
            font-size: 10px;
        }

        .validity-notice strong {
            color: #6b5a1e;
        }

        .validity-sub {
            font-size: 9px;
            color: #6b7280;
        }

        /* INFO BOXES */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        .info-table > tbody > tr > td {
            vertical-align: top;
        }

        .info-box {
            padding: 8px 10px;
            background: #fafbfc;
            border: 1px solid #d1d5db;
            border-top: 3px solid #0f1b3d;
        }

        .info-box-title {
            font-size: 9px;
            text-transform: uppercase;
            color: #0f1b3d;
            margin-bottom: 5px;
            font-weight: bold;
            letter-spacing: 0.8px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
        }

        .info-box-content {
            font-size: 10px;
            line-height: 1.5;
        }

        .info-box-content strong {
            color: #0f1b3d;
        }

        .info-label {
            color: #6b7280;
            font-size: 9px;
        }

        .tipo-badge {
            display: inline-block;
            background: #e8eaed;
            padding: 1px 8px;
            border-radius: 2px;
            font-size: 8px;
            color: #374151;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.3px;
        }

        .credito-badge {
            display: inline-block;
            background: #f8f5ec;
            padding: 1px 8px;
            border-radius: 2px;
            font-size: 8px;
            color: #6b5a1e;
            font-weight: bold;
            border: 1px solid #d4c48a;
        }

        /* PRODUCTS TABLE */
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        .products-table th {
            background: #0f1b3d;
            color: #ffffff;
            padding: 7px 6px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .products-table td {
            padding: 6px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
            font-size: 10px;
            color: #1a1a2e;
        }

        .products-table tr:nth-child(even) {
            background: #f8f9fb;
        }

        .products-table tr:last-child td {
            border-bottom: 2px solid #0f1b3d;
        }

        .products-table tfoot td {
            background: #f0f1f5;
            font-weight: bold;
            border-top: 2px solid #0f1b3d;
            border-bottom: none;
            font-size: 9px;
            color: #0f1b3d;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .product-name {
            font-weight: bold;
            color: #0f1b3d;
            font-size: 10px;
        }

        .product-unit {
            font-size: 8px;
            color: #6b7280;
        }

        .isv-indicator {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            margin-right: 3px;
        }

        .isv-yes { background: #0f1b3d; }
        .isv-no { background: #c4c4c4; }

        .isv-legend {
            font-size: 8px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        /* TOTALS */
        .totals-outer {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        .totals-outer td {
            vertical-align: top;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #d1d5db;
        }

        .totals-table td {
            padding: 6px 12px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 10px;
        }

        .totals-table .label {
            text-align: left;
            color: #4b5563;
        }

        .totals-table .value {
            text-align: right;
            font-weight: 600;
            color: #0f1b3d;
        }

        .totals-table .total-row {
            background: #0f1b3d;
            color: #ffffff;
        }

        .totals-table .total-row td {
            padding: 8px 12px;
            font-size: 13px;
            font-weight: bold;
            border: none;
        }

        .amount-words {
            font-size: 9px;
            color: #374151;
            font-style: italic;
            padding: 5px 12px;
            background: #f8f9fb;
            border: 1px solid #d1d5db;
            border-top: none;
        }

        /* NOTES & CONDITIONS */
        .notes-section {
            margin-bottom: 8px;
            padding: 8px 10px;
            background: #f8f9fb;
            border: 1px solid #d1d5db;
            border-left: 4px solid #8b7332;
        }

        .notes-title {
            font-size: 9px;
            font-weight: bold;
            color: #0f1b3d;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .notes-content {
            font-size: 9px;
            color: #4b5563;
            line-height: 1.5;
        }

        .confirm-section {
            margin-bottom: 8px;
            padding: 8px 10px;
            background: #f8f9fb;
            border: 1px solid #d1d5db;
            border-left: 4px solid #0f1b3d;
        }

        .confirm-section .notes-title {
            color: #0f1b3d;
        }

        .confirm-section .notes-content {
            color: #374151;
        }

        .conditions-section {
            margin-bottom: 8px;
            padding: 8px 10px;
            background: #f8f9fb;
            border: 1px solid #d1d5db;
            border-left: 4px solid #4b5563;
        }

        .conditions-section .notes-title {
            color: #0f1b3d;
        }

        .conditions-section .notes-content {
            color: #4b5563;
        }

        .conditions-list {
            margin: 0;
            padding-left: 14px;
            font-size: 9px;
            color: #4b5563;
            line-height: 1.6;
        }

        /* FOOTER - flows with content, not fixed */
        .footer {
            margin-top: 15px;
            border-top: 1px solid #c9a84c;
            padding-top: 6px;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
            line-height: 1.5;
        }

        .footer-bold {
            font-weight: bold;
            color: #4b5563;
        }

        .footer-line2 {
            border-top: 1px solid #e5e7eb;
            margin-top: 3px;
            padding-top: 3px;
        }
    </style>
</head>
<body>
    <div class="container">

        <table class="header-table">
            <tr>
                <td class="header-left">
                    @if($empresa?->logo)
                        <img src="{{ $logoPath }}" class="company-logo" alt="Logo">
                        <br>
                    @endif
                    <div class="company-name">{{ $empresa?->nombre ?? 'Mi Empresa' }}</div>
                    @if($empresa?->lema)
                        <div class="company-slogan">{{ $empresa->lema }}</div>
                    @endif
                    <div class="company-info-line">
                        @if($empresa?->rtn)
                            <strong>RTN:</strong> {{ $empresa->rtn }}<br>
                        @endif
                        @if($empresa?->direccion)
                            {{ $empresa->direccion }}<br>
                        @endif
                        @if($empresa?->telefono)
                            Tel: {{ $empresa->telefono }}
                        @endif
                        @if($empresa?->correo_electronico)
                            | {{ $empresa->correo_electronico }}
                        @endif
                    </div>
                </td>
                <td class="header-right">
                    <div class="doc-title">Cotización</div>
                    <div class="doc-number">{{ $numeroCotizacion }}</div>
                    <div style="margin-bottom: 4px;">
                        <span class="estado-badge">Pendiente de Confirmación</span>
                    </div>
                    <div style="font-size: 9px; color: #6b7280; margin-top: 8px;">
                        <strong style="color: #0f1b3d;">Fecha:</strong> {{ $fechaEmision }}<br>
                        <strong style="color: #0f1b3d;">Válida hasta:</strong> {{ $fechaValidez }}
                    </div>
                </td>
            </tr>
        </table>
        <hr class="header-divider">
        <hr class="header-divider-gold">

        <div class="validity-notice">
            <strong>Esta cotización es válida hasta el {{ $fechaValidez }}</strong>
            <br>
            <span class="validity-sub">Los precios y disponibilidad están sujetos a cambios después de esta fecha.</span>
        </div>

        <table class="info-table">
            <tr>
                <td style="width: 48%; padding-right: 2%;">
                    <div class="info-box">
                        <div class="info-box-title">Datos del Cliente</div>
                        <div class="info-box-content">
                            <strong style="font-size: 11px;">{{ $venta->cliente->nombre ?? 'Sin cliente' }}</strong><br>
                            @if($venta->cliente?->rtn)
                                <span class="info-label">RTN:</span> <strong>{{ $venta->cliente->rtn }}</strong><br>
                            @endif
                            @if($venta->cliente?->telefono)
                                <span class="info-label">Teléfono:</span> {{ $venta->cliente->telefono }}<br>
                            @endif
                            @if($venta->cliente?->email)
                                <span class="info-label">Email:</span> {{ $venta->cliente->email }}<br>
                            @endif
                            @if($venta->cliente?->direccion)
                                <span class="info-label">Dirección:</span> {{ $venta->cliente->direccion }}<br>
                            @endif
                            <div style="margin-top: 4px;">
                                <span class="tipo-badge">{{ ucfirst($venta->cliente?->tipo ?? 'cliente') }}</span>
                                @if($venta->tipo_pago === 'credito' && $venta->cliente?->dias_credito > 0)
                                    <span class="credito-badge">Crédito {{ $venta->cliente->dias_credito }} días</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </td>
                <td style="width: 48%; padding-left: 2%;">
                    <div class="info-box">
                        <div class="info-box-title">Datos de la Cotización</div>
                        <div class="info-box-content">
                            <span class="info-label">No. Cotización:</span> <strong>{{ $numeroCotizacion }}</strong><br>
                            <span class="info-label">Fecha de Emisión:</span> {{ $fechaEmision }}<br>
                            <span class="info-label">Válida Hasta:</span> {{ $fechaValidez }}<br>
                            <span class="info-label">Bodega:</span> {{ $venta->bodega->nombre ?? 'N/A' }}<br>
                            <span class="info-label">Vendedor:</span> {{ $venta->creador->name ?? 'N/A' }}<br>
                            <span class="info-label">Forma de Pago:</span>
                            <strong>
                                @switch($venta->tipo_pago)
                                    @case('efectivo') Efectivo @break
                                    @case('transferencia') Transferencia Bancaria @break
                                    @case('tarjeta') Tarjeta de Crédito/Débito @break
                                    @case('credito') Crédito @break
                                    @default {{ ucfirst($venta->tipo_pago) }}
                                @endswitch
                            </strong>
                            @if($venta->tipo_pago === 'credito' && $venta->cliente?->dias_credito > 0)
                                <br><span class="info-label">Plazo de Crédito:</span> <strong>{{ $venta->cliente->dias_credito }} días</strong>
                            @endif
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 4%;" class="text-center">#</th>
                    <th style="width: 32%;">Producto</th>
                    <th style="width: 9%;" class="text-center">Cant.</th>
                    <th style="width: 13%;" class="text-right">P. Unitario</th>
                    <th style="width: 12%;" class="text-right">Subtotal</th>
                    <th style="width: 8%;" class="text-center">ISV</th>
                    <th style="width: 11%;" class="text-right">ISV (L)</th>
                    <th style="width: 11%;" class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalCantidad = 0;
                    $totalSubtotalLineas = 0;
                    $totalIsvLineas = 0;
                    $totalTotalLineas = 0;
                @endphp
                @foreach($venta->detalles as $index => $detalle)
                    @php
                        $subtotalLinea = $detalle->cantidad * $detalle->precio_unitario;
                        $totalCantidad += $detalle->cantidad;
                        $totalSubtotalLineas += $subtotalLinea;
                        $totalIsvLineas += $detalle->total_isv;
                        $totalTotalLineas += $detalle->total_linea;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>
                            <span class="product-name">{{ $detalle->producto->nombre ?? 'Producto' }}</span>
                            @if($detalle->unidad?->nombre)
                                <br><span class="product-unit">{{ $detalle->unidad->nombre }}</span>
                            @endif
                        </td>
                        <td class="text-center">{{ number_format($detalle->cantidad, 2) }}</td>
                        <td class="text-right">L {{ number_format($detalle->precio_unitario, 2) }}</td>
                        <td class="text-right">L {{ number_format($subtotalLinea, 2) }}</td>
                        <td class="text-center">
                            @if($detalle->aplica_isv)
                                <span class="isv-indicator isv-yes"></span> 15%
                            @else
                                <span class="isv-indicator isv-no"></span> N/A
                            @endif
                        </td>
                        <td class="text-right">L {{ number_format($detalle->total_isv, 2) }}</td>
                        <td class="text-right"><strong>L {{ number_format($detalle->total_linea, 2) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="text-center" colspan="2" style="font-size: 9px; color: #4b5563;">
                        {{ $venta->detalles->count() }} {{ $venta->detalles->count() === 1 ? 'producto' : 'productos' }}
                    </td>
                    <td class="text-center">{{ number_format($totalCantidad, 2) }}</td>
                    <td class="text-right"></td>
                    <td class="text-right">L {{ number_format($totalSubtotalLineas, 2) }}</td>
                    <td class="text-center"></td>
                    <td class="text-right">L {{ number_format($totalIsvLineas, 2) }}</td>
                    <td class="text-right">L {{ number_format($totalTotalLineas, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="isv-legend">
            @if($venta->detalles->where('aplica_isv', true)->count() > 0)
                <span class="isv-indicator isv-yes"></span> Gravado con ISV (15%)
                &nbsp;&nbsp;&nbsp;
            @endif
            @if($venta->detalles->where('aplica_isv', false)->count() > 0 || $venta->detalles->whereNull('aplica_isv')->count() > 0)
                <span class="isv-indicator isv-no"></span> Exento de ISV
            @endif
        </div>

        <table class="totals-outer">
            <tr>
                <td style="width: 55%;"></td>
                <td style="width: 45%;">
                    <table class="totals-table">
                        <tr>
                            <td class="label">Subtotal (Exento):</td>
                            <td class="value">L {{ number_format($venta->subtotal - $subtotalGravado, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="label">Subtotal (Gravado):</td>
                            <td class="value">L {{ number_format($subtotalGravado, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="label">ISV (15%):</td>
                            <td class="value">L {{ number_format($venta->total_isv, 2) }}</td>
                        </tr>
                        @if($venta->descuento > 0)
                            <tr>
                                <td class="label">Descuento:</td>
                                <td class="value" style="color: #991b1b;">- L {{ number_format($venta->descuento, 2) }}</td>
                            </tr>
                        @endif
                        <tr class="total-row">
                            <td>TOTAL:</td>
                            <td class="text-right">L {{ number_format($venta->total, 2) }}</td>
                        </tr>
                    </table>
                    @if($totalEnLetras)
                        <div class="amount-words">
                            <strong>Son:</strong> {{ $totalEnLetras }}
                        </div>
                    @endif
                </td>
            </tr>
        </table>

        @if($venta->nota)
            <div class="notes-section">
                <div class="notes-title">Observaciones</div>
                <div class="notes-content">{{ $venta->nota }}</div>
            </div>
        @endif

        <div class="conditions-section">
            <div class="notes-title">Condiciones Comerciales</div>
            <div class="notes-content">
                <ol class="conditions-list">
                    <li>Esta cotización tiene una validez de 7 días calendario a partir de la fecha de emisión.</li>
                    <li>Los precios están expresados en Lempiras (L) e incluyen el desglose de ISV donde aplica.</li>
                    <li>Esta cotización no reserva inventario. Los productos están sujetos a disponibilidad al momento de la confirmación.</li>
                    @if($venta->tipo_pago === 'credito' && $venta->cliente?->dias_credito > 0)
                        <li>Condiciones de crédito: {{ $venta->cliente->dias_credito }} días a partir de la fecha de facturación.</li>
                    @endif
                    <li>Para confirmar esta cotización, comuníquese con su vendedor asignado.</li>
                </ol>
            </div>
        </div>

        <div class="confirm-section">
            <div class="notes-title">¿Desea confirmar esta cotización?</div>
            <div class="notes-content">
                @if($empresa?->telefono)
                    Contáctenos al <strong>{{ $empresa->telefono }}</strong>
                @endif
                @if($empresa?->correo_electronico)
                    o escriba a <strong>{{ $empresa->correo_electronico }}</strong>
                @endif
                para confirmar y proceder con su pedido.
            </div>
        </div>

        <div class="footer">
            <span class="footer-bold">{{ $empresa?->nombre ?? 'Sistema de Ventas' }}</span>
            @if($empresa?->rtn)
                | RTN: {{ $empresa->rtn }}
            @endif
            @if($empresa?->telefono)
                | Tel: {{ $empresa->telefono }}
            @endif
            <br>
            @if($empresa?->direccion)
                {{ $empresa->direccion }}
            @endif
            <div class="footer-line2">
                Este documento es una cotización y no tiene valor fiscal. | Generado el {{ $fechaEmision }} | Válido hasta {{ $fechaValidez }}
            </div>
        </div>
    </div>
</body>
</html>