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
use App\Application\Services\ReempaqueService;
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
            Viaje::ESTADO_CARGANDO,
            Viaje::ESTADO_RECARGANDO,
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
                    ->getOptionLabelUsing(fn ($value) => Producto::find($value)?->nombre ?? $value)
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated()
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
                    ->options(fn() => Unidad::where('activo', true)->pluck('nombre', 'id'))
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated()
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

                // Campos informativos para estado Recargando (solo visibles en edit + recargando)
                Forms\Components\Placeholder::make('info_vendidos')
                    ->label('Vendidos')
                    ->content(function () {
                        $record = $this->getMountedTableActionRecord();
                        if (!$record) return '0';
                        $record->loadMissing('unidad');
                        return number_format(floatval($record->cantidad_vendida ?? 0), 2) . ' ' . ($record->unidad?->abreviatura ?? '');
                    })
                    ->visible(fn (string $operation) => $operation === 'edit' && $this->getOwnerRecord()->estado === Viaje::ESTADO_RECARGANDO),

                Forms\Components\Placeholder::make('info_disponible')
                    ->label('Disponible en Camión')
                    ->content(function () {
                        $record = $this->getMountedTableActionRecord();
                        if (!$record) return '0';
                        $record->loadMissing('unidad');
                        $cantidad = floatval($record->cantidad ?? 0);
                        $vendida = floatval($record->cantidad_vendida ?? 0);
                        $merma = floatval($record->cantidad_merma ?? 0);
                        $devuelta = floatval($record->cantidad_devuelta ?? 0);
                        $disponible = $cantidad - $vendida - $merma - $devuelta;
                        return number_format(max(0, $disponible), 2) . ' ' . ($record->unidad?->abreviatura ?? '');
                    })
                    ->visible(fn (string $operation) => $operation === 'edit' && $this->getOwnerRecord()->estado === Viaje::ESTADO_RECARGANDO),

                Forms\Components\TextInput::make('cantidad')
                    ->label(fn (string $operation) =>
                        $operation === 'edit' && $this->getOwnerRecord()->estado === Viaje::ESTADO_RECARGANDO
                            ? 'Nueva Cantidad Total (solo aumentar)'
                            : 'Cantidad a Cargar'
                    )
                    ->numeric()
                    ->required()
                    ->step(0.01)
                    ->minValue(function (string $operation) {
                        // En Recargando + edit: no puede bajar de la cantidad actual
                        if ($operation === 'edit' && $this->getOwnerRecord()->estado === Viaje::ESTADO_RECARGANDO) {
                            $record = $this->getMountedTableActionRecord();
                            return $record ? floatval($record->cantidad) : 0.01;
                        }
                        return 0.01;
                    })
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2, '.', '') : null)
                    ->maxValue(fn(Forms\Get $get) => floatval($get('stock_maximo')) ?: 999999)
                    ->validationMessages([
                        'max' => 'La cantidad no puede exceder el stock disponible.',
                        'min' => 'En recarga solo se puede aumentar la cantidad, no reducirla.',
                    ])
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set, string $operation) {
                        $cantidad = floatval($state ?? 0);
                        $productoId = $get('producto_id');

                        if (!$productoId || $cantidad <= 0) {
                            $this->recalcularSubtotales($get, $set, $state);
                            return;
                        }

                        if ($operation === 'edit') {
                            // En EDIT: NO recalcular desde precios actuales.
                            // El costo real se calcula al guardar (en ->using()) basándose
                            // en los campos de origen (cantidad_de_bodega, cantidad_de_lote, etc.)
                            // Aquí solo recalculamos los subtotales con el costo guardado.
                            $this->recalcularSubtotales($get, $set, $state);
                            return;
                        }

                        // En CREATE: calcular costo desde precios actuales de bodega y lotes
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
                        fn(Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                            $stockMaximo = floatval($get('stock_maximo') ?? 0);
                            if ($stockMaximo > 0 && floatval($value) > $stockMaximo) {
                                $fail("La cantidad no puede ser mayor al stock disponible ({$stockMaximo}).");
                            }
                        },
                    ]),

                Forms\Components\TextInput::make('costo_con_isv')
                    ->label('Costo Unitario')->numeric()->prefix('L')->disabled()->dehydrated(false)
                    ->helperText(fn(Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Lo que pagaste (incluye ISV)' : 'Costo del producto'),

                Forms\Components\Hidden::make('costo_unitario')->default(0),

                Forms\Components\TextInput::make('precio_venta_sugerido')
                    ->label(fn(Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Precio Venta (sin ISV)' : 'Precio Venta')
                    ->numeric()->prefix('L')->required()
                    ->disabled(fn (string $operation) => $operation === 'edit')
                    ->dehydrated()
                    ->minValue(fn(Forms\Get $get) => floatval($get('precio_venta_minimo')) ?: 0.01)
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
                    ->onColor('success')->offColor('gray')
                    ->hidden(),

                Forms\Components\TextInput::make('precio_con_isv')
                    ->label('Precio con ISV')->numeric()->prefix('L')->disabled()->dehydrated(false)
                    ->helperText('Precio final al cliente')
                    ->visible(fn(Forms\Get $get) => $get('aplica_isv') ?? false),

                Forms\Components\TextInput::make('precio_venta_minimo')
                    ->label('Precio Minimo')->numeric()->prefix('L')->disabled()->dehydrated(true)
                    ->helperText('No vender por debajo'),

                Forms\Components\TextInput::make('subtotal_costo')
                    ->label('Subtotal Costo')->numeric()->prefix('L')->disabled()->dehydrated(true)->default(0),

                Forms\Components\TextInput::make('subtotal_venta')
                    ->label(fn(Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Subtotal Venta (con ISV)' : 'Subtotal Venta')
                    ->numeric()->prefix('L')->disabled()->dehydrated(true)->default(0)
                    ->helperText(fn(Forms\Get $get) => ($get('aplica_isv') ?? false) ? 'Incluye ISV' : ''),
            ])
            ->columns(3);
    }

    // ============================================
    // MÉTODOS PRIVADOS DE CARGA DE DATOS
    // ============================================

    private function cargarDatosDesdeLote(Producto $producto, $viaje, Forms\Set $set, Forms\Get $get): void
    {
        $reempaqueService = app(ReempaqueService::class);
        $stockInfo = $reempaqueService->calcularStockDisponible($producto->id, $viaje->bodega_origen_id);

        if ($stockInfo['stock_total'] <= 0) {
            Notification::make()->title('Sin stock disponible')
                ->body("No hay stock en bodega ni en lotes para este producto")->warning()->send();
            $this->limpiarCampos($set);
            return;
        }

        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
            ->where('producto_id', $producto->id)->first();

        $costoUnitario = $stockInfo['costo_promedio'];
        $aplicaIsv = $producto->aplica_isv ?? false;
        $costoConIsv = $aplicaIsv ? round($costoUnitario * 1.15, 2) : $costoUnitario;
        $precioConIsv = $bodegaProducto->precio_venta_sugerido ?? ($producto->precio_sugerido ?? $costoConIsv * 1.15);
        $precioSinIsv = $aplicaIsv && $precioConIsv > 0 ? round($precioConIsv / 1.15, 2) : $precioConIsv;

        $stockDisplay = $reempaqueService->getStockDisplay($stockInfo['stock_en_bodega'], $stockInfo['stock_desde_lote']);

        $set('stock_disponible', $stockDisplay . " unidades");
        $set('stock_maximo', $stockInfo['stock_total']);
        $set('usa_lotes', true);
        $set('categoria_lote_id', $producto->categoria->categoria_origen_id);
        $set('stock_en_bodega', $stockInfo['stock_en_bodega']);
        $set('stock_desde_lote', $stockInfo['stock_desde_lote']);
        $set('costo_bodega', $stockInfo['costo_bodega']);
        $set('costo_lote', $stockInfo['costo_lote']);
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

        if (!$bodegaProducto) {
            $this->limpiarCampos($set);
            return;
        }

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
        $set('stock_disponible', null);
        $set('stock_maximo', 0);
        $set('usa_lotes', false);
        $set('categoria_lote_id', null);
        $set('costo_unitario', null);
        $set('costo_con_isv', null);
        $set('precio_venta_sugerido', null);
        $set('precio_venta_minimo', null);
        $set('aplica_isv', false);
        $set('precio_con_isv', null);
        $set('subtotal_costo', 0);
        $set('subtotal_venta', 0);
    }

    private function recalcularSubtotales(Forms\Get $get, Forms\Set $set, $cantidad): void
    {
        $cantidad = floatval($cantidad ?? 0);
        $costoSinIsv = floatval($get('costo_unitario') ?? 0);
        $precioConIsv = floatval($get('precio_con_isv') ?? 0);
        $set('subtotal_costo', round($costoSinIsv * $cantidad, 2));
        $set('subtotal_venta', round($precioConIsv * $cantidad, 2));
    }

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
                    ->description(fn($record) => $record->producto?->aplica_isv ? 'Incluye ISV' : ''),

                Tables\Columns\TextColumn::make('precio_venta_sugerido')
                    ->label('Precio Venta')->money('HNL')->sortable(),

                Tables\Columns\IconColumn::make('producto.aplica_isv')
                    ->label('ISV')->boolean()
                    ->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')->falseColor('gray')
                    ->tooltip(fn($record) => $record->producto?->aplica_isv ? 'Aplica 15% ISV' : 'Sin ISV'),

                Tables\Columns\TextColumn::make('subtotal_costo')
                    ->label('Subtotal Costo')->money('HNL')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL')),

                Tables\Columns\TextColumn::make('subtotal_venta')
                    ->label('Subtotal Venta')->money('HNL')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('HNL'))
                    ->tooltip('Incluye ISV si aplica'),

                Tables\Columns\TextColumn::make('cantidad_vendida')
                    ->label('Vendido')->numeric(decimalPlaces: 2)->color('success')
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_RECARGANDO,
                        Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO,
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('cantidad_merma')
                    ->label('Merma')->numeric(decimalPlaces: 2)->color('danger')
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_RECARGANDO,
                        Viaje::ESTADO_REGRESANDO,
                        Viaje::ESTADO_DESCARGANDO,
                        Viaje::ESTADO_LIQUIDANDO,
                        Viaje::ESTADO_CERRADO
                    ])),

                Tables\Columns\TextColumn::make('disponible')
                    ->label('Disponible')
                    ->getStateUsing(fn($record) => $record->getCantidadDisponible())
                    ->numeric(decimalPlaces: 2)->color('warning')
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_EN_RUTA,
                        Viaje::ESTADO_RECARGANDO,
                        Viaje::ESTADO_REGRESANDO,
                    ])),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar Producto')
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO,
                        Viaje::ESTADO_RECARGANDO,
                    ]))
                    ->using(function (array $data, string $model) {
                        $viaje = $this->getOwnerRecord();

                        if (!in_array($viaje->estado, [
                            Viaje::ESTADO_PLANIFICADO,
                            Viaje::ESTADO_CARGANDO,
                            Viaje::ESTADO_RECARGANDO,
                        ])) {
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
                                        $reempaqueService = app(ReempaqueService::class);
                                        $resultado = $reempaqueService->ejecutarReempaqueAutomatico(
                                            $data['producto_id'],
                                            $viaje->bodega_origen_id,
                                            (int) $tomarDeLote,
                                            "Viaje #{$viaje->id}"
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
                                    $data['cantidad_de_bodega'] = $tomarDeBodega;
                                    $data['cantidad_de_lote'] = $tomarDeLote;
                                    $data['costo_unitario_lote'] = $costoDelReempaque;

                                    $data['viaje_id'] = $viaje->id;
                                    $data['reempaque_id'] = $reempaqueId;
                                    $record = $model::create($data);

                                    if ($tomarDeBodega > 0) {
                                        $bodegaProducto->stock = max(0, $stockEnBodega - $tomarDeBodega);
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
                                    $data['cantidad_de_bodega'] = $cantidadSolicitada;
                                    $data['cantidad_de_lote'] = 0;
                                    $data['costo_unitario_lote'] = 0;
                                    $data['viaje_id'] = $viaje->id;
                                    $data['reempaque_id'] = null;
                                    $record = $model::create($data);

                                    $bodegaProducto->stock = max(0, $stockEnBodega - $cantidadSolicitada);
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
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO,
                        Viaje::ESTADO_RECARGANDO,
                    ]))
                    ->mutateRecordDataUsing(function (array $data, $record): array {
                        $viaje = $this->getOwnerRecord();
                        $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                            ->where('producto_id', $record->producto_id)->first();

                        $producto = $record->producto;
                        $aplicaIsv = $producto?->aplica_isv ?? false;
                        $costoSinIsv = $data['costo_unitario'] ?? 0;
                        $precioSinIsv = $data['precio_venta_sugerido'] ?? 0;

                        // Cuánto de esta carga vino de bodega (descontando lo reempacado)
                        $cantidadDeBodega = floatval($record->cantidad);
                        if ($record->reempaque_id) {
                            $reempaqueProducto = ReempaqueProducto::where('reempaque_id', $record->reempaque_id)
                                ->where('producto_id', $record->producto_id)->first();
                            $cantidadDeBodega = $cantidadDeBodega - floatval($reempaqueProducto->cantidad ?? 0);
                        }

                        $categoria = $producto?->categoria;
                        $usaLotes = $categoria && $categoria->categoria_origen_id;
                        $stockEnBodega = floatval($bodegaProducto->stock ?? 0);

                        if ($usaLotes) {
                            // stock_maximo = cantidad actual cargada + stock libre en bodega + stock libre en lote
                            $categoriaLoteId = $categoria->categoria_origen_id;
                            $huevosPorUnidad = 30;
                            if ($producto->unidad && str_contains(strtolower($producto->unidad->nombre ?? ''), '15')) {
                                $huevosPorUnidad = 15;
                            }
                            $totalHuevosEnLotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
                                ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
                                ->where('estado', 'disponible')
                                ->where('cantidad_huevos_remanente', '>', 0)
                                ->sum('cantidad_huevos_remanente');
                            $stockDesdeLote = floor($totalHuevosEnLotes / $huevosPorUnidad);
                            $stockMaximo = floatval($record->cantidad) + $stockEnBodega + $stockDesdeLote;

                            $data['stock_disponible'] = number_format($stockEnBodega + $stockDesdeLote, 0) . " adicionales disponibles";
                            $data['stock_maximo'] = $stockMaximo;
                            $data['usa_lotes'] = true;
                        } else {
                            // stock_maximo = cantidad actual + stock libre en bodega
                            $stockMaximo = floatval($record->cantidad) + $stockEnBodega;
                            $data['stock_disponible'] = number_format($stockEnBodega, 2) . " adicionales en bodega";
                            $data['stock_maximo'] = $stockMaximo;
                            $data['usa_lotes'] = false;
                        }

                        $data['aplica_isv'] = $aplicaIsv;
                        $data['costo_con_isv'] = $aplicaIsv ? round($costoSinIsv * 1.15, 2) : $costoSinIsv;
                        $data['precio_con_isv'] = $aplicaIsv
                            ? round($precioSinIsv * (1 + self::ISV_RATE), 2) : $precioSinIsv;

                        return $data;
                    })
                    ->using(function ($record, array $data) {
                        $viaje = $this->getOwnerRecord();

                        if (!in_array($viaje->estado, [
                            Viaje::ESTADO_PLANIFICADO,
                            Viaje::ESTADO_CARGANDO,
                            Viaje::ESTADO_RECARGANDO,
                        ])) {
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

                                // FIX: Ya NO bloqueamos cargas con reempaque — ahora se soporta aumento desde lote
                                $producto = Producto::with('categoria.categoriaOrigen', 'unidad')
                                    ->find($record->producto_id);
                                $categoria = $producto?->categoria;
                                $usaLotes = $categoria && $categoria->categoria_origen_id;

                                $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                    ->where('producto_id', $record->producto_id)
                                    ->lockForUpdate()->first();

                                $cantidadAnterior = floatval($record->cantidad);
                                $diferencia = $cantidadNueva - $cantidadAnterior;
                                $stockActual = floatval($bodegaProducto->stock ?? 0);

                                // Protección server-side: en Recargando no se permite reducir
                                if ($viaje->estado === Viaje::ESTADO_RECARGANDO && $diferencia < 0) {
                                    Notification::make()->title('No permitido')
                                        ->body('En estado Recargando solo se puede aumentar la cantidad. Para devolver producto use la acción "Regresar".')
                                        ->danger()->send();
                                    throw new \Exception('Reducción no permitida en estado Recargando');
                                }

                                // Sin cambio de cantidad: solo actualizar precio u otros campos
                                if ($diferencia == 0) {
                                    $record->update($data);
                                    Notification::make()->title('Carga actualizada')->success()->send();
                                    return $record;
                                }

                                if ($diferencia < 0) {
                                    // ── REDUCIR: LIFO — primero devolver al lote, luego a bodega ──
                                    $cantidadADevolver = abs($diferencia);
                                    $cantidadDeLoteActual = floatval($record->cantidad_de_lote ?? 0);
                                    $cantidadDeBodegaActual = floatval($record->cantidad_de_bodega ?? 0);
                                    $costoLoteActual = floatval($record->costo_unitario_lote ?? 0);
                                    $costoBodegaOriginal = floatval($record->costo_bodega_original ?? $record->costo_unitario ?? 0);

                                    $devolverAlLote = 0;
                                    $devolverABodega = 0;
                                    $mensaje = "Cantidad reducida de {$cantidadAnterior} a {$cantidadNueva}. ";

                                    if ($usaLotes && $cantidadDeLoteActual > 0) {
                                        // LIFO: primero devolver al lote
                                        $devolverAlLote = min($cantidadADevolver, $cantidadDeLoteActual);
                                        $devolverABodega = max(0, $cantidadADevolver - $devolverAlLote);

                                        // Revertir reempaque parcialmente (devolver huevos al lote)
                                        if ($devolverAlLote > 0 && $record->reempaque_id) {
                                            app(ReempaqueService::class)->revertirReempaqueParcial(
                                                $record->reempaque_id,
                                                $record->producto_id,
                                                $devolverAlLote
                                            );
                                            $mensaje .= "{$devolverAlLote} devueltas al lote. ";
                                        }

                                        // Devolver a bodega si sobra
                                        if ($devolverABodega > 0) {
                                            app(ReempaqueService::class)->devolverStockABodega($bodegaProducto, $devolverABodega, $costoBodegaOriginal);
                                            $mensaje .= "{$devolverABodega} devueltas a bodega.";
                                        }
                                    } else {
                                        // Sin lotes: todo va a bodega
                                        $devolverABodega = $cantidadADevolver;
                                        app(ReempaqueService::class)->devolverStockABodega($bodegaProducto, $devolverABodega, $costoBodegaOriginal);
                                        $mensaje .= "{$devolverABodega} devueltas a bodega.";
                                    }

                                    // Actualizar campos de origen en la carga
                                    $nuevaCantidadDeLote = max(0, $cantidadDeLoteActual - $devolverAlLote);
                                    $nuevaCantidadDeBodega = max(0, $cantidadDeBodegaActual - $devolverABodega);

                                    $data['cantidad_de_lote'] = $nuevaCantidadDeLote;
                                    $data['cantidad_de_bodega'] = $nuevaCantidadDeBodega;
                                    $data['costo_unitario_lote'] = $costoLoteActual; // se mantiene

                                    // Recalcular costo unitario promedio ponderado
                                    $valorBodega = $nuevaCantidadDeBodega * $costoBodegaOriginal;
                                    $valorLote = $nuevaCantidadDeLote * $costoLoteActual;
                                    $costoNuevo = $cantidadNueva > 0
                                        ? round(($valorBodega + $valorLote) / $cantidadNueva, 4)
                                        : 0;

                                    $data['costo_unitario'] = $costoNuevo;
                                    $data['subtotal_costo'] = round($costoNuevo * $cantidadNueva, 2);

                                    $record->update($data);

                                    Notification::make()->title('Carga actualizada')
                                        ->body($mensaje)->success()->send();
                                    return $record;
                                }

                                // ── AUMENTAR (diferencia > 0) ─────────────────────────────────
                                if ($usaLotes) {
                                    // Primero agotar bodega, luego ir al lote
                                    $tomarDeBodega = min($stockActual, $diferencia);
                                    $tomarDeLote = $diferencia - $tomarDeBodega;

                                    if ($tomarDeLote > 0) {
                                        $categoriaLoteId = $categoria->categoria_origen_id;
                                        $huevosPorUnidad = 30;
                                        if ($producto->unidad && str_contains(strtolower($producto->unidad->nombre ?? ''), '15')) {
                                            $huevosPorUnidad = 15;
                                        }

                                        // Validar stock para el TOTAL consolidado (anterior + nuevo),
                                        // considerando que la reversión del reempaque anterior devuelve
                                        // huevos al lote, así que estarán disponibles
                                        $cantidadDeLoteAnteriorValidacion = floatval($record->cantidad_de_lote ?? 0);
                                        $totalLoteConsolidadoValidacion = $cantidadDeLoteAnteriorValidacion + $tomarDeLote;
                                        $huevosNecesarios = intval($totalLoteConsolidadoValidacion) * $huevosPorUnidad;

                                        // Huevos actuales en lotes + huevos que se recuperarán de la reversión
                                        $huevosQueSeRecuperan = intval($cantidadDeLoteAnteriorValidacion) * $huevosPorUnidad;
                                        $totalHuevosEnLotes = Lote::where('bodega_id', $viaje->bodega_origen_id)
                                            ->whereHas('producto', fn($q) => $q->where('categoria_id', $categoriaLoteId))
                                            ->where('estado', 'disponible')
                                            ->where('cantidad_huevos_remanente', '>', 0)
                                            ->sum('cantidad_huevos_remanente');
                                        $totalHuevosDisponibles = $totalHuevosEnLotes + $huevosQueSeRecuperan;

                                        if ($totalHuevosDisponibles < $huevosNecesarios) {
                                            $unidadesDisponibles = floor($totalHuevosDisponibles / $huevosPorUnidad) + $stockActual;
                                            Notification::make()->title('Stock insuficiente')
                                                ->body("Disponible: {$unidadesDisponibles} unidades adicionales. Necesita: {$diferencia}")
                                                ->danger()->persistent()->send();
                                            throw new \Exception('Stock insuficiente');
                                        }
                                    }

                                    // ── REEMPAQUE CONSOLIDADO ──
                                    // Si la carga ya tiene reempaque y necesita más del lote,
                                    // revertimos el anterior y creamos uno nuevo consolidado
                                    // (anterior + nuevo). Así siempre hay UN solo reempaque_id
                                    // por carga, evitando reempaques huérfanos.
                                    $cantidadDeLoteAnterior = floatval($record->cantidad_de_lote ?? 0);
                                    $cantidadDeBodegaAnterior = floatval($record->cantidad_de_bodega ?? 0);
                                    $costoBodegaOriginal = floatval($record->costo_bodega_original ?? 0);

                                    $nuevaCantidadDeBodega = $cantidadDeBodegaAnterior + $tomarDeBodega;
                                    $totalLoteConsolidado = $cantidadDeLoteAnterior + $tomarDeLote;

                                    $reempaqueService = app(ReempaqueService::class);
                                    $nuevoReempaqueId = null;
                                    $costoLoteConsolidado = 0;

                                    if ($tomarDeLote > 0 && $totalLoteConsolidado > 0) {
                                        // Necesita nuevas unidades del lote — consolidar

                                        // 1. Revertir reempaque anterior si existe (devuelve huevos al lote)
                                        if ($record->reempaque_id && $cantidadDeLoteAnterior > 0) {
                                            $reempaqueAnterior = Reempaque::find($record->reempaque_id);
                                            if ($reempaqueAnterior && !$reempaqueAnterior->estaInactivo()) {
                                                $reempaqueService->revertirReempaqueParcial(
                                                    $record->reempaque_id,
                                                    $record->producto_id,
                                                    $cantidadDeLoteAnterior
                                                );
                                            }
                                        }

                                        // 2. Crear reempaque consolidado (anterior + nuevo)
                                        $etiquetaOrigen = $cantidadDeLoteAnterior > 0
                                            ? "Viaje #{$viaje->id} (recarga consolidada)"
                                            : "Viaje #{$viaje->id}";
                                        $resultado = $reempaqueService->ejecutarReempaqueAutomatico(
                                            $record->producto_id,
                                            $viaje->bodega_origen_id,
                                            (int) $totalLoteConsolidado,
                                            $etiquetaOrigen
                                        );
                                        $nuevoReempaqueId = $resultado['reempaque_id'];
                                        $costoLoteConsolidado = $resultado['costo_unitario'];
                                    } elseif ($cantidadDeLoteAnterior > 0) {
                                        // Solo aumenta bodega, lote anterior se mantiene intacto
                                        $costoLoteConsolidado = floatval($record->costo_unitario_lote ?? 0);
                                    }

                                    // Descontar de bodega si aplica
                                    if ($tomarDeBodega > 0) {
                                        $bodegaProducto->stock = max(0, $stockActual - $tomarDeBodega);
                                        $bodegaProducto->save();
                                    }

                                    // Costo promedio ponderado total de la carga
                                    $valorBodegaTotal = $nuevaCantidadDeBodega * $costoBodegaOriginal;
                                    $valorLoteTotal = $totalLoteConsolidado * $costoLoteConsolidado;
                                    $costoNuevo = $cantidadNueva > 0
                                        ? round(($valorBodegaTotal + $valorLoteTotal) / $cantidadNueva, 4)
                                        : floatval($record->costo_unitario);

                                    $data['costo_unitario'] = $costoNuevo;
                                    $data['subtotal_costo'] = round($costoNuevo * $cantidadNueva, 2);
                                    $data['cantidad_de_bodega'] = $nuevaCantidadDeBodega;
                                    $data['cantidad_de_lote'] = $totalLoteConsolidado;
                                    $data['costo_unitario_lote'] = $costoLoteConsolidado;

                                    // Siempre actualizar reempaque_id al consolidado
                                    if ($nuevoReempaqueId) {
                                        $data['reempaque_id'] = $nuevoReempaqueId;
                                    }

                                    $record->update($data);

                                    $mensaje = "Cantidad aumentada de {$cantidadAnterior} a {$cantidadNueva}.";
                                    if ($tomarDeBodega > 0 && $tomarDeLote > 0) {
                                        $mensaje .= " {$tomarDeBodega} de bodega + {$tomarDeLote} del lote (reempaque automático).";
                                    } elseif ($tomarDeLote > 0) {
                                        $mensaje .= " {$tomarDeLote} unidades desde lote (reempaque automático).";
                                    } else {
                                        $mensaje .= " {$tomarDeBodega} unidades desde bodega.";
                                    }

                                    Notification::make()->title('Carga actualizada')->body($mensaje)->success()->send();
                                    return $record;

                                } else {
                                    // ── PRODUCTO SIN LOTES: solo bodega ──────────────────────
                                    if ($stockActual < $diferencia) {
                                        Notification::make()->title('Stock insuficiente')
                                            ->body("No hay suficiente stock para aumentar la cantidad. Necesita: {$diferencia}, Disponible: {$stockActual}")
                                            ->danger()->persistent()->send();
                                        throw new \Exception('Stock insuficiente');
                                    }

                                    $data['cantidad_de_bodega'] = $cantidadNueva;
                                    $data['cantidad_de_lote'] = 0;
                                    $data['costo_unitario_lote'] = 0;

                                    $record->update($data);
                                    $bodegaProducto->stock = max(0, $stockActual - $diferencia);
                                    $bodegaProducto->save();

                                    Notification::make()->title('Carga actualizada')
                                        ->body("Cantidad actualizada de {$cantidadAnterior} a {$cantidadNueva}")
                                        ->success()->send();
                                    return $record;
                                }
                            });
                        } catch (\Exception $e) {
                            Log::error("Error al editar carga: " . $e->getMessage());
                            if (!in_array($e->getMessage(), ['Stock insuficiente', 'Cantidad inválida'])) {
                                Notification::make()->title('Error')
                                    ->body('Ocurrio un error al actualizar. Por favor intente nuevamente.')->danger()->send();
                            }
                            return $record;
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn() => in_array($this->getOwnerRecord()->estado, [
                        Viaje::ESTADO_PLANIFICADO,
                        Viaje::ESTADO_CARGANDO,
                        Viaje::ESTADO_RECARGANDO,
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Eliminar carga')
                    ->modalDescription(fn($record) => $record->reempaque_id
                        ? "¿Está seguro de eliminar esta carga? El reempaque asociado será revertido y los huevos volverán al lote."
                        : "¿Está seguro de eliminar esta carga? El stock será devuelto a bodega.")
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->using(function ($record) {
                        $viaje = $this->getOwnerRecord();

                        if (!in_array($viaje->estado, [
                            Viaje::ESTADO_PLANIFICADO,
                            Viaje::ESTADO_CARGANDO,
                            Viaje::ESTADO_RECARGANDO,
                        ])) {
                            Notification::make()->title('Viaje no editable')
                                ->body('No se pueden eliminar productos de un viaje en este estado.')->danger()->send();
                            return false;
                        }

                        try {
                            return DB::transaction(function () use ($record, $viaje) {
                                $cantidadTotal = floatval($record->cantidad);

                                // Usar campos de origen para determinar exactamente cuánto vino de cada fuente
                                $cantidadDeLote = floatval($record->cantidad_de_lote ?? 0);
                                $cantidadDeBodega = floatval($record->cantidad_de_bodega ?? 0);

                                // Fallback legacy: si no hay campos de origen, calcular desde reempaque
                                if ($cantidadDeBodega == 0 && $cantidadDeLote == 0) {
                                    $cantidadDeBodega = $cantidadTotal;
                                }

                                $mensajeExtra = '';

                                // 1. Devolver unidades al lote (revertir reempaque)
                                if ($cantidadDeLote > 0 && $record->reempaque_id) {
                                    $reempaque = Reempaque::find($record->reempaque_id);

                                    if ($reempaque && !$reempaque->estaInactivo()) {
                                        app(ReempaqueService::class)->revertirReempaqueParcial(
                                            $record->reempaque_id,
                                            $record->producto_id,
                                            $cantidadDeLote
                                        );
                                        $reempaque->refresh();
                                        $mensajeExtra = " Reempaque {$reempaque->numero_reempaque} revertido.";
                                    }
                                }

                                // 2. Devolver unidades de bodega con costo original
                                if ($cantidadDeBodega > 0) {
                                    $bodegaProducto = BodegaProducto::where('bodega_id', $viaje->bodega_origen_id)
                                        ->where('producto_id', $record->producto_id)
                                        ->lockForUpdate()->first();

                                    $costoOriginal = floatval($record->costo_bodega_original ?? $record->costo_unitario ?? 0);

                                    if ($bodegaProducto) {
                                        app(ReempaqueService::class)->devolverStockABodega($bodegaProducto, $cantidadDeBodega, $costoOriginal);
                                    } else {
                                        $bp = BodegaProducto::create([
                                            'bodega_id' => $viaje->bodega_origen_id,
                                            'producto_id' => $record->producto_id,
                                            'stock' => $cantidadDeBodega,
                                            'costo_promedio_actual' => round($costoOriginal, 4),
                                            'stock_minimo' => 0,
                                            'activo' => true,
                                        ]);
                                        $bp->actualizarPrecioVentaSegunCosto();
                                        $bp->save();
                                    }
                                }

                                $record->delete();

                                $mensaje = "Se devolvieron {$cantidadTotal} unidades.";
                                if ($cantidadDeBodega > 0 && $cantidadDeLote > 0) {
                                    $mensaje = "{$cantidadDeBodega} a bodega + {$cantidadDeLote} al lote.{$mensajeExtra}";
                                } elseif ($cantidadDeLote > 0) {
                                    $mensaje = "{$cantidadDeLote} devueltas al lote.{$mensajeExtra}";
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