<div class="space-y-6">
    {{-- Encabezado con datos del cliente y fecha --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Cliente --}}
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cliente</span>
            </div>
            <div class="bg-white dark:bg-gray-900 p-4">
                <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $venta->cliente?->nombre ?? 'Consumidor Final' }}</p>
                @if($venta->cliente?->rtn)
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">RTN: {{ $venta->cliente->rtn }}</p>
                @endif
                @if($venta->cliente?->telefono)
                    <p class="text-sm text-gray-600 dark:text-gray-400">Tel: {{ $venta->cliente->telefono }}</p>
                @endif
            </div>
        </div>

        {{-- Datos de la venta --}}
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Datos de Venta</span>
            </div>
            <div class="bg-white dark:bg-gray-900 p-4">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Fecha:</span>
                        <span class="ml-2 text-gray-900 dark:text-white font-medium">{{ $venta->fecha_venta->format('d/m/Y') }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Hora:</span>
                        <span class="ml-2 text-gray-900 dark:text-white font-medium">{{ $venta->fecha_venta->format('H:i') }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Vendedor:</span>
                        <span class="ml-2 text-gray-900 dark:text-white font-medium">{{ $venta->userCreador?->name ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Factura:</span>
                        <span class="ml-2 text-gray-900 dark:text-white font-medium">{{ $venta->numero_factura ?? 'Pendiente' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Detalle de productos --}}
    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
        <div class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Detalle de Productos</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Descripción</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Cant.</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">P. Unit.</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">ISV</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Importe</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($venta->detalles as $detalle)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                {{ $detalle->producto?->nombre ?? 'Producto eliminado' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-center text-gray-700 dark:text-gray-300">
                                {{ number_format($detalle->cantidad, 2) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">
                                {{ number_format($detalle->precio_base, 2) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">
                                {{ $detalle->aplica_isv ? number_format($detalle->monto_isv * $detalle->cantidad, 2) : '0.00' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-medium text-gray-900 dark:text-white">
                                {{ number_format($detalle->total_linea, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Totales --}}
    <div class="flex justify-end">
        <div class="w-full max-w-sm border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex justify-between px-4 py-2 text-sm">
                    <span class="text-gray-600 dark:text-gray-400">Subtotal</span>
                    <span class="text-gray-900 dark:text-white">L {{ number_format($venta->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between px-4 py-2 text-sm">
                    <span class="text-gray-600 dark:text-gray-400">ISV (15%)</span>
                    <span class="text-gray-900 dark:text-white">L {{ number_format($venta->impuesto, 2) }}</span>
                </div>
                @if($venta->descuento > 0)
                    <div class="flex justify-between px-4 py-2 text-sm">
                        <span class="text-gray-600 dark:text-gray-400">Descuento</span>
                        <span class="text-red-600 dark:text-red-400">- L {{ number_format($venta->descuento, 2) }}</span>
                    </div>
                @endif
                <div class="flex justify-between px-4 py-3 bg-gray-50 dark:bg-gray-800">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">TOTAL</span>
                    <span class="text-base font-bold text-gray-900 dark:text-white">L {{ number_format($venta->total, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Estado y tipo de pago --}}
    <div class="grid grid-cols-2 gap-4">
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="bg-white dark:bg-gray-900 p-4 flex items-center justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">Tipo de Pago</span>
                <span class="text-sm font-semibold px-3 py-1 rounded-full 
                    {{ $venta->tipo_pago === 'contado' 
                        ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400' 
                        : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' }}">
                    {{ ucfirst($venta->tipo_pago) }}
                </span>
            </div>
        </div>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="bg-white dark:bg-gray-900 p-4 flex items-center justify-between">
                <span class="text-sm text-gray-600 dark:text-gray-400">Estado</span>
                <span class="text-sm font-semibold px-3 py-1 rounded-full 
                    @if($venta->estado === 'completada') bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400
                    @elseif($venta->estado === 'cancelada') bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400
                    @else bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300
                    @endif">
                    {{ ucfirst($venta->estado) }}
                </span>
            </div>
        </div>
    </div>

    {{-- Notas --}}
    @if($venta->nota)
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Observaciones</span>
            </div>
            <div class="bg-white dark:bg-gray-900 p-4">
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $venta->nota }}</p>
            </div>
        </div>
    @endif
</div>