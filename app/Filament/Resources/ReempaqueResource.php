<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReempaqueResource\Pages;
use App\Models\Reempaque;
use App\Models\Lote;
use App\Models\Categoria;
use App\Models\Unidad;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReempaqueResource extends Resource
{
    protected static ?string $model = Reempaque::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Inventario';
    protected static ?int $navigationSort = 4;
    protected static ?string $pluralModelLabel = 'Reempaques';
    protected static ?string $modelLabel = 'Reempaque';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        if ($esSuperAdminOJefe) {
            return $query;
        }

        $bodegasUsuario = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->where('activo', true)
            ->pluck('bodega_id')
            ->toArray();

        return $query->whereIn('bodega_id', $bodegasUsuario);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // INFORMACION GENERAL
            Forms\Components\Section::make('Información General')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('bodega_id')
                            ->label('Bodega')
                            ->options(function () {
                                $currentUser = Auth::user();
                                if (!$currentUser) return [];

                                $esSuperAdminOJefe = DB::table('model_has_roles')
                                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                    ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                    ->where('model_has_roles.model_id', '=', $currentUser->id)
                                    ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                    ->exists();

                                if ($esSuperAdminOJefe) {
                                    return \App\Models\Bodega::where('activo', true)->pluck('nombre', 'id')->toArray();
                                }

                                return DB::table('bodega_user')
                                    ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                    ->where('bodega_user.user_id', $currentUser->id)
                                    ->where('bodegas.activo', true)
                                    ->pluck('bodegas.nombre', 'bodegas.id')
                                    ->toArray();
                            })
                            ->default(function () {
                                $currentUser = Auth::user();
                                if (!$currentUser) return null;

                                $esSuperAdminOJefe = DB::table('model_has_roles')
                                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                    ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                    ->where('model_has_roles.model_id', '=', $currentUser->id)
                                    ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                    ->exists();

                                if (!$esSuperAdminOJefe) {
                                    return DB::table('bodega_user')
                                        ->where('user_id', $currentUser->id)
                                        ->where('activo', true)
                                        ->value('bodega_id');
                                }
                                return null;
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn(Forms\Set $set) => $set('lotes_seleccionados', []))
                            ->disabled(fn(string $operation) => $operation === 'edit')
                            ->dehydrated(),

                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de Reempaque')
                            ->options([
                                'individual' => 'Individual (1 lote)',
                                'mezclado' => 'Mezclado (varios lotes)',
                            ])
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn(Forms\Set $set) => $set('lotes_seleccionados', []))
                            ->disabled(fn(string $operation) => $operation === 'edit')
                            ->dehydrated(),
                    ]),
                ]),

            // SELECCION DE LOTES
            Forms\Components\Section::make('Selección de Lotes')
                ->schema([
                    Forms\Components\Repeater::make('lotes_seleccionados')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('lote_id')
                                ->label('Lote')
                                ->options(function (Forms\Get $get) {
                                    $bodegaId = $get('../../bodega_id');
                                    if (!$bodegaId) return [];

                                    $lotes = Lote::where('bodega_id', $bodegaId)
                                        ->where('estado', 'disponible')
                                        ->where('cantidad_huevos_remanente', '>=', 30)
                                        ->with(['producto', 'proveedor'])
                                        ->orderBy('created_at', 'asc')
                                        ->get();

                                    $opciones = [];

                                    foreach ($lotes as $lote) {
                                        $huevos = (int) $lote->cantidad_huevos_remanente;
                                        $c30Disp = floor($huevos / 30);
                                        $bufferRegalo = $lote->getBufferRegaloDisponible();
                                        $cartonesRegalo = floor($bufferRegalo / 30);

                                        $costoPorCarton = $lote->costo_por_carton_facturado ?? 0;

                                        // Mostrar info de regalo si hay
                                        $regaloInfo = $cartonesRegalo > 0 ? " | {$cartonesRegalo} regalo" : "";

                                        $opciones[$lote->id] = sprintf(
                                            '%s | %s | %d C30%s | L%.2f/cart',
                                            $lote->producto->nombre ?? 'Sin producto',
                                            $lote->numero_lote,
                                            $c30Disp,
                                            $regaloInfo,
                                            $costoPorCarton
                                        );
                                    }

                                    return $opciones;
                                })
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if (!$state) return;

                                    $lote = Lote::find($state);
                                    if (!$lote) return;

                                    $total = (int) $lote->cantidad_huevos_remanente;
                                    $c30 = floor($total / 30);

                                    $set('disponible_c30', $c30);

                                    // Guardar datos del lote para cálculos FIFO
                                    $set('costo_por_huevo', $lote->costo_por_huevo ?? 0);
                                    $set('costo_por_carton', $lote->costo_por_carton_facturado ?? 0);
                                    $set('huevos_facturados_disponibles', $lote->getHuevosFacturadosDisponibles());
                                    $set('huevos_regalo_disponibles', $lote->getBufferRegaloDisponible());

                                    $set('cantidad_c30', 0);
                                    $set('cantidad_huevos', 0);
                                    $set('costo_parcial', 0);
                                    $set('huevos_facturados_usados', 0);
                                    $set('huevos_regalo_usados', 0);
                                })
                                ->disabled(fn(string $operation) => $operation === 'edit')
                                ->dehydrated()
                                ->columnSpanFull(),

                            Forms\Components\Grid::make(4)->schema([
                                Forms\Components\Placeholder::make('info_disponible')
                                    ->label('Disponible')
                                    ->content(function (Forms\Get $get) {
                                        $c30 = (int)($get('disponible_c30') ?? 0);
                                        $regaloHuevos = (float)($get('huevos_regalo_disponibles') ?? 0);
                                        $regaloCartones = floor($regaloHuevos / 30);
                                        
                                        $regaloText = $regaloCartones > 0 
                                            ? "<div class='text-xs text-green-500'>{$regaloCartones} de regalo</div>" 
                                            : "";
                                        
                                        return new \Illuminate\Support\HtmlString("
                                            <div class='text-center'>
                                                <div class='text-3xl font-bold text-blue-600 dark:text-blue-400'>
                                                    {$c30}
                                                </div>
                                                <div class='text-xs text-gray-500'>cartones</div>
                                                {$regaloText}
                                            </div>
                                        ");
                                    }),

                                Forms\Components\TextInput::make('cantidad_c30')
                                    ->label('Usar Cartones')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(fn(Forms\Get $get) => $get('disponible_c30') ?? 0)
                                    ->live(debounce: 300)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $c30 = min((int)($state ?? 0), (int)($get('disponible_c30') ?? 0));
                                        $huevosAUsar = $c30 * 30;
                                        $set('cantidad_huevos', $huevosAUsar);

                                        // 🎯 LÓGICA FIFO: Calcular cuántos son facturados vs regalo
                                        $huevosFacturadosDisp = (float)($get('huevos_facturados_disponibles') ?? 0);
                                        $huevosRegaloDisp = (float)($get('huevos_regalo_disponibles') ?? 0);
                                        
                                        // CORREGIDO: Derivar costo por huevo desde costo por carton para evitar redondeos
                                        $costoPorCarton = (float)($get('costo_por_carton') ?? 0);
                                        $costoPorHuevo = $costoPorCarton / 30;

                                        if ($huevosAUsar <= $huevosFacturadosDisp) {
                                            // Todos son facturados (con costo)
                                            $huevosFacturadosUsados = $huevosAUsar;
                                            $huevosRegaloUsados = 0;
                                        } else {
                                            // Parte facturados + parte regalo
                                            $huevosFacturadosUsados = $huevosFacturadosDisp;
                                            $huevosRegaloUsados = min(
                                                $huevosAUsar - $huevosFacturadosDisp,
                                                $huevosRegaloDisp
                                            );
                                        }

                                        // Solo los facturados tienen costo
                                        $costoParcial = round($huevosFacturadosUsados * $costoPorHuevo, 4);

                                        $set('huevos_facturados_usados', $huevosFacturadosUsados);
                                        $set('huevos_regalo_usados', $huevosRegaloUsados);
                                        $set('costo_parcial', $costoParcial);
                                    })
                                    ->suffix('C30')
                                    ->disabled(fn(string $operation) => $operation === 'edit')
                                    ->dehydrated(),

                                Forms\Components\Placeholder::make('info_costo')
                                    ->label('Desglose')
                                    ->content(function (Forms\Get $get) {
                                        $facturados = (float)($get('huevos_facturados_usados') ?? 0);
                                        $regalo = (float)($get('huevos_regalo_usados') ?? 0);
                                        $costoPorCarton = (float)($get('costo_por_carton') ?? 0);
                                        
                                        $cartFacturados = $facturados / 30;
                                        $cartRegalo = $regalo / 30;
                                        
                                        if ($facturados <= 0 && $regalo <= 0) {
                                            return new \Illuminate\Support\HtmlString("
                                                <div class='text-sm text-gray-400'>-</div>
                                            ");
                                        }
                                        
                                        $lines = [];
                                        if ($cartFacturados > 0) {
                                            $lines[] = "<span class='text-blue-600'>" . number_format($cartFacturados, 1) . " fact</span>";
                                        }
                                        if ($cartRegalo > 0) {
                                            $lines[] = "<span class='text-green-600 font-bold'>" . number_format($cartRegalo, 1) . " regalo</span>";
                                        }
                                        
                                        return new \Illuminate\Support\HtmlString("
                                            <div class='text-sm'>
                                                " . implode(' + ', $lines) . "
                                            </div>
                                            <div class='text-xs text-gray-500'>L " . number_format($costoPorCarton, 4) . "/cart</div>
                                        ");
                                    }),

                                Forms\Components\Placeholder::make('costo_display')
                                    ->label('Costo Total')
                                    ->content(function (Forms\Get $get) {
                                        $costo = (float)($get('costo_parcial') ?? 0);
                                        $regaloUsados = (float)($get('huevos_regalo_usados') ?? 0);
                                        
                                        $color = $costo > 0 ? 'text-blue-600' : 'text-green-600';
                                        $regaloNote = $regaloUsados > 0 
                                            ? "<div class='text-xs text-green-500'>Incluye regalo gratis</div>" 
                                            : "";
                                        
                                        return new \Illuminate\Support\HtmlString("
                                            <div class='text-xl font-bold {$color}'>
                                                L " . number_format($costo, 4) . "
                                            </div>
                                            {$regaloNote}
                                        ");
                                    }),
                            ]),

                            // Campos ocultos para datos
                            Forms\Components\Hidden::make('disponible_c30')->dehydrated(false),
                            Forms\Components\Hidden::make('cantidad_huevos')->dehydrated(),
                            Forms\Components\Hidden::make('costo_por_huevo')->dehydrated(),
                            Forms\Components\Hidden::make('costo_por_carton')->dehydrated(),
                            Forms\Components\Hidden::make('costo_parcial')->dehydrated(),
                            Forms\Components\Hidden::make('huevos_facturados_disponibles')->dehydrated(false),
                            Forms\Components\Hidden::make('huevos_regalo_disponibles')->dehydrated(false),
                            Forms\Components\Hidden::make('huevos_facturados_usados')->dehydrated(),
                            Forms\Components\Hidden::make('huevos_regalo_usados')->dehydrated(),
                        ])
                        ->columns(1)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(fn(Forms\Get $get) => $get('tipo') === 'individual' ? 1 : 20)
                        ->addActionLabel('+ Agregar Lote')
                        ->reorderable(false)
                        ->itemLabel(fn(array $state): ?string =>
                            isset($state['lote_id']) ? Lote::find($state['lote_id'])?->producto?->nombre ?? 'Lote' : 'Nuevo lote'
                        )
                        ->live()
                        ->disabled(fn(string $operation) => $operation === 'edit')
                        ->dehydrated(),
                ])
                ->visible(fn(Forms\Get $get) => $get('bodega_id') && $get('tipo')),

            // DISTRIBUCIÓN
            Forms\Components\Section::make('Distribución')
                ->schema([
                    // Resumen de huevos disponibles
                    Forms\Components\Placeholder::make('resumen_disponible')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $lotes = $get('lotes_seleccionados') ?? [];
                            $totalHuevos = (int) collect($lotes)->sum('cantidad_huevos');

                            if ($totalHuevos <= 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-gray-100 dark:bg-gray-800 p-4 text-center'>
                                        <p class='text-gray-500'>Selecciona lotes y cantidad para distribuir</p>
                                    </div>
                                ");
                            }

                            $maxC30 = floor($totalHuevos / 30);
                            $maxC15 = floor($totalHuevos / 15);

                            // Calcular huevos de regalo usados
                            $totalRegaloUsados = (float) collect($lotes)->sum('huevos_regalo_usados');
                            $cartonesRegalo = $totalRegaloUsados / 30;
                            
                            $regaloInfo = $totalRegaloUsados > 0 
                                ? "<p class='text-sm text-green-600 font-bold mt-1'>Incluye " . number_format($cartonesRegalo, 1) . " cartones de regalo (costo L 0)</p>"
                                : "";

                            return new \Illuminate\Support\HtmlString("
                                <div class='rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4'>
                                    <p class='text-lg font-bold text-blue-900 dark:text-blue-100'>
                                        {$totalHuevos} huevos disponibles
                                    </p>
                                    <p class='text-sm text-blue-700 dark:text-blue-300'>
                                        Máximo: {$maxC30} cartones (C30) o {$maxC15} medios (C15)
                                    </p>
                                    {$regaloInfo}
                                </div>
                            ");
                        }),

                    // Validación en tiempo real
                    Forms\Components\Placeholder::make('validacion')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $lotes = $get('lotes_seleccionados') ?? [];
                            $disponibles = (int) collect($lotes)->sum('cantidad_huevos');

                            if ($disponibles <= 0) return '';

                            $distribuciones = $get('distribuciones') ?? [];
                            $asignados = 0;
                            foreach ($distribuciones as $dist) {
                                $cantidad = (int)($dist['cantidad'] ?? 0);
                                $unidadId = $dist['unidad_id'] ?? null;
                                
                                if ($unidadId) {
                                    $unidad = Unidad::find($unidadId);
                                    $factor = 30;
                                    if ($unidad && str_contains($unidad->nombre, '15')) {
                                        $factor = 15;
                                    }
                                    $asignados += $cantidad * $factor;
                                }
                            }

                            $pendientes = $disponibles - $asignados;

                            if ($pendientes === 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-3 border border-green-300'>
                                        <p class='font-bold text-green-700 dark:text-green-300'>✓ Distribución completa</p>
                                    </div>
                                ");
                            } elseif ($pendientes > 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3 border border-amber-300'>
                                        <p class='font-bold text-amber-700 dark:text-amber-300'>⚠ Faltan {$pendientes} huevos por asignar</p>
                                    </div>
                                ");
                            } else {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-3 border border-red-300'>
                                        <p class='font-bold text-red-700 dark:text-red-300'>✗ Exceso de " . abs($pendientes) . " huevos</p>
                                    </div>
                                ");
                            }
                        }),

                    // Líneas de distribución
                    Forms\Components\Repeater::make('distribuciones')
                        ->label('Líneas de Distribución')
                        ->schema([
                            Forms\Components\Grid::make(4)->schema([
                                Forms\Components\Select::make('categoria_id')
                                    ->label('Categoría')
                                    ->options(
                                        Categoria::where('activo', true)
                                            ->whereHas('unidades', fn($q) => $q->whereIn('nombre', ['1x30', '1x15']))
                                            ->pluck('nombre', 'id')
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(fn(Forms\Set $set) => $set('unidad_id', null)),

                                Forms\Components\Select::make('unidad_id')
                                    ->label('Unidad')
                                    ->options(function (Forms\Get $get) {
                                        $categoriaId = $get('categoria_id');
                                        if (!$categoriaId) return [];

                                        $distribuciones = $get('../../distribuciones') ?? [];
                                        
                                        $unidadesUsadas = [];
                                        foreach ($distribuciones as $dist) {
                                            if (($dist['categoria_id'] ?? null) == $categoriaId && !empty($dist['unidad_id'])) {
                                                $unidadesUsadas[] = $dist['unidad_id'];
                                            }
                                        }

                                        $categoria = Categoria::with(['unidades' => function ($query) {
                                            $query->where('unidades.activo', true)
                                                  ->wherePivot('activo', true)
                                                  ->whereIn('nombre', ['1x30', '1x15']);
                                        }])->find($categoriaId);

                                        if (!$categoria) return [];

                                        return $categoria->unidades
                                            ->filter(fn($unidad) => !in_array($unidad->id, $unidadesUsadas))
                                            ->pluck('nombre', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->disabled(fn(Forms\Get $get) => !$get('categoria_id'))
                                    ->helperText(fn(Forms\Get $get) => !$get('categoria_id') ? 'Selecciona categoría primero' : null),

                                Forms\Components\TextInput::make('cantidad')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(onBlur: true),

                                Forms\Components\Placeholder::make('subtotal')
                                    ->label('Huevos')
                                    ->content(function (Forms\Get $get) {
                                        $cantidad = (int)($get('cantidad') ?? 0);
                                        $unidadId = $get('unidad_id');
                                        
                                        if (!$unidadId || $cantidad <= 0) {
                                            return new \Illuminate\Support\HtmlString(
                                                "<span class='text-lg font-bold text-gray-400'>0</span>"
                                            );
                                        }
                                        
                                        $unidad = Unidad::find($unidadId);
                                        $factor = 30;
                                        if ($unidad && str_contains($unidad->nombre, '15')) {
                                            $factor = 15;
                                        }
                                        $total = $cantidad * $factor;
                                        
                                        return new \Illuminate\Support\HtmlString(
                                            "<span class='text-lg font-bold text-green-600'>{$total}</span>"
                                        );
                                    }),
                            ]),
                        ])
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('+ Agregar Línea')
                        ->reorderable(false)
                        ->live()
                        ->itemLabel(function (array $state): ?string {
                            $categoriaId = $state['categoria_id'] ?? null;
                            $unidadId = $state['unidad_id'] ?? null;
                            $cantidad = (int)($state['cantidad'] ?? 0);

                            if (!$categoriaId || !$unidadId) return 'Nueva linea';

                            $categoria = Categoria::find($categoriaId);
                            $unidad = Unidad::find($unidadId);
                            
                            $factor = 30;
                            if ($unidad && str_contains($unidad->nombre, '15')) {
                                $factor = 15;
                            }
                            $huevos = $cantidad * $factor;

                            return ($categoria?->nombre ?? '') . " - {$cantidad} " . ($unidad?->nombre ?? '') . " ({$huevos} huevos)";
                        })
                        ->disabled(fn(string $operation) => $operation === 'edit')
                        ->dehydrated(),
                ])
                ->visible(fn(Forms\Get $get) => !empty($get('lotes_seleccionados'))),

            // RESUMEN DE COSTOS
            Forms\Components\Section::make('Resumen de Costos')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Placeholder::make('costo_total')
                            ->label('Costo Total')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $costoTotal = (float) collect($lotes)->sum('costo_parcial');
                                $huevosTotal = (int) collect($lotes)->sum('cantidad_huevos');
                                $huevosRegalo = (float) collect($lotes)->sum('huevos_regalo_usados');

                                $regaloInfo = $huevosRegalo > 0 
                                    ? "<div class='text-xs text-green-500 font-bold'>" . number_format($huevosRegalo / 30, 1) . " cartones gratis</div>"
                                    : "";

                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold text-blue-600'>
                                        L " . number_format($costoTotal, 4) . "
                                    </div>
                                    <div class='text-xs text-gray-500'>
                                        {$huevosTotal} huevos
                                    </div>
                                    {$regaloInfo}
                                ");
                            }),

                        Forms\Components\Placeholder::make('costo_por_carton')
                            ->label('Costo por Cartón (30)')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $costoTotal = (float) collect($lotes)->sum('costo_parcial');
                                $huevosTotal = (int) collect($lotes)->sum('cantidad_huevos');

                                if ($huevosTotal <= 0) {
                                    return new \Illuminate\Support\HtmlString("<div class='text-2xl font-bold text-gray-400'>L 0.00</div>");
                                }

                                $costoPorCarton = ($costoTotal / $huevosTotal) * 30;

                                // Color verde si hay descuento por regalo
                                $huevosRegalo = (float) collect($lotes)->sum('huevos_regalo_usados');
                                $color = $huevosRegalo > 0 ? 'text-green-600' : 'text-green-600';

                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold {$color}'>
                                        L " . number_format($costoPorCarton, 4) . "
                                    </div>
                                ");
                            }),

                        Forms\Components\Placeholder::make('costo_por_medio')
                            ->label('Costo por Medio (15)')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $costoTotal = (float) collect($lotes)->sum('costo_parcial');
                                $huevosTotal = (int) collect($lotes)->sum('cantidad_huevos');

                                if ($huevosTotal <= 0) {
                                    return new \Illuminate\Support\HtmlString("<div class='text-2xl font-bold text-gray-400'>L 0.00</div>");
                                }

                                $costoPorMedio = ($costoTotal / $huevosTotal) * 15;

                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold text-amber-600'>
                                        L " . number_format($costoPorMedio, 4) . "
                                    </div>
                                ");
                            }),
                    ]),
                ])
                ->visible(fn(Forms\Get $get) => !empty($get('lotes_seleccionados'))),

            // NOTAS
            Forms\Components\Textarea::make('nota')
                ->label('Notas (opcional)')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero_reempaque')
                    ->label('No. Reempaque')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state === 'individual' ? 'Individual' : 'Mezclado')
                    ->color(fn($state) => $state === 'individual' ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('total_huevos_usados')
                    ->label('Huevos')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('productos_generados')
                    ->label('Productos')
                    ->getStateUsing(function ($record) {
                        $items = [];
                        if ($record->cartones_30 > 0) $items[] = "{$record->cartones_30} C30";
                        if ($record->cartones_15 > 0) $items[] = "{$record->cartones_15} C15";
                        return implode(' + ', $items) ?: '-';
                    }),

                Tables\Columns\TextColumn::make('costo_total')
                    ->label('Costo')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => match ($state) {
                        'en_proceso' => 'En Proceso',
                        'completado' => 'Completado',
                        'cancelado' => 'Cancelado',
                        default => $state,
                    })
                    ->color(fn($state) => match ($state) {
                        'en_proceso' => 'warning',
                        'completado' => 'success',
                        'cancelado' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'en_proceso' => 'En Proceso',
                        'completado' => 'Completado',
                        'cancelado' => 'Cancelado',
                    ]),

                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReempaques::route('/'),
            'create' => Pages\CreateReempaque::route('/create'),
            'view' => Pages\ViewReempaque::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $currentUser = Auth::user();
        if (!$currentUser) return null;

        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        $query = static::getModel()::where('estado', 'en_proceso');

        if (!$esSuperAdminOJefe) {
            $bodegasUsuario = DB::table('bodega_user')
                ->where('user_id', $currentUser->id)
                ->where('activo', true)
                ->pluck('bodega_id')
                ->toArray();

            if (!empty($bodegasUsuario)) {
                $query->whereIn('bodega_id', $bodegasUsuario);
            }
        }

        $count = $query->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}