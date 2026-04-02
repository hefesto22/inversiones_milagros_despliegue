<div class="space-y-4">
    {{-- Resumen de la liquidación --}}
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800 text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">Viajes</div>
            <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $viajes->count() }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800 text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">Comisiones</div>
            <div class="text-lg font-bold text-success-600 dark:text-success-400">L {{ number_format($liquidacion->total_comisiones, 2) }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800 text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">Cobros</div>
            <div class="text-lg font-bold text-danger-600 dark:text-danger-400">L {{ number_format($liquidacion->total_cobros, 2) }}</div>
        </div>
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800 text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">Neto Pagado</div>
            <div class="text-lg font-bold {{ $liquidacion->total_pagar >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                L {{ number_format($liquidacion->total_pagar, 2) }}
            </div>
        </div>
    </div>

    {{-- Tabla de viajes --}}
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">No. Viaje</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Fecha</th>
                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Ventas</th>
                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Comisi&oacute;n</th>
                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Cobros</th>
                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Neto</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($viajes as $viaje)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">{{ $viaje->numero_viaje }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $viaje->fecha_salida->format('d/m/Y') }}</td>
                    <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-400">L {{ number_format($viaje->total_vendido, 2) }}</td>
                    <td class="px-3 py-2 text-right text-success-600 dark:text-success-400">L {{ number_format($viaje->comision_ganada, 2) }}</td>
                    <td class="px-3 py-2 text-right text-danger-600 dark:text-danger-400">
                        {{ $viaje->cobros_devoluciones > 0 ? 'L ' . number_format($viaje->cobros_devoluciones, 2) : '-' }}
                    </td>
                    <td class="px-3 py-2 text-right font-medium {{ $viaje->neto_chofer >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                        L {{ number_format($viaje->neto_chofer, 2) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
