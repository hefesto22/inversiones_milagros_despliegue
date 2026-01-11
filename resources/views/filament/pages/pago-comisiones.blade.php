<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Formulario de selección --}}
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        {{-- Resumen de totales --}}
        @if($this->chofer_id)
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <x-filament::section>
                    <div class="text-center">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Viajes</div>
                        <div class="text-2xl font-bold text-primary-600">
                            {{ $this->viajesPendientes->count() }}
                        </div>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-center">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Comisiones</div>
                        <div class="text-2xl font-bold text-success-600">
                            L {{ number_format($this->totalComisiones, 2) }}
                        </div>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-center">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Cobros</div>
                        <div class="text-2xl font-bold text-danger-600">
                            L {{ number_format($this->totalCobros, 2) }}
                        </div>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-center">
                        <div class="text-sm text-gray-500 dark:text-gray-400">NETO A PAGAR</div>
                        <div class="text-3xl font-bold {{ $this->netoAPagar >= 0 ? 'text-success-600' : 'text-danger-600' }}">
                            L {{ number_format($this->netoAPagar, 2) }}
                        </div>
                    </div>
                </x-filament::section>
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