<x-filament-panels::page>
    {{-- Iframe oculto para imprimir sin abrir nueva ventana --}}
    <iframe id="printFrame" style="display: none; position: absolute; width: 0; height: 0;"></iframe>
    
    <script>
        function imprimirFactura(url) {
            const iframe = document.getElementById('printFrame');
            iframe.src = url;
            
            iframe.onload = function() {
                setTimeout(function() {
                    iframe.contentWindow.print();
                }, 500);
            };
        }
    </script>
    {{-- Estadísticas en fila horizontal - usando flex inline como en Gastos del Camión --}}
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        {{-- Total Facturas --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-xl font-bold">
                    {{ number_format($this->estadisticas['total_facturas']) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Total Facturas
                </div>
            </div>
        </div>

        {{-- Facturas Hoy --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-xl font-bold text-primary-600 dark:text-primary-400">
                    {{ number_format($this->estadisticas['facturas_hoy']) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Hoy
                </div>
            </div>
        </div>

        {{-- Vendido Hoy --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-xl font-bold text-success-600 dark:text-success-400">
                    L {{ number_format($this->estadisticas['total_vendido_hoy'], 2) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Vendido Hoy
                </div>
            </div>
        </div>

        {{-- Por Cobrar --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-xl font-bold text-danger-600 dark:text-danger-400">
                    L {{ number_format($this->estadisticas['pendiente_cobro'], 2) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Por Cobrar
                </div>
            </div>
        </div>
    </div>

    {{-- Filtros rápidos en fila horizontal --}}
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        {{-- Ventas Bodega --}}
        <div 
            style="flex: 1; min-width: 150px; cursor: pointer;" 
            class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 hover:ring-2 hover:ring-primary-500 transition {{ $filtroTipo === 'bodega' ? 'ring-2 ring-primary-500' : '' }}"
            wire:click="$set('filtroTipo', '{{ $filtroTipo === 'bodega' ? '' : 'bodega' }}')"
        >
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <x-heroicon-o-building-storefront class="w-5 h-5 text-primary-500" />
                    <span class="text-sm font-medium">Bodega</span>
                </div>
                <span class="text-lg font-bold">{{ $this->estadisticas['ventas_bodega'] }}</span>
            </div>
        </div>

        {{-- Ventas Ruta --}}
        <div 
            style="flex: 1; min-width: 150px; cursor: pointer;" 
            class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 hover:ring-2 hover:ring-warning-500 transition {{ $filtroTipo === 'viaje' ? 'ring-2 ring-warning-500' : '' }}"
            wire:click="$set('filtroTipo', '{{ $filtroTipo === 'viaje' ? '' : 'viaje' }}')"
        >
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <x-heroicon-o-truck class="w-5 h-5 text-warning-500" />
                    <span class="text-sm font-medium">Ruta</span>
                </div>
                <span class="text-lg font-bold">{{ $this->estadisticas['ventas_viaje'] }}</span>
            </div>
        </div>

        {{-- Filtrar Hoy --}}
        <div 
            style="flex: 1; min-width: 150px; cursor: pointer;" 
            class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 hover:ring-2 hover:ring-info-500 transition {{ $fechaDesde === now()->format('Y-m-d') ? 'ring-2 ring-info-500' : '' }}"
            wire:click="filtrarHoy"
        >
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <x-heroicon-o-funnel class="w-5 h-5 text-info-500" />
                    <span class="text-sm font-medium">Solo Hoy</span>
                </div>
                <x-heroicon-o-arrow-right class="w-4 h-4 text-gray-400" />
            </div>
        </div>

        {{-- Vendido Mes --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <x-heroicon-o-chart-bar class="w-5 h-5 text-success-500" />
                    <span class="text-sm font-medium">Mes</span>
                </div>
                <span class="text-lg font-bold text-success-600 dark:text-success-400">L {{ number_format($this->estadisticas['total_vendido_mes'], 0) }}</span>
            </div>
        </div>
    </div>

    {{-- Filtros avanzados --}}
    <x-filament::section class="mb-6">
        <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 1rem;">
            {{-- Búsqueda --}}
            <div style="grid-column: span 2;">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        wire:model.live.debounce.300ms="busqueda"
                        placeholder="Buscar factura, cliente..."
                    />
                </x-filament::input.wrapper>
            </div>

            {{-- Tipo --}}
            <div>
                <select wire:model.live="filtroTipo" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm h-10">
                    <option value="">Tipo: Todos</option>
                    <option value="bodega">Bodega</option>
                    <option value="viaje">Ruta</option>
                </select>
            </div>

            {{-- Pago --}}
            <div>
                <select wire:model.live="filtroPago" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm h-10">
                    <option value="">Pago: Todos</option>
                    <option value="efectivo">Efectivo</option>
                    <option value="contado">Contado</option>
                    <option value="credito">Crédito</option>
                    <option value="transferencia">Transferencia</option>
                </select>
            </div>

            {{-- Estado --}}
            <div>
                <select wire:model.live="filtroEstado" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm h-10">
                    <option value="">Estado: Todos</option>
                    <option value="pagado">Pagado</option>
                    <option value="pendiente">Pendiente</option>
                </select>
            </div>

            {{-- Limpiar --}}
            <div>
                <x-filament::button 
                    wire:click="limpiarFiltros" 
                    color="gray"
                    class="w-full"
                >
                    Limpiar
                </x-filament::button>
            </div>
        </div>

        {{-- Fechas --}}
        <div style="display: flex; gap: 1rem; margin-top: 1rem;">
            <div style="width: 150px;">
                <input 
                    type="date" 
                    wire:model.live="fechaDesde" 
                    placeholder="Desde"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm h-10"
                >
            </div>
            <div style="width: 150px;">
                <input 
                    type="date" 
                    wire:model.live="fechaHasta" 
                    placeholder="Hasta"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm h-10"
                >
            </div>
        </div>
    </x-filament::section>

    {{-- Tabla de facturas --}}
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">No. Factura</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Tipo</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Fecha</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Cliente</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Pago</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Total</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500 dark:text-gray-400">Saldo</th>
                        <th class="px-4 py-3 text-center font-medium text-gray-500 dark:text-gray-400">Estado</th>
                        <th class="px-4 py-3 text-center font-medium text-gray-500 dark:text-gray-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($this->facturasPaginadas['data'] as $factura)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-medium">
                            {{ $factura->numero_factura }}
                        </td>
                        <td class="px-4 py-3">
                            @if($factura->tipo === 'bodega')
                                <x-filament::badge color="info" size="sm">
                                    Bodega
                                </x-filament::badge>
                            @else
                                <x-filament::badge color="warning" size="sm">
                                    Ruta
                                </x-filament::badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                            <div>{{ $factura->fecha->format('d/m/Y') }}</div>
                            <div class="text-xs">{{ $factura->fecha->format('H:i') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            {{ Str::limit($factura->cliente_nombre, 20) }}
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $colorPago = match($factura->tipo_pago) {
                                    'efectivo', 'contado' => 'success',
                                    'credito' => 'warning',
                                    'transferencia' => 'info',
                                    default => 'gray'
                                };
                            @endphp
                            <x-filament::badge :color="$colorPago" size="sm">
                                {{ ucfirst($factura->tipo_pago) }}
                            </x-filament::badge>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-success-600 dark:text-success-400">
                            L {{ number_format($factura->total, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right {{ $factura->saldo_pendiente > 0 ? 'font-semibold text-danger-600 dark:text-danger-400' : 'text-gray-400' }}">
                            L {{ number_format($factura->saldo_pendiente, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($factura->estado_pago === 'pagado')
                                <x-filament::badge color="success" size="sm">
                                    Pagado
                                </x-filament::badge>
                            @elseif($factura->estado_pago === 'parcial')
                                <x-filament::badge color="warning" size="sm">
                                    Parcial
                                </x-filament::badge>
                            @else
                                <x-filament::badge color="danger" size="sm">
                                    Pendiente
                                </x-filament::badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 0.25rem;">
                                <button
                                    type="button"
                                    onclick="imprimirFactura('{{ $factura->url_imprimir }}')"
                                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 h-8 w-8 text-success-600 hover:bg-success-50 focus-visible:ring-success-600 dark:text-success-400 dark:hover:bg-success-500/10"
                                    title="Imprimir"
                                >
                                    <x-heroicon-o-printer class="w-5 h-5" />
                                </button>
                                @if($factura->url_ver)
                                <x-filament::icon-button
                                    icon="heroicon-o-eye"
                                    color="info"
                                    size="sm"
                                    tag="a"
                                    :href="$factura->url_ver"
                                    tooltip="Ver detalles"
                                />
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center">
                            <x-heroicon-o-document-text class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" />
                            <p class="text-gray-500 dark:text-gray-400">No se encontraron facturas</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Paginación --}}
        @if($this->facturasPaginadas['total'] > $porPagina)
        <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 1rem; margin-top: 1rem; border-top: 1px solid;" class="border-gray-200 dark:border-gray-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ ($this->facturasPaginadas['current_page'] - 1) * $porPagina + 1 }}-{{ min($this->facturasPaginadas['current_page'] * $porPagina, $this->facturasPaginadas['total']) }} 
                de {{ $this->facturasPaginadas['total'] }}
            </p>
            <div style="display: flex; gap: 0.5rem;">
                @if($this->facturasPaginadas['current_page'] > 1)
                <x-filament::button wire:click="previousPage" size="sm" color="gray">
                    Anterior
                </x-filament::button>
                @endif
                @if($this->facturasPaginadas['current_page'] < $this->facturasPaginadas['last_page'])
                <x-filament::button wire:click="nextPage" size="sm" color="gray">
                    Siguiente
                </x-filament::button>
                @endif
            </div>
        </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>