<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Pagos Realizados
        </x-slot>
        <x-slot name="description">
            Historial de comisiones pagadas a los choferes
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>