<x-filament-panels::page>
    @if($this->viajeActivo)
        {{-- Información del viaje --}}
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
            {{-- Viaje --}}
            <div style="flex: 1; min-width: 140px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-center">
                    <div class="text-xl font-bold text-primary-600">
                        Viaje #{{ $this->viajeActivo->id }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $this->viajeActivo->fecha_salida?->format('d/m/Y') }}
                    </div>
                </div>
            </div>

            {{-- Camión --}}
            <div style="flex: 1; min-width: 140px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-center">
                    <div class="text-xl font-bold text-primary-600">
                        {{ $this->viajeActivo->camion?->codigo ?? 'N/A' }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $this->viajeActivo->camion?->placa ?? 'Camión' }}
                    </div>
                </div>
            </div>

            {{-- Bodega Origen --}}
            <div style="flex: 1; min-width: 140px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-center">
                    <div class="text-xl font-bold text-info-600">
                        {{ $this->viajeActivo->bodegaOrigen?->nombre ?? 'N/A' }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Bodega Origen
                    </div>
                </div>
            </div>

            {{-- Estado --}}
            <div style="flex: 1; min-width: 140px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-center">
                    <div class="text-xl font-bold text-warning-600">
                        {{ ucfirst(str_replace('_', ' ', $this->viajeActivo->estado)) }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Estado del Viaje
                    </div>
                </div>
            </div>

            {{-- Total Productos --}}
            <div style="flex: 1; min-width: 140px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-center">
                    <div class="text-xl font-bold text-success-600">
                        {{ $this->resumenCarga['total_productos'] }} productos
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $this->resumenCarga['total_items'] }} unidades
                    </div>
                </div>
            </div>

            {{-- Valor Total Carga --}}
            <div style="flex: 1; min-width: 140px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-center">
                    <div class="text-xl font-bold text-success-600">
                        L {{ number_format($this->resumenCarga['total_venta'], 2) }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Valor Total (Venta)
                    </div>
                </div>
            </div>
        </div>

        {{-- Tabla de cargas --}}
        {{ $this->table }}
    @else
        {{-- Sin viaje activo --}}
        <div class="rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-6xl mb-4">🚛</div>
                <h2 class="text-2xl font-bold text-gray-700 dark:text-gray-300 mb-2">
                    No tienes viaje asignado
                </h2>
                <p class="text-gray-500 dark:text-gray-400">
                    Cuando te asignen un viaje, aquí podrás ver el detalle de la carga.
                </p>
            </div>
        </div>
    @endif
</x-filament-panels::page>
