<?php

namespace App\Filament\Resources;

use App\Models\Producto;
use App\Models\Bodega;
use App\Models\BodegaProducto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use App\Filament\Resources\ProductoResource\Pages;
use App\Filament\Resources\ProductoResource\RelationManagers\ProductoImagenRelationManager;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Inventario';
    protected static ?int    $navigationSort  = 1;

    protected static ?string $modelLabel       = 'Producto';
    protected static ?string $pluralModelLabel = 'Productos';
    protected static ?string $navigationLabel  = 'Productos';

    /* -----------------------------------------------------------------
     |  ELOQUENT QUERY
     |----------------------------------------------------------------- */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        $esSuperAdmin = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->where('roles.name', 'super_admin')
            ->exists();

        if ($esSuperAdmin) {
            return $query;
        }

        $esJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->where('roles.name', 'jefe')
            ->exists();

        if ($esJefe) {
            $usuariosCreados = DB::table('users')
                ->where('created_by', $currentUser->id)
                ->pluck('id')
                ->toArray();

            return $query->where(function ($q) use ($currentUser, $usuariosCreados) {
                $q->where('created_by', $currentUser->id)
                    ->orWhereIn('created_by', $usuariosCreados);
            });
        }

        $jefeId = $currentUser->created_by;

        return $query->where(function ($q) use ($currentUser, $jefeId) {
            $q->where('created_by', $currentUser->id);

            if ($jefeId) {
                $q->orWhere('created_by', $jefeId);
            }
        });
    }

    /* -----------------------------------------------------------------
     |  HELPER: Verificar si usuario puede editar stock
     |----------------------------------------------------------------- */
    protected static function usuarioPuedeEditarStock(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['super_admin', 'jefe'])
            ->exists();
    }

    /* -----------------------------------------------------------------
     |  FORM
     |----------------------------------------------------------------- */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Información del Producto')
                ->schema([
                    Forms\Components\Select::make('categoria_id')
                        ->label('Categoría')
                        ->relationship('categoria', 'nombre', fn(Builder $query) => $query->where('activo', true))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            $set('unidad_id', null);
                            self::generateNombre($set, $get);
                        }),

                    Forms\Components\Select::make('unidad_id')
                        ->label('Unidad de medida')
                        ->options(function (Get $get) {
                            $categoriaId = $get('categoria_id');

                            if (!$categoriaId) {
                                return [];
                            }

                            $categoria = \App\Models\Categoria::find($categoriaId);

                            if (!$categoria) {
                                return [];
                            }

                            return $categoria->unidades()
                                ->wherePivot('activo', true)
                                ->where('unidades.activo', true)
                                ->pluck('unidades.nombre', 'unidades.id')
                                ->toArray();
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->disabled(fn(Get $get) => !$get('categoria_id'))
                        ->helperText(
                            fn(Get $get) => !$get('categoria_id')
                                ? 'Selecciona una categoría primero'
                                : null
                        )
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            self::generateNombre($set, $get);
                        }),

                    Forms\Components\TextInput::make('nombre')
                        ->label('Nombre del Producto')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Se genera automáticamente, pero puedes cambiarlo libremente (ej: Caja de Aceite x12, Bolsa de Arroz 5lb)')
                        ->placeholder('Ej: Huevo Grande Cartón, Caja de Leche x24'),

                    Forms\Components\TextInput::make('precio_sugerido')
                        ->label('Precio Base de Referencia')
                        ->prefix('L')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(999999)
                        ->default(0)
                        ->required()
                        ->helperText('Precio de referencia inicial (opcional). El precio real se calcula automáticamente.'),

                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Se genera automáticamente al crear el producto')
                        ->visible(fn($livewire) => $livewire instanceof Pages\EditProducto),

                    Forms\Components\Textarea::make('descripcion')
                        ->label('Descripción')
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('activo')
                        ->label('Activo')
                        ->default(true),
                ])
                ->columns(2),

            // 🎯 SECCIÓN: Configuración de Margen de Ganancia e ISV
            Forms\Components\Section::make('Configuración de Precio de Venta')
                ->description('El precio de venta se calcula automáticamente: Costo Promedio Actual + Margen + ISV (si aplica)')
                ->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Forms\Components\Select::make('tipo_margen')
                                ->label('Tipo de Margen')
                                ->options([
                                    'monto' => 'Monto Fijo (L)',
                                    'porcentaje' => 'Porcentaje (%)',
                                ])
                                ->required()
                                ->default('monto')
                                ->live()
                                ->native(false)
                                ->helperText('¿Cómo quieres calcular tu ganancia?'),

                            Forms\Components\TextInput::make('margen_ganancia')
                                ->label(function (Get $get) {
                                    $tipo = $get('tipo_margen') ?? 'monto';
                                    return $tipo === 'porcentaje'
                                        ? 'Margen de Ganancia (%)'
                                        : 'Ganancia por Unidad (L)';
                                })
                                ->prefix(function (Get $get) {
                                    $tipo = $get('tipo_margen') ?? 'monto';
                                    return $tipo === 'monto' ? 'L' : null;
                                })
                                ->suffix(function (Get $get) {
                                    $tipo = $get('tipo_margen') ?? 'monto';
                                    return $tipo === 'porcentaje' ? '%' : null;
                                })
                                ->numeric()
                                ->required()
                                ->default(5)
                                ->minValue(0)
                                ->maxValue(function (Get $get) {
                                    $tipo = $get('tipo_margen') ?? 'monto';
                                    return $tipo === 'porcentaje' ? 500 : 999999;
                                })
                                ->step(0.01)
                                ->live(onBlur: true)
                                ->helperText(function (Get $get) {
                                    $tipo = $get('tipo_margen') ?? 'monto';
                                    if ($tipo === 'porcentaje') {
                                        return 'Ejemplo: 25% → Si el costo es L100, vendes a L125';
                                    }
                                    return 'Ejemplo: L5 → Si el costo es L90, vendes a L95';
                                }),

                            // 🆕 TOGGLE DE ISV
                            Forms\Components\Toggle::make('aplica_isv')
                                ->label('Aplica ISV (15%)')
                                ->default(true)
                                ->live()
                                ->onColor('success')
                                ->offColor('gray')
                                ->helperText('Impuesto Sobre Ventas de Honduras'),
                        ]),

                    Forms\Components\Placeholder::make('ejemplo_calculo')
                        ->label('Simulación de Precio')
                        ->content(function (Get $get) {
                            $costoEjemplo = 100;
                            $margen = $get('margen_ganancia') ?? 5;
                            $tipo = $get('tipo_margen') ?? 'monto';
                            $aplicaIsv = $get('aplica_isv') ?? true;
                            $tasaIsv = 0.15;

                            // Calcular precio base (sin ISV)
                            if ($tipo === 'porcentaje') {
                                $precioBase = $costoEjemplo * (1 + ($margen / 100));
                            } else {
                                $precioBase = $costoEjemplo + $margen;
                            }

                            $precioBase = ceil($precioBase);
                            $gananciaBase = $precioBase - $costoEjemplo;

                            // Calcular ISV si aplica
                            $montoIsv = $aplicaIsv ? ceil($precioBase * $tasaIsv) : 0;
                            $precioFinal = $precioBase + $montoIsv;

                            $porcentajeGanancia = $costoEjemplo > 0 ? (($gananciaBase / $costoEjemplo) * 100) : 0;

                            $colorClass = $tipo === 'porcentaje' ? 'blue' : 'green';
                            $isvBadge = $aplicaIsv
                                ? "<span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'>+15% ISV</span>"
                                : "<span class='inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'>Sin ISV</span>";

                            return new \Illuminate\Support\HtmlString("
                                <div class='rounded-lg bg-{$colorClass}-50 dark:bg-{$colorClass}-900/20 p-4'>
                                    <div class='flex items-center justify-between mb-3'>
                                        <p class='text-sm font-semibold text-{$colorClass}-900 dark:text-{$colorClass}-100'>
                                            💰 Simulación de Precio:
                                        </p>
                                        {$isvBadge}
                                    </div>
                                    <div class='text-sm text-{$colorClass}-800 dark:text-{$colorClass}-200 space-y-2'>
                                        <div class='flex justify-between border-b border-{$colorClass}-200 dark:border-{$colorClass}-700 pb-2'>
                                            <span>Costo base:</span>
                                            <strong>L " . number_format($costoEjemplo, 0) . "</strong>
                                        </div>
                                        <div class='flex justify-between'>
                                            <span>+ Margen (" . ($tipo === 'porcentaje' ? "{$margen}%" : "L{$margen}") . "):</span>
                                            <strong class='text-green-600 dark:text-green-400'>+L " . number_format($gananciaBase, 0) . "</strong>
                                        </div>
                                        <div class='flex justify-between border-b border-{$colorClass}-200 dark:border-{$colorClass}-700 pb-2'>
                                            <span>= Precio sin ISV:</span>
                                            <strong>L " . number_format($precioBase, 0) . "</strong>
                                        </div>
                                        " . ($aplicaIsv ? "
                                        <div class='flex justify-between'>
                                            <span>+ ISV (15%):</span>
                                            <strong class='text-amber-600 dark:text-amber-400'>+L " . number_format($montoIsv, 0) . "</strong>
                                        </div>" : "") . "
                                        <div class='flex justify-between pt-2 border-t-2 border-{$colorClass}-300 dark:border-{$colorClass}-600'>
                                            <span class='font-bold'>💵 PRECIO FINAL:</span>
                                            <strong class='text-lg text-green-600 dark:text-green-400'>L " . number_format($precioFinal, 0) . "</strong>
                                        </div>
                                        <div class='text-xs text-{$colorClass}-600 dark:text-{$colorClass}-400 mt-2'>
                                            Ganancia neta: L" . number_format($gananciaBase, 0) . " (" . number_format($porcentajeGanancia, 1) . "% sobre costo)
                                        </div>
                                    </div>
                                </div>
                            ");
                        })
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('info_importante')
                        ->label('')
                        ->content(new \Illuminate\Support\HtmlString("
                            <div class='rounded-lg bg-yellow-50 dark:bg-yellow-900/20 p-4'>
                                <p class='text-sm font-semibold text-yellow-900 dark:text-yellow-100 mb-2'>
                                    ⚠️ Importante:
                                </p>
                                <ul class='text-sm text-yellow-800 dark:text-yellow-200 space-y-1 list-disc list-inside'>
                                    <li>El precio de venta se <strong>actualiza automáticamente</strong> cada vez que entra stock (compra o reempaque)</li>
                                    <li>El sistema usa <strong>Costo Promedio Ponderado</strong> para calcular el costo real</li>
                                    <li>Los precios se <strong>redondean hacia arriba</strong> para evitar pérdidas</li>
                                    <li>El <strong>ISV (15%)</strong> se calcula sobre el precio de venta y se muestra por separado</li>
                                    <li>Así <strong>nunca venderás a pérdida</strong> 🎯</li>
                                </ul>
                            </div>
                        "))
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsible(),

            // Sección de Bodega (visible solo al crear)
            Forms\Components\Section::make('Asignación de Bodega')
                ->schema([
                    Forms\Components\Select::make('bodega_id')
                        ->label('Bodega')
                        ->options(function () {
                            $currentUser = Auth::user();

                            if (!$currentUser) {
                                return [];
                            }

                            $esSuperAdminOJefe = DB::table('model_has_roles')
                                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                ->where('model_has_roles.model_id', '=', $currentUser->id)
                                ->whereIn('roles.name', ['super_admin', 'jefe'])
                                ->exists();

                            if ($esSuperAdminOJefe) {
                                return Bodega::where('activo', true)
                                    ->pluck('nombre', 'id')
                                    ->toArray();
                            }

                            return DB::table('bodega_user')
                                ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                ->where('bodega_user.user_id', $currentUser->id)
                                ->where('bodegas.activo', true)
                                ->pluck('bodegas.nombre', 'bodegas.id')
                                ->toArray();
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->default(function () {
                            return self::getBodegaDefaultParaUsuario();
                        })
                        ->disabled(fn() => !self::usuarioPuedeSeleccionarBodega())
                        ->dehydrated(false)
                        ->helperText(function () {
                            return self::getHelperTextBodega();
                        }),

                    Forms\Components\TextInput::make('stock_minimo')
                        ->label('Stock mínimo')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(999999.99)
                        ->step(0.01)
                        ->inputMode('decimal')
                        ->default(0)
                        ->required()
                        ->dehydrated(true)
                        ->suffix('unidades')
                        ->helperText('Cantidad mínima recomendada en inventario'),
                ])
                ->columns(2)
                ->visible(fn($livewire) => $livewire instanceof Pages\CreateProducto),

            // 🎯 SECCIÓN: Gestión por Bodega (con stock editable para jefe/super_admin)
            Forms\Components\Section::make('Gestión por Bodega')
                ->description('Información en tiempo real de cada bodega')
                ->schema([
                    Forms\Components\Grid::make()
                        ->schema(function ($record) {
                            if (!$record) {
                                return [];
                            }

                            $fields = [];
                            $puedeEditarStock = self::usuarioPuedeEditarStock();

                            $bodegasProducto = BodegaProducto::where('producto_id', $record->id)
                                ->with('bodega')
                                ->get();

                            foreach ($bodegasProducto as $bodegaProducto) {
                                $bodega = $bodegaProducto->bodega;

                                $fields[] = Forms\Components\Fieldset::make($bodega ? $bodega->nombre : 'Sin bodega')
                                    ->schema([
                                        Forms\Components\Grid::make(5)
                                            ->schema([
                                                // 🎯 STOCK ACTUAL - Editable para jefe/super_admin
                                                Forms\Components\TextInput::make("stock_actual_{$bodegaProducto->id}")
                                                    ->label('Stock Actual')
                                                    ->default(number_format($bodegaProducto->stock, 2, '.', ''))
                                                    ->disabled(!$puedeEditarStock)
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->dehydrated(true)
                                                    ->suffix($record->unidad->nombre ?? 'unidades')
                                                    ->helperText($puedeEditarStock ? '⚠️ Ajuste manual' : null)
                                                    ->extraAttributes($puedeEditarStock ? ['class' => 'border-orange-300'] : []),

                                                Forms\Components\TextInput::make("stock_minimo_{$bodegaProducto->id}")
                                                    ->label('Stock Mínimo')
                                                    ->default(number_format($bodegaProducto->stock_minimo, 2, '.', ''))
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->required()
                                                    ->suffix($record->unidad->nombre ?? 'unidades'),

                                                Forms\Components\TextInput::make("costo_promedio_{$bodegaProducto->id}")
                                                    ->label('Costo Promedio')
                                                    ->prefix('L')
                                                    ->default(number_format($bodegaProducto->costo_promedio_actual ?? 0, 0))
                                                    ->disabled()
                                                    ->dehydrated(false)
                                                    ->helperText('Automático')
                                                    ->extraAttributes(['class' => 'font-bold']),

                                                Forms\Components\TextInput::make("precio_venta_{$bodegaProducto->id}")
                                                    ->label('Precio Venta')
                                                    ->prefix('L')
                                                    ->default(number_format($bodegaProducto->precio_venta_sugerido ?? 0, 0))
                                                    ->disabled()
                                                    ->dehydrated(false)
                                                    ->extraAttributes(['class' => 'font-bold text-green-600'])
                                                    ->helperText('Costo + Margen'),

                                                // 🆕 PRECIO CON ISV
                                                Forms\Components\TextInput::make("precio_isv_{$bodegaProducto->id}")
                                                    ->label('Precio + ISV')
                                                    ->prefix('L')
                                                    ->default(function () use ($bodegaProducto, $record) {
                                                        $precioBase = $bodegaProducto->precio_venta_sugerido ?? 0;
                                                        $aplicaIsv = $record->aplica_isv ?? true;

                                                        if ($aplicaIsv && $precioBase > 0) {
                                                            return number_format(ceil($precioBase * 1.15), 0);
                                                        }
                                                        return number_format($precioBase, 0);
                                                    })
                                                    ->disabled()
                                                    ->dehydrated(false)
                                                    ->extraAttributes(['class' => 'font-bold text-amber-600'])
                                                    ->helperText(fn() => ($record->aplica_isv ?? true) ? '+15% ISV' : 'Sin ISV'),
                                            ]),
                                    ]);
                            }

                            if (empty($fields)) {
                                $fields[] = Forms\Components\Placeholder::make('sin_bodegas')
                                    ->label('')
                                    ->content('Este producto no está asignado a ninguna bodega.');
                            }

                            return $fields;
                        })
                        ->columns(1),
                ])
                ->visible(fn($livewire) => $livewire instanceof Pages\EditProducto),
        ]);
    }

    /* -----------------------------------------------------------------
     |  HELPER METHODS
     |----------------------------------------------------------------- */
    protected static function generateNombre(Forms\Set $set, Forms\Get $get): void
    {
        $categoriaId = $get('categoria_id');
        $unidadId = $get('unidad_id');
        $nombreActual = $get('nombre');

        // Solo generar automáticamente si el nombre está vacío
        // Esto permite que el usuario lo cambie libremente
        if (!empty($nombreActual)) {
            return;
        }

        if (!$categoriaId || !$unidadId) {
            return;
        }

        $categoria = \App\Models\Categoria::find($categoriaId);
        $unidad = \App\Models\Unidad::find($unidadId);

        if ($categoria && $unidad) {
            $nombre = $categoria->nombre . ' ' . $unidad->nombre;
            $set('nombre', $nombre);
        }
    }

    protected static function usuarioPuedeSeleccionarBodega(): bool
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['super_admin', 'jefe'])
            ->exists();
    }

    protected static function getBodegaDefaultParaUsuario(): ?int
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return null;
        }

        if (self::usuarioPuedeSeleccionarBodega()) {
            return null;
        }

        return DB::table('bodega_user')
            ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
            ->where('bodega_user.user_id', $currentUser->id)
            ->where('bodegas.activo', true)
            ->value('bodegas.id');
    }

    protected static function getHelperTextBodega(): string
    {
        $currentUser = Auth::user();

        if (!$currentUser) {
            return '';
        }

        if (self::usuarioPuedeSeleccionarBodega()) {
            return 'Selecciona la bodega donde se guardará este producto';
        }

        $bodegas = DB::table('bodega_user')
            ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
            ->where('bodega_user.user_id', $currentUser->id)
            ->where('bodegas.activo', true)
            ->select('bodegas.id', 'bodegas.nombre')
            ->get();

        if ($bodegas->isEmpty()) {
            return 'No tienes bodegas asignadas. Contacta al administrador.';
        }

        if ($bodegas->count() === 1) {
            return "Se asignará automáticamente a tu bodega: {$bodegas->first()->nombre}";
        }

        return "Se asignará a tu bodega principal: {$bodegas->first()->nombre}";
    }

    /* -----------------------------------------------------------------
     |  TABLE
     |----------------------------------------------------------------- */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('imagenes.path')
                    ->label('Imagen')
                    ->circular()
                    ->defaultImageUrl(url('/images/placeholder-product.png'))
                    ->getStateUsing(function ($record) {
                        return $record->imagenes()
                            ->where('activo', true)
                            ->orderBy('orden')
                            ->first()
                            ?->path;
                    }),

                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('categoria.nombre')
                    ->label('Categoría')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('unidad.nombre')
                    ->label('Unidad')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('stock_actual')
                    ->label('Stock Actual')
                    ->getStateUsing(function ($record) {
                        $currentUser = Auth::user();

                        if (!$currentUser) {
                            return 0;
                        }

                        $esSuperAdminOJefe = DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['super_admin', 'jefe'])
                            ->exists();

                        if ($esSuperAdminOJefe) {
                            return DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->sum('stock');
                        }

                        return DB::table('bodega_producto')
                            ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                            ->where('bodega_producto.producto_id', $record->id)
                            ->where('bodega_user.user_id', $currentUser->id)
                            ->sum('bodega_producto.stock');
                    })
                    ->formatStateUsing(fn($state) => number_format($state, 2, '.', ','))
                    ->badge()
                    ->color(fn($state) => match (true) {
                        $state == 0 => 'danger',
                        $state < 10 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_minimo')
                    ->label('Stock Mínimo')
                    ->getStateUsing(function ($record) {
                        $currentUser = Auth::user();

                        if (!$currentUser) {
                            return 0;
                        }

                        $esSuperAdminOJefe = DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['super_admin', 'jefe'])
                            ->exists();

                        if ($esSuperAdminOJefe) {
                            return DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->max('stock_minimo');
                        }

                        return DB::table('bodega_producto')
                            ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                            ->where('bodega_producto.producto_id', $record->id)
                            ->where('bodega_user.user_id', $currentUser->id)
                            ->max('bodega_producto.stock_minimo');
                    })
                    ->numeric()
                    ->sortable(),

                // 🎯 NUEVA COLUMNA: Costo Promedio
                Tables\Columns\TextColumn::make('costo_promedio')
                    ->label('Costo Promedio')
                    ->money('HNL', divideBy: 1)
                    ->sortable(false)
                    ->getStateUsing(function ($record) {
                        $currentUser = Auth::user();

                        if (!$currentUser) {
                            return 0;
                        }

                        $esSuperAdminOJefe = DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['super_admin', 'jefe'])
                            ->exists();

                        if ($esSuperAdminOJefe) {
                            $promedio = DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->where('costo_promedio_actual', '>', 0)
                                ->avg('costo_promedio_actual');

                            return $promedio ?? $record->precio_sugerido ?? 0;
                        }

                        $promedio = DB::table('bodega_producto')
                            ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                            ->where('bodega_producto.producto_id', $record->id)
                            ->where('bodega_user.user_id', $currentUser->id)
                            ->where('bodega_producto.costo_promedio_actual', '>', 0)
                            ->avg('bodega_producto.costo_promedio_actual');

                        return $promedio ?? $record->precio_sugerido ?? 0;
                    })
                    ->description('Promedio ponderado'),

                // 🎯 COLUMNA: Precio Venta (sin ISV)
                Tables\Columns\TextColumn::make('precio_venta')
                    ->label('Precio Venta')
                    ->money('HNL', divideBy: 1)
                    ->sortable(false)
                    ->weight('bold')
                    ->color('success')
                    ->getStateUsing(function ($record) {
                        $currentUser = Auth::user();

                        if (!$currentUser) {
                            return 0;
                        }

                        $esSuperAdminOJefe = DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['super_admin', 'jefe'])
                            ->exists();

                        if ($esSuperAdminOJefe) {
                            $promedio = DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->where('precio_venta_sugerido', '>', 0)
                                ->avg('precio_venta_sugerido');

                            return $promedio ?? 0;
                        }

                        $promedio = DB::table('bodega_producto')
                            ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                            ->where('bodega_producto.producto_id', $record->id)
                            ->where('bodega_user.user_id', $currentUser->id)
                            ->where('bodega_producto.precio_venta_sugerido', '>', 0)
                            ->avg('bodega_producto.precio_venta_sugerido');

                        return $promedio ?? 0;
                    })
                    ->description(function ($record) {
                        $margen = $record->margen_ganancia ?? 0;
                        $tipo = $record->tipo_margen ?? 'monto';

                        if ($tipo === 'porcentaje') {
                            return "Margen: {$margen}%";
                        }

                        return "Margen: +L{$margen}";
                    }),

                // 🆕 COLUMNA: Precio + ISV (precio final al cliente)
                Tables\Columns\TextColumn::make('precio_con_isv')
                    ->label('Precio + ISV')
                    ->money('HNL', divideBy: 1)
                    ->sortable(false)
                    ->weight('bold')
                    ->color(fn($record) => ($record->aplica_isv ?? true) ? 'warning' : 'gray')
                    ->getStateUsing(function ($record) {
                        $currentUser = Auth::user();

                        if (!$currentUser) {
                            return 0;
                        }

                        $esSuperAdminOJefe = DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['super_admin', 'jefe'])
                            ->exists();

                        if ($esSuperAdminOJefe) {
                            $precioBase = DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->where('precio_venta_sugerido', '>', 0)
                                ->avg('precio_venta_sugerido') ?? 0;
                        } else {
                            $precioBase = DB::table('bodega_producto')
                                ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                                ->where('bodega_producto.producto_id', $record->id)
                                ->where('bodega_user.user_id', $currentUser->id)
                                ->where('bodega_producto.precio_venta_sugerido', '>', 0)
                                ->avg('bodega_producto.precio_venta_sugerido') ?? 0;
                        }

                        // Calcular precio con ISV si aplica
                        $aplicaIsv = $record->aplica_isv ?? true;

                        if ($aplicaIsv && $precioBase > 0) {
                            return (int) ceil($precioBase * 1.15); // +15% ISV
                        }

                        return (int) ceil($precioBase);
                    })
                    ->description(fn($record) => ($record->aplica_isv ?? true) ? '+15% ISV' : 'Sin ISV'),
            ])

            ->filters([
                Tables\Filters\SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre'),

                Tables\Filters\SelectFilter::make('unidad_id')
                    ->label('Unidad')
                    ->relationship('unidad', 'nombre'),

                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),

                Tables\Filters\Filter::make('stock_bajo')
                    ->label('Stock Bajo')
                    ->query(function (Builder $query) {
                        $currentUser = Auth::user();

                        if (!$currentUser) {
                            return $query;
                        }

                        return $query->whereHas('bodegas', function ($q) use ($currentUser) {
                            $q->whereRaw('bodega_producto.stock < bodega_producto.stock_minimo');
                        });
                    })
                    ->toggle(),
            ])

            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])

            ->defaultSort('nombre', 'asc');
    }

    /* -----------------------------------------------------------------
     |  RELATION MANAGERS
     |----------------------------------------------------------------- */
    public static function getRelations(): array
    {
        return [
            ProductoImagenRelationManager::class,
        ];
    }

    /* -----------------------------------------------------------------
     |  PAGES
     |----------------------------------------------------------------- */
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'edit'   => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
