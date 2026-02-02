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

        // Super Admin ve todo
        $esSuperAdmin = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->where('roles.name', 'super_admin')
            ->exists();

        if ($esSuperAdmin) {
            return $query;
        }

        // Jefe ve productos que él creó + los que crearon sus subordinados
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

        // 🎯 ENCARGADO: Ve productos asignados a SUS bodegas
        $bodegasDelUsuario = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->pluck('bodega_id')
            ->toArray();

        if (empty($bodegasDelUsuario)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('bodegas', function ($q) use ($bodegasDelUsuario) {
            $q->whereIn('bodegas.id', $bodegasDelUsuario);
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
            // 🎯 SECCIÓN DE BODEGA PRIMERO (visible solo al crear)
            Forms\Components\Section::make('Asignación de Bodega')
                ->description('Selecciona primero la bodega para filtrar las opciones disponibles')
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
                        ->live()
                        ->default(function () {
                            return self::getBodegaDefaultParaUsuario();
                        })
                        ->disabled(fn() => !self::usuarioPuedeSeleccionarBodega())
                        ->dehydrated(true)
                        ->afterStateUpdated(function (Forms\Set $set) {
                            // Limpiar categoría y unidad al cambiar bodega
                            $set('categoria_id', null);
                            $set('unidad_id', null);
                            $set('nombre', null);
                        })
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

            // 🎯 SECCIÓN DE INFORMACIÓN DEL PRODUCTO
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
                            $set('nombre', null);
                            
                            // 🎯 Heredar ISV de la categoría seleccionada
                            if ($state) {
                                $categoria = \App\Models\Categoria::find($state);
                                if ($categoria) {
                                    $set('aplica_isv', $categoria->aplica_isv ?? false);
                                }
                            }
                        }),

                    Forms\Components\Select::make('unidad_id')
                        ->label('Unidad de medida')
                        ->options(function (Get $get) {
                            $categoriaId = $get('categoria_id');
                            $bodegaId = $get('bodega_id');

                            if (!$categoriaId) {
                                return [];
                            }

                            $categoria = \App\Models\Categoria::find($categoriaId);

                            if (!$categoria) {
                                return [];
                            }

                            // Obtener todas las unidades activas de esta categoría
                            $unidadesDisponibles = $categoria->unidades()
                                ->wherePivot('activo', true)
                                ->where('unidades.activo', true)
                                ->pluck('unidades.nombre', 'unidades.id')
                                ->toArray();

                            // 🎯 Determinar la bodega para filtrar
                            $currentUser = Auth::user();
                            $bodegaFinal = $bodegaId;

                            // Si no hay bodega seleccionada, intentar obtener la del usuario
                            if (!$bodegaFinal && $currentUser) {
                                $bodegaFinal = DB::table('bodega_user')
                                    ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                    ->where('bodega_user.user_id', $currentUser->id)
                                    ->where('bodegas.activo', true)
                                    ->value('bodegas.id');
                            }

                            // Si aún no hay bodega, retornar todas las unidades
                            if (!$bodegaFinal) {
                                return $unidadesDisponibles;
                            }

                            // 🎯 Obtener unidades YA USADAS con esta categoría en esta bodega
                            $unidadesUsadas = DB::table('productos')
                                ->join('bodega_producto', 'productos.id', '=', 'bodega_producto.producto_id')
                                ->where('productos.categoria_id', $categoriaId)
                                ->where('bodega_producto.bodega_id', $bodegaFinal)
                                ->whereNull('productos.deleted_at')
                                ->pluck('productos.unidad_id')
                                ->toArray();

                            // 🎯 Excluir las unidades ya usadas
                            return collect($unidadesDisponibles)
                                ->filter(fn($nombre, $id) => !in_array($id, $unidadesUsadas))
                                ->toArray();
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->disabled(fn(Get $get) => !$get('categoria_id'))
                        ->helperText(function (Get $get) {
                            if (!$get('categoria_id')) {
                                return 'Selecciona una categoría primero';
                            }

                            $categoriaId = $get('categoria_id');
                            $bodegaId = $get('bodega_id');
                            $currentUser = Auth::user();

                            $bodegaFinal = $bodegaId;
                            if (!$bodegaFinal && $currentUser) {
                                $bodegaFinal = DB::table('bodega_user')
                                    ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                    ->where('bodega_user.user_id', $currentUser->id)
                                    ->where('bodegas.activo', true)
                                    ->value('bodegas.id');
                            }

                            if ($bodegaFinal) {
                                $categoria = \App\Models\Categoria::find($categoriaId);
                                $totalUnidades = $categoria?->unidades()
                                    ->wherePivot('activo', true)
                                    ->where('unidades.activo', true)
                                    ->count() ?? 0;

                                $unidadesUsadas = DB::table('productos')
                                    ->join('bodega_producto', 'productos.id', '=', 'bodega_producto.producto_id')
                                    ->where('productos.categoria_id', $categoriaId)
                                    ->where('bodega_producto.bodega_id', $bodegaFinal)
                                    ->whereNull('productos.deleted_at')
                                    ->count();

                                if ($unidadesUsadas > 0) {
                                    $disponibles = $totalUnidades - $unidadesUsadas;
                                    if ($disponibles <= 0) {
                                        return "Todas las unidades ya están en uso para esta categoría";
                                    }
                                    return "{$disponibles} de {$totalUnidades} unidades disponibles";
                                }
                            }

                            return null;
                        })
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                            self::generateNombre($set, $get);
                        }),

                    Forms\Components\TextInput::make('nombre')
                        ->label('Nombre del Producto')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Se genera automáticamente, pero puedes cambiarlo libremente')
                        ->placeholder('Ej: Huevo Grande Cartón, Caja de Leche x24'),

                    Forms\Components\TextInput::make('precio_sugerido')
                        ->label('Precio Base de Referencia')
                        ->prefix('L')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(999999)
                        ->default(0)
                        ->required()
                        ->helperText('Precio de referencia inicial (opcional)'),

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

            // 🆕 SECCIÓN: Formato de Empaque (opcional)
            Forms\Components\Section::make('Formato de Empaque')
                ->description('Configura si el producto viene en cajas/bultos (opcional - para galletas, confites, bebidas, etc.)')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('formato_empaque')
                                ->label('Código de Empaque')
                                ->placeholder('Ej: 1X24X12')
                                ->maxLength(50)
                                ->helperText('Tal como aparece en la factura del proveedor')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    // Intentar extraer unidades_por_bulto del formato
                                    if ($state) {
                                        $partes = explode('X', strtoupper($state));
                                        if (count($partes) >= 2 && is_numeric($partes[1])) {
                                            $set('unidades_por_bulto', (int) $partes[1]);
                                        }
                                    }
                                }),

                            Forms\Components\TextInput::make('unidades_por_bulto')
                                ->label('Unidades por Caja/Bulto')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(9999)
                                ->placeholder('Ej: 24')
                                ->helperText(function (Forms\Get $get) {
                                    $unidadId = $get('unidad_id');
                                    if ($unidadId) {
                                        $unidad = \App\Models\Unidad::find($unidadId);
                                        if ($unidad) {
                                            return "Cuántos {$unidad->nombre}s vienen en cada caja";
                                        }
                                    }
                                    return 'Cuántas unidades vienen en cada caja';
                                }),
                        ]),

                    Forms\Components\Placeholder::make('info_formato')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $formato = $get('formato_empaque');
                            $unidadesPorBulto = $get('unidades_por_bulto');
                            $unidadId = $get('unidad_id');

                            if (!$formato && !$unidadesPorBulto) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-gray-50 dark:bg-gray-900/20 p-4'>
                                        <p class='text-sm text-gray-600 dark:text-gray-400'>
                                            <strong>Opcional:</strong> Configura esto solo si el producto viene en cajas/bultos 
                                            con cantidad fija (ej: galletas, confites, bebidas).
                                        </p>
                                        <p class='text-xs text-gray-500 dark:text-gray-500 mt-2'>
                                            Para productos como huevos que se venden por cartón, no es necesario configurar esto.
                                        </p>
                                    </div>
                                ");
                            }

                            if ($unidadesPorBulto) {
                                $unidadNombre = 'unidades';
                                if ($unidadId) {
                                    $unidad = \App\Models\Unidad::find($unidadId);
                                    $unidadNombre = $unidad ? strtolower($unidad->nombre) . 's' : 'unidades';
                                }

                                // Ejemplos de cálculo
                                $ejemplo1 = $unidadesPorBulto * 5; // 5 cajas
                                $ejemplo2 = $unidadesPorBulto * 10; // 10 cajas
                                $ejemplo3 = ($unidadesPorBulto * 10) + 5; // 10 cajas + 5 sueltos

                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4'>
                                        <p class='text-sm font-semibold text-blue-900 dark:text-blue-100 mb-2'>
                                            Configuración de Empaque:
                                        </p>
                                        <div class='text-sm text-blue-800 dark:text-blue-200 space-y-1'>
                                            <p>• <strong>1 caja = {$unidadesPorBulto} {$unidadNombre}</strong></p>
                                            <p class='text-xs text-blue-600 dark:text-blue-400 mt-2'>Ejemplos de conversión:</p>
                                            <p class='text-xs'>• {$ejemplo1} {$unidadNombre} = 5 cajas exactas</p>
                                            <p class='text-xs'>• {$ejemplo2} {$unidadNombre} = 10 cajas exactas</p>
                                            <p class='text-xs'>• {$ejemplo3} {$unidadNombre} = 10 cajas + 5 sueltos</p>
                                        </div>
                                    </div>
                                ");
                            }

                            return '';
                        })
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(fn($record) => $record ? empty($record->formato_empaque) : true),

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

                            if ($tipo === 'porcentaje') {
                                $precioBase = $costoEjemplo * (1 + ($margen / 100));
                            } else {
                                $precioBase = $costoEjemplo + $margen;
                            }

                            // Sin redondeo
                            $gananciaBase = $precioBase - $costoEjemplo;

                            $montoIsv = $aplicaIsv ? ($precioBase * $tasaIsv) : 0;
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
                                         Simulación de Precio:
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
                                         <span class='font-bold'>PRECIO FINAL:</span>
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
                                 Importante:
                             </p>
                             <ul class='text-sm text-yellow-800 dark:text-yellow-200 space-y-1 list-disc list-inside'>
                                 <li>El precio de venta se <strong>actualiza automáticamente</strong> cada vez que entra stock (compra o reempaque)</li>
                                 <li>El sistema usa <strong>Costo Promedio Ponderado</strong> para calcular el costo real</li>
                                 <li>Los precios se <strong>redondean hacia arriba</strong> para evitar pérdidas</li>
                                 <li>El <strong>ISV (15%)</strong> se calcula sobre el precio de venta y se muestra por separado</li>
                                 <li>Así <strong>nunca venderás a pérdida</strong></li>
                             </ul>
                         </div>
                     "))
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->collapsible()
                ->collapsed(true), // Colapsada por defecto para uso futuro

            // 🆕 SECCIÓN: Precio Máximo Competitivo (PRECIO MANUAL)
            Forms\Components\Section::make('Precio de Venta')
                ->description('Configura el precio de venta del producto')
                ->schema([
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('precio_venta_maximo')
                                ->label('Precio de Venta')
                                ->prefix('L')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(999999)
                                ->step(0.01)
                                ->required()
                                ->live(onBlur: true)
                                ->helperText(function (Forms\Get $get) {
                                    $aplicaIsv = $get('aplica_isv');
                                    if ($aplicaIsv) {
                                        return 'Este precio YA incluye el 15% de ISV';
                                    }
                                    return 'Este producto no aplica ISV';
                                }),

                            Forms\Components\TextInput::make('margen_minimo_seguridad')
                                ->label('Margen Mínimo de Seguridad')
                                ->suffix('%')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(50)
                                ->default(3)
                                ->step(0.5)
                                ->helperText('Se aplica cuando el costo supera el precio de venta'),
                        ]),

                    // Mostrar desglose de ISV si aplica
                    Forms\Components\Placeholder::make('desglose_isv')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $precioVenta = (float) ($get('precio_venta_maximo') ?? 0);
                            $aplicaIsv = $get('aplica_isv');

                            if ($precioVenta <= 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-gray-50 dark:bg-gray-800 p-4'>
                                        <p class='text-sm text-gray-600 dark:text-gray-400'>
                                            Ingresa el precio de venta para ver el desglose
                                        </p>
                                    </div>
                                ");
                            }

                            if ($aplicaIsv) {
                                // Calcular desglose: Precio incluye ISV
                                $precioSinIsv = round($precioVenta / 1.15, 2);
                                $montoIsv = round($precioVenta - $precioSinIsv, 2);

                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-blue-50 dark:bg-blue-900/30 p-4'>
                                        <p class='text-sm font-semibold text-blue-900 dark:text-blue-100 mb-3'>
                                            Desglose del Precio (incluye ISV 15%):
                                        </p>
                                        <div class='space-y-2 text-sm'>
                                            <div class='flex justify-between text-blue-800 dark:text-blue-200'>
                                                <span>Precio sin ISV:</span>
                                                <strong>L " . number_format($precioSinIsv, 2) . "</strong>
                                            </div>
                                            <div class='flex justify-between text-amber-700 dark:text-amber-300'>
                                                <span>ISV (15%):</span>
                                                <strong>L " . number_format($montoIsv, 2) . "</strong>
                                            </div>
                                            <div class='flex justify-between text-green-700 dark:text-green-300 pt-2 border-t border-blue-200 dark:border-blue-700'>
                                                <span class='font-bold'>Precio Final:</span>
                                                <strong class='text-lg'>L " . number_format($precioVenta, 2) . "</strong>
                                            </div>
                                        </div>
                                    </div>
                                ");
                            } else {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-green-50 dark:bg-green-900/30 p-4'>
                                        <p class='text-sm font-semibold text-green-900 dark:text-green-100 mb-2'>
                                            Precio Final (Sin ISV):
                                        </p>
                                        <p class='text-2xl font-bold text-green-700 dark:text-green-300'>
                                            L " . number_format($precioVenta, 2) . "
                                        </p>
                                        <p class='text-xs text-green-600 dark:text-green-400 mt-2'>
                                            Este producto está exento de ISV
                                        </p>
                                    </div>
                                ");
                            }
                        })
                        ->columnSpanFull(),

                    // Campo oculto para ISV (heredado de categoría)
                    Forms\Components\Toggle::make('aplica_isv')
                        ->label('Aplica ISV (15%)')
                        ->disabled()
                        ->dehydrated(true)
                        ->helperText('Se hereda automáticamente de la categoría seleccionada'),
                ])
                ->columns(1),

            // 🎯 SECCIÓN: Gestión por Bodega (visible solo al editar)
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
                                                Forms\Components\TextInput::make("stock_actual_{$bodegaProducto->id}")
                                                    ->label('Stock Actual')
                                                    ->default(number_format($bodegaProducto->stock, 2, '.', ''))
                                                    ->disabled(!$puedeEditarStock)
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->step(0.01)
                                                    ->dehydrated(true)
                                                    ->suffix($record->unidad->nombre ?? 'unidades')
                                                    ->helperText($puedeEditarStock ? 'Ajuste manual' : null)
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
                                                    ->default(number_format($bodegaProducto->costo_promedio_actual ?? 0, 2))
                                                    ->disabled()
                                                    ->dehydrated(false)
                                                    ->helperText('Automático')
                                                    ->extraAttributes(['class' => 'font-bold']),

                                                Forms\Components\TextInput::make("precio_venta_{$bodegaProducto->id}")
                                                    ->label('Precio Venta')
                                                    ->prefix('L')
                                                    ->default(number_format($bodegaProducto->precio_venta_sugerido ?? 0, 2))
                                                    ->disabled()
                                                    ->dehydrated(false)
                                                    ->extraAttributes(['class' => 'font-bold text-green-600'])
                                                    ->helperText('Costo + Margen'),

                                                Forms\Components\TextInput::make("precio_isv_{$bodegaProducto->id}")
                                                    ->label('Precio + ISV')
                                                    ->prefix('L')
                                                    ->default(function () use ($bodegaProducto, $record) {
                                                        $precioBase = $bodegaProducto->precio_venta_sugerido ?? 0;
                                                        $aplicaIsv = $record->aplica_isv ?? true;

                                                        if ($aplicaIsv && $precioBase > 0) {
                                                            return number_format($precioBase * 1.15, 2);
                                                        }
                                                        return number_format($precioBase, 2);
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
                    ->weight('bold')
                    ->description(fn($record) => $record->formato_empaque ? "{$record->formato_empaque}" : null),

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

                // 🆕 COLUMNA DE STOCK CON EQUIVALENCIA EN CAJAS
                Tables\Columns\TextColumn::make('stock_actual')
                    ->label('Stock')
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
                    ->formatStateUsing(function ($state, $record) {
                        // Si tiene formato de empaque, usar el texto del modelo
                        if ($record->tieneFormatoEmpaque()) {
                            $equivalencia = $record->calcularEquivalenciaBultos((float) $state);

                            // Usar el texto ya calculado por el modelo
                            if (!empty($equivalencia['texto'])) {
                                return $equivalencia['texto'];
                            }
                        }

                        // Sin formato, mostrar solo número con unidad
                        $unidadNombre = $record->unidad->nombre ?? 'unidades';
                        return number_format($state, 2, '.', ',') . " {$unidadNombre}";
                    })
                    ->description(function ($state, $record) {
                        if ($record->tieneFormatoEmpaque()) {
                            // Usar el nombre de la unidad del producto (Carton, Medio Carton, Paquete, etc.)
                            $unidadNombre = $record->unidad->nombre ?? 'unidades';
                            
                            // Pluralizar simple: agregar "s" si no termina en "s"
                            if ($state != 1 && !str_ends_with(strtolower($unidadNombre), 's')) {
                                $unidadNombre .= 's';
                            }
                            
                            return number_format($state, 0) . " {$unidadNombre}";
                        }
                        return null;
                    })
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // 🎯 NUEVA COLUMNA: Costo Promedio (lo que realmente pagaste)
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
                            $costoSinIsv = DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->where('costo_promedio_actual', '>', 0)
                                ->avg('costo_promedio_actual');

                            $costo = $costoSinIsv ?? $record->precio_sugerido ?? 0;
                        } else {
                            $costoSinIsv = DB::table('bodega_producto')
                                ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                                ->where('bodega_producto.producto_id', $record->id)
                                ->where('bodega_user.user_id', $currentUser->id)
                                ->where('bodega_producto.costo_promedio_actual', '>', 0)
                                ->avg('bodega_producto.costo_promedio_actual');

                            $costo = $costoSinIsv ?? $record->precio_sugerido ?? 0;
                        }

                        // Si el producto aplica ISV, mostrar el costo CON ISV (lo que realmente pagó)
                        $aplicaIsv = $record->aplica_isv ?? false;
                        
                        if ($aplicaIsv && $costo > 0) {
                            return round($costo * 1.15, 2); // Costo + ISV = lo que pagaste
                        }

                        return $costo;
                    })
                    ->description(function ($record) {
                        $aplicaIsv = $record->aplica_isv ?? false;
                        if ($aplicaIsv) {
                            return 'Incluye ISV';
                        }
                        return 'Sin ISV';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                // 🎯 COLUMNA: Precio Venta (sin ISV - desglosado)
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
                            $precioConIsv = DB::table('bodega_producto')
                                ->where('producto_id', $record->id)
                                ->where('precio_venta_sugerido', '>', 0)
                                ->avg('precio_venta_sugerido') ?? 0;
                        } else {
                            $precioConIsv = DB::table('bodega_producto')
                                ->join('bodega_user', 'bodega_producto.bodega_id', '=', 'bodega_user.bodega_id')
                                ->where('bodega_producto.producto_id', $record->id)
                                ->where('bodega_user.user_id', $currentUser->id)
                                ->where('bodega_producto.precio_venta_sugerido', '>', 0)
                                ->avg('bodega_producto.precio_venta_sugerido') ?? 0;
                        }

                        // Si aplica ISV, el precio guardado YA lo incluye, entonces desgloso
                        $aplicaIsv = $record->aplica_isv ?? false;
                        
                        if ($aplicaIsv && $precioConIsv > 0) {
                            // Precio sin ISV = Precio con ISV / 1.15
                            return round($precioConIsv / 1.15, 2);
                        }

                        return $precioConIsv;
                    })
                    ->description(function ($record) {
                        $aplicaIsv = $record->aplica_isv ?? false;
                        if ($aplicaIsv) {
                            return 'Sin ISV';
                        }
                        return 'Exento ISV';
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

                        // El precio_venta_sugerido YA incluye ISV si aplica
                        // Solo retornamos el valor tal cual
                        return $precioBase;
                    })
                    ->description(fn($record) => ($record->aplica_isv ?? true) ? 'Incluye 15% ISV' : 'Sin ISV'),
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
                    ->falseLabel('Inactivos')
                    ->default(true),

                // 🆕 Filtro para productos con formato de empaque
                Tables\Filters\TernaryFilter::make('tiene_formato')
                    ->label('Formato Empaque')
                    ->placeholder('Todos')
                    ->trueLabel('Con formato')
                    ->falseLabel('Sin formato')
                    ->queries(
                        true: fn(Builder $query) => $query->whereNotNull('formato_empaque')->where('formato_empaque', '!=', ''),
                        false: fn(Builder $query) => $query->where(function ($q) {
                            $q->whereNull('formato_empaque')->orWhere('formato_empaque', '');
                        }),
                    ),

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