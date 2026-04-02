<?php

namespace App\Filament\Resources;

use App\Enums\CompraEstado;
use App\Filament\Resources\CompraResource\Pages;
use App\Services\Compra\CompraStateManager;
use App\Filament\Resources\CompraResource\RelationManagers;
use App\Models\Compra;
use App\Models\Concerns\HasBodegaScope;
use App\Models\Producto;
use App\Models\Unidad;
use App\Services\Fiscal\IsvCalculator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompraResource extends Resource
{
    use HasBodegaScope;
    protected static ?string $model = Compra::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 2;

    protected static ?string $pluralModelLabel = 'Compras';

    public static function getEloquentQuery(): Builder
    {
        return self::scopeQueryPorBodega(parent::getEloquentQuery());
    }

    // ISV: Delegado a App\Services\Fiscal\IsvCalculator
    // Autorización: Delegada a App\Models\Concerns\HasBodegaScope

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información General')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('proveedor_id')
                                    ->label('Proveedor')
                                    ->relationship('proveedor', 'nombre', fn($query) => $query->where('estado', true))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('nombre')->required(),
                                        Forms\Components\TextInput::make('rtn')->label('RTN'),
                                        Forms\Components\TextInput::make('telefono')->tel(),
                                        Forms\Components\TextInput::make('email')->email(),
                                        Forms\Components\Textarea::make('direccion')->rows(2),
                                    ]),

                                Forms\Components\Select::make('bodega_id')
                                    ->label('Bodega')
                                    ->options(fn () => self::getBodegasOptions())
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->default(fn () => self::getBodegaDefault())
                                    ->disabled(fn () => !self::esSuperAdminOJefe())
                                    ->dehydrated()
                                    ->helperText(fn () => self::esSuperAdminOJefe()
                                        ? 'Selecciona la bodega donde se registrará esta compra'
                                        : 'Se asignará automáticamente a tu bodega'),

                                Forms\Components\Select::make('tipo_pago')
                                    ->label('Tipo de Pago')
                                    ->required()
                                    ->options([
                                        'contado' => 'Contado',
                                        'credito' => 'Crédito',
                                    ])
                                    ->default('contado')
                                    ->live()
                                    ->native(false),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('interes_porcentaje')
                                    ->label('Interés (%)')
                                    ->numeric()
                                    ->suffix('%')
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->required()
                                    ->helperText('Porcentaje de interés por periodo'),

                                Forms\Components\Select::make('periodo_interes')
                                    ->label('Periodo de Interés')
                                    ->options([
                                        'semanal' => 'Semanal',
                                        'mensual' => 'Mensual',
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->helperText('¿Cada cuánto se cobra el interés?'),

                                Forms\Components\DatePicker::make('fecha_inicio_credito')
                                    ->label('Fecha Inicio Crédito')
                                    ->required()
                                    ->default(now())
                                    ->native(false)
                                    ->helperText('Desde cuándo empezará a correr el interés')
                                    ->maxDate(now()->addDays(30)),
                            ])
                            ->visible(fn(Forms\Get $get) => $get('tipo_pago') === 'credito'),

                        Forms\Components\Placeholder::make('info_credito')
                            ->label('')
                            ->content(function (Forms\Get $get) {
                                $total = (float)($get('total') ?? 0);
                                $interes = (float)($get('interes_porcentaje') ?? 0);
                                $periodo = $get('periodo_interes') ?? 'mensual';

                                if ($total <= 0) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-gray-50 dark:bg-gray-900/20 p-4'>
                                            <p class='text-sm text-gray-600 dark:text-gray-400'>
                                                El cálculo de intereses se mostrará una vez que agregues productos
                                            </p>
                                        </div>
                                    ");
                                }

                                $interesPeriodo = $total * ($interes / 100);
                                $periodoTexto = $periodo === 'semanal' ? 'semana' : 'mes';

                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-yellow-50 dark:bg-yellow-900/20 p-4'>
                                        <p class='text-sm font-semibold text-yellow-900 dark:text-yellow-100 mb-2'>
                                            💰 Cálculo de Intereses:
                                        </p>
                                        <div class='text-sm text-yellow-800 dark:text-yellow-200 space-y-1'>
                                            <p>• Monto del crédito: <strong>L " . number_format($total, 2) . "</strong></p>
                                            <p>• Interés por {$periodoTexto}: <strong>L " . number_format($interesPeriodo, 2) . " ({$interes}%)</strong></p>
                                            <p>• El interés se cobrará cada <strong>{$periodoTexto}</strong> que pase</p>
                                        </div>
                                    </div>
                                ");
                            })
                            ->visible(fn(Forms\Get $get) => $get('tipo_pago') === 'credito')
                            ->columnSpan(3),
                    ]),

                Forms\Components\Section::make('Detalles de Compra')
                    ->schema([
                        Forms\Components\Repeater::make('detalles')
                            ->relationship('detalles')
                            ->schema([
                                // 🎯 FILA 1: Producto y Unidad
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\Select::make('producto_id')
                                            ->label('Producto')
                                            ->options(function () {
                                                // Excluir productos con unidad "1x15" o "Medio" (no se compran, se reempaquetan)
                                                $unidadesExcluidas = Unidad::where('activo', true)
                                                    ->where(function($query) {
                                                        $query->where('nombre', 'like', '%Medio%')
                                                              ->orWhere('nombre', '1x15');
                                                    })
                                                    ->pluck('id')
                                                    ->toArray();

                                                $queryProductos = Producto::where('activo', true)
                                                    ->whereNotIn('unidad_id', $unidadesExcluidas)
                                                    ->where('nombre', 'NOT LIKE', '%opoa%');

                                                if (!self::esSuperAdminOJefe()) {
                                                    $bodegasUsuario = self::getBodegasUsuario();
                                                    if (empty($bodegasUsuario)) {
                                                        return [];
                                                    }
                                                    $queryProductos->whereHas('bodegas', function ($query) use ($bodegasUsuario) {
                                                        $query->whereIn('bodega_producto.bodega_id', $bodegasUsuario)
                                                            ->where('bodega_producto.activo', true);
                                                    });
                                                }

                                                return $queryProductos->orderBy('nombre')
                                                    ->get()
                                                    ->mapWithKeys(fn($producto) => [
                                                        $producto->id => $producto->nombre .
                                                            ($producto->formato_empaque ? " [{$producto->formato_empaque}]" : '') .
                                                            ' - ' . ($producto->sku ?? 'Sin SKU')
                                                    ])
                                                    ->toArray();
                                            })
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                if ($state) {
                                                    $producto = Producto::with(['unidad', 'categoria'])->find($state);
                                                    if ($producto && $producto->unidad) {
                                                        $set('unidad_id', $producto->unidad_id);
                                                    } else {
                                                        $set('unidad_id', null);
                                                    }

                                                    // 🆕 Guardar info de formato de empaque
                                                    $set('_tiene_formato', $producto ? $producto->tieneFormatoEmpaque() : false);
                                                    $set('_unidades_por_bulto', $producto->unidades_por_bulto ?? null);
                                                    $set('_formato_empaque', $producto->formato_empaque ?? null);

                                                    // Verificar si aplica ISV
                                                    $aplicaIsv = $producto && $producto->categoria && $producto->categoria->aplica_isv;
                                                    $set('_aplica_isv', $aplicaIsv);

                                                    // Limpiar campos ISV si no aplica
                                                    if (!$aplicaIsv) {
                                                        $set('precio_con_isv', null);
                                                        $set('costo_sin_isv', null);
                                                        $set('isv_credito', null);
                                                    }

                                                    // Cargar último precio de compra del proveedor
                                                    $proveedorId = $get('../../proveedor_id');
                                                    if ($proveedorId) {
                                                        $ultimoPrecio = DB::table('proveedor_producto')
                                                            ->where('proveedor_id', $proveedorId)
                                                            ->where('producto_id', $state)
                                                            ->value('ultimo_precio_compra');

                                                        if ($ultimoPrecio) {
                                                            $set('precio_unitario', number_format($ultimoPrecio, 2, '.', ''));

                                                            if ($aplicaIsv) {
                                                                $desglose = IsvCalculator::calcularDesglose((float)$ultimoPrecio);
                                                                $set('precio_con_isv', number_format($desglose['precio_con_isv'], 2, '.', ''));
                                                                $set('costo_sin_isv', number_format($desglose['costo_sin_isv'], 2, '.', ''));
                                                                $set('isv_credito', number_format($desglose['isv_credito'], 2, '.', ''));
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    $set('unidad_id', null);
                                                    $set('_aplica_isv', false);
                                                    $set('_tiene_formato', false);
                                                    $set('_unidades_por_bulto', null);
                                                    $set('_formato_empaque', null);
                                                }
                                            })
                                            ->columnSpan(1),

                                        Forms\Components\Select::make('unidad_id')
                                            ->label('Unidad')
                                            ->required()
                                            ->options(Unidad::where('activo', true)->pluck('nombre', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->disabled(fn(Forms\Get $get) => !$get('producto_id'))
                                            ->dehydrated()
                                            ->columnSpan(1),
                                    ]),

                                // 🎯 FILA 2: Cantidades
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('cantidad_facturada')
                                            ->label('Cant. Facturada')
                                            ->required()
                                            ->numeric()
                                            ->minValue(0.001)
                                            ->step(0.01)
                                            ->default(1)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                $facturada = (float)($state ?? 0);
                                                $regalo = (float)($get('cantidad_regalo') ?? 0);
                                                $set('cantidad_recibida', $facturada + $regalo);
                                            })
                                            ->helperText(function (Forms\Get $get) {
                                                $productoId = $get('producto_id');
                                                if (!$productoId) {
                                                    return 'Lo que pagas';
                                                }

                                                $producto = Producto::with('unidad')->find($productoId);
                                                if ($producto && $producto->tieneFormatoEmpaque()) {
                                                    $unidadNombre = $producto->unidad->nombre ?? 'unidades';
                                                    return "En {$unidadNombre} (1 caja = {$producto->unidades_por_bulto})";
                                                }

                                                return 'Lo que pagas';
                                            })
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('cantidad_regalo')
                                            ->label('Cant. Regalo')
                                            ->numeric()
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->default(0)
                                            ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                $facturada = (float)($get('cantidad_facturada') ?? 0);
                                                $regalo = (float)($state ?? 0);
                                                $set('cantidad_recibida', $facturada + $regalo);
                                            })
                                            ->helperText('Regalado por merma')
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('cantidad_recibida')
                                            ->label('Total Recibido')
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated()
                                            ->default(1)
                                            ->suffix('total')
                                            ->extraAttributes(['class' => 'font-bold text-lg'])
                                            ->columnSpan(1),
                                    ]),

                                // 🆕 EQUIVALENCIA EN CAJAS (solo visible si tiene formato)
                                Forms\Components\Placeholder::make('equivalencia_cajas')
                                    ->label('')
                                    ->content(function (Forms\Get $get) {
                                        $productoId = $get('producto_id');
                                        $cantidadFacturada = (float) ($get('cantidad_facturada') ?? 0);
                                        $cantidadRegalo = (float) ($get('cantidad_regalo') ?? 0);
                                        $cantidadTotal = $cantidadFacturada + $cantidadRegalo;

                                        if (!$productoId || $cantidadTotal <= 0) {
                                            return '';
                                        }

                                        $producto = Producto::with('unidad')->find($productoId);

                                        if (!$producto || !$producto->tieneFormatoEmpaque()) {
                                            return '';
                                        }

                                        $equivalencia = $producto->calcularEquivalenciaBultos($cantidadTotal);
                                        $unidadNombre = $producto->unidad->nombre ?? 'unidades';

                                        $esExacto = $equivalencia['sueltos'] == 0;
                                        $colorClass = $esExacto ? 'green' : 'blue';
                                        $icono = $esExacto ? '✅' : '📦';

                                        return new \Illuminate\Support\HtmlString("
                                            <div class='rounded-lg bg-{$colorClass}-50 dark:bg-{$colorClass}-900/20 p-3'>
                                                <div class='flex items-center justify-between'>
                                                    <div>
                                                        <p class='text-sm font-semibold text-{$colorClass}-900 dark:text-{$colorClass}-100'>
                                                            {$icono} Equivalencia: <strong>{$equivalencia['texto']}</strong>
                                                        </p>
                                                        <p class='text-xs text-{$colorClass}-700 dark:text-{$colorClass}-300 mt-1'>
                                                            Formato: {$producto->formato_empaque} → 1 caja = {$producto->unidades_por_bulto} {$unidadNombre}
                                                        </p>
                                                    </div>
                                                    <div class='text-right'>
                                                    <p class='text-lg font-bold text-{$colorClass}-600 dark:text-{$colorClass}-400'>
                                                        " . number_format($cantidadTotal, 2) . " {$unidadNombre}
                                                     </p>
                                                    </div>
                                                </div>
                                            </div>
                                        ");
                                    })
                                    ->columnSpanFull()
                                    ->visible(function (Forms\Get $get) {
                                        $productoId = $get('producto_id');
                                        if (!$productoId) return false;

                                        $producto = Producto::find($productoId);
                                        return $producto && $producto->tieneFormatoEmpaque();
                                    }),

                                // 🎯 FILA 3: Precios
                                Forms\Components\Grid::make(4)
                                    ->schema([
                                        Forms\Components\TextInput::make('precio_unitario')
                                            ->label('Precio Unitario')
                                            ->required()
                                            ->numeric()
                                            ->prefix('L')
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->default(0)
                                            ->formatStateUsing(fn($state) => number_format((float)$state, 2, '.', ''))
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                $productoId = $get('producto_id');
                                                if ($productoId && IsvCalculator::productoAplicaIsv((int)$productoId)) {
                                                    $precio = (float)($state ?? 0);
                                                    if ($precio > 0) {
                                                        $desglose = IsvCalculator::calcularDesglose($precio);
                                                        $set('precio_con_isv', number_format($desglose['precio_con_isv'], 2, '.', ''));
                                                        $set('costo_sin_isv', number_format($desglose['costo_sin_isv'], 2, '.', ''));
                                                        $set('isv_credito', number_format($desglose['isv_credito'], 2, '.', ''));
                                                    }
                                                }
                                            })
                                            ->helperText(function (Forms\Get $get) {
                                                $productoId = $get('producto_id');
                                                if ($productoId && IsvCalculator::productoAplicaIsv((int)$productoId)) {
                                                    return 'Precio con ISV incluido';
                                                }
                                                return 'Por unidad facturada';
                                            })
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('descuento')
                                            ->label('Descuento')
                                            ->numeric()
                                            ->prefix('L')
                                            ->default(0)
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->formatStateUsing(fn($state) => number_format((float)$state, 2, '.', ''))
                                            ->live(onBlur: true)
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('impuesto')
                                            ->label('Impuesto (ISV)')
                                            ->numeric()
                                            ->prefix('L')
                                            ->default(0)
                                            ->minValue(0)
                                            ->step(0.01)
                                            ->formatStateUsing(fn($state) => number_format((float)$state, 2, '.', ''))
                                            ->live(onBlur: true)
                                            ->columnSpan(1),

                                        Forms\Components\Placeholder::make('subtotal_display')
                                            ->label('Subtotal')
                                            ->content(function (Forms\Get $get) {
                                                $cantidadFacturada = (float)($get('cantidad_facturada') ?? 1);
                                                $precio = (float)($get('precio_unitario') ?? 0);
                                                $descuento = (float)($get('descuento') ?? 0);
                                                $impuesto = (float)($get('impuesto') ?? 0);

                                                $subtotal = ($cantidadFacturada * $precio) - $descuento + $impuesto;
                                                return new \Illuminate\Support\HtmlString(
                                                    '<div class="text-lg font-bold text-primary-600">L ' .
                                                        number_format($subtotal, 2) .
                                                        '</div>'
                                                );
                                            })
                                            ->columnSpan(1),
                                    ]),

                                // FILA 4: Desglose ISV (solo visible si aplica)
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('precio_con_isv')
                                            ->label('Precio con ISV')
                                            ->numeric()
                                            ->prefix('L')
                                            ->disabled()
                                            ->dehydrated()
                                            ->helperText('Lo que pagaste')
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('costo_sin_isv')
                                            ->label('Costo Real (sin ISV)')
                                            ->numeric()
                                            ->prefix('L')
                                            ->disabled()
                                            ->dehydrated()
                                            ->helperText('Precio ÷ 1.15')
                                            ->extraAttributes(['class' => 'font-bold text-green-600'])
                                            ->columnSpan(1),

                                        Forms\Components\TextInput::make('isv_credito')
                                            ->label('ISV Crédito Fiscal')
                                            ->numeric()
                                            ->prefix('L')
                                            ->disabled()
                                            ->dehydrated()
                                            ->helperText('15% recuperable')
                                            ->extraAttributes(['class' => 'text-blue-600'])
                                            ->columnSpan(1),
                                    ])
                                    ->visible(function (Forms\Get $get) {
                                        $productoId = $get('producto_id');
                                        return $productoId && IsvCalculator::productoAplicaIsv((int)$productoId);
                                    }),

                                // Indicador visual de ISV
                                Forms\Components\Placeholder::make('isv_indicator')
                                    ->label('')
                                    ->content(function (Forms\Get $get) {
                                        $productoId = $get('producto_id');
                                        if (!$productoId) {
                                            return '';
                                        }

                                        if (IsvCalculator::productoAplicaIsv((int)$productoId)) {
                                            return new \Illuminate\Support\HtmlString("
                                                <div class='rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3 mt-2'>
                                                    <p class='text-sm text-blue-800 dark:text-blue-200'>
                                                        💰 <strong>Este producto incluye ISV (15%)</strong> - El costo real y el crédito fiscal se calculan automáticamente.
                                                    </p>
                                                </div>
                                            ");
                                        }

                                        return '';
                                    })
                                    ->columnSpanFull(),

                                // Campos ocultos para tracking
                                Forms\Components\Hidden::make('_aplica_isv')
                                    ->default(false)
                                    ->dehydrated(false),

                                Forms\Components\Hidden::make('_tiene_formato')
                                    ->default(false)
                                    ->dehydrated(false),

                                Forms\Components\Hidden::make('_unidades_por_bulto')
                                    ->default(null)
                                    ->dehydrated(false),

                                Forms\Components\Hidden::make('_formato_empaque')
                                    ->default(null)
                                    ->dehydrated(false),
                            ])
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->collapsible()
                            ->collapsed(false)
                            ->itemLabel(
                                fn(array $state): ?string =>
                                $state['producto_id']
                                    ? Producto::find($state['producto_id'])?->getNombreConFormato()
                                    : 'Nuevo producto'
                            )
                            ->addActionLabel('+ Agregar producto')
                            ->live()
                            ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                self::calcularTotal($get, $set);
                            })
                            ->deleteAction(
                                fn(Forms\Components\Actions\Action $action) => $action->after(function (Forms\Get $get, Forms\Set $set) {
                                    self::calcularTotal($get, $set);
                                })
                            ),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('Total')
                    ->schema([
                        Forms\Components\TextInput::make('total')
                            ->label('Total')
                            ->numeric()
                            ->prefix('L')
                            ->disabled()
                            ->dehydrated()
                            ->default(0)
                            ->extraAttributes(['class' => 'text-xl font-bold']),
                    ]),
            ]);
    }

    protected static function calcularTotal(Forms\Get $get, Forms\Set $set): void
    {
        $detalles = $get('detalles') ?? [];

        $total = collect($detalles)->reduce(function ($carry, $item) {
            $cantidadFacturada = (float)($item['cantidad_facturada'] ?? 1);
            $precio = (float)($item['precio_unitario'] ?? 0);
            $descuento = (float)($item['descuento'] ?? 0);
            $impuesto = (float)($item['impuesto'] ?? 0);

            $subtotal = ($cantidadFacturada * $precio) - $descuento + $impuesto;
            return $carry + $subtotal;
        }, 0);

        $set('total', number_format($total, 2, '.', ''));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_compra')
                    ->label('No. Compra')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_pago')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                        default => $state,
                    })
                    ->color(fn($state) => match ($state) {
                        'contado' => 'success',
                        'credito' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('saldo_con_intereses')
                    ->label('Saldo Total')
                    ->money('HNL')
                    ->sortable(false)
                    ->getStateUsing(fn($record) => $record ? $record->getSaldoConIntereses() : 0)
                    ->description(function ($record) {
                        if (!$record || $record->tipo_pago !== 'credito') {
                            return null;
                        }

                        $info = $record->getInfoCredito();
                        if (empty($info)) {
                            return null;
                        }

                        $periodos = $info['periodos_transcurridos'];
                        $interes = $info['interes_acumulado'];

                        if ($periodos === 0) {
                            return 'Sin intereses acumulados';
                        }

                        return "Interés: L" . number_format($interes, 2) . " ({$periodos} " .
                            ($record->periodo_interes === 'semanal' ? 'semanas' : 'meses') . ")";
                    })
                    ->toggleable()
                    ->visible(fn($record) => $record && $record->tipo_pago === 'credito'),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        CompraEstado::Borrador => 'Borrador',
                        CompraEstado::Ordenada => 'Ordenada',
                        CompraEstado::RecibidaPagada => 'Recibida y Pagada',
                        CompraEstado::RecibidaPendientePago => 'Recibida - Debo Pagar',
                        CompraEstado::PorRecibirPagada => 'Pagada - Falta Recibir',
                        CompraEstado::PorRecibirPendientePago => 'Pendiente Todo',
                        CompraEstado::Cancelada => 'Cancelada',
                        default => $state instanceof CompraEstado ? $state->label() : $state,
                    })
                    ->color(fn($state) => match ($state) {
                        CompraEstado::Borrador => 'gray',
                        CompraEstado::Ordenada => 'info',
                        CompraEstado::RecibidaPagada => 'success',
                        CompraEstado::RecibidaPendientePago => 'warning',
                        CompraEstado::PorRecibirPagada => 'info',
                        CompraEstado::PorRecibirPendientePago => 'danger',
                        CompraEstado::Cancelada => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('creador.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options(CompraEstado::options()),

                Tables\Filters\SelectFilter::make('tipo_pago')
                    ->label('Tipo de Pago')
                    ->options([
                        'contado' => 'Contado',
                        'credito' => 'Crédito',
                    ]),

                Tables\Filters\SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('desde')
                            ->label('Desde')
                            ->native(false),
                        Forms\Components\DatePicker::make('hasta')
                            ->label('Hasta')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['hasta'], fn($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),

                Tables\Filters\Filter::make('pendiente_pago')
                    ->label('Pendiente de Pago')
                    ->query(fn($query) => $query->whereIn('estado', CompraEstado::conDeudaPendiente())),

                Tables\Filters\Filter::make('pendiente_recibir')
                    ->label('Pendiente de Recibir')
                    ->query(fn($query) => $query->whereIn('estado', [
                        CompraEstado::Ordenada,
                        CompraEstado::PorRecibirPagada,
                        CompraEstado::PorRecibirPendientePago,
                    ])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\EditAction::make()
                    ->visible(fn($record) => $record->estado === CompraEstado::Borrador),

                Tables\Actions\Action::make('cambiar_estado')
                    ->label('Cambiar Estado')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->form(fn($record) => [
                        Forms\Components\Select::make('estado')
                            ->label('Nuevo Estado')
                            ->options(CompraStateManager::opcionesTransicion($record->estado))
                            ->required()
                            ->native(false),
                    ])
                    ->action(function ($record, array $data) {
                        try {
                            $nuevoEstado = CompraEstado::from($data['estado']);

                            \Illuminate\Support\Facades\DB::transaction(function () use ($record, $nuevoEstado) {
                                $record->refresh();
                                $record = Compra::where('id', $record->id)->lockForUpdate()->first();

                                // Si va a Cancelada y tiene inventario, revertir
                                if ($nuevoEstado === CompraEstado::Cancelada
                                    && CompraStateManager::tieneInventarioProcesado($record->estado)) {
                                    CompraStateManager::revertirInventario($record);
                                }

                                CompraStateManager::transicionar($record, $nuevoEstado);
                                $record->update(['updated_by' => Auth::id()]);
                            });

                            \Filament\Notifications\Notification::make()
                                ->title('Estado actualizado')
                                ->body("Nuevo estado: {$nuevoEstado->label()}")
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('No se puede completar')
                                ->body($e->getMessage())
                                ->danger()
                                ->duration(10000)
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Transición no permitida')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(function ($record) {
                        if (CompraStateManager::esEstadoFinal($record->estado)) {
                            return false;
                        }
                        if ($record->estado === CompraEstado::Borrador) {
                            return false;
                        }
                        return self::esSuperAdminOJefe();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn($record) => $record->estado === CompraEstado::Borrador),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function ($records) {
                            $records->each(function ($record) {
                                if ($record->estado === CompraEstado::Borrador) {
                                    $record->delete();
                                }
                            });
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompras::route('/'),
            'create' => Pages\CreateCompra::route('/create'),
            'view' => Pages\ViewCompra::route('/{record}'),
            'edit' => Pages\EditCompra::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('estado', CompraEstado::Borrador)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}