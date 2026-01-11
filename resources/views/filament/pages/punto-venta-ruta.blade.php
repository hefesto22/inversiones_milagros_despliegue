<x-filament-panels::page>
    @php
        $cantidadCarrito = $this->cantidadCarrito;
        $carrito = $this->carrito;
        $infoViaje = $this->infoViaje;
        $clienteSeleccionado = $this->clienteSeleccionado;
        $tieneCliente = $this->tieneClienteSeleccionado;
    @endphp

    {{-- Header con información del viaje --}}
    <div class="mb-6 p-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="p-3 bg-primary-100 dark:bg-primary-900 rounded-lg">
                    <x-heroicon-o-truck class="w-8 h-8 text-primary-600 dark:text-primary-400" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                        Viaje: {{ $infoViaje['numero'] }}
                    </h2>
                    <div class="flex flex-wrap gap-3 text-sm text-gray-600 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <x-heroicon-o-identification class="w-4 h-4" />
                            {{ $infoViaje['camion'] }}
                        </span>
                        <span class="flex items-center gap-1">
                            <x-heroicon-o-user class="w-4 h-4" />
                            {{ $infoViaje['chofer'] }}
                        </span>
                        <span class="flex items-center gap-1">
                            <x-heroicon-o-building-storefront class="w-4 h-4" />
                            {{ $infoViaje['bodega'] }}
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 text-sm font-medium rounded-full 
                    {{ $infoViaje['estado'] === 'En Ruta' ? 'bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300' : 'bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300' }}">
                    {{ $infoViaje['estado'] }}
                </span>
            </div>
        </div>
    </div>

    {{-- Sección de selección de cliente (OBLIGATORIO PRIMERO) --}}
    <div class="mb-6 p-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border-2 {{ $tieneCliente ? 'border-green-500 dark:border-green-600' : 'border-orange-400 dark:border-orange-500' }}">
        <div class="flex flex-wrap items-center justify-between gap-4">
            @if ($tieneCliente)
                {{-- Cliente seleccionado --}}
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-green-100 dark:bg-green-900 rounded-lg">
                        <x-heroicon-o-user-circle class="w-8 h-8 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Cliente</p>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                            {{ $clienteSeleccionado->nombre }}
                        </h3>
                        <div class="flex flex-wrap gap-3 text-sm text-gray-600 dark:text-gray-400">
                            @if ($clienteSeleccionado->rtn)
                                <span class="flex items-center gap-1">
                                    <x-heroicon-o-identification class="w-4 h-4" />
                                    {{ $clienteSeleccionado->rtn }}
                                </span>
                            @endif
                            @if ($clienteSeleccionado->telefono)
                                <span class="flex items-center gap-1">
                                    <x-heroicon-o-phone class="w-4 h-4" />
                                    {{ $clienteSeleccionado->telefono }}
                                </span>
                            @endif
                            <span class="flex items-center gap-1">
                                <x-heroicon-o-tag class="w-4 h-4" />
                                {{ ucfirst($clienteSeleccionado->tipo ?? 'general') }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if ($clienteSeleccionado->saldo_pendiente > 0)
                        <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">
                            Deuda: L {{ number_format($clienteSeleccionado->saldo_pendiente, 2) }}
                        </span>
                    @endif
                    <button wire:click="limpiarCliente"
                        class="px-4 py-2 text-sm font-medium text-white bg-gray-600 dark:bg-gray-500 rounded-lg hover:bg-gray-700 dark:hover:bg-gray-400 transition">
                        Cambiar Cliente
                    </button>
                </div>
            @else
                {{-- Sin cliente - Mostrar selector --}}
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-orange-100 dark:bg-orange-900/50 rounded-lg">
                        <x-heroicon-o-user-plus class="w-8 h-8 text-orange-600 dark:text-orange-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">
                            Seleccione un Cliente
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Debe seleccionar un cliente antes de agregar productos al carrito
                        </p>
                    </div>
                </div>
            @endif
        </div>

        @if (!$tieneCliente)
            {{-- Buscador de cliente --}}
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Buscar cliente</label>
                    <div class="relative" x-data="{ open: false, search: '', clientes: [] }" x-init="
                        $watch('search', async (value) => {
                            if (value.length >= 2) {
                                const response = await fetch(`/api/clientes/buscar?q=${encodeURIComponent(value)}`);
                                clientes = await response.json();
                                open = true;
                            } else {
                                clientes = [];
                                open = false;
                            }
                        })
                    ">
                        <input 
                            type="text" 
                            x-model="search"
                            placeholder="Escriba nombre, RTN o teléfono..."
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:ring-primary-500 focus:border-primary-500"
                        />
                        
                        {{-- Dropdown de resultados --}}
                        <div x-show="open && clientes.length > 0" 
                             x-cloak
                             @click.away="open = false"
                             class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                            <template x-for="cliente in clientes" :key="cliente.id">
                                <button 
                                    type="button"
                                    @click="$wire.seleccionarCliente(cliente.id); open = false; search = '';"
                                    class="w-full px-4 py-3 text-left hover:bg-gray-100 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 last:border-0">
                                    <div class="font-medium text-gray-900 dark:text-white" x-text="cliente.nombre"></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        <span x-show="cliente.rtn" x-text="'RTN: ' + cliente.rtn"></span>
                                        <span x-show="cliente.telefono" x-text="' • Tel: ' + cliente.telefono"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">O seleccione de la lista</label>
                    <select 
                        wire:change="seleccionarCliente($event.target.value)"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-primary-500 focus:border-primary-500">
                        <option value="">-- Seleccionar cliente --</option>
                        @foreach (\App\Models\Cliente::where('estado', true)->orderBy('nombre')->limit(50)->get() as $cliente)
                            <option value="{{ $cliente->id }}">
                                {{ $cliente->nombre }} 
                                @if ($cliente->tipo) ({{ ucfirst($cliente->tipo) }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            
            {{-- Opción consumidor final --}}
            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                <button 
                    wire:click="crearConsumidorFinal"
                    class="px-4 py-2 text-sm font-medium text-primary-700 dark:text-primary-300 bg-primary-50 dark:bg-primary-900/30 rounded-lg hover:bg-primary-100 dark:hover:bg-primary-900/50 transition">
                    <x-heroicon-o-user class="w-4 h-4 inline mr-1" />
                    Venta a Consumidor Final (sin registro)
                </button>
            </div>
        @endif
    </div>

    {{-- Botón "Ver carrito" con contador --}}
    <div class="flex justify-end mb-4">
        <button wire:click="$dispatch('open-modal', { id: 'carrito-modal' })"
            class="relative inline-flex items-center gap-2 px-5 py-2.5 text-white text-sm font-semibold rounded-lg shadow-lg transition-all duration-200 hover:scale-105"
            style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            <x-heroicon-o-shopping-cart class="w-5 h-5" />
            Ver Carrito

            @if ($cantidadCarrito > 0)
                <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full shadow-lg ring-2 ring-white dark:ring-gray-800 animate-pulse">
                    {{ $cantidadCarrito }}
                </span>
            @endif
        </button>
    </div>

    {{-- Tabla de productos disponibles --}}
    @if ($tieneCliente)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            {{ $this->table }}
        </div>
    @else
        <div class="bg-gray-50 dark:bg-gray-800 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-12 text-center">
            <x-heroicon-o-user-plus class="w-16 h-16 mx-auto text-gray-400 dark:text-gray-500" />
            <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-white">Seleccione un cliente primero</h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Debe seleccionar un cliente para ver los productos disponibles y sus precios personalizados.
            </p>
        </div>
    @endif

    {{-- Modal del carrito --}}
    <x-filament::modal id="carrito-modal" width="5xl" slide-over>
        <x-slot name="heading">
            <div class="flex items-center gap-3">
                <span class="text-2xl">🛒</span>
                <span>Carrito de Venta</span>
                @if ($cantidadCarrito > 0)
                    <span class="bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300 text-sm px-2 py-0.5 rounded-full">
                        {{ $cantidadCarrito }} items
                    </span>
                @endif
            </div>
        </x-slot>

        <div class="space-y-6">
            @if (count($carrito))
                {{-- Info del cliente en el carrito --}}
                @if ($clienteSeleccionado)
                    <div class="p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                        <div class="flex items-center gap-2 text-green-800 dark:text-green-300">
                            <x-heroicon-o-user-circle class="w-5 h-5" />
                            <span class="font-medium">{{ $clienteSeleccionado->nombre }}</span>
                            @if ($clienteSeleccionado->tipo)
                                <span class="text-sm text-green-600 dark:text-green-400">({{ ucfirst($clienteSeleccionado->tipo) }})</span>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Tabla de items del carrito --}}
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Producto</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Unidad</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Precio Base</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">ISV</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Precio Cliente</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Cantidad</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($carrito as $item)
                                @php
                                    $subtotal = $item['precio_con_isv'] * $item['cantidad'];
                                    $bajoMinimo = $item['bajo_minimo'] ?? false;
                                @endphp
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition {{ $bajoMinimo ? 'bg-red-50 dark:bg-red-900/20' : '' }}">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $item['nombre'] }}
                                        @if ($bajoMinimo)
                                            <span class="block text-xs text-red-500 dark:text-red-400">
                                                ⚠️ Bajo costo mínimo
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                            {{ $item['unidad'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">
                                        L {{ number_format($item['precio_base'], 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if ($item['aplica_isv'])
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300">
                                                +L {{ number_format($item['monto_isv'], 2) }}
                                            </span>
                                        @else
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                                                —
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-medium text-gray-900 dark:text-white">
                                        L {{ number_format($item['precio_con_isv'], 2) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-center gap-2">
                                            <button wire:click="modificarCantidad('{{ $item['uid'] }}', -1)"
                                                class="w-7 h-7 flex items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                                                <x-heroicon-o-minus class="w-4 h-4" />
                                            </button>
                                            <span class="w-12 text-center font-semibold text-gray-900 dark:text-white">
                                                {{ $item['cantidad'] }}
                                            </span>
                                            <button wire:click="modificarCantidad('{{ $item['uid'] }}', 1)"
                                                class="w-7 h-7 flex items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                                                <x-heroicon-o-plus class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-primary-600 dark:text-primary-400">
                                        L {{ number_format($subtotal, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <button wire:click="quitarDelCarrito('{{ $item['uid'] }}')"
                                            class="text-red-500 hover:text-red-700 dark:hover:text-red-400 transition">
                                            <x-heroicon-o-trash class="w-5 h-5" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Resumen y totales --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {{-- Totales --}}
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
                        <h3 class="font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            <x-heroicon-o-calculator class="w-5 h-5" />
                            Resumen
                        </h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Subtotal (sin ISV)</span>
                                <span>L {{ number_format($this->subtotalSinISV, 2) }}</span>
                            </div>
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>ISV (15%)</span>
                                <span>L {{ number_format($this->totalISV, 2) }}</span>
                            </div>
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Subtotal Bruto</span>
                                <span>L {{ number_format($this->subtotalBruto, 2) }}</span>
                            </div>
                            <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                                <label class="block text-gray-600 dark:text-gray-400 mb-1">Descuento</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">L</span>
                                    <input type="number" wire:model.live="descuento" step="0.01" min="0"
                                        class="w-full pl-8 pr-3 py-2 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-primary-500 focus:border-primary-500" />
                                </div>
                            </div>
                            <div class="pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                <span class="text-lg font-bold text-gray-900 dark:text-white">Total Final</span>
                                <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                                    L {{ number_format($this->totalFinal, 2) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Tipo de pago y observaciones --}}
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3">
                        <h3 class="font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            <x-heroicon-o-banknotes class="w-5 h-5" />
                            Pago
                        </h3>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Tipo de Pago</label>
                                <select wire:model="tipo_pago"
                                    class="w-full px-3 py-2 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-primary-500 focus:border-primary-500">
                                    <option value="contado">Contado</option>
                                    <option value="credito">Crédito</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Observaciones</label>
                                <textarea wire:model="observaciones" rows="3" placeholder="Notas adicionales..."
                                    class="w-full px-3 py-2 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm focus:ring-primary-500 focus:border-primary-500"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Modal de confirmación --}}
                @if ($this->mostrarConfirmacion)
                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-6 max-w-md w-full mx-4">
                            <div class="text-center">
                                <div class="mx-auto w-16 h-16 bg-yellow-100 dark:bg-yellow-900/30 rounded-full flex items-center justify-center mb-4">
                                    <x-heroicon-o-exclamation-triangle class="w-8 h-8 text-yellow-600 dark:text-yellow-400" />
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">
                                    ¿Confirmar Venta?
                                </h3>
                                <p class="text-gray-600 dark:text-gray-400 mb-4">
                                    Está a punto de procesar una venta por:
                                </p>
                                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400 mb-2">
                                    L {{ number_format($this->totalFinal, 2) }}
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                                    Cliente: <strong>{{ $clienteSeleccionado?->nombre ?? '---' }}</strong><br>
                                    Tipo de pago: <strong>{{ ucfirst($this->tipo_pago) }}</strong>
                                </p>
                                <div class="flex gap-3 justify-center">
                                    <button wire:click="cancelarConfirmacion"
                                        class="px-6 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                                        Cancelar
                                    </button>
                                    <button wire:click="procesarVenta"
                                        wire:loading.attr="disabled"
                                        class="px-6 py-2.5 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 transition disabled:opacity-50">
                                        <span wire:loading.remove wire:target="procesarVenta">
                                            ✓ Confirmar Venta
                                        </span>
                                        <span wire:loading wire:target="procesarVenta">
                                            Procesando...
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @else
                <div class="text-center py-12">
                    <x-heroicon-o-shopping-cart class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600" />
                    <p class="mt-4 text-gray-500 dark:text-gray-400">El carrito está vacío</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500">Agregue productos desde la tabla</p>
                </div>
            @endif
        </div>

        <x-slot name="footerActions">
            @if (count($carrito))
                <x-filament::button color="danger" wire:click="descartarVenta">
                    <x-heroicon-o-trash class="w-4 h-4 mr-1" />
                    Descartar
                </x-filament::button>
                <x-filament::button color="success" wire:click="confirmarVenta" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="confirmarVenta">
                        <x-heroicon-o-check-circle class="w-4 h-4 mr-1" />
                        Procesar Venta
                    </span>
                    <span wire:loading wire:target="confirmarVenta">
                        Procesando...
                    </span>
                </x-filament::button>
            @endif
        </x-slot>
    </x-filament::modal>

    {{-- Script para abrir ventana de impresión --}}
    @script
    <script>
        $wire.on('abrir-impresion', ({ ventaId }) => {
            window.open(`/viaje-venta/${ventaId}/imprimir`, '_blank', 'width=800,height=600');
        });
    </script>
    @endscript
</x-filament-panels::page>