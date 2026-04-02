<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Stats resumen global --}}
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
            @php
                $pendientes = \App\Models\Viaje::where('estado', 'cerrado')->where('comision_pagada', false)->count();
                $totalPendiente = \App\Models\Viaje::where('estado', 'cerrado')->where('comision_pagada', false)->sum('neto_chofer');
                $pagadasMes = \App\Models\Liquidacion::where('estado', 'pagada')
                    ->whereMonth('fecha_pago', now()->month)
                    ->whereYear('fecha_pago', now()->year)
                    ->count();
                $totalPagadoMes = \App\Models\Liquidacion::where('estado', 'pagada')
                    ->whereMonth('fecha_pago', now()->month)
                    ->whereYear('fecha_pago', now()->year)
                    ->sum('total_pagar');
            @endphp

            <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-x-3">
                    <div class="rounded-lg bg-warning-50 p-2 dark:bg-warning-500/10">
                        <x-heroicon-o-clock class="h-6 w-6 text-warning-600 dark:text-warning-400" />
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Viajes Pendientes</span>
                        <p class="text-2xl font-semibold text-warning-600 dark:text-warning-400">
                            {{ $pendientes }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-x-3">
                    <div class="rounded-lg bg-danger-50 p-2 dark:bg-danger-500/10">
                        <x-heroicon-o-exclamation-triangle class="h-6 w-6 text-danger-600 dark:text-danger-400" />
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Por Liquidar</span>
                        <p class="text-2xl font-semibold text-danger-600 dark:text-danger-400">
                            L {{ number_format($totalPendiente, 2) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-x-3">
                    <div class="rounded-lg bg-success-50 p-2 dark:bg-success-500/10">
                        <x-heroicon-o-check-circle class="h-6 w-6 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Liquidaciones (mes)</span>
                        <p class="text-2xl font-semibold text-success-600 dark:text-success-400">
                            {{ $pagadasMes }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="relative rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center gap-x-3">
                    <div class="rounded-lg bg-success-50 p-2 dark:bg-success-500/10">
                        <x-heroicon-o-banknotes class="h-6 w-6 text-success-600 dark:text-success-400" />
                    </div>
                    <div>
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Pagado (mes)</span>
                        <p class="text-2xl font-semibold text-success-600 dark:text-success-400">
                            L {{ number_format($totalPagadoMes, 2) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tabla de liquidaciones --}}
        <x-filament::section>
            <x-slot name="heading">
                Liquidaciones
            </x-slot>
            <x-slot name="description">
                Las liquidaciones se generan autom&aacute;ticamente el d&iacute;a 1 de cada mes. Use &quot;Liquidar Manualmente&quot; para casos excepcionales.
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
