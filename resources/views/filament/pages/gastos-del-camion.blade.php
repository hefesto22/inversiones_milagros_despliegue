<x-filament-panels::page>
    {{-- Resumen rápido - en fila horizontal --}}
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        {{-- Camión Asignado --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                @if($this->tieneCamionAsignado)
                    <div class="text-xl font-bold text-primary-600">
                        🚛 {{ $this->camionAsignado->codigo }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $this->camionAsignado->placa }}
                    </div>
                @else
                    <div class="text-xl font-bold text-danger-600">
                        Sin asignar
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Camión
                    </div>
                @endif
            </div>
        </div>

        {{-- Total Hoy --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-xl font-bold text-primary-600">
                    L {{ number_format($this->totalHoy, 2) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Gastado Hoy
                </div>
            </div>
        </div>

        {{-- Total del Viaje o Mes --}}
        <div style="flex: 1; min-width: 150px;" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-center">
                <div class="text-xl font-bold text-info-600">
                    L {{ number_format($this->totalMes, 2) }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    {{ $this->viajeActivo ? 'Total Viaje' : 'Este Mes' }}
                </div>
            </div>
        </div>
    </div>

    {{-- Alerta si no tiene camión asignado (solo si tampoco tiene viaje) --}}
    @if(!$this->tieneCamionAsignado && !$this->viajeActivo)
        <div class="mb-6 p-3 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg">
            <div class="flex items-center gap-2 text-warning-700 dark:text-warning-400 text-sm">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 flex-shrink-0" />
                <span>No tienes un camión asignado. Contacta a tu supervisor.</span>
            </div>
        </div>
    @endif

    {{-- Info del viaje activo o alerta si no hay viaje --}}
    @if($this->viajeActivo)
        @php
            $estadosLabels = [
                'planificado' => ['label' => 'Planificado', 'color' => 'gray', 'icon' => 'heroicon-o-clock'],
                'cargando' => ['label' => 'Cargando', 'color' => 'info', 'icon' => 'heroicon-o-arrow-down-tray'],
                'en_ruta' => ['label' => 'En Ruta', 'color' => 'success', 'icon' => 'heroicon-o-truck'],
                'regresando' => ['label' => 'Regresando', 'color' => 'warning', 'icon' => 'heroicon-o-arrow-uturn-left'],
                'descargando' => ['label' => 'Descargando', 'color' => 'info', 'icon' => 'heroicon-o-arrow-up-tray'],
                'liquidando' => ['label' => 'Liquidando', 'color' => 'purple', 'icon' => 'heroicon-o-calculator'],
                'cerrado' => ['label' => 'Cerrado', 'color' => 'gray', 'icon' => 'heroicon-o-check-circle'],
                'cancelado' => ['label' => 'Cancelado', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'],
            ];
            $estadoActual = $estadosLabels[$this->viajeActivo->estado] ?? ['label' => $this->viajeActivo->estado, 'color' => 'gray', 'icon' => 'heroicon-o-question-mark-circle'];
        @endphp

        @if($this->puedeRegistrarGastos)
            {{-- Viaje en estado operativo - puede registrar gastos --}}
            <div class="mb-6 p-3 bg-success-50 dark:bg-success-900/20 border border-success-200 dark:border-success-700 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-success-700 dark:text-success-400 text-sm">
                        <x-heroicon-o-truck class="w-5 h-5 flex-shrink-0" />
                        <span>
                            <strong>Viaje:</strong> 
                            {{ $this->viajeActivo->numero_viaje ?? 'Viaje #' . $this->viajeActivo->id }}
                        </span>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-full bg-{{ $estadoActual['color'] }}-100 text-{{ $estadoActual['color'] }}-700 dark:bg-{{ $estadoActual['color'] }}-900/30 dark:text-{{ $estadoActual['color'] }}-400">
                        {{ $estadoActual['label'] }}
                    </span>
                </div>
            </div>
        @else
            {{-- Viaje existe pero en estado no operativo (planificado, liquidando, etc) --}}
            <div class="mb-6 p-4 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg">
                <div class="flex items-center gap-3 text-warning-700 dark:text-warning-400">
                    <x-heroicon-o-clock class="w-6 h-6 flex-shrink-0" />
                    <div>
                        <p class="font-medium">Viaje {{ $this->viajeActivo->numero_viaje ?? '#' . $this->viajeActivo->id }} - {{ $estadoActual['label'] }}</p>
                        <p class="text-sm opacity-80">No puedes registrar gastos en este estado. El viaje debe estar en carga, en ruta, regresando o descargando.</p>
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="mb-6 p-4 bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-700 rounded-lg">
            <div class="flex items-center gap-3 text-danger-700 dark:text-danger-400">
                <x-heroicon-o-exclamation-circle class="w-6 h-6 flex-shrink-0" />
                <div>
                    <p class="font-medium">No tienes un viaje activo</p>
                    <p class="text-sm opacity-80">No puedes registrar gastos hasta que inicies un viaje.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Formulario (visible solo cuando se activa Y puede registrar gastos) --}}
    @if($mostrarFormulario && $this->puedeRegistrarGastos)
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

    {{-- Tabla de gastos (solo si hay viaje activo) --}}
    @if($this->viajeActivo)
        <x-filament::section>
            <x-slot name="heading">
                Gastos del Viaje
            </x-slot>

            <x-slot name="description">
                @if($this->puedeRegistrarGastos)
                    Después de registrar un gasto, presiona "Enviar" para enviarlo por WhatsApp.
                @else
                    Gastos registrados en este viaje (solo lectura).
                @endif
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    @endif
</x-filament-panels::page>