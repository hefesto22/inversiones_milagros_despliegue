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
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            background: #fff;
        }

        .container {
            padding: 20px 30px;
        }

        /* Header */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 3px solid #f59e0b;
            padding-bottom: 15px;
        }

        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: middle;
        }

        .header-right {
            display: table-cell;
            width: 50%;
            text-align: right;
            vertical-align: middle;
        }

        .company-logo {
            max-height: 70px;
            max-width: 180px;
            margin-bottom: 5px;
        }

        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #b91c1c;
            margin-bottom: 3px;
        }

        .company-slogan {
            font-size: 10px;
            color: #666;
            font-style: italic;
        }

        .company-info {
            font-size: 9px;
            color: #666;
            margin-top: 5px;
        }

        .doc-title {
            font-size: 20px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .doc-number {
            font-size: 14px;
            color: #f59e0b;
            font-weight: bold;
        }

        /* Info boxes */
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .info-box {
            display: table-cell;
            width: 48%;
            padding: 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            vertical-align: top;
        }

        .info-box:first-child {
            margin-right: 4%;
        }

        .info-box-title {
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .info-box-content {
            font-size: 11px;
        }

        .info-box-content strong {
            color: #111;
        }

        .info-row {
            margin-bottom: 4px;
        }

        /* Estado badge */
        .estado-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .estado-cotizacion {
            background: #fef3c7;
            color: #92400e;
        }

        /* Validity notice */
        .validity-notice {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 6px;
            padding: 10px 15px;
            margin-bottom: 20px;
            text-align: center;
        }

        .validity-notice strong {
            color: #92400e;
        }

        /* Table */
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .products-table th {
            background: #b91c1c;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .products-table th:first-child {
            border-radius: 6px 0 0 0;
        }

        .products-table th:last-child {
            border-radius: 0 6px 0 0;
        }

        .products-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        .products-table tr:nth-child(even) {
            background: #f9fafb;
        }

        .products-table tr:last-child td {
            border-bottom: 2px solid #b91c1c;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .product-name {
            font-weight: 600;
            color: #111;
        }

        .product-unit {
            font-size: 9px;
            color: #6b7280;
        }

        /* Totals */
        .totals-section {
            display: table;
            width: 100%;
        }

        .totals-spacer {
            display: table-cell;
            width: 60%;
        }

        .totals-box {
            display: table-cell;
            width: 40%;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .totals-table .label {
            text-align: left;
            color: #6b7280;
        }

        .totals-table .value {
            text-align: right;
            font-weight: 600;
        }

        .totals-table .total-row {
            background: #b91c1c;
            color: white;
        }

        .totals-table .total-row td {
            padding: 12px;
            font-size: 14px;
            font-weight: bold;
            border: none;
        }

        /* Notes */
        .notes-section {
            margin-top: 25px;
            padding: 12px;
            background: #f3f4f6;
            border-radius: 6px;
            border-left: 4px solid #f59e0b;
        }

        .notes-title {
            font-size: 10px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .notes-content {
            font-size: 10px;
            color: #6b7280;
        }

        /* Footer */
        .footer {
            position: fixed;
            bottom: 20px;
            left: 30px;
            right: 30px;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            font-size: 9px;
            color: #9ca3af;
            text-align: center;
        }

        /* ISV indicator */
        .isv-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .isv-yes {
            background: #10b981;
        }

        .isv-no {
            background: #9ca3af;
        }

        .isv-legend {
            font-size: 9px;
            color: #6b7280;
            margin-top: 10px;
        }

        .spacer {
            width: 4%;
            display: table-cell;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                @if($empresa?->logo)
                    <img src="{{ storage_path('app/public/' . $empresa->logo) }}" class="company-logo" alt="Logo">
                @endif
                <div class="company-name">{{ $empresa?->nombre ?? 'Mi Empresa' }}</div>
                @if($empresa?->lema)
                    <div class="company-slogan">{{ $empresa->lema }}</div>
                @endif
                <div class="company-info">
                    @if($empresa?->rtn)
                        RTN: {{ $empresa->rtn }}<br>
                    @endif
                    @if($empresa?->telefono)
                        Tel: {{ $empresa->telefono }}
                    @endif
                    @if($empresa?->correo_electronico)
                        | {{ $empresa->correo_electronico }}
                    @endif
                </div>
            </div>
            <div class="header-right">
                <div class="doc-title">COTIZACIÓN</div>
                <div class="doc-number">{{ $numeroCotizacion }}</div>
                <span class="estado-badge estado-cotizacion">Pendiente de Confirmación</span>
            </div>
        </div>

        <!-- Validity Notice -->
        <div class="validity-notice">
            <strong>⏱ Esta cotización es válida hasta el {{ $fechaValidez }}</strong>
            <br>
            <span style="font-size: 10px; color: #6b7280;">Los precios están sujetos a cambios después de esta fecha</span>
        </div>

        <!-- Info Section -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-box-title">📋 Datos del Cliente</div>
                <div class="info-box-content">
                    <div class="info-row"><strong>{{ $venta->cliente->nombre ?? 'Sin cliente' }}</strong></div>
                    @if($venta->cliente?->rtn)
                        <div class="info-row">RTN: {{ $venta->cliente->rtn }}</div>
                    @endif
                    @if($venta->cliente?->telefono)
                        <div class="info-row">📞 {{ $venta->cliente->telefono }}</div>
                    @endif
                    @if($venta->cliente?->direccion)
                        <div class="info-row">📍 {{ $venta->cliente->direccion }}</div>
                    @endif
                    <div class="info-row" style="margin-top: 6px;">
                        <span style="background: #e5e7eb; padding: 2px 8px; border-radius: 10px; font-size: 9px;">
                            {{ ucfirst($venta->cliente?->tipo ?? 'Cliente') }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="spacer"></div>
            <div class="info-box">
                <div class="info-box-title">📄 Datos de la Cotización</div>
                <div class="info-box-content">
                    <div class="info-row"><strong>Fecha:</strong> {{ $fechaEmision }}</div>
                    <div class="info-row"><strong>Válida hasta:</strong> {{ $fechaValidez }}</div>
                    <div class="info-row"><strong>Bodega:</strong> {{ $venta->bodega->nombre ?? 'N/A' }}</div>
                    <div class="info-row"><strong>Vendedor:</strong> {{ $venta->creador->name ?? 'N/A' }}</div>
                    <div class="info-row"><strong>Forma de Pago:</strong> 
                        @switch($venta->tipo_pago)
                            @case('efectivo') Efectivo @break
                            @case('transferencia') Transferencia @break
                            @case('tarjeta') Tarjeta @break
                            @case('credito') Crédito @break
                            @default {{ $venta->tipo_pago }}
                        @endswitch
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 35%;">Producto</th>
                    <th style="width: 10%;" class="text-center">Cantidad</th>
                    <th style="width: 15%;" class="text-right">Precio Unit.</th>
                    <th style="width: 10%;" class="text-center">ISV</th>
                    <th style="width: 12%;" class="text-right">ISV Total</th>
                    <th style="width: 13%;" class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($venta->detalles as $index => $detalle)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        <span class="product-name">{{ $detalle->producto->nombre ?? 'Producto' }}</span>
                        <br>
                        <span class="product-unit">{{ $detalle->unidad->nombre ?? '' }}</span>
                    </td>
                    <td class="text-center">{{ number_format($detalle->cantidad, 2) }}</td>
                    <td class="text-right">L {{ number_format($detalle->precio_unitario, 2) }}</td>
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
        </table>

        <div class="isv-legend">
            <span class="isv-indicator isv-yes"></span> Producto gravado con ISV (15%)
            &nbsp;&nbsp;
            <span class="isv-indicator isv-no"></span> Producto exento de ISV
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-spacer"></div>
            <div class="totals-box">
                <table class="totals-table">
                    <tr>
                        <td class="label">Subtotal:</td>
                        <td class="value">L {{ number_format($venta->subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">ISV (15%):</td>
                        <td class="value">L {{ number_format($venta->total_isv, 2) }}</td>
                    </tr>
                    @if($venta->descuento > 0)
                    <tr>
                        <td class="label">Descuento:</td>
                        <td class="value" style="color: #dc2626;">- L {{ number_format($venta->descuento, 2) }}</td>
                    </tr>
                    @endif
                    <tr class="total-row">
                        <td>TOTAL:</td>
                        <td class="text-right">L {{ number_format($venta->total, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Notes -->
        @if($venta->nota)
        <div class="notes-section">
            <div class="notes-title">📝 Observaciones</div>
            <div class="notes-content">{{ $venta->nota }}</div>
        </div>
        @endif

        <div class="notes-section" style="margin-top: 15px; background: #ecfdf5; border-left-color: #10b981;">
            <div class="notes-title" style="color: #065f46;">📞 ¿Desea confirmar esta cotización?</div>
            <div class="notes-content" style="color: #047857;">
                @if($empresa?->telefono)
                    Contáctenos al {{ $empresa->telefono }}
                @else
                    Contacte a su vendedor
                @endif
                para confirmar esta cotización y proceder con la venta.
                <br>
                Esta cotización no reserva inventario. Los productos están sujetos a disponibilidad.
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            Cotización generada el {{ $fechaEmision }} | {{ $empresa?->nombre ?? 'Sistema de Ventas' }}
            <br>
            @if($empresa?->direccion)
                {{ $empresa->direccion }}
                <br>
            @endif
            Este documento es una cotización y no tiene valor fiscal. Válido hasta {{ $fechaValidez }}.
        </div>
    </div>
</body>
</html>