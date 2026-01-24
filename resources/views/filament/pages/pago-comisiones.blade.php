<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Formulario de selección --}}
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        {{-- Resumen de totales - Stats Cards en fila --}}
        @if($this->chofer_id)
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
                {{-- Viajes --}}
                <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-x-3">
                        <div class="rounded-lg bg-primary-50 p-2 dark:bg-primary-500/10">
                            <x-heroicon-o-truck class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Viajes</span>
                            <p class="text-2xl font-semibold text-gray-950 dark:text-white">
                                {{ $this->viajesPendientes->count() }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Comisiones --}}
                <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-x-3">
                        <div class="rounded-lg bg-success-50 p-2 dark:bg-success-500/10">
                            <x-heroicon-o-currency-dollar class="h-6 w-6 text-success-600 dark:text-success-400" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Comisiones</span>
                            <p class="text-2xl font-semibold text-success-600 dark:text-success-400">
                                L {{ number_format($this->totalComisiones, 2) }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Cobros --}}
                <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-x-3">
                        <div class="rounded-lg bg-danger-50 p-2 dark:bg-danger-500/10">
                            <x-heroicon-o-arrow-trending-down class="h-6 w-6 text-danger-600 dark:text-danger-400" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Cobros</span>
                            <p class="text-2xl font-semibold text-danger-600 dark:text-danger-400">
                                L {{ number_format($this->totalCobros, 2) }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Neto a Pagar --}}
                <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center gap-x-3">
                        <div class="rounded-lg p-2 {{ $this->netoAPagar >= 0 ? 'bg-success-50 dark:bg-success-500/10' : 'bg-danger-50 dark:bg-danger-500/10' }}">
                            <x-heroicon-o-banknotes class="h-6 w-6 {{ $this->netoAPagar >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}" />
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">NETO A PAGAR</span>
                            <p class="text-2xl font-semibold {{ $this->netoAPagar >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                L {{ number_format($this->netoAPagar, 2) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Tabla de viajes --}}
        <x-filament::section>
            <x-slot name="heading">
                Viajes Pendientes de Pago
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>