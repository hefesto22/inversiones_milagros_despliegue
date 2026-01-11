<div class="space-y-6">
    {{-- Información del Cliente --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Cliente</p>
                <p class="font-semibold text-gray-900 dark:text-white">{{ $venta->cliente?->nombre ?? 'Consumidor Final' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">RTN</p>
                <p class="font-medium text-gray-700 dark:text-gray-300">{{ $venta->cliente?->rtn ?? '-' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Teléfono</p>
                <p class="font-medium text-gray-700 dark:text-gray-300">{{ $venta->cliente?->telefono ?? '-' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Fecha</p>
                <p class="font-medium text-gray-700 dark:text-gray-300">{{ $venta->fecha_venta->format('d/m/Y H:i') }}</p>
            </div>
        </div>
    </div>

    {{-- Tabla de Productos --}}
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Producto</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Cantidad</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Precio Base</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ISV</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Precio Cliente</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($venta->detalles as $detalle)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">
                            {{ $detalle->producto?->nombre ?? 'Producto eliminado' }}
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-700 dark:text-gray-300">
                            {{ number_format($detalle->cantidad, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">
                            L {{ number_format($detalle->precio_base, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">
                            @if($detalle->aplica_isv)
                                <span class="text-green-600 dark:text-green-400">
                                    +L {{ number_format($detalle->monto_isv, 2) }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                            L {{ number_format($detalle->precio_con_isv, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-primary-600 dark:text-primary-400">
                            L {{ number_format($detalle->total_linea, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Totales --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
        <div class="flex justify-end">
            <div class="w-full max-w-xs space-y-2">
                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                    <span>Subtotal (sin ISV)</span>
                    <span>L {{ number_format($venta->subtotal, 2) }}</span>
                </div>
                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                    <span>ISV (15%)</span>
                    <span>L {{ number_format($venta->impuesto, 2) }}</span>
                </div>
                @if($venta->descuento > 0)
                    <div class="flex justify-between text-sm text-red-600 dark:text-red-400">
                        <span>Descuento</span>
                        <span>- L {{ number_format($venta->descuento, 2) }}</span>
                    </div>
                @endif
                <div class="pt-2 border-t border-gray-200 dark:border-gray-700 flex justify-between">
                    <span class="text-lg font-bold text-gray-900 dark:text-white">Total</span>
                    <span class="text-lg font-bold text-primary-600 dark:text-primary-400">
                        L {{ number_format($venta->total, 2) }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Información adicional --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Tipo de Pago</p>
            <p class="font-semibold {{ $venta->tipo_pago === 'contado' ? 'text-green-600 dark:text-green-400' : 'text-orange-600 dark:text-orange-400' }}">
                {{ $venta->tipo_pago === 'contado' ? 'Contado' : 'Crédito' }}
            </p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Estado</p>
            <p class="font-semibold 
                @if($venta->estado === 'completada') text-green-600 dark:text-green-400
                @elseif($venta->estado === 'cancelada') text-red-600 dark:text-red-400
                @else text-gray-700 dark:text-gray-300
                @endif">
                {{ ucfirst($venta->estado) }}
            </p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Vendedor</p>
            <p class="font-medium text-gray-700 dark:text-gray-300">{{ $venta->userCreador?->name ?? '-' }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">No. Factura</p>
            <p class="font-medium text-gray-700 dark:text-gray-300">{{ $venta->numero_factura ?? 'Pendiente' }}</p>
        </div>
    </div>

    @if($venta->nota)
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
            <p class="text-xs text-yellow-600 dark:text-yellow-400 uppercase mb-1">Notas</p>
            <p class="text-sm text-yellow-800 dark:text-yellow-200">{{ $venta->nota }}</p>
        </div>
    @endif
</div>