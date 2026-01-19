<x-filament-panels::page>
    {{-- Resumen rápido --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-primary-600">
                    L {{ number_format($this->totalHoy, 2) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Total Gastado Hoy
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-warning-600">
                    {{ $this->gastosPendientes }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Pendientes de Aprobar
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-2xl font-bold text-success-600">
                    {{ now()->format('d/m/Y') }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Fecha de Hoy
                </div>
            </div>
        </x-filament::section>
    </div>

    {{-- Formulario (visible solo cuando se activa) --}}
    @if($mostrarFormulario)
        <x-filament::section class="mb-6">
            <form wire:submit="guardarGasto">
                {{ $this->form }}

                <div class="flex justify-end gap-3 mt-4">
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="$set('mostrarFormulario', false)"
                    >
                        Cancelar
                    </x-filament::button>

                    <x-filament::button
                        type="submit"
                        color="primary"
                        icon="heroicon-o-check"
                    >
                        Guardar Gasto
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif

    {{-- Tabla de gastos --}}
    <x-filament::section>
        <x-slot name="heading">
            Mis Gastos Registrados
        </x-slot>

        <x-slot name="description">
            Después de registrar un gasto, presiona "Enviar WhatsApp" para enviar el comprobante.
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>