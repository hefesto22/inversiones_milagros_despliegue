<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura {{ $venta->numero_venta }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            padding: 10px;
            max-width: 80mm;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .header .logo {
            max-width: 60px;
            max-height: 60px;
            margin-bottom: 5px;
        }
        .header h1 {
            font-size: 16px;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 10px;
            color: #666;
        }
        .header .lema {
            font-style: italic;
            margin-top: 5px;
        }
        .info-section {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #000;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .info-label {
            font-weight: bold;
        }
        .cliente-section {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #000;
        }
        .productos-table {
            width: 100%;
            margin-bottom: 10px;
        }
        .productos-table th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding: 3px 0;
            font-size: 10px;
        }
        .productos-table td {
            padding: 5px 0;
            vertical-align: top;
            font-size: 11px;
        }
        .productos-table .qty {
            text-align: center;
            width: 30px;
        }
        .productos-table .price {
            text-align: right;
            width: 60px;
        }
        .productos-table .total {
            text-align: right;
            width: 70px;
            font-weight: bold;
        }
        .producto-nombre {
            font-weight: bold;
        }
        .producto-detalle {
            font-size: 10px;
            color: #666;
        }
        .totales-section {
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-top: 10px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .total-row.grand-total {
            font-size: 14px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        .pago-info {
            background: #e8f5e9;
            padding: 8px;
            margin-top: 10px;
            text-align: center;
            font-size: 11px;
            border: 1px dashed #4caf50;
        }
        .credito-info {
            background: #fef3c7;
            padding: 8px;
            margin-top: 10px;
            text-align: center;
            font-size: 11px;
            border: 1px dashed #f59e0b;
        }
        .cai-section {
            margin-top: 10px;
            padding: 8px;
            background: #f9f9f9;
            font-size: 9px;
            text-align: center;
            border: 1px dashed #ccc;
        }
        .cai-section p {
            margin: 2px 0;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #000;
            font-size: 10px;
        }
        .footer p {
            margin-bottom: 5px;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
        .print-button {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        .print-button:hover {
            background: #059669;
        }
        .back-button {
            position: fixed;
            top: 10px;
            left: 10px;
            padding: 10px 20px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .back-button:hover {
            background: #4b5563;
        }
    </style>
</head>
<body>
    <a href="{{ url()->previous() }}" class="back-button no-print">← Volver</a>
    <button class="print-button no-print" onclick="window.print()">🖨️ Imprimir</button>

    <div class="header">
        @if ($empresa && $empresa->logo)
            <img src="{{ asset('storage/' . $empresa->logo) }}" alt="Logo" class="logo">
        @endif
        <h1>{{ $empresa->nombre ?? 'Mi Empresa' }}</h1>
        @if($empresa?->rtn)
            <p>RTN: {{ $empresa->rtn }}</p>
        @endif
        @if($empresa?->telefono)
            <p>Tel: {{ $empresa->telefono }}</p>
        @endif
        @if($empresa?->direccion)
            <p>{{ $empresa->direccion }}</p>
        @endif
        @if ($empresa && $empresa->lema)
            <p class="lema">{{ $empresa->lema }}</p>
        @endif
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="info-label">No. Factura:</span>
            <span>{{ $venta->numero_venta ?? 'BORRADOR' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Fecha:</span>
            <span>{{ $venta->created_at->format('d/m/Y H:i') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Bodega:</span>
            <span>{{ $venta->bodega?->nombre ?? '---' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Vendedor:</span>
            <span>{{ $venta->creador?->name ?? '---' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Forma Pago:</span>
            <span>
                @switch($venta->tipo_pago)
                    @case('efectivo') EFECTIVO @break
                    @case('transferencia') TRANSFERENCIA @break
                    @case('tarjeta') TARJETA @break
                    @case('credito') CRÉDITO @break
                    @default {{ strtoupper($venta->tipo_pago) }}
                @endswitch
            </span>
        </div>
    </div>

    <div class="cliente-section">
        <div class="info-row">
            <span class="info-label">Cliente:</span>
            <span>{{ $venta->cliente?->nombre ?? 'Consumidor Final' }}</span>
        </div>
        @if($venta->cliente?->rtn)
        <div class="info-row">
            <span class="info-label">RTN:</span>
            <span>{{ $venta->cliente->rtn }}</span>
        </div>
        @endif
        @if($venta->cliente?->direccion)
        <div class="info-row">
            <span class="info-label">Dir:</span>
            <span>{{ Str::limit($venta->cliente->direccion, 30) }}</span>
        </div>
        @endif
        @if($venta->cliente?->telefono)
        <div class="info-row">
            <span class="info-label">Tel:</span>
            <span>{{ $venta->cliente->telefono }}</span>
        </div>
        @endif
    </div>

    <table class="productos-table">
        <thead>
            <tr>
                <th>Producto</th>
                <th class="qty">Cant</th>
                <th class="price">Precio</th>
                <th class="total">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($venta->detalles as $detalle)
            <tr>
                <td>
                    <div class="producto-nombre">{{ Str::limit($detalle->producto?->nombre ?? 'Producto', 20) }}</div>
                    @if($detalle->aplica_isv)
                    <div class="producto-detalle">+ISV: L {{ number_format($detalle->isv_unitario, 2) }}/u</div>
                    @else
                    <div class="producto-detalle">Sin ISV</div>
                    @endif
                </td>
                <td class="qty">{{ number_format($detalle->cantidad, $detalle->cantidad == intval($detalle->cantidad) ? 0 : 2) }}</td>
                <td class="price">L {{ number_format($detalle->precio_unitario, 2) }}</td>
                <td class="total">L {{ number_format($detalle->total_linea, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totales-section">
        <div class="total-row">
            <span>Subtotal:</span>
            <span>L {{ number_format($venta->subtotal, 2) }}</span>
        </div>
        <div class="total-row">
            <span>ISV (15%):</span>
            <span>L {{ number_format($venta->total_isv, 2) }}</span>
        </div>
        @if($venta->descuento > 0)
        <div class="total-row">
            <span>Descuento:</span>
            <span>- L {{ number_format($venta->descuento, 2) }}</span>
        </div>
        @endif
        <div class="total-row grand-total">
            <span>TOTAL:</span>
            <span>L {{ number_format($venta->total, 2) }}</span>
        </div>
    </div>

    {{-- Estado de pago --}}
    @if($venta->estado_pago === 'pagado')
    <div class="pago-info">
        <strong>PAGADO</strong><br>
        Monto: L {{ number_format($venta->monto_pagado, 2) }}
    </div>
    @elseif($venta->tipo_pago === 'credito' || $venta->saldo_pendiente > 0)
    <div class="credito-info">
        <strong>CRÉDITO</strong><br>
        Pagado: L {{ number_format($venta->monto_pagado, 2) }}<br>
        Saldo Pendiente: L {{ number_format($venta->saldo_pendiente, 2) }}
        @if($venta->fecha_vencimiento)
            <br>Vence: {{ $venta->fecha_vencimiento->format('d/m/Y') }}
        @endif
    </div>
    @endif

    @if($venta->nota)
    <div style="margin-top: 10px; padding: 8px; background: #f3f4f6; font-size: 10px;">
        <strong>Nota:</strong> {{ $venta->nota }}
    </div>
    @endif

    {{-- Información del CAI --}}
    @if ($empresa && $empresa->cai && $venta->numero_venta)
    <div class="cai-section">
        <p><strong>CAI:</strong> {{ $empresa->cai }}</p>
        @if($empresa->rango_desde && $empresa->rango_hasta)
            <p><strong>Rango:</strong> {{ $empresa->rango_desde }} al {{ $empresa->rango_hasta }}</p>
        @endif
        @if($empresa->fecha_limite_emision)
            <p><strong>Fecha Límite:</strong> {{ $empresa->fecha_limite_emision->format('d/m/Y') }}</p>
        @endif
    </div>
    @endif

    <div class="footer">
        <p>¡Gracias por su compra!</p>
        @if ($empresa && $empresa->correo_electronico)
            <p>{{ $empresa->correo_electronico }}</p>
        @endif
        <p style="margin-top: 10px; font-size: 9px; color: #999;">
            Impreso: {{ now()->format('d/m/Y H:i:s') }}
        </p>
    </div>
</body>
</html>