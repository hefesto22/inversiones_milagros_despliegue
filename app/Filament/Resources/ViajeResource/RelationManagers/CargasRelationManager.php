<?php

namespace App\Filament\Resources\ViajeResource\RelationManagers;

use App\Models\Producto;
use App\Models\BodegaProducto;
use App\Models\Unidad;
use App\Models\Viaje;
use App\Models\Lote;
use App\Models\Reempaque;
use App\Models\ReempaqueLote;
use App\Models\ReempaqueProducto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class CargasRelationManager extends RelationManager
{
    protected static string $relationship = 'cargas';
    protected static ?string $title = 'Productos Cargados';
    protected static ?string $modelLabel = 'Carga';
    protected static ?string $pluralModelLabel = 'Cargas';
    private const ISV_RATE = 0.15;

    public function isReadOnly(): bool
    {
        return !in_array($this->getOwnerRecord()->estado, [
            Viaje::ESTADO_PLANIFICADO,
            Viaje::ESTADO_CARGANDO
        ]);
    }

    public function form(Form $form): Form
    {
        $viaje = $this->getOwnerRecord();

        return $form
            ->schema([
                Forms\Components\Select::make('producto_id')
                    ->label('Producto')
                    ->options(function () use ($viaje) {
                        $productosYaCargados = $viaje->cargas()->pluck('producto_id')->toArray();

                        $productosConStock = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('stock', '>', 0)
                            ->whereNotIn('producto_id', $productosYaCargados)
                            ->whereHas('producto')
                            ->with('producto.categoria')
                            ->get()
                            ->filter(fn($bp) => $bp->producto !== null)
                            ->pluck('producto.nombre', 'producto_id')
                            ->toArray();

                        $productosConLote = Producto::whereHas('categoria', function ($q) {
                                $q->whereNotNull('categoria_origen_id');
                            })
                            ->where('activo', true)
                            ->whereNotIn('id', $productosYaCargados)
                            ->with('categoria.categoriaOrigen')
                            ->get()
                            ->filter(function ($producto) use ($viaje) {
                                if (!$producto->categoria || !$producto->categoria->categoria_origen_id) {
                                    return false;
                                }
                                $categoriaLoteId = $producto->categoria->categoria_origen_id;
                                return Lote::where('bodega_id', $viaje->bodega_origen_id)
                                    ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
                                    ->where('estado', 'disponible')
                                    ->where('cantidad_huevos_remanente', '>=', 30)
                                    ->exists();
                            })
                            ->pluck('nombre', 'id')
                            ->toArray();

                        return $productosConStock + $productosConLote;
                    })
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) use ($viaje) {
                        if ($state) {
                            $producto = Producto::with('categoria.categoriaOrigen')->find($state);
                            if (!$producto) return;
                            $categoria = $producto->categoria;
                            $usaLotes = $categoria && $categoria->categoria_origen_id;
                            if ($usaLotes) {
                                $this->cargarDatosDesdeLote($producto, $viaje, $set, $get);
                            } else {
                                $this->cargarDatosDesdeBodega($producto, $viaje, $set, $get);
                            }
                        } else {
                            $this->limpiarCampos($set);
                        }
                    })
                    ->columnSpan(2),

                Forms\Components\Select::make('unidad_id')
                    ->label('Unidad')
                    ->options(fn () => Unidad::where('activo', true)->pluck('nombre', 'id'))
                    ->required()
                    ->searchable(),

                Forms\Components\TextInput::make('stock_disponible')
                    ->label('Stock Disponible')
                    ->disabled()
                    ->dehydrated(false),

                Forms\Components\Hidden::make('stock_maximo')->default(0)->dehydrated(false),
                Forms\Components\Hidden::make('usa_lotes')->default(false)->dehydrated(false),
                Forms\Components\Hidden::make('categoria_lote_id')->default(null)->dehydrated(false),
                Forms\Components\Hidden::make('stock_en_bodega')->default(0)->dehydrated(false),
                Forms\Components\Hidden::make('stock_desde_lote')->default(0)->dehydrated(false),
                Forms\Components\Hidden::make('costo_bodega')->default(0)->dehydrated(false),
                Forms\Components\Hidden::make('costo_lote')->default(0)->dehydrated(false),

                Forms\Components\TextInput::make('cantidad')
                    ->label('Cantidad a Cargar')
                    ->numeric()
                    ->required()
                    ->minValue(0.001)
                    ->maxValue(fn (Forms\Get $get) => floatval($get('stock_maximo')) ?: 999999)
                    ->validationMessages(['max' => 'La cantidad no puede exceder el stock disponible.'])
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $cantidad = floatval($state ?? 0);
                        $productoId = $get('producto_id');
                        
                        if (!$productoId || $cantidad <= 0) {
                            $this->recalcularSubtotales($get, $set, $state);
                            return;
                        }
                        
                        $viaje = $this->getOwnerRecord();
                        $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($productoId);
                        
                        if (!$producto) {
                            $this->recalcularSubtotales($get, $set, $state);
                            return;
                        }
                        
                        $categoria = $producto->categoria;
                        $usaLotes = $categoria && $categoria->categoria_origen_id;
                        
                        if ($usaLotes) {
                            $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                ->where('producto_id', $productoId)->first();
                            
                            $stockEnBodega = floatval($bodegaProducto->stock ?? 0);
                            $costoEnBodega = floatval($bodegaProducto->costo_promedio_actual ?? 0);
                            
                            $categoriaLoteId = $categoria->categoria_origen_id;
                            $lotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
                                ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
                                ->where('estado', 'disponible')
                                ->where('cantidad_huevos_remanente', '>=', 30)->get();
                            
                            $huevosPorUnidad = 30;
                            if ($producto->unidad && str_contains(strtolower($producto->unidad->nombre), '15')) {
                                $huevosPorUnidad = 15;
                            }
                            
                            $costoTotalLotes = 0;
                            $unidadesTotalesLote = 0;
                            foreach ($lotes as $lote) {
                                $unidadesEnLote = floor($lote->cantidad_huevos_remanente / $huevosPorUnidad);
                                $costoPorCarton30 = floatval($lote->costo_por_carton_facturado ?? 0);
                                $costoPorUnidad = ($huevosPorUnidad == 30) ? $costoPorCarton30 : $costoPorCarton30 * ($huevosPorUnidad / 30);
                                $costoTotalLotes += $unidadesEnLote * $costoPorUnidad;
                                $unidadesTotalesLote += $unidadesEnLote;
                            }
                            $costoEnLote = $unidadesTotalesLote > 0 ? $costoTotalLotes / $unidadesTotalesLote : 0;
                            
                            $tomarDeBodega = min($stockEnBodega, $cantidad);
                            $tomarDeLote = max(0, $cantidad - $tomarDeBodega);
                            
                            $valorBodega = $tomarDeBodega * $costoEnBodega;
                            $valorLote = $tomarDeLote * $costoEnLote;
                            $valorTotal = $valorBodega + $valorLote;
                            
                            // FIX: 4 decimales para mantener precision
                            $costoUnitario = $cantidad > 0 ? round($valorTotal / $cantidad, 4) : 0;
                            
                            $aplicaIsv = $producto->aplica_isv ?? false;
                            $costoConIsv = $aplicaIsv ? round($costoUnitario * 1.15, 2) : $costoUnitario;
                            
                            $set('costo_unitario', $costoUnitario);
                            $set('costo_con_isv', $costoConIsv);
                            $set('precio_venta_minimo', $costoConIsv);
                        }
                        
                        $this->recalcularSubtotales($get, $set, $state);
                    })
                    ->rules([
                        fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            $stockMaximo = floatval($get('stock_maximo') ?? 0);
                            if ($stockMaximo > 0 && floatval($value) > $stockMaximo) {
                                $fail("La cantidad no puede ser mayor al stock disponible ({$stockMaximo}).");
                            }
                        },
                    ]),

                Forms\Components\TextInput::make('costo_con_isv')
                    ->label('Costo Unitario')->numeric()->prefix('L')->disabled()->dehydrated(false)
                    ->helperText(fn (Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Lo que pagaste (incluye ISV)' : 'Costo del producto'),

                Forms\Components\Hidden::make('costo_unitario')->default(0),

                Forms\Components\TextInput::make('precio_venta_sugerido')
                    ->label(fn (Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Precio Venta (sin ISV)' : 'Precio Venta')
                    ->numeric()->prefix('L')->required()
                    ->minValue(fn (Forms\Get $get) => floatval($get('precio_venta_minimo')) ?: 0.01)
                    ->validationMessages(['min' => 'El precio no puede ser menor al precio mínimo (costo).'])
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                        $precioSinIsv = floatval($state ?? 0);
                        $precioMinimo = floatval($get('precio_venta_minimo') ?? 0);
                        $aplicaIsv = $get('aplica_isv') ?? false;
                        
                        if ($precioSinIsv > 0 && $precioMinimo > 0 && $precioSinIsv < $precioMinimo) {
                            Notification::make()->title('Advertencia')
                                ->body("El precio L {$precioSinIsv} es menor al costo L {$precioMinimo}. Venderá con pérdida.")
                                ->warning()->send();
                        }
                        
                        $precioConIsv = $aplicaIsv ? round($precioSinIsv * (1 + self::ISV_RATE), 2) : $precioSinIsv;
                        $set('precio_con_isv', $precioConIsv);
                        $this->recalcularSubtotales($get, $set, $get('cantidad'));
                    }),

                Forms\Components\Toggle::make('aplica_isv')
                    ->label('Aplica ISV (15%)')->disabled()->dehydrated(false)->inline(false)
                    ->onColor('success')->offColor('gray'),

                Forms\Components\TextInput::make('precio_con_isv')
                    ->label('Precio con ISV')->numeric()->prefix('L')->disabled()->dehydrated(false)
                    ->helperText('Precio final al cliente')
                    ->visible(fn (Forms\Get $get) => $get('aplica_isv') ?? false),

                Forms\Components\TextInput::make('precio_venta_minimo')
                    ->label('Precio Minimo')->numeric()->prefix('L')->disabled()->dehydrated(true)
                    ->helperText('No vender por debajo'),

                Forms\Components\TextInput::make('subtotal_costo')
                    ->label('Subtotal Costo')->numeric()->prefix('L')->disabled()->dehydrated(true)->default(0),

                Forms\Components\TextInput::make('subtotal_venta')
                    ->label(fn (Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Subtotal Venta (con ISV)' : 'Subtotal Venta')
                    ->numeric()->prefix('L')->disabled()->dehydrated(true)->default(0)
                    ->helperText(fn (Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Incluye ISV' : ''),
            ])
            ->columns(3);
    }

    // ============================================
    // MÉTODOS PRIVADOS DE CARGA DE DATOS
    // ============================================

    private function cargarDatosDesdeLote(Producto $producto, $viaje, Forms\Set $set, Forms\Get $get): void
    {
        $categoria = $producto->categoria;
        $categoriaLoteId = $categoria->categoria_origen_id;

        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
            ->where('producto_id', $producto->id)->first();

        $stockEnBodega = floatval($bodegaProducto->stock ?? 0);
        $costoEnBodega = floatval($bodegaProducto->costo_promedio_actual ?? 0);

        $lotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
            ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
            ->where('estado', 'disponible')
            ->where('cantidad_huevos_remanente', '>=', 30)
            ->orderBy('created_at', 'asc')->get();

        $producto->loadMissing('unidad');
        $unidad = $producto->unidad;
        
        $huevosPorUnidad = 30;
        if ($unidad) {
            $factor = floatval($unidad->factor ?? 1);
            if ($factor == 0.5) {
                $huevosPorUnidad = 15;
            } elseif (str_contains(strtolower($unidad->nombre), '15')) {
                $huevosPorUnidad = 15;
            }
        }

        $totalHuevosEnLotes = $lotes->sum('cantidad_huevos_remanente');
        $stockDesdeLote = floor($totalHuevosEnLotes / $huevosPorUnidad);
        $stockTotal = $stockEnBodega + $stockDesdeLote;

        if ($stockTotal <= 0) {
            Notification::make()->title('Sin stock disponible')
                ->body("No hay stock en bodega ni en lotes para este producto")->warning()->send();
            $this->limpiarCampos($set);
            return;
        }

        $costoTotalLotes = 0;
        $unidadesTotalesLote = 0;
        foreach ($lotes as $lote) {
            $unidadesEnLote = floor($lote->cantidad_huevos_remanente / $huevosPorUnidad);
            $costoPorCarton30 = floatval($lote->costo_por_carton_facturado ?? 0);
            $costoPorUnidad = ($huevosPorUnidad == 30) ? $costoPorCarton30 : $costoPorCarton30 * ($huevosPorUnidad / 30);
            $costoTotalLotes += $unidadesEnLote * $costoPorUnidad;
            $unidadesTotalesLote += $unidadesEnLote;
        }
        $costoUnitarioLote = $unidadesTotalesLote > 0 ? $costoTotalLotes / $unidadesTotalesLote : 0;

        $valorTotalBodega = $stockEnBodega * $costoEnBodega;
        $valorTotalLote = $stockDesdeLote * $costoUnitarioLote;
        $valorTotal = $valorTotalBodega + $valorTotalLote;
        
        // FIX: 4 decimales
        $costoUnitario = $stockTotal > 0 ? round($valorTotal / $stockTotal, 4) : 0;

        $aplicaIsv = $producto->aplica_isv ?? false;
        $costoConIsv = $aplicaIsv ? round($costoUnitario * 1.15, 2) : $costoUnitario;
        $precioConIsv = $bodegaProducto->precio_venta_sugerido ?? ($producto->precio_sugerido ?? $costoConIsv * 1.15);
        $precioSinIsv = $aplicaIsv && $precioConIsv > 0 ? round($precioConIsv / 1.15, 2) : $precioConIsv;

        $stockDisplay = '';
        if ($stockEnBodega > 0 && $stockDesdeLote > 0) {
            $stockDisplay = number_format($stockTotal, 0) . " unidades ({$stockEnBodega} en bodega + {$stockDesdeLote} en lote)";
        } elseif ($stockEnBodega > 0) {
            $stockDisplay = number_format($stockEnBodega, 0) . " unidades (en bodega)";
        } else {
            $stockDisplay = number_format($stockDesdeLote, 0) . " unidades (desde lote)";
        }

        $set('stock_disponible', $stockDisplay);
        $set('stock_maximo', $stockTotal);
        $set('usa_lotes', true);
        $set('categoria_lote_id', $categoriaLoteId);
        $set('stock_en_bodega', $stockEnBodega);
        $set('stock_desde_lote', $stockDesdeLote);
        $set('costo_bodega', round($costoEnBodega, 4));
        $set('costo_lote', round($costoUnitarioLote, 4));
        $set('costo_unitario', $costoUnitario);
        $set('costo_con_isv', $costoConIsv);
        $set('precio_venta_sugerido', $precioSinIsv);
        $set('precio_venta_minimo', $costoConIsv);
        $set('aplica_isv', $aplicaIsv);
        $set('precio_con_isv', $precioConIsv);
        $set('unidad_id', $producto->unidad_id);
        $set('subtotal_costo', 0);
        $set('subtotal_venta', 0);

        $cantidad = floatval($get('cantidad') ?? 0);
        if ($cantidad > 0) {
            $set('subtotal_costo', round($costoUnitario * $cantidad, 2));
            $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
        }
    }

    private function cargarDatosDesdeBodega(Producto $producto, $viaje, Forms\Set $set, Forms\Get $get): void
    {
        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
            ->where('producto_id', $producto->id)->first();

        if (!$bodegaProducto) { $this->limpiarCampos($set); return; }

        $costoSinIsv = $bodegaProducto->costo_promedio_actual ?? 0;
        $aplicaIsv = $producto->aplica_isv ?? false;
        $costoConIsv = $aplicaIsv ? round($costoSinIsv * 1.15, 2) : $costoSinIsv;
        $precioConIsv = $bodegaProducto->precio_venta_sugerido ?? 0;
        $precioSinIsv = $aplicaIsv && $precioConIsv > 0 ? round($precioConIsv / 1.15, 2) : $precioConIsv;

        $stockActual = (float) $bodegaProducto->stock;
        
        $set('stock_disponible', number_format($stockActual, 2));
        $set('stock_maximo', $stockActual);
        $set('usa_lotes', false);
        $set('categoria_lote_id', null);
        $set('costo_unitario', $costoSinIsv);
        $set('costo_con_isv', $costoConIsv);
        $set('precio_venta_sugerido', $precioSinIsv);
        $set('precio_venta_minimo', $costoConIsv);
        $set('aplica_isv', $aplicaIsv);
        $set('precio_con_isv', $precioConIsv);
        $set('unidad_id', $producto->unidad_id);
        $set('subtotal_costo', 0);
        $set('subtotal_venta', 0);
        
        $cantidad = floatval($get('cantidad') ?? 0);
        if ($cantidad > 0) {
            $set('subtotal_costo', round($costoSinIsv * $cantidad, 2));
            $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
        }
    }

    private function limpiarCampos(Forms\Set $set): void
    {
        $set('stock_disponible', null); $set('stock_maximo', 0);
        $set('usa_lotes', false); $set('categoria_lote_id', null);
        $set('costo_unitario', null); $set('costo_con_isv', null);
        $set('precio_venta_sugerido', null); $set('precio_venta_minimo', null);
        $set('aplica_isv', false); $set('precio_con_isv', null);
        $set('subtotal_costo', 0); $set('subtotal_venta', 0);
    }

    private function recalcularSubtotales(Forms\Get $get, Forms\Set $set, $cantidad): void
    {
        $cantidad = floatval($cantidad ?? 0);
        $costoSinIsv = floatval($get('costo_unitario') ?? 0);
        $precioConIsv = floatval($get('precio_con_isv') ?? 0);
        $set('subtotal_costo', round($costoSinIsv * $cantidad, 2));
        $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
    }

    // ============================================
    // REEMPAQUE AUTOMÁTICO
    // ============================================

    private function ejecutarReempaqueAutomatico(int $productoId, int $bodegaId, int $cantidad, int $viajeId): array
    {
        if ($cantidad <= 0) {
            throw new \Exception("La cantidad para reempaque debe ser mayor a cero");
        }
        
        $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($productoId);
        
        if (!$producto || !$producto->categoria || !$producto->categoria->categoria_origen_id) {
            throw new \Exception("Producto no válido para reempaque automático");
        }
        
        $categoriaLoteId = $producto->categoria->categoria_origen_id;

        $huevosPorUnidad = 30;
        if ($producto->unidad && str_contains(strtolower($producto->unidad->nombre), '15')) {
            $huevosPorUnidad = 15;
        }

        $huevosNecesarios = $cantidad * $huevosPorUnidad;

        $lotes = Lote::where('bodega_id', $bodegaId)
            ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
            ->where('estado', 'disponible')
            ->where('cantidad_huevos_remanente', '>', 0)
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        $totalDisponible = $lotes->sum('cantidad_huevos_remanente');
        if ($totalDisponible < $huevosNecesarios) {
            throw new \Exception(
                "Stock insuficiente en lotes. Necesario: {$huevosNecesarios} huevos, Disponible: {$totalDisponible}"
            );
        }

        $reempaque = Reempaque::create([
            'bodega_id' => $bodegaId,
            'tipo' => 'individual',
            'total_huevos_usados' => $huevosNecesarios,
            'merma' => 0,
            'huevos_utiles' => $huevosNecesarios,
            'costo_total' => 0,
            'costo_unitario_promedio' => 0,
            'cartones_30' => $huevosPorUnidad == 30 ? $cantidad : 0,
            'cartones_15' => $huevosPorUnidad == 15 ? $cantidad : 0,
            'huevos_sueltos' => 0,
            'estado' => 'completado',
            'nota' => "Reempaque automatico - Viaje #{$viajeId}",
            'created_by' => Auth::id(),
        ]);

        $huevosRestantes = $huevosNecesarios;
        $costoTotal = 0;

        foreach ($lotes as $lote) {
            if ($huevosRestantes <= 0) break;

            $huevosAUsar = min($huevosRestantes, $lote->cantidad_huevos_remanente);
            $resultado = $lote->calcularConsumoHuevos($huevosAUsar);

            $huevosPorCarton = $lote->huevos_por_carton ?? 30;
            $cartonesFacturadosUsados = $resultado['huevos_facturados_usados'] / $huevosPorCarton;
            $cartonesRegaloUsados = $resultado['huevos_regalo_usados'] / $huevosPorCarton;
            $cartonesTotalesUsados = $cartonesFacturadosUsados + $cartonesRegaloUsados;

            ReempaqueLote::create([
                'reempaque_id' => $reempaque->id,
                'lote_id' => $lote->id,
                'cantidad_cartones_usados' => round($cartonesTotalesUsados, 3),
                'cantidad_huevos_usados' => $huevosAUsar,
                'cartones_facturados_usados' => round($cartonesFacturadosUsados, 3),
                'cartones_regalo_usados' => round($cartonesRegaloUsados, 3),
                'costo_parcial' => round($resultado['costo'], 4),
            ]);

            $lote->reducirRemanente($huevosAUsar, $resultado['huevos_regalo_usados']);
            $costoTotal += $resultado['costo'];
            $huevosRestantes -= $huevosAUsar;
        }

        $costoUnitarioPorHuevo = $huevosNecesarios > 0 ? $costoTotal / $huevosNecesarios : 0;
        $costoUnitarioProducto = $costoUnitarioPorHuevo * $huevosPorUnidad;

        $reempaque->update([
            'costo_total' => round($costoTotal, 4),
            'costo_unitario_promedio' => round($costoUnitarioPorHuevo, 4),
        ]);

        ReempaqueProducto::create([
            'reempaque_id' => $reempaque->id,
            'producto_id' => $productoId,
            'categoria_id' => $producto->categoria_id,
            'bodega_id' => $bodegaId,
            'cantidad' => $cantidad,
            'costo_unitario' => round($costoUnitarioProducto, 4),
            'costo_total' => round($costoTotal, 4),
            'agregado_a_stock' => false,
        ]);

        return [
            'costo_unitario' => round($costoUnitarioProducto, 4),
            'reempaque_id' => $reempaque->id,
            'reempaque_numero' => $reempaque->numero_reempaque,
        ];
    }

    // ============================================
    // FIX: DEVOLVER STOCK CON PROMEDIO PONDERADO
    // ============================================

    /**
     * Devolver stock a bodega con promedio ponderado correcto.
     * 
     * SIEMPRE recalcula el costo promedio ponderado entre lo que hay
     * en bodega y lo que se devuelve con su costo original.
     * Esto garantiza que las finanzas cuadren sin importar el orden
     * de operaciones (cargar, cancelar, recargar).
     */
    private function devolverStockABodega(
        BodegaProducto $bodegaProducto,
        float $cantidadDevolver,
        float $costoOriginalBodega
    ): void {
        $stockActual = floatval($bodegaProducto->stock);
        $costoActual = floatval($bodegaProducto->costo_promedio_actual);
        $nuevoStock = $stockActual + $cantidadDevolver;

        if ($costoActual <= 0 || $stockActual <= 0) {
            // Bodega vacia o sin costo: restaurar costo original directamente
            $bodegaProducto->costo_promedio_actual = round($costoOriginalBodega, 4);
        } else {
            // Promedio ponderado: stock existente + devuelto con su costo original
            $valorExistente = $stockActual * $costoActual;
            $valorDevuelto = $cantidadDevolver * $costoOriginalBodega;
            $nuevoCosto = ($valorExistente + $valorDevuelto) / $nuevoStock;
            $bodegaProducto->costo_promedio_actual = round($nuevoCosto, 4);
        }

        $bodegaProducto->stock = $nuevoStock;
        $bodegaProducto->actualizarPrecioVentaSegunCosto();
        $bodegaProducto->save();

        Log::info("Stock devuelto a bodega", [
            'producto_id' => $bodegaProducto->producto_id,
            'stock_antes' => $stockActual,
            'stock_despues' => $nuevoStock,
            'costo_antes' => $costoActual,
            'costo_despues' => $bodegaProducto->costo_promedio_actual,
            'cantidad_devuelta' => $cantidadDevolver,
            'costo_devuelto' => $costoOriginalBodega,
        ]);
    }

    // ============================================
    // TABLA Y ACCIONES
    // ============================================

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('producto.nombre')
            ->columns([
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')->searchable()->sortable()->weight('bold'),

                Tables\Columns\TextColumn::make('cantidad')
                    ->label('Cantidad')->numeric(decimalPlaces: 2)->sortable(),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')->badge()->color('gray'),

                Tables\Columns\TextColumn::make('costo_unitario')
                    ->label('Costo Unit.')->money('HNL')->sortable()
                    ->getStateUsing(function ($record) {
                        $costoSinIsv = $record->costo_unitario ?? 0;
                        $aplicaIsv = $record->producto?->aplica_isv ?? false;
                        if ($aplicaIsv && $costoSinIsv > 0) {
                            return round($costoSinIsv * 1.15, 2);
                        }
                        return $costoSinIsv;
                    })
                    ->description(fn ($record) => $record->producto?->aplica_isv ? 'Incluye ISV' : ''),

                Tables\Columns\TextColumn::make('precio_venta_sugerido')
                    ->label('Precio Venta')->money('HNL')->sortable(),

                Tables\Columns\IconColumn::make('producto.aplica_isv')
                    ->label('ISV')->boolean()
                    ->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')->falseColor('gray')
                    ->tooltip(fn ($record) => $record->producto?->aplica_isv ? 'Aplica 15% ISV' : 'Sin ISV'),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Subtotal Costo')->money('HNL')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL')),

                Tables\Columns\TextColumn::make('subtotal_venta')
                    ->label('Subtotal Venta')->money('HNL')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL'))
                    ->tooltip('Incluye ISV si aplica'),

                Tables\Columns\TextColumn::make('cantidad_vendida')
                    ->label('Vendido')->numeric(decimalPlaces: 2)->color('success')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA, Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO, Viaje::ESTADO_LIQUIDANDO, Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('cantidad_merma')
                    ->label('Merma')->numeric(decimalPlaces: 2)->color('danger')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA, Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO, Viaje::ESTADO_LIQUIDANDO, Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('disponible')
                    ->label('Disponible')
                    ->getStateUsing(fn ($record) => $record->getCantidadDisponible())
                    ->numeric(decimalPlaces: 2)->color('warning')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA, Viaje::ESTADO_REGRESANDO,
                    ])),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Producto')
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO
                    ]))
                    ->using(function (array $data, string $model) {
                        $viaje = $this->getOwnerRecord();
                        
                        if (!in_array($viaje->estado, [Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO])) {
                            Notification::make()->title('Viaje no editable')
                                ->body('No se pueden agregar productos a un viaje en este estado.')->danger()->send();
                            return null;
                        }
                        
                        try {
                            return DB::transaction(function () use ($data, $model, $viaje) {
                                $producto = Producto::with('categoria.categoriaOrigen', 'unidad')->find($data['producto_id']);
                                if (!$producto) throw new \Exception('Producto no encontrado');
                                
                                if (!$producto->activo) {
                                    Notification::make()->title('Producto inactivo')
                                        ->body('Este producto está desactivado y no puede ser cargado.')->danger()->send();
                                    throw new \Exception('Producto inactivo');
                                }
                                
                                $yaExiste = $viaje->cargas()->where('producto_id', $data['producto_id'])->exists();
                                if ($yaExiste) {
                                    Notification::make()->title('Producto ya cargado')
                                        ->body('Este producto ya está en la lista de cargas. Use la opción de editar para modificar la cantidad.')
                                        ->warning()->send();
                                    throw new \Exception('Producto ya cargado');
                                }
                                
                                $cantidadSolicitada = floatval($data['cantidad'] ?? 0);
                                if ($cantidadSolicitada <= 0) {
                                    Notification::make()->title('Cantidad inválida')
                                        ->body('La cantidad debe ser mayor a cero.')->danger()->send();
                                    throw new \Exception('Cantidad inválida');
                                }
                                
                                $categoria = $producto->categoria;
                                $usaLotes = $categoria && $categoria->categoria_origen_id;

                                $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                    ->where('producto_id', $data['producto_id'])
                                    ->lockForUpdate()->first();

                                $stockEnBodega = floatval($bodegaProducto->stock ?? 0);
                                $reempaqueId = null;
                                $reempaqueNumero = null;

                                if ($usaLotes) {
                                    // === PRODUCTO QUE USA LOTES ===
                                    $costoEnBodegaOriginal = floatval($bodegaProducto->costo_promedio_actual ?? 0);
                                    
                                    $tomarDeBodega = min($stockEnBodega, $cantidadSolicitada);
                                    $tomarDeLote = $cantidadSolicitada - $tomarDeBodega;
                                    
                                    if ($tomarDeLote > 0) {
                                        $categoriaLoteId = $categoria->categoria_origen_id;
                                        $huevosPorUnidad = 30;
                                        if ($producto->unidad && str_contains(strtolower($producto->unidad->nombre), '15')) {
                                            $huevosPorUnidad = 15;
                                        }
                                        $huevosNecesarios = intval($tomarDeLote) * $huevosPorUnidad;
                                        
                                        $totalHuevosEnLotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
                                            ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
                                            ->where('estado', 'disponible')
                                            ->where('cantidad_huevos_remanente', '>', 0)
                                            ->sum('cantidad_huevos_remanente');
                                        
                                        if ($totalHuevosEnLotes < $huevosNecesarios) {
                                            $unidadesDisponibles = floor($totalHuevosEnLotes / $huevosPorUnidad) + $stockEnBodega;
                                            Notification::make()->title('Stock insuficiente')
                                                ->body("Stock total disponible: {$unidadesDisponibles} unidades. Solicitado: {$cantidadSolicitada}")
                                                ->danger()->persistent()->send();
                                            throw new \Exception('Stock insuficiente en lotes');
                                        }
                                    }
                                    
                                    $costoDelReempaque = 0;

                                    if ($tomarDeLote > 0) {
                                        $resultado = $this->ejecutarReempaqueAutomatico(
                                            $data['producto_id'], $viaje->bodega_origen_id,
                                            (int) $tomarDeLote, $viaje->id
                                        );
                                        $reempaqueId = $resultado['reempaque_id'];
                                        $reempaqueNumero = $resultado['reempaque_numero'];
                                        $costoDelReempaque = $resultado['costo_unitario'];
                                    }

                                    // Costo promedio ponderado de la CARGA (4 decimales)
                                    $valorDeBodega = $tomarDeBodega * $costoEnBodegaOriginal;
                                    $valorDeLote = $tomarDeLote * $costoDelReempaque;
                                    $valorTotal = $valorDeBodega + $valorDeLote;
                                    $costoUnitarioCorrecto = $cantidadSolicitada > 0 
                                        ? round($valorTotal / $cantidadSolicitada, 4) : 0;
                                    
                                    $data['costo_unitario'] = $costoUnitarioCorrecto;
                                    $data['subtotal_costo'] = round($costoUnitarioCorrecto * $cantidadSolicitada, 2);
                                    $data['costo_bodega_original'] = $costoEnBodegaOriginal;

                                    $data['viaje_id'] = $viaje->id;
                                    $data['reempaque_id'] = $reempaqueId;
                                    $record = $model::create($data);

                                    if ($tomarDeBodega > 0) {
                                        $bodegaProducto->stock = $stockEnBodega - $tomarDeBodega;
                                        if ($bodegaProducto->stock <= 0) {
                                            $bodegaProducto->stock = 0;
                                            $bodegaProducto->costo_promedio_actual = 0;
                                        }
                                        $bodegaProducto->save();
                                    }

                                    if ($viaje->estado === Viaje::ESTADO_PLANIFICADO) {
                                        $viaje->iniciarCarga();
                                    }

                                    $mensaje = "Se cargaron {$cantidadSolicitada} unidades.";
                                    if ($tomarDeBodega > 0 && $tomarDeLote > 0) {
                                        $mensaje = "{$tomarDeBodega} de bodega (L " . round($costoEnBodegaOriginal, 2) . ") + {$tomarDeLote} reempacadas (L " . round($costoDelReempaque, 2) . "). Costo promedio: L " . round($costoUnitarioCorrecto, 2);
                                    } elseif ($tomarDeLote > 0) {
                                        $mensaje = "Reempaque {$reempaqueNumero}. {$cantidadSolicitada} unidades a L " . round($costoUnitarioCorrecto, 2);
                                    }

                                    Notification::make()->title('Producto cargado')->body($mensaje)->success()->send();
                                    return $record;

                                } else {
                                    // === FLUJO NORMAL (sin lotes) ===
                                    if (!$bodegaProducto || $stockEnBodega < $cantidadSolicitada) {
                                        Notification::make()->title('Stock insuficiente')
                                            ->body("No hay suficiente stock. Solicitado: {$cantidadSolicitada}, Disponible: {$stockEnBodega}")
                                            ->danger()->persistent()->send();
                                        throw new \Exception('Stock insuficiente');
                                    }

                                    $data['costo_bodega_original'] = floatval($bodegaProducto->costo_promedio_actual ?? 0);
                                    $data['viaje_id'] = $viaje->id;
                                    $data['reempaque_id'] = null;
                                    $record = $model::create($data);

                                    $bodegaProducto->stock = $stockEnBodega - $cantidadSolicitada;
                                    if ($bodegaProducto->stock <= 0) {
                                        $bodegaProducto->stock = 0;
                                        $bodegaProducto->costo_promedio_actual = 0;
                                    }
                                    $bodegaProducto->save();

                                    if ($viaje->estado === Viaje::ESTADO_PLANIFICADO) {
                                        $viaje->iniciarCarga();
                                    }

                                    Notification::make()->title('Producto agregado')
                                        ->body("Se cargaron {$cantidadSolicitada} unidades. Stock restante: " . floatval($bodegaProducto->stock))
                                        ->success()->send();
                                    return $record;
                                }
                            });
                        } catch (\Exception $e) {
                            Log::error("Error al agregar carga: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                            if (strpos($e->getMessage(), 'Stock insuficiente') === false) {
                                Notification::make()->title('Error')->body('Error: ' . $e->getMessage())
                                    ->danger()->persistent()->send();
                            }
                            return null;
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO
                    ]))
                    ->mutateRecordDataUsing(function (array $data, $record): array {
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)->first();
                        
                        $producto = $record->producto;
                        $aplicaIsv = $producto?->aplica_isv ?? false;
                        $costoSinIsv = $data['costo_unitario'] ?? 0;
                        $precioSinIsv = $data['precio_venta_sugerido'] ?? 0;
                        
                        $cantidadDeBodega = floatval($record->cantidad);
                        if ($record->reempaque_id) {
                            $reempaqueProducto = ReempaqueProducto::where('reempaque_id', $record->reempaque_id)
                                ->where('producto_id', $record->producto_id)->first();
                            $cantidadDeBodega = $cantidadDeBodega - floatval($reempaqueProducto->cantidad ?? 0);
                        }
                        
                        $stockDisponible = floatval($bodegaProducto->stock ?? 0) + $cantidadDeBodega;
                        
                        $data['stock_disponible'] = number_format($stockDisponible, 2);
                        $data['stock_maximo'] = $stockDisponible;
                        $data['usa_lotes'] = false;
                        $data['aplica_isv'] = $aplicaIsv;
                        $data['costo_con_isv'] = $aplicaIsv ? round($costoSinIsv * 1.15, 2) : $costoSinIsv;
                        $data['precio_con_isv'] = $aplicaIsv 
                            ? round($precioSinIsv * (1 + self::ISV_RATE), 2) : $precioSinIsv;
                        
                        return $data;
                    })
                    ->using(function ($record, array $data) {
                        $viaje = $this->getOwnerRecord();
                        
                        if (!in_array($viaje->estado, [Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO])) {
                            Notification::make()->title('Viaje no editable')
                                ->body('No se pueden modificar productos de un viaje en este estado.')->danger()->send();
                            return $record;
                        }
                        
                        try {
                            return DB::transaction(function () use ($record, $data, $viaje) {
                                $cantidadNueva = floatval($data['cantidad'] ?? 0);
                                if ($cantidadNueva <= 0) {
                                    Notification::make()->title('Cantidad inválida')
                                        ->body('La cantidad debe ser mayor a cero. Use eliminar si desea quitar el producto.')
                                        ->danger()->send();
                                    throw new \Exception('Cantidad inválida');
                                }
                                
                                if ($record->reempaque_id) {
                                    Notification::make()->title('No se puede editar')
                                        ->body('Esta carga tiene un reempaque asociado. Elimínela y cree una nueva si necesita cambiar la cantidad.')
                                        ->warning()->send();
                                    throw new \Exception('No se puede editar carga con reempaque');
                                }
                                
                                $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                    ->where('producto_id', $record->producto_id)
                                    ->lockForUpdate()->first();

                                $cantidadAnterior = floatval($record->cantidad);
                                $diferencia = $cantidadNueva - $cantidadAnterior;
                                $stockActual = floatval($bodegaProducto->stock ?? 0);

                                if ($diferencia > 0 && $stockActual < $diferencia) {
                                    Notification::make()->title('Stock insuficiente')
                                        ->body("No hay suficiente stock para aumentar la cantidad. Necesita: {$diferencia}, Disponible: {$stockActual}")
                                        ->danger()->persistent()->send();
                                    throw new \Exception('Stock insuficiente');
                                }

                                $record->update($data);

                                if ($diferencia != 0) {
                                    if ($diferencia < 0) {
                                        // FIX: Devolviendo unidades a bodega con promedio ponderado
                                        $costoOriginal = floatval($record->costo_bodega_original ?? $record->costo_unitario ?? 0);
                                        $this->devolverStockABodega($bodegaProducto, abs($diferencia), $costoOriginal);
                                    } else {
                                        // Sacando mas unidades de bodega
                                        $bodegaProducto->stock = $stockActual - $diferencia;
                                        if ($bodegaProducto->stock <= 0) {
                                            $bodegaProducto->stock = 0;
                                            $bodegaProducto->costo_promedio_actual = 0;
                                        }
                                        $bodegaProducto->save();
                                    }
                                }

                                Notification::make()->title('Carga actualizada')
                                    ->body("Cantidad actualizada de {$cantidadAnterior} a {$cantidadNueva}")->success()->send();
                                return $record;
                            });
                        } catch (\Exception $e) {
                            Log::error("Error al editar carga: " . $e->getMessage());
                            if (!in_array($e->getMessage(), ['Stock insuficiente', 'Cantidad inválida', 'No se puede editar carga con reempaque'])) {
                                Notification::make()->title('Error')
                                    ->body('Ocurrio un error al actualizar. Por favor intente nuevamente.')->danger()->send();
                            }
                            return $record;
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar carga')
                    ->modalDescription(fn ($record) => $record->reempaque_id 
                        ? "¿Está seguro de eliminar esta carga? El reempaque asociado será revertido y los huevos volverán al lote."
                        : "¿Está seguro de eliminar esta carga? El stock será devuelto a bodega.")
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->using(function ($record) {
                        $viaje = $this->getOwnerRecord();
                        
                        if (!in_array($viaje->estado, [Viaje::ESTADO_PLANIFICADO, Viaje::ESTADO_CARGANDO])) {
                            Notification::make()->title('Viaje no editable')
                                ->body('No se pueden eliminar productos de un viaje en este estado.')->danger()->send();
                            return false;
                        }
                        
                        try {
                            return DB::transaction(function () use ($record, $viaje) {
                                $cantidadTotal = floatval($record->cantidad);
                                $reempaqueId = $record->reempaque_id;
                                $mensajeExtra = '';
                                $cantidadReempacada = 0;
                                $cantidadDeBodegaOriginal = $cantidadTotal;

                                if ($reempaqueId) {
                                    $reempaque = Reempaque::find($reempaqueId);
                                    
                                    if ($reempaque && !$reempaque->estaCancelado()) {
                                        $reempaqueProducto = $reempaque->reempaqueProductos()
                                            ->where('producto_id', $record->producto_id)->first();
                                        
                                        if ($reempaqueProducto) {
                                            $cantidadReempacada = floatval($reempaqueProducto->cantidad);
                                            $cantidadDeBodegaOriginal = $cantidadTotal - $cantidadReempacada;
                                            $reempaqueProducto->agregado_a_stock = false;
                                            $reempaqueProducto->save();
                                        }

                                        $reempaque->cancelar("Carga eliminada del viaje #{$viaje->id}");
                                        $mensajeExtra = " Reempaque {$reempaque->numero_reempaque} revertido.";
                                    }
                                }

                                // FIX: Devolver unidades de bodega con PROMEDIO PONDERADO correcto
                                if ($cantidadDeBodegaOriginal > 0) {
                                    $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                        ->where('producto_id', $record->producto_id)
                                        ->lockForUpdate()->first();

                                    if ($bodegaProducto) {
                                        // Usar costo_bodega_original (el costo que tenia bodega ANTES de cargar)
                                        $costoOriginal = floatval($record->costo_bodega_original ?? $record->costo_unitario ?? 0);
                                        $this->devolverStockABodega($bodegaProducto, $cantidadDeBodegaOriginal, $costoOriginal);
                                    } else {
                                        $costoOriginal = floatval($record->costo_bodega_original ?? $record->costo_unitario ?? 0);
                                        $bp = BodegaProducto::create([
                                            'bodega_id' => $viaje->bodega_origen_id,
                                            'producto_id' => $record->producto_id,
                                            'stock' => $cantidadDeBodegaOriginal,
                                            'costo_promedio_actual' => round($costoOriginal, 4),
                                            'stock_minimo' => 0,
                                            'activo' => true,
                                        ]);
                                        $bp->actualizarPrecioVentaSegunCosto();
                                        $bp->save();
                                        
                                        Log::warning("BodegaProducto no existía al eliminar carga. Creado nuevo registro.", [
                                            'bodega_id' => $viaje->bodega_origen_id,
                                            'producto_id' => $record->producto_id,
                                            'cantidad_devuelta' => $cantidadDeBodegaOriginal,
                                        ]);
                                    }
                                }

                                $record->delete();

                                $mensaje = "Se devolvieron {$cantidadTotal} unidades.";
                                if ($cantidadDeBodegaOriginal > 0 && $cantidadReempacada > 0) {
                                    $mensaje = "{$cantidadDeBodegaOriginal} a bodega + {$cantidadReempacada} al lote.{$mensajeExtra}";
                                } elseif ($cantidadReempacada > 0) {
                                    $mensaje = "{$cantidadReempacada} devueltas al lote.{$mensajeExtra}";
                                } else {
                                    $mensaje = "{$cantidadTotal} devueltas a bodega.";
                                }

                                Notification::make()->title('Carga eliminada')->body($mensaje)->success()->send();
                                return true;
                            });
                        } catch (\Exception $e) {
                            Log::error("Error al eliminar carga: " . $e->getMessage());
                            Notification::make()->title('Error')->body('Error al eliminar: ' . $e->getMessage())->danger()->send();
                            return false;
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin productos cargados')
            ->emptyStateDescription('Agregue productos para cargar en el camion.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }
}