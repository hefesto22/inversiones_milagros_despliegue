<x-filament-panels::page>
    {{-- Header con botón Ver Carrito --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-4">
            {{-- Bodega --}}
            <select
                wire:model.live="bodega_id"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm focus:border-primary-500 focus:ring-primary-500"
            >
                <option value="">Seleccionar Bodega...</option>
                @foreach($this->bodegas as $bodega)
                    <option value="{{ $bodega->id }}">{{ $bodega->nombre }}</option>
                @endforeach
            </select>

            {{-- Cliente seleccionado o buscador --}}
            @if($this->cliente)
                <div class="flex items-center gap-2 px-3 py-1.5 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg text-sm">
                    <span class="font-medium">{{ $this->cliente->nombre }}</span>
                    <button wire:click="limpiarCliente" class="text-green-600 hover:text-red-500">
                        <x-heroicon-o-x-mark class="w-4 h-4" />
                    </button>
                </div>
            @else
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="busqueda_cliente"
                        placeholder="Buscar cliente..."
                        class="w-64 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                    @if(!empty($busqueda_cliente) && $this->clientes->count() > 0)
                        <div class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                            @foreach($this->clientes as $cliente)
                                <button
                                    wire:click="seleccionarCliente({{ $cliente->id }})"
                                    class="w-full px-4 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-700 text-sm"
                                >
                                    <span class="font-medium">{{ $cliente->nombre }}</span>
                                    <span class="text-gray-500 ml-2">{{ $cliente->telefono ?? '' }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Botón Ver Carrito --}}
        <button
            wire:click="$toggle('mostrarCarrito')"
            class="inline-flex items-center gap-2 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg font-medium transition-colors shadow-lg"
        >
            <x-heroicon-o-shopping-cart class="w-5 h-5" />
            <span>Ver carrito</span>
            @if($this->cantidadItems > 0)
                <span class="bg-white text-primary-600 text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                    {{ $this->cantidadItems }}
                </span>
            @endif
        </button>
    </div>

    {{-- Tabla de Productos --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        {{-- Buscador y Filtros --}}
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 flex-1">
                <div class="relative flex-1 max-w-md">
                    <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="busqueda_producto"
                        placeholder="Buscar..."
                        class="w-full pl-10 pr-4 py-2 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>
                <select
                    wire:model.live="categoria_id"
                    class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm focus:border-primary-500 focus:ring-primary-500"
                >
                    <option value="">Todas las categorías</option>
                    @foreach($this->categorias as $categoria)
                        <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filtro icono --}}
            <button class="p-2 text-gray-400 hover:text-gray-600 relative">
                <x-heroicon-o-funnel class="w-5 h-5" />
                @if($this->categoria_id)
                    <span class="absolute -top-1 -right-1 w-2 h-2 bg-primary-500 rounded-full"></span>
                @endif
            </button>
        </div>

        {{-- Tabla --}}
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Nombre</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Categoría</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Precio</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">ISV</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Stock disponible</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($this->productos as $producto)
                        @php
                            $bp = $producto->bodegaProductos->first();
                            $stock = $bp?->stock ?? 0;
                            $precio = $bp?->precio_venta_sugerido ?? 0;
                            $enCarrito = collect($this->carrito)->firstWhere('producto_id', $producto->id);
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 {{ $enCarrito ? 'bg-primary-50 dark:bg-primary-900/10' : '' }}">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    @if($enCarrito)
                                        <span class="w-5 h-5 rounded-full bg-primary-500 text-white text-xs font-bold flex items-center justify-center">
                                            {{ intval($enCarrito['cantidad']) }}
                                        </span>
                                    @endif
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $producto->nombre }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-500 dark:text-gray-400">
                                {{ $producto->categoria?->nombre ?? '-' }}
                            </td>
                            <td class="px-6 py-4 text-right font-semibold text-gray-900 dark:text-white">
                                {{ number_format($precio, 2) }} HNL
                            </td>
                            <td class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">
                                {{ $producto->aplica_isv ? '1' : '0' }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="{{ $stock > 10 ? 'text-gray-900 dark:text-white' : 'text-amber-600' }}">
                                    {{ number_format($stock, 0) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button
                                    wire:click="agregarProducto({{ $producto->id }})"
                                    class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-lg transition-colors"
                                >
                                    <x-heroicon-o-plus class="w-4 h-4" />
                                    Agregar
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                @if(!$this->bodega_id)
                                    Selecciona una bodega para ver los productos
                                @else
                                    No se encontraron productos
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal/Sidebar del Carrito --}}
    @if($mostrarCarrito)
        <div class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
            {{-- Overlay --}}
            <div class="absolute inset-0 bg-gray-500 bg-opacity-75 transition-opacity" wire:click="$toggle('mostrarCarrito')"></div>

            {{-- Panel del Carrito --}}
            <div class="fixed inset-y-0 right-0 flex max-w-full pl-10">
                <div class="w-screen max-w-md">
                    <div class="flex h-full flex-col bg-white dark:bg-gray-800 shadow-xl">
                        {{-- Header --}}
                        <div class="flex items-center justify-between px-4 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                <x-heroicon-o-shopping-cart class="w-5 h-5" />
                                Carrito de Venta
                                @if($this->cantidadItems > 0)
                                    <span class="bg-primary-500 text-white text-xs font-bold rounded-full px-2 py-0.5">
                                        {{ $this->cantidadItems }} items
                                    </span>
                                @endif
                            </h2>
                            <button wire:click="$toggle('mostrarCarrito')" class="text-gray-400 hover:text-gray-600">
                                <x-heroicon-o-x-mark class="w-6 h-6" />
                            </button>
                        </div>

                        {{-- Cliente y Tipo de Pago --}}
                        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                            @if($this->cliente)
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-10 h-10 rounded-full bg-primary-500 text-white flex items-center justify-center font-bold">
                                        {{ strtoupper(substr($this->cliente->nombre, 0, 1)) }}
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $this->cliente->nombre }}</p>
                                        <p class="text-xs text-gray-500">{{ $this->cliente->telefono ?? 'Sin teléfono' }}</p>
                                    </div>
                                </div>
                                @if($this->cliente->dias_credito > 0)
                                    <div class="text-xs text-gray-500 mb-2">
                                        Crédito disponible: <span class="text-green-600 font-semibold">L {{ number_format(min($this->cliente->getCreditoDisponible(), $this->cliente->limite_credito), 2) }}</span>
                                    </div>
                                @endif
                            @else
                                <p class="text-sm text-amber-600 dark:text-amber-400 mb-2">⚠️ Selecciona un cliente</p>
                            @endif

                            <div class="grid grid-cols-2 gap-2">
                                <select
                                    wire:model.live="tipo_pago"
                                    class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"
                                >
                                    <option value="efectivo">💵 Efectivo</option>
                                    <option value="transferencia">🏦 Transferencia</option>
                                    <option value="tarjeta">💳 Tarjeta</option>
                                    @if($this->cliente && $this->cliente->dias_credito > 0)
                                        <option value="credito">📋 Crédito</option>
                                    @endif
                                </select>
                                <input
                                    type="number"
                                    wire:model.live="descuento_global"
                                    placeholder="Descuento"
                                    class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"
                                    min="0"
                                    step="0.01"
                                />
                            </div>
                        </div>

                        {{-- Items del Carrito --}}
                        <div class="flex-1 overflow-y-auto px-4 py-4">
                            @forelse($carrito as $indice => $item)
                                <div class="flex items-start gap-3 py-3 border-b border-gray-100 dark:border-gray-700">
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900 dark:text-white text-sm">{{ $item['nombre'] }}</h4>
                                        <div class="flex items-center gap-2 mt-2">
                                            {{-- Cantidad --}}
                                            <div class="flex items-center border border-gray-300 dark:border-gray-600 rounded">
                                                <button wire:click="decrementarCantidad({{ $indice }})" class="px-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-700">
                                                    <x-heroicon-o-minus class="w-3 h-3" />
                                                </button>
                                                <input
                                                    type="number"
                                                    wire:change="actualizarCantidad({{ $indice }}, $event.target.value)"
                                                    value="{{ $item['cantidad'] }}"
                                                    class="w-12 text-center border-0 text-sm p-0 focus:ring-0 bg-transparent"
                                                    min="1"
                                                />
                                                <button wire:click="incrementarCantidad({{ $indice }})" class="px-2 py-1 hover:bg-gray-100 dark:hover:bg-gray-700">
                                                    <x-heroicon-o-plus class="w-3 h-3" />
                                                </button>
                                            </div>
                                            <span class="text-gray-400">×</span>
                                            {{-- Precio --}}
                                            <div class="flex items-center">
                                                <span class="text-gray-400 text-sm">L</span>
                                                <input
                                                    type="number"
                                                    wire:change="actualizarPrecio({{ $indice }}, $event.target.value)"
                                                    value="{{ $item['precio_unitario'] }}"
                                                    class="w-20 border-0 text-sm p-0 focus:ring-0 bg-transparent"
                                                    min="0"
                                                    step="0.01"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-primary-600 dark:text-primary-400">L {{ number_format($item['total_linea'], 2) }}</p>
                                        <button wire:click="quitarDelCarrito({{ $indice }})" class="text-red-400 hover:text-red-600 text-xs mt-1">
                                            Quitar
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-12 text-gray-400">
                                    <x-heroicon-o-shopping-cart class="w-16 h-16 mx-auto mb-4 opacity-30" />
                                    <p>El carrito está vacío</p>
                                </div>
                            @endforelse
                        </div>

                        {{-- Totales y Botones --}}
                        @if(count($carrito) > 0)
                            <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-4 bg-gray-50 dark:bg-gray-900">
                                {{-- Totales --}}
                                <div class="space-y-1 mb-4 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Subtotal</span>
                                        <span>L {{ number_format($this->subtotal, 2) }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">ISV (15%)</span>
                                        <span>L {{ number_format($this->totalIsv, 2) }}</span>
                                    </div>
                                    @if($descuento_global > 0)
                                        <div class="flex justify-between text-red-500">
                                            <span>Descuento</span>
                                            <span>- L {{ number_format($descuento_global, 2) }}</span>
                                        </div>
                                    @endif
                                    <div class="flex justify-between text-lg font-bold pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <span>TOTAL</span>
                                        <span class="text-primary-600">L {{ number_format($this->total, 2) }}</span>
                                    </div>
                                </div>

                                {{-- Nota --}}
                                <textarea
                                    wire:model="nota"
                                    placeholder="Nota (opcional)..."
                                    rows="2"
                                    class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 mb-3"
                                ></textarea>

                                {{-- Botones --}}
                                <div class="flex gap-2">
                                    <button
                                        wire:click="vaciarCarrito"
                                        wire:confirm="¿Vaciar el carrito?"
                                        class="px-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg"
                                    >
                                        Vaciar
                                    </button>
                                    <button
                                        wire:click="guardarBorrador"
                                        class="flex-1 px-4 py-2 text-sm font-medium bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 rounded-lg"
                                    >
                                        Borrador
                                    </button>
                                    <button
                                        wire:click="completarVenta"
                                        wire:loading.attr="disabled"
                                        class="flex-1 px-4 py-2 text-sm font-bold text-white bg-primary-500 hover:bg-primary-600 rounded-lg disabled:opacity-50 flex items-center justify-center gap-2"
                                    >
                                        <wire:loading.remove wire:target="completarVenta">
                                            Completar Venta
                                        </wire:loading.remove>
                                        <wire:loading wire:target="completarVenta">
                                            Procesando...
                                        </wire:loading>
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
