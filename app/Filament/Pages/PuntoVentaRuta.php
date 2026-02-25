<?php

namespace App\Filament\Pages;

use App\Models\Viaje;
use App\Models\ViajeCarga;
use App\Models\ViajeVenta;
use App\Models\Cliente;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Notifications\Notification;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;

class PuntoVentaRuta extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Punto de Venta';
    protected static ?string $title = 'Punto de Venta en Ruta';
    protected static ?string $slug = 'punto-venta-ruta';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.punto-venta-ruta';

    // Propiedades del carrito
    public array $carrito = [];
    public float $descuento = 0;
    
    // Datos del cliente - DEBE SELECCIONARSE PRIMERO
    public ?int $cliente_id = null;
    public string $nombre_cliente = '';
    public string $rtn_cliente = '';
    public string $direccion_cliente = '';
    public string $telefono_cliente = '';
    
    // Tipo de pago
    public string $tipo_pago = 'contado';
    public string $observaciones = '';

    // Control de confirmación
    public bool $mostrarConfirmacion = false;

    /**
     * Obtener cantidad en carrito para una carga específica
     */
    public function getCantidadEnCarrito(int $cargaId): float
    {
        $cantidad = 0;
        foreach ($this->carrito as $item) {
            if ($item['carga_id'] === $cargaId) {
                $cantidad += $item['cantidad'];
            }
        }
        return $cantidad;
    }

    /**
     * Obtener el cliente seleccionado
     */
    #[Computed]
    public function clienteSeleccionado(): ?Cliente
    {
        if (!$this->cliente_id) {
            return null;
        }
        return Cliente::find($this->cliente_id);
    }

    /**
     * Verificar si hay cliente seleccionado
     */
    #[Computed]
    public function tieneClienteSeleccionado(): bool
    {
        return $this->cliente_id !== null;
    }

    /**
     * Obtener último precio de un producto para el cliente seleccionado
     */
    public function getUltimoPrecioCliente(int $productoId): ?array
    {
        if (!$this->cliente_id) {
            return null;
        }

        $cliente = Cliente::find($this->cliente_id);
        if (!$cliente) {
            return null;
        }

        return $cliente->getUltimoPrecio($productoId);
    }

    /**
     * Obtener el precio mínimo permitido para un producto y el cliente actual.
     *
     * Jerarquía:
     * 1. Si hay regla de descuento (override o por tipo) → precio_venta - descuento
     * 2. Si no hay regla → costo + L 1.00 (nunca vender a pérdida)
     *
     * @return array ['precio_minimo' => float, 'fuente' => string]
     */
    public function getPrecioMinimoPermitido($carga): array
    {
        $cliente = $this->clienteSeleccionado;
        $producto = $carga->producto;

        if (!$cliente || !$producto) {
            // Fallback: costo + 1
            $costoBase = (float) ($carga->costo_unitario ?? 0);
            return [
                'precio_minimo' => $costoBase + 1,
                'fuente' => 'proteccion',
            ];
        }

        $precioVentaRef = (float) ($producto->precio_venta_maximo ?? $carga->precio_venta_sugerido ?? 0);

        if ($precioVentaRef > 0) {
            $resultado = $producto->obtenerPrecioMinimo($cliente, $precioVentaRef);

            if ($resultado['precio_minimo'] !== null) {
                return [
                    'precio_minimo' => (float) $resultado['precio_minimo'],
                    'fuente' => $resultado['fuente'],
                ];
            }
        }

        // Sin regla de descuento → costo + L 1.00
        $costoBase = (float) ($carga->costo_unitario ?? 0);
        return [
            'precio_minimo' => $costoBase + 1,
            'fuente' => 'proteccion',
        ];
    }

    /**
     * Seleccionar cliente
     */
    public function seleccionarCliente(int $clienteId): void
    {
        $cliente = Cliente::find($clienteId);
        
        if ($cliente) {
            $this->cliente_id = $cliente->id;
            $this->nombre_cliente = $cliente->nombre;
            $this->rtn_cliente = $cliente->rtn ?? '';
            $this->direccion_cliente = $cliente->direccion ?? '';
            $this->telefono_cliente = $cliente->telefono ?? '';
            
            // Limpiar carrito al cambiar cliente (los precios pueden ser diferentes)
            if (count($this->carrito) > 0) {
                Notification::make()
                    ->title('Cliente seleccionado')
                    ->body('El carrito se ha vaciado porque los precios pueden variar por cliente.')
                    ->warning()
                    ->send();
                $this->carrito = [];
            } else {
                Notification::make()
                    ->title('Cliente seleccionado')
                    ->body($cliente->nombre)
                    ->success()
                    ->send();
            }
        }
    }

    /**
     * Limpiar cliente seleccionado
     */
    public function limpiarCliente(): void
    {
        $this->cliente_id = null;
        $this->nombre_cliente = '';
        $this->rtn_cliente = '';
        $this->direccion_cliente = '';
        $this->telefono_cliente = '';
        $this->carrito = [];
        
        Notification::make()
            ->title('Cliente removido')
            ->body('Seleccione un cliente para continuar.')
            ->info()
            ->send();
    }

    /**
     * Crear venta como Consumidor Final (sin cliente registrado)
     */
    public function crearConsumidorFinal(): void
    {
        // Buscar o crear cliente "Consumidor Final"
        $consumidorFinal = Cliente::firstOrCreate(
            ['rtn' => 'CF-0000000000000'],
            [
                'nombre' => 'Consumidor Final',
                'tipo' => 'minorista',
                'estado' => true,
                'dias_credito' => 0,
                'limite_credito' => 0,
            ]
        );

        $this->cliente_id = $consumidorFinal->id;
        $this->nombre_cliente = $consumidorFinal->nombre;
        $this->rtn_cliente = '';
        $this->direccion_cliente = '';
        $this->telefono_cliente = '';

        Notification::make()
            ->title('Venta a Consumidor Final')
            ->body('Se usarán los precios estándar.')
            ->success()
            ->send();
    }

    /**
     * Buscar clientes (para API o uso interno)
     */
    public static function buscarClientes(string $query): array
    {
        return Cliente::where('estado', true)
            ->where(function ($q) use ($query) {
                $q->where('nombre', 'like', "%{$query}%")
                  ->orWhere('rtn', 'like', "%{$query}%")
                  ->orWhere('telefono', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'nombre', 'rtn', 'telefono', 'tipo'])
            ->toArray();
    }

    /**
     * Verificar si el usuario puede acceder a esta página
     * SOLO choferes con viaje activo en ruta
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        // Solo choferes pueden acceder
        if (!static::esChofer()) {
            return false;
        }

        // Y solo si tienen un viaje en ruta
        return static::getViajeActivoUsuario() !== null;
    }

    /**
     * Verificar si es Super Admin, Jefe o Encargado
     */
    protected static function esSuperAdminOJefeOEncargado(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($user))
            ->where('model_has_roles.model_id', '=', $user->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe', 'Encargado'])
            ->exists();
    }

    /**
     * Verificar si el usuario es Chofer
     */
    protected static function esChofer(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($user))
            ->where('model_has_roles.model_id', '=', $user->id)
            ->where('roles.name', 'Chofer')
            ->exists();
    }

    /**
     * Obtener el viaje activo del usuario actual (para choferes)
     * Solo viajes EN RUTA - no durante carga
     */
    protected static function getViajeActivoUsuario(): ?Viaje
    {
        $user = Auth::user();
        if (!$user) return null;

        return Viaje::where('chofer_id', $user->id)
            ->where('estado', Viaje::ESTADO_EN_RUTA)
            ->with(['cargas.producto', 'cargas.unidad', 'camion', 'bodegaOrigen'])
            ->first();
    }

    /**
     * Obtener el viaje actual para mostrar productos
     * Solo el viaje del chofer en ruta
     */
    #[Computed]
    public function viajeActual(): ?Viaje
    {
        return static::getViajeActivoUsuario();
    }

    /**
     * Información del viaje para mostrar en header
     */
    #[Computed]
    public function infoViaje(): array
    {
        $viaje = $this->viajeActual;
        
        if (!$viaje) {
            return [
                'numero' => 'Sin viaje',
                'camion' => '-',
                'chofer' => '-',
                'estado' => '-',
                'bodega' => '-',
            ];
        }

        return [
            'numero' => $viaje->numero_viaje,
            'camion' => $viaje->camion?->placa ?? '-',
            'chofer' => $viaje->chofer?->name ?? '-',
            'estado' => match($viaje->estado) {
                Viaje::ESTADO_CARGANDO => 'Cargando',
                Viaje::ESTADO_EN_RUTA => 'En Ruta',
                default => $viaje->estado,
            },
            'bodega' => $viaje->bodegaOrigen?->nombre ?? '-',
        ];
    }

    /**
     * Configurar la tabla de productos disponibles
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $viaje = $this->viajeActual;
                
                if (!$viaje) {
                    return ViajeCarga::query()->whereRaw('1 = 0');
                }

                return ViajeCarga::query()
                    ->where('viaje_id', $viaje->id)
                    ->whereRaw('cantidad > (cantidad_vendida + cantidad_merma + cantidad_devuelta)')
                    ->with(['producto', 'unidad']);
            })
            ->columns([
                TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('disponible')
                    ->label('Disponible')
                    ->getStateUsing(function ($record) {
                        $disponibleReal = $record->getCantidadDisponible();
                        $enCarrito = $this->getCantidadEnCarrito($record->id);
                        return $disponibleReal - $enCarrito;
                    })
                    ->formatStateUsing(function ($state, $record) {
                        $enCarrito = $this->getCantidadEnCarrito($record->id);
                        if ($enCarrito > 0) {
                            return number_format($state, 2) . ' (🛒' . number_format($enCarrito, 0) . ')';
                        }
                        return number_format($state, 2);
                    })
                    ->badge()
                    ->color(function ($record) {
                        $enCarrito = $this->getCantidadEnCarrito($record->id);
                        return $enCarrito > 0 ? 'warning' : 'success';
                    }),

                TextColumn::make('precio_venta_sugerido')
                    ->label('Precio (sin ISV)')
                    ->money('HNL')
                    ->sortable(),

                IconColumn::make('producto.aplica_isv')
                    ->label('ISV')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                TextColumn::make('precio_con_isv')
                    ->label('Precio Cliente')
                    ->getStateUsing(fn ($record) => 'L ' . number_format($record->getPrecioConIsv(), 2))
                    ->weight('bold')
                    ->color('success')
                    ->tooltip('Precio final que paga el cliente (con ISV si aplica)'),
            ])
            ->actions([
                Action::make('agregar')
                    ->label('Agregar')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->visible(fn () => $this->tieneClienteSeleccionado)
                    ->form([
                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->default(1)
                            ->step(0.01),
                        TextInput::make('precio_base')
                            ->label('Precio Venta (sin ISV)')
                            ->numeric()
                            ->required()
                            ->prefix('L')
                            ->default(function ($record) {
                                // Si es Consumidor Final, siempre usar precio sugerido
                                $cliente = $this->clienteSeleccionado;
                                if ($cliente && $cliente->rtn === 'CF-0000000000000') {
                                    return $record->precio_venta_sugerido;
                                }
                                
                                // Para clientes registrados, buscar último precio
                                $ultimoPrecio = $this->getUltimoPrecioCliente($record->producto_id);
                                
                                if ($ultimoPrecio && $ultimoPrecio['precio_sin_isv']) {
                                    return $ultimoPrecio['precio_sin_isv'];
                                }
                                
                                // Si no hay historial, usar precio sugerido
                                return $record->precio_venta_sugerido;
                            })
                            ->rules([
                                function ($record) {
                                    return function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (!$record) {
                                            return;
                                        }

                                        $minimo = $this->getPrecioMinimoPermitido($record);
                                        $precioMinimo = $minimo['precio_minimo'];

                                        if ((float) $value < $precioMinimo) {
                                            $sugerido = $precioMinimo;
                                            $fail("El precio mínimo permitido es L " . number_format($precioMinimo, 2) . ". Precio sugerido: L " . number_format($sugerido, 2));
                                        }
                                    };
                                },
                            ])
                            ->helperText(function ($record) {
                                $textos = [];

                                // Último precio a este cliente
                                $ultimoPrecio = $this->getUltimoPrecioCliente($record->producto_id);
                                if ($ultimoPrecio && $ultimoPrecio['precio_sin_isv']) {
                                    $fecha = $ultimoPrecio['fecha'] ? \Carbon\Carbon::parse($ultimoPrecio['fecha'])->format('d/m/Y') : '';
                                    $texto = 'Último precio a este cliente: L ' . number_format($ultimoPrecio['precio_sin_isv'], 2);
                                    if ($fecha) {
                                        $texto .= ' (' . $fecha . ')';
                                    }
                                    $textos[] = $texto;
                                }

                                // Precio mínimo permitido
                                $minimo = $this->getPrecioMinimoPermitido($record);
                                $textos[] = 'Precio mínimo: L ' . number_format($minimo['precio_minimo'], 2);

                                // ISV
                                if ($record->producto && $record->producto->aplica_isv) {
                                    $textos[] = '+15% ISV';
                                }
                                
                                return implode(' • ', $textos);
                            }),
                    ])
                    ->action(function (array $data, $record) {
                        $this->agregarAlCarrito($record, $data['cantidad'], $data['precio_base']);
                    }),
            ])
            ->emptyStateHeading('Sin productos disponibles')
            ->emptyStateDescription('No hay productos cargados en este viaje o ya se vendieron todos.')
            ->emptyStateIcon('heroicon-o-archive-box-x-mark')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    /**
     * Agregar producto al carrito
     * Recibe precio BASE (sin ISV), el sistema calcula el ISV automáticamente
     * Si el producto ya existe con el mismo precio, acumula la cantidad
     */
    public function agregarAlCarrito($carga, float $cantidad, float $precioBase): void
    {
        // Validar disponibilidad
        $disponible = $carga->getCantidadDisponible();
        
        // Verificar cuánto ya está en el carrito para este producto
        $cantidadEnCarrito = $this->getCantidadEnCarrito($carga->id);
        $indiceExistente = null;
        
        foreach ($this->carrito as $key => $item) {
            if ($item['carga_id'] === $carga->id && $item['precio_base'] == $precioBase) {
                $indiceExistente = $key;
                break;
            }
        }

        if (($cantidad + $cantidadEnCarrito) > $disponible) {
            Notification::make()
                ->title('Stock insuficiente')
                ->body("Solo hay {$disponible} unidades disponibles. Ya tienes {$cantidadEnCarrito} en el carrito.")
                ->danger()
                ->send();
            return;
        }

        // Validar precio mínimo permitido (regla de descuento o costo + 1)
        $minimo = $this->getPrecioMinimoPermitido($carga);
        $precioMinimo = $minimo['precio_minimo'];

        if ($precioBase < $precioMinimo) {
            Notification::make()
                ->title('Precio no permitido')
                ->body("El precio mínimo permitido es L " . number_format($precioMinimo, 2) . ". Precio sugerido: L " . number_format($precioMinimo, 2))
                ->danger()
                ->duration(8000)
                ->send();
            return;
        }

        // Calcular precio con ISV si aplica
        $aplicaIsv = $carga->producto->aplica_isv ?? false;
        $precioConIsv = $aplicaIsv ? ceil($precioBase * 1.15) : $precioBase;
        $montoIsv = $aplicaIsv ? ($precioConIsv - $precioBase) : 0;

        // Si ya existe el producto con el mismo precio, acumular cantidad
        if ($indiceExistente !== null) {
            $this->carrito[$indiceExistente]['cantidad'] += $cantidad;
            
            Notification::make()
                ->title('Cantidad actualizada')
                ->body("Ahora tienes {$this->carrito[$indiceExistente]['cantidad']} {$carga->unidad->nombre} de {$carga->producto->nombre}")
                ->success()
                ->send();
            return;
        }

        // Si no existe, crear nueva entrada
        $uid = uniqid('item_');

        $this->carrito[] = [
            'uid' => $uid,
            'carga_id' => $carga->id,
            'producto_id' => $carga->producto_id,
            'nombre' => $carga->producto->nombre,
            'unidad' => $carga->unidad->nombre ?? 'Unidad',
            'cantidad' => $cantidad,
            'precio_base' => $precioBase,
            'precio_con_isv' => $precioConIsv,
            'monto_isv' => $montoIsv,
            'precio_minimo' => $precioMinimo,
            'costo' => $carga->costo_unitario,
            'aplica_isv' => $aplicaIsv,
            'bajo_minimo' => false,
        ];

        $mensaje = "{$cantidad} {$carga->unidad->nombre} de {$carga->producto->nombre}";
        if ($aplicaIsv) {
            $mensaje .= " (+ ISV = L " . number_format($precioConIsv, 2) . ")";
        }
        
        Notification::make()
            ->title('Producto agregado')
            ->body($mensaje)
            ->success()
            ->send();
    }

    /**
     * Modificar cantidad en carrito
     */
    public function modificarCantidad(string $uid, int $delta): void
    {
        foreach ($this->carrito as $key => $item) {
            if ($item['uid'] === $uid) {
                $nuevaCantidad = $item['cantidad'] + $delta;
                
                if ($nuevaCantidad <= 0) {
                    $this->quitarDelCarrito($uid);
                    return;
                }

                // Validar stock disponible
                $carga = ViajeCarga::find($item['carga_id']);
                if ($carga) {
                    $disponible = $carga->getCantidadDisponible();
                    $otrosEnCarrito = 0;
                    
                    foreach ($this->carrito as $otro) {
                        if ($otro['carga_id'] === $item['carga_id'] && $otro['uid'] !== $uid) {
                            $otrosEnCarrito += $otro['cantidad'];
                        }
                    }

                    if (($nuevaCantidad + $otrosEnCarrito) > $disponible) {
                        Notification::make()
                            ->title('Stock insuficiente')
                            ->danger()
                            ->send();
                        return;
                    }
                }

                $this->carrito[$key]['cantidad'] = $nuevaCantidad;
                break;
            }
        }
    }

    /**
     * Quitar item del carrito
     */
    public function quitarDelCarrito(string $uid): void
    {
        $this->carrito = array_values(array_filter($this->carrito, fn($item) => $item['uid'] !== $uid));
        
        Notification::make()
            ->title('Producto eliminado')
            ->success()
            ->send();
    }

    /**
     * Cantidad de items en carrito
     */
    #[Computed]
    public function cantidadCarrito(): int
    {
        return count($this->carrito);
    }

    /**
     * Subtotal sin ISV (precio base × cantidad)
     */
    #[Computed]
    public function subtotalSinISV(): float
    {
        $total = 0;
        foreach ($this->carrito as $item) {
            $total += $item['precio_base'] * $item['cantidad'];
        }
        return round($total, 2);
    }

    /**
     * Total ISV
     */
    #[Computed]
    public function totalISV(): float
    {
        $total = 0;
        foreach ($this->carrito as $item) {
            $total += $item['monto_isv'] * $item['cantidad'];
        }
        return round($total, 2);
    }

    /**
     * Subtotal bruto (precio con ISV × cantidad) - lo que paga el cliente
     */
    #[Computed]
    public function subtotalBruto(): float
    {
        $total = 0;
        foreach ($this->carrito as $item) {
            $total += $item['precio_con_isv'] * $item['cantidad'];
        }
        return round($total, 2);
    }

    /**
     * Total final
     */
    #[Computed]
    public function totalFinal(): float
    {
        return round($this->subtotalBruto - $this->descuento, 2);
    }

    /**
     * Descartar venta / Vaciar carrito
     */
    public function descartarVenta(): void
    {
        $this->carrito = [];
        $this->descuento = 0;
        $this->cliente_id = null;
        $this->nombre_cliente = '';
        $this->rtn_cliente = '';
        $this->direccion_cliente = '';
        $this->telefono_cliente = '';
        $this->tipo_pago = 'contado';
        $this->observaciones = '';
        $this->mostrarConfirmacion = false;

        Notification::make()
            ->title('Carrito vaciado')
            ->warning()
            ->send();

        $this->dispatch('close-modal', id: 'carrito-modal');
    }

    /**
     * Mostrar modal de confirmación
     */
    public function confirmarVenta(): void
    {
        if (empty($this->carrito)) {
            Notification::make()
                ->title('Carrito vacío')
                ->body('Agregue productos antes de procesar la venta.')
                ->danger()
                ->send();
            return;
        }

        if (!$this->cliente_id) {
            Notification::make()
                ->title('Error')
                ->body('Debe seleccionar un cliente.')
                ->danger()
                ->send();
            return;
        }

        $this->mostrarConfirmacion = true;
    }

    /**
     * Cancelar confirmación
     */
    public function cancelarConfirmacion(): void
    {
        $this->mostrarConfirmacion = false;
    }

    /**
     * Procesar venta (después de confirmar)
     */
    public function procesarVenta(): void
    {
        if (empty($this->carrito)) {
            Notification::make()
                ->title('Carrito vacío')
                ->body('Agregue productos antes de procesar la venta.')
                ->danger()
                ->send();
            return;
        }

        $viaje = $this->viajeActual;
        if (!$viaje) {
            Notification::make()
                ->title('Error')
                ->body('No hay viaje activo.')
                ->danger()
                ->send();
            return;
        }

        if (!$this->cliente_id) {
            Notification::make()
                ->title('Error')
                ->body('Debe seleccionar un cliente.')
                ->danger()
                ->send();
            return;
        }

        try {
            DB::beginTransaction();

            // Generar número de venta único
            $ultimaVenta = ViajeVenta::where('viaje_id', $viaje->id)
                ->orderBy('id', 'desc')
                ->first();
            
            $secuencia = 1;
            if ($ultimaVenta && $ultimaVenta->numero_venta) {
                $partes = explode('-', $ultimaVenta->numero_venta);
                $ultimoNumero = (int) end($partes);
                $secuencia = $ultimoNumero + 1;
            }
            
            $numeroVenta = 'VR-' . $viaje->id . '-' . str_pad($secuencia, 4, '0', STR_PAD_LEFT);

            // Crear la venta
            $venta = ViajeVenta::create([
                'viaje_id' => $viaje->id,
                'cliente_id' => $this->cliente_id,
                'numero_venta' => $numeroVenta,
                'fecha_venta' => now(),
                'tipo_pago' => $this->tipo_pago,
                'plazo_dias' => $this->tipo_pago === 'credito' ? 30 : 0,
                'subtotal' => $this->subtotalSinISV,
                'impuesto' => $this->totalISV,
                'descuento' => $this->descuento,
                'total' => $this->totalFinal,
                'saldo_pendiente' => $this->tipo_pago === 'credito' ? $this->totalFinal : 0,
                'estado' => 'completada',
                'nota' => $this->observaciones,
                'user_id' => Auth::id(),
            ]);

            // Crear los detalles y actualizar stock
            foreach ($this->carrito as $item) {
                $venta->detalles()->create([
                    'viaje_carga_id' => $item['carga_id'],
                    'producto_id' => $item['producto_id'],
                    'cantidad' => $item['cantidad'],
                    'precio_base' => $item['precio_base'],
                    'precio_con_isv' => $item['precio_con_isv'],
                    'monto_isv' => $item['monto_isv'],
                    'costo_unitario' => $item['costo'],
                    'aplica_isv' => $item['aplica_isv'],
                    'subtotal' => $item['precio_base'] * $item['cantidad'],
                    'total_isv' => $item['monto_isv'] * $item['cantidad'],
                    'total_linea' => $item['precio_con_isv'] * $item['cantidad'],
                ]);

                $carga = ViajeCarga::find($item['carga_id']);
                if ($carga) {
                    $carga->increment('cantidad_vendida', $item['cantidad']);
                }
            }

            // Actualizar total vendido del viaje
            $viaje->increment('total_vendido', $this->totalFinal);

            // Actualizar último precio al cliente
            $cliente = Cliente::find($this->cliente_id);
            if ($cliente) {
                foreach ($this->carrito as $item) {
                    $cliente->actualizarUltimoPrecio(
                        $item['producto_id'],
                        $item['precio_base'],
                        $item['precio_con_isv'],
                        $item['cantidad']
                    );
                }
            }

            DB::commit();

            $ventaId = $venta->id;
            $totalVenta = $this->totalFinal;

            // Limpiar carrito y datos
            $this->carrito = [];
            $this->descuento = 0;
            $this->observaciones = '';
            $this->mostrarConfirmacion = false;

            $this->dispatch('close-modal', id: 'carrito-modal');
            $this->dispatch('abrir-impresion', ventaId: $ventaId);

            Notification::make()
                ->title('Venta registrada!')
                ->body('Total: L ' . number_format($totalVenta, 2) . ' - ' . $venta->numero_venta)
                ->success()
                ->duration(5000)
                ->send();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->mostrarConfirmacion = false;
            
            Notification::make()
                ->title('Error al procesar venta')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Buscar cliente por RTN
     */
    public function buscarCliente(): void
    {
        if (empty($this->rtn_cliente)) {
            return;
        }

        $cliente = Cliente::where('rtn', $this->rtn_cliente)->first();
        
        if ($cliente) {
            $this->cliente_id = $cliente->id;
            $this->nombre_cliente = $cliente->nombre;
            $this->direccion_cliente = $cliente->direccion ?? '';
            $this->telefono_cliente = $cliente->telefono ?? '';

            Notification::make()
                ->title('Cliente encontrado')
                ->body($cliente->nombre)
                ->success()
                ->send();
        }
    }
}