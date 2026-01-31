<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReempaqueResource\Pages;
use App\Models\Reempaque;
use App\Models\Lote;
use App\Models\Categoria;
use App\Models\Producto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

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

    /**
     * CALCULO CORRECTO DE COSTO UNITARIO
     *
     * REGLA DE NEGOCIO:
     * - El regalo es un "buffer" exclusivo para mermas
     * - Si NO hay merma → Costo = precio factura exacto
     * - Si merma <= regalo → Costo = precio factura (solo perdiste gratis)
     * - Si merma > regalo → Costo sube (perdiste huevos pagados)
     */
    public static function calcularCostoUnitario(
        float $costoTotalPagado,
        int $huevosFacturados,
        int $huevosRegalo,
        int $merma
    ): array {
        if ($huevosFacturados <= 0) {
            return [
                'costo_por_huevo' => 0,
                'costo_por_carton' => 0,
                'huevos_base' => 0,
                'tipo_calculo' => 'sin_facturados',
            ];
        }

        // REGLA CLAVE: Si merma <= regalo, el costo NO cambia
        // porque solo perdiste huevos gratis
        if ($merma <= $huevosRegalo) {
            $costoPorHuevo = $costoTotalPagado / $huevosFacturados;
            $costoPorCarton = $costoTotalPagado / ($huevosFacturados / 30);

            return [
                'costo_por_huevo' => round($costoPorHuevo, 4),
                'costo_por_carton' => round($costoPorCarton, 2),
                'huevos_base' => $huevosFacturados,
                'tipo_calculo' => 'sin_perdida',
            ];
        }

        // Si merma > regalo, perdiste huevos pagados
        $mermaPagada = $merma - $huevosRegalo;
        $huevosUtilesPagados = $huevosFacturados - $mermaPagada;

        if ($huevosUtilesPagados <= 0) {
            return [
                'costo_por_huevo' => 0,
                'costo_por_carton' => 0,
                'huevos_base' => 0,
                'tipo_calculo' => 'perdida_total',
            ];
        }

        $costoPorHuevo = $costoTotalPagado / $huevosUtilesPagados;
        $costoPorCarton = $costoPorHuevo * 30;

        return [
            'costo_por_huevo' => round($costoPorHuevo, 4),
            'costo_por_carton' => round($costoPorCarton, 2),
            'huevos_base' => $huevosUtilesPagados,
            'tipo_calculo' => 'con_perdida',
        ];
    }

    /**
     * NUEVA FUNCION: Calcular costo parcial de un lote
     * 
     * La lógica es simple:
     * - Siempre usas huevos al precio de factura
     * - El regalo NO afecta el costo, solo sirve como buffer de merma
     */
    public static function calcularCostoParcialLote(Lote $lote, int $huevosAUsar): array
    {
        $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');
        
        if ($esLoteSueltos) {
            // Lote consolidado de sueltos - usar costo promedio almacenado
            $costoParcial = ($lote->costo_por_huevo ?? 0) * $huevosAUsar;
            
            return [
                'costo_parcial' => round($costoParcial, 2),
                'huevos_facturados_usados' => $huevosAUsar,
                'huevos_regalo_disponibles' => 0,
                'costo_por_huevo' => $lote->costo_por_huevo ?? 0,
            ];
        }
        
        // Lote normal - calcular basado en precio de factura
        $costoTotalLote = (float) ($lote->costo_total_lote ?? 0);
        $cartonesFacturados = (int) ($lote->cantidad_cartones_facturados ?? 0);
        $cartonesRegalo = (int) ($lote->cantidad_cartones_regalo ?? 0);
        
        $huevosFacturadosLote = $cartonesFacturados * 30;
        $huevosRegaloLote = $cartonesRegalo * 30;
        
        // Costo por huevo basado SOLO en facturados
        $costoPorHuevoFacturado = $huevosFacturadosLote > 0 
            ? $costoTotalLote / $huevosFacturadosLote 
            : 0;
        
        // El costo parcial es simplemente: huevos × costo por huevo facturado
        // El regalo NO reduce el costo - solo sirve como buffer de merma
        $costoParcial = $huevosAUsar * $costoPorHuevoFacturado;
        
        return [
            'costo_parcial' => round($costoParcial, 2),
            'huevos_facturados_usados' => $huevosAUsar,
            'huevos_regalo_disponibles' => $huevosRegaloLote,
            'costo_por_huevo' => $costoPorHuevoFacturado,
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // INFORMACION GENERAL
            Forms\Components\Section::make('Informacion General')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
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
                            ->disabled(function (string $operation) {
                                if ($operation === 'edit') return true;
                                $currentUser = Auth::user();
                                if (!$currentUser) return true;

                                $esSuperAdminOJefe = DB::table('model_has_roles')
                                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                    ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                    ->where('model_has_roles.model_id', '=', $currentUser->id)
                                    ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                    ->exists();

                                return !$esSuperAdminOJefe;
                            })
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

                        Forms\Components\Placeholder::make('info')
                            ->label('')
                            ->content(function (Forms\Get $get) {
                                $tipo = $get('tipo');
                                if ($tipo === 'individual') {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-blue-50 dark:bg-blue-900/20 p-3'>
                                            <p class='text-sm text-blue-900 dark:text-blue-100'>
                                                <strong>Individual:</strong> Usa un solo lote. Puedes distribuir a multiples categorias.
                                            </p>
                                        </div>
                                    ");
                                } elseif ($tipo === 'mezclado') {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3'>
                                            <p class='text-sm text-amber-900 dark:text-amber-100'>
                                                <strong>Mezclado:</strong> Combina varios lotes. Distribuye a multiples categorias.
                                            </p>
                                        </div>
                                    ");
                                }
                                return '';
                            }),
                    ]),
                ]),

            // WIDGET DE SUELTOS DISPONIBLES
            Forms\Components\Section::make('Huevos Sueltos Disponibles en esta Bodega')
                ->description('Vista rapida de huevos sueltos listos para reempacar')
                ->schema([
                    Forms\Components\Placeholder::make('sueltos_info')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $bodegaId = $get('bodega_id');
                            if (!$bodegaId) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-gray-500 text-sm'>Selecciona una bodega para ver sueltos disponibles</div>
                                ");
                            }

                            $loteSueltos = Lote::where('bodega_id', $bodegaId)
                                ->where('numero_lote', "SUELTOS-B{$bodegaId}")
                                ->where('estado', 'disponible')
                                ->where('cantidad_huevos_remanente', '>', 0)
                                ->first();

                            $lotesConSueltos = Lote::where('bodega_id', $bodegaId)
                                ->where('estado', 'disponible')
                                ->where('cantidad_huevos_remanente', '>', 0)
                                ->where('cantidad_huevos_remanente', '<', 30)
                                ->where('numero_lote', 'NOT LIKE', 'SUELTOS-%')
                                ->orderBy('created_at', 'asc')
                                ->with(['producto', 'proveedor'])
                                ->get();

                            $totalSueltos = 0;
                            $html = "<div class='space-y-4'>";

                            if ($loteSueltos && $loteSueltos->cantidad_huevos_remanente > 0) {
                                $huevosCons = (int) $loteSueltos->cantidad_huevos_remanente;
                                $totalSueltos += $huevosCons;
                                $c30Cons = floor($huevosCons / 30);
                                $restoCons = $huevosCons % 30;
                                $c15Cons = floor($restoCons / 15);
                                $sueltosFinales = $restoCons % 15;

                                $costoTexto = $loteSueltos->costo_por_huevo > 0
                                    ? "L " . number_format($loteSueltos->costo_por_huevo, 2) . "/huevo"
                                    : "L 0.00/huevo (gratis)";

                                $html .= "
                                    <div class='rounded-lg bg-purple-50 dark:bg-purple-900/20 p-4 border-2 border-purple-300'>
                                        <div class='flex items-center justify-between'>
                                            <div>
                                                <p class='text-lg font-bold text-purple-900 dark:text-purple-100'>
                                                    Lote Consolidado: {$huevosCons} huevos
                                                </p>
                                                <p class='text-sm text-purple-700 dark:text-purple-300 mt-1'>
                                                    Equivalente a: {$c30Cons} C30 + {$c15Cons} C15 + {$sueltosFinales} sueltos
                                                </p>
                                                <p class='text-xs text-purple-600 dark:text-purple-400 mt-1'>
                                                    Costo: {$costoTexto}
                                                </p>
                                            </div>
                                            <div class='text-right'>
                                                <p class='text-3xl font-bold text-purple-600 dark:text-purple-400'>
                                                    {$huevosCons}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ";
                            }

                            if ($lotesConSueltos->isNotEmpty()) {
                                $totalLotesNormales = (int) $lotesConSueltos->sum('cantidad_huevos_remanente');
                                $totalSueltos += $totalLotesNormales;

                                $html .= "<p class='text-sm font-semibold text-gray-700 dark:text-gray-300 mt-4'>Lotes con residuos:</p>";
                                $html .= "<div class='grid grid-cols-1 md:grid-cols-2 gap-3'>";

                                foreach ($lotesConSueltos as $lote) {
                                    $huevos = (int) $lote->cantidad_huevos_remanente;
                                    $c15 = floor($huevos / 15);
                                    $resto = $huevos % 15;

                                    $html .= "
                                        <div class='rounded-lg border-2 border-gray-200 dark:border-gray-700 p-3'>
                                            <div class='flex items-start justify-between'>
                                                <div class='flex-1'>
                                                    <p class='font-semibold text-sm text-gray-900 dark:text-gray-100'>
                                                        {$lote->numero_lote}
                                                    </p>
                                                    <p class='text-xs text-gray-500 mt-1'>
                                                        " . ($lote->producto->nombre ?? 'Sin producto') . "
                                                    </p>
                                                </div>
                                                <div class='text-right'>
                                                    <p class='text-2xl font-bold text-blue-600 dark:text-blue-400'>
                                                        {$huevos}
                                                    </p>
                                                    <p class='text-xs text-gray-500'>huevos</p>
                                                </div>
                                            </div>
                                            <div class='mt-2 pt-2 border-t border-gray-200 dark:border-gray-700'>
                                                <p class='text-xs text-gray-600 dark:text-gray-400'>
                                                    = {$c15} medios + {$resto} sueltos
                                                </p>
                                                <p class='text-xs text-green-600 dark:text-green-400 mt-1'>
                                                    L " . number_format($lote->costo_por_huevo, 2) . "/huevo
                                                </p>
                                            </div>
                                        </div>
                                    ";
                                }

                                $html .= "</div>";
                            }

                            if ($totalSueltos == 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-4'>
                                        <p class='text-sm text-green-900 dark:text-green-100'>
                                            <strong>No hay huevos sueltos pendientes</strong><br>
                                            Todos los lotes estan completos o agotados.
                                        </p>
                                    </div>
                                ");
                            }

                            $c30Total = floor($totalSueltos / 30);
                            $restoTotal = $totalSueltos % 30;
                            $c15Total = floor($restoTotal / 15);
                            $sueltosTotal = $restoTotal % 15;

                            $html = "
                                <div class='rounded-lg bg-blue-50 dark:bg-blue-900/20 p-4 mb-4'>
                                    <div class='flex items-center justify-between'>
                                        <div>
                                            <p class='text-lg font-bold text-blue-900 dark:text-blue-100'>
                                                Total Disponible: {$totalSueltos} huevos
                                            </p>
                                            <p class='text-sm text-blue-700 dark:text-blue-300 mt-1'>
                                                Equivalente a: {$c30Total} C30 + {$c15Total} C15 + {$sueltosTotal} sueltos
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            " . $html;

                            $html .= "</div>";

                            return new \Illuminate\Support\HtmlString($html);
                        }),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('categoria_mezcla_sueltos')
                            ->label('Categoria para mezclar sueltos')
                            ->options(function () {
                                // Solo categorías que tengan unidades tipo cartón
                                return Categoria::where('activo', true)
                                    ->whereHas('unidades', function ($query) {
                                        $query->where(function ($q) {
                                            $q->where('nombre', 'LIKE', '%carton%')
                                              ->orWhere('nombre', 'LIKE', '%Carton%')
                                              ->orWhere('nombre', 'LIKE', '%30%')
                                              ->orWhere('nombre', 'LIKE', '%15%')
                                              ->orWhere('nombre', 'LIKE', '%medio%')
                                              ->orWhere('nombre', 'LIKE', '%Medio%');
                                        });
                                    })
                                    ->pluck('nombre', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->placeholder('Selecciona categoria...')
                            ->helperText('Selecciona la categoria antes de mezclar'),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('mezclar_sueltos')
                                ->label('Mezclar Todos los Sueltos')
                                ->color('warning')
                                ->size('lg')
                                ->action(function (Forms\Get $get, Forms\Set $set) {
                                    $bodegaId = $get('bodega_id');
                                    $categoriaId = $get('categoria_mezcla_sueltos');

                                    if (!$bodegaId) {
                                        Notification::make()
                                            ->danger()
                                            ->title('Error')
                                            ->body('Selecciona una bodega primero.')
                                            ->send();
                                        return;
                                    }

                                    if (!$categoriaId) {
                                        Notification::make()
                                            ->danger()
                                            ->title('Error')
                                            ->body('Selecciona la categoria para los productos resultantes.')
                                            ->send();
                                        return;
                                    }

                                    $lotesSueltos = Lote::where('bodega_id', $bodegaId)
                                        ->where('estado', 'disponible')
                                        ->where('cantidad_huevos_remanente', '>', 0)
                                        ->where(function ($query) use ($bodegaId) {
                                            $query->where('numero_lote', "SUELTOS-B{$bodegaId}")
                                                  ->orWhere('cantidad_huevos_remanente', '<', 30);
                                        })
                                        ->orderBy('costo_por_huevo', 'asc')
                                        ->get();

                                    if ($lotesSueltos->isEmpty()) {
                                        Notification::make()
                                            ->warning()
                                            ->title('Sin sueltos')
                                            ->body('No hay huevos sueltos disponibles para mezclar.')
                                            ->send();
                                        return;
                                    }

                                    $set('tipo', 'mezclado');

                                    $lotesSeleccionados = [];
                                    $totalHuevos = 0;

                                    foreach ($lotesSueltos as $lote) {
                                        $huevos = (int) $lote->cantidad_huevos_remanente;
                                        $totalHuevos += $huevos;

                                        // Usar la nueva función de cálculo
                                        $calculo = self::calcularCostoParcialLote($lote, $huevos);

                                        $lotesSeleccionados[] = [
                                            'lote_id' => $lote->id,
                                            'cantidad_c30' => 0,
                                            'cantidad_c15' => 0,
                                            'cantidad_huevos' => $huevos,
                                            'huevos_facturados_usados' => $calculo['huevos_facturados_usados'],
                                            'huevos_regalo_disponibles' => $calculo['huevos_regalo_disponibles'],
                                            'costo_unitario' => $calculo['costo_por_huevo'],
                                            'costo_parcial' => $calculo['costo_parcial'],
                                            'costo_por_carton_facturado' => $lote->costo_por_carton_facturado ?? ($calculo['costo_por_huevo'] * 30),
                                            'disponible_c30' => 0,
                                            'disponible_c15' => 0,
                                            'disponible_sueltos' => $huevos,
                                        ];
                                    }

                                    $set('lotes_seleccionados', $lotesSeleccionados);

                                    // Calcular distribucion automatica
                                    $c30 = floor($totalHuevos / 30);
                                    $resto = $totalHuevos - ($c30 * 30);
                                    $c15 = floor($resto / 15);
                                    $sueltos = $resto - ($c15 * 15);

                                    $set('merma', 0);

                                    // Crear distribuciones automaticas
                                    $distribuciones = [];
                                    if ($c30 > 0) {
                                        $distribuciones[] = [
                                            'categoria_id' => $categoriaId,
                                            'tipo_empaque' => 'carton_30',
                                            'cantidad' => $c30,
                                        ];
                                    }
                                    if ($c15 > 0) {
                                        $distribuciones[] = [
                                            'categoria_id' => $categoriaId,
                                            'tipo_empaque' => 'carton_15',
                                            'cantidad' => $c15,
                                        ];
                                    }
                                    $set('distribuciones', $distribuciones);
                                    $set('huevos_sueltos', $sueltos);

                                    Notification::make()
                                        ->success()
                                        ->title('Sueltos cargados')
                                        ->body("Se cargaron {$lotesSueltos->count()} lotes con {$totalHuevos} huevos.")
                                        ->duration(5000)
                                        ->send();
                                })
                                ->visible(function (Forms\Get $get) {
                                    $bodegaId = $get('bodega_id');
                                    if (!$bodegaId) return false;

                                    return Lote::where('bodega_id', $bodegaId)
                                        ->where('estado', 'disponible')
                                        ->where('cantidad_huevos_remanente', '>', 0)
                                        ->where(function ($query) use ($bodegaId) {
                                            $query->where('numero_lote', "SUELTOS-B{$bodegaId}")
                                                  ->orWhere('cantidad_huevos_remanente', '<', 30);
                                        })
                                        ->exists();
                                }),
                        ])->verticallyAlignEnd(),
                    ])->visible(function (Forms\Get $get) {
                        $bodegaId = $get('bodega_id');
                        if (!$bodegaId) return false;

                        return Lote::where('bodega_id', $bodegaId)
                            ->where('estado', 'disponible')
                            ->where('cantidad_huevos_remanente', '>', 0)
                            ->where(function ($query) use ($bodegaId) {
                                $query->where('numero_lote', "SUELTOS-B{$bodegaId}")
                                      ->orWhere('cantidad_huevos_remanente', '<', 30);
                            })
                            ->exists();
                    }),
                ])
                ->visible(fn(Forms\Get $get) => $get('bodega_id'))
                ->collapsible()
                ->collapsed(false),

            // SELECCION DE LOTES
            Forms\Components\Section::make('Seleccion de Lotes')
                ->description('Puedes usar lotes completos Y lotes con sueltos.')
                ->schema([
                    Forms\Components\Repeater::make('lotes_seleccionados')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('lote_id')
                                ->label('Selecciona el Lote')
                                ->options(function (Forms\Get $get) {
                                    $bodegaId = $get('../../bodega_id');
                                    if (!$bodegaId) return [];

                                    $lotes = Lote::where('bodega_id', $bodegaId)
                                        ->where('estado', 'disponible')
                                        ->where('cantidad_huevos_remanente', '>', 0)
                                        ->with(['producto', 'proveedor'])
                                        ->orderByRaw("
                                            CASE
                                                WHEN numero_lote LIKE 'SUELTOS-%' THEN 1
                                                ELSE 0
                                            END ASC
                                        ")
                                        ->orderBy('cantidad_huevos_remanente', 'asc')
                                        ->get();

                                    $opciones = [];

                                    foreach ($lotes as $lote) {
                                        $huevos = (int) $lote->cantidad_huevos_remanente;
                                        $c30Facturados = (int) ($lote->cantidad_cartones_facturados ?? 0);
                                        $c30Regalo = (int) ($lote->cantidad_cartones_regalo ?? 0);

                                        $c30Disp = floor($huevos / 30);
                                        $resto = $huevos % 30;
                                        $c15Disp = floor($resto / 15);
                                        $sueltosDisp = $resto % 15;

                                        $disponible = "";
                                        if ($c30Disp > 0) $disponible .= "{$c30Disp} C30";
                                        if ($c15Disp > 0) $disponible .= ($disponible ? " + " : "") . "{$c15Disp} C15";
                                        if ($sueltosDisp > 0) $disponible .= ($disponible ? " + " : "") . "{$sueltosDisp} sueltos";
                                        if (!$disponible) $disponible = "{$huevos} sueltos";

                                        $infoCompra = "";
                                        if ($c30Facturados > 0 || $c30Regalo > 0) {
                                            if ($c30Regalo > 0) {
                                                $infoCompra = " ({$c30Facturados} + {$c30Regalo} regalo)";
                                            }
                                        }

                                        $esLoteSueltos = str_starts_with($lote->numero_lote, 'SUELTOS-');
                                        $prefijo = $esLoteSueltos ? '[CONSOLIDADO] ' : '';

                                        // Mostrar costo por cartón de factura (sin afectar por regalo)
                                        $costoPorCarton = ($lote->costo_por_carton_facturado ?? 0) > 0
                                            ? $lote->costo_por_carton_facturado
                                            : ($lote->costo_por_huevo * 30);

                                        $opciones[$lote->id] = sprintf(
                                            '%s%s | %s | %s | %s%s | L%s/carton',
                                            $prefijo,
                                            $lote->numero_lote,
                                            $lote->proveedor->nombre ?? 'Sin proveedor',
                                            $lote->producto->nombre ?? 'Sin producto',
                                            $disponible,
                                            $infoCompra,
                                            number_format($costoPorCarton, 2)
                                        );
                                    }

                                    return $opciones;
                                })
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                    if (!$state) return;

                                    $lote = Lote::find($state);
                                    if (!$lote) return;

                                    $total = (int) $lote->cantidad_huevos_remanente;
                                    $c30 = floor($total / 30);
                                    $resto = $total % 30;
                                    $c15 = floor($resto / 15);
                                    $sueltos = $resto % 15;

                                    $set('disponible_c30', $c30);
                                    $set('disponible_c15', $c15);
                                    $set('disponible_sueltos', $sueltos);
                                    $set('huevos_totales_lote', $total);
                                    
                                    // Calcular costo por huevo de factura
                                    $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');
                                    if ($esLoteSueltos) {
                                        $costoPorHuevo = $lote->costo_por_huevo ?? 0;
                                    } else {
                                        $huevosFacturados = ($lote->cantidad_cartones_facturados ?? 0) * 30;
                                        $costoPorHuevo = $huevosFacturados > 0 
                                            ? ($lote->costo_total_lote ?? 0) / $huevosFacturados 
                                            : 0;
                                    }
                                    
                                    $set('costo_unitario', $costoPorHuevo);
                                    $set('costo_por_carton_facturado', $costoPorHuevo * 30);
                                    
                                    // Guardar info del regalo disponible en el lote
                                    $set('huevos_regalo_disponibles', ($lote->cantidad_cartones_regalo ?? 0) * 30);

                                    // Iniciar en 0 para que el usuario defina cuánto sacar
                                    $set('cantidad_c30', 0);
                                    $set('cantidad_c15', 0);
                                    $set('cantidad_huevos', 0);
                                    $set('costo_parcial', 0);
                                    $set('huevos_facturados_usados', 0);
                                })
                                ->disabled(fn(string $operation) => $operation === 'edit')
                                ->dehydrated()
                                ->columnSpanFull(),

                            Forms\Components\Grid::make(3)->schema([
                                Forms\Components\Group::make()->schema([
                                    Forms\Components\Placeholder::make('info_c30')
                                        ->label('Cartones de 30 Disponibles')
                                        ->content(fn(Forms\Get $get) => new \Illuminate\Support\HtmlString("
                                            <div class='text-center'>
                                                <div class='text-4xl font-bold text-blue-600 dark:text-blue-400'>
                                                    " . (int)($get('disponible_c30') ?? 0) . "
                                                </div>
                                                <div class='text-xs text-gray-500 mt-1'>disponibles</div>
                                            </div>
                                        ")),

                                    Forms\Components\TextInput::make('cantidad_c30')
                                        ->label('Usar Cartones de 30')
                                        ->required()
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->maxValue(fn(Forms\Get $get) => $get('disponible_c30') ?? 0)
                                        ->step(1)
                                        ->suffix('cartones')
                                        ->live(debounce: 500)
                                        ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                            $c30 = (int)($state ?? 0);
                                            $c15 = (int)($get('cantidad_c15') ?? 0);
                                            $maxC30 = (int)($get('disponible_c30') ?? 0);

                                            if ($c30 > $maxC30) {
                                                $c30 = $maxC30;
                                                $set('cantidad_c30', $c30);
                                            }

                                            $huevos = ($c30 * 30) + ($c15 * 15);
                                            $set('cantidad_huevos', $huevos);

                                            // LOGICA CORREGIDA: Costo = huevos × costo por huevo facturado
                                            // El regalo NO afecta el costo
                                            $costoPorHuevo = (float)($get('costo_unitario') ?? 0);
                                            $costoParcial = $huevos * $costoPorHuevo;
                                            
                                            $set('costo_parcial', round($costoParcial, 2));
                                            $set('huevos_facturados_usados', $huevos);
                                        })
                                        ->helperText('Solo cartones completos')
                                        ->disabled(fn(string $operation) => $operation === 'edit')
                                        ->dehydrated(),
                                ]),

                                Forms\Components\Group::make()->schema([
                                    Forms\Components\Placeholder::make('info_c15')
                                        ->label('Medios de 15 Disponibles')
                                        ->content(fn(Forms\Get $get) => new \Illuminate\Support\HtmlString("
                                            <div class='text-center'>
                                                <div class='text-4xl font-bold text-green-600 dark:text-green-400'>
                                                    " . (int)($get('disponible_c15') ?? 0) . "
                                                </div>
                                                <div class='text-xs text-gray-500 mt-1'>disponibles</div>
                                            </div>
                                        ")),

                                    Forms\Components\TextInput::make('cantidad_c15')
                                        ->label('Usar Medios de 15')
                                        ->required()
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->maxValue(fn(Forms\Get $get) => $get('disponible_c15') ?? 0)
                                        ->step(1)
                                        ->suffix('cartones')
                                        ->live(debounce: 500)
                                        ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                            $c30 = (int)($get('cantidad_c30') ?? 0);
                                            $c15 = (int)($state ?? 0);
                                            $maxC15 = (int)($get('disponible_c15') ?? 0);

                                            if ($c15 > $maxC15) {
                                                $c15 = $maxC15;
                                                $set('cantidad_c15', $c15);
                                            }

                                            $huevos = ($c30 * 30) + ($c15 * 15);
                                            $set('cantidad_huevos', $huevos);

                                            // LOGICA CORREGIDA: Costo = huevos × costo por huevo facturado
                                            $costoPorHuevo = (float)($get('costo_unitario') ?? 0);
                                            $costoParcial = $huevos * $costoPorHuevo;
                                            
                                            $set('costo_parcial', round($costoParcial, 2));
                                            $set('huevos_facturados_usados', $huevos);
                                        })
                                        ->helperText('Solo medios cartones')
                                        ->disabled(fn(string $operation) => $operation === 'edit')
                                        ->dehydrated(),
                                ]),

                                Forms\Components\Group::make()->schema([
                                    Forms\Components\Placeholder::make('info_sueltos')
                                        ->label('Huevos Sueltos')
                                        ->content(function (Forms\Get $get) {
                                            $sueltos = (int)($get('disponible_sueltos') ?? 0);
                                            if ($sueltos > 0) {
                                                return new \Illuminate\Support\HtmlString("
                                                    <div class='text-center'>
                                                        <div class='text-4xl font-bold text-amber-500'>
                                                            {$sueltos}
                                                        </div>
                                                        <div class='text-xs text-gray-500 mt-1'>quedaran en lote</div>
                                                    </div>
                                                ");
                                            }
                                            return new \Illuminate\Support\HtmlString("
                                                <div class='text-center'>
                                                    <div class='text-4xl font-bold text-green-600'>0</div>
                                                    <div class='text-xs text-green-600 mt-1'>sin sueltos</div>
                                                </div>
                                            ");
                                        }),
                                ]),
                            ]),

                            Forms\Components\Grid::make(3)->schema([
                                Forms\Components\TextInput::make('cantidad_huevos')
                                    ->label('Total Huevos a Usar')
                                    ->disabled()
                                    ->dehydrated()
                                    ->suffix('huevos')
                                    ->extraAttributes(['class' => 'font-bold text-lg']),

                                Forms\Components\TextInput::make('costo_por_carton_facturado')
                                    ->label('Costo/Carton (Factura)')
                                    ->disabled()
                                    ->dehydrated()
                                    ->prefix('L')
                                    ->formatStateUsing(fn($state) => number_format($state ?? 0, 2))
                                    ->helperText('Precio original de compra'),

                                Forms\Components\TextInput::make('costo_parcial')
                                    ->label('Costo Total')
                                    ->disabled()
                                    ->dehydrated()
                                    ->prefix('L')
                                    ->formatStateUsing(fn($state) => number_format($state ?? 0, 2))
                                    ->extraAttributes(['class' => 'font-bold text-lg']),
                            ]),

                            Forms\Components\Hidden::make('disponible_c30')->dehydrated(false),
                            Forms\Components\Hidden::make('disponible_c15')->dehydrated(false),
                            Forms\Components\Hidden::make('disponible_sueltos')->dehydrated(false),
                            Forms\Components\Hidden::make('huevos_totales_lote')->dehydrated(false),
                            Forms\Components\Hidden::make('huevos_facturados_usados')->dehydrated(),
                            Forms\Components\Hidden::make('huevos_regalo_disponibles')->dehydrated(),
                            Forms\Components\Hidden::make('costo_unitario')->dehydrated(),
                        ])
                        ->columns(1)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(fn(Forms\Get $get) => $get('tipo') === 'individual' ? 1 : 20)
                        ->addActionLabel('+ Agregar Otro Lote')
                        ->reorderable(false)
                        ->collapsible()
                        ->collapsed(false)
                        ->itemLabel(fn(array $state): ?string =>
                            isset($state['lote_id']) ? Lote::find($state['lote_id'])?->numero_lote : 'Nuevo lote'
                        )
                        ->live()
                        ->disabled(fn(string $operation) => $operation === 'edit')
                        ->dehydrated(),
                ])
                ->visible(fn(Forms\Get $get) => $get('bodega_id') && $get('tipo'))
                ->collapsible()
                ->collapsed(false),

            // PROCESO DE REEMPAQUE - MERMA
            Forms\Components\Section::make('Proceso de Reempaque')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Placeholder::make('total_usados')
                            ->label('Total Huevos a Usar')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $total = (int) collect($lotes)->sum('cantidad_huevos');
                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold text-blue-600'>
                                        " . number_format($total, 0) . " huevos
                                    </div>
                                ");
                            }),

                        Forms\Components\TextInput::make('merma')
                            ->label('Merma (Huevos Rotos)')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->suffix('huevos')
                            ->helperText('Huevos rotos o perdidos')
                            ->disabled(fn(string $operation) => $operation === 'edit')
                            ->dehydrated(),

                        Forms\Components\Placeholder::make('utiles')
                            ->label('Huevos Utiles (Disponibles para distribuir)')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $total = (int) collect($lotes)->sum('cantidad_huevos');
                                $merma = (int)($get('merma') ?? 0);
                                $utiles = $total - $merma;
                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold text-green-600'>
                                        " . number_format($utiles, 0) . " huevos
                                    </div>
                                ");
                            }),
                    ]),
                ])
                ->visible(fn(Forms\Get $get) => !empty($get('lotes_seleccionados'))),

            // =====================================================
            // DISTRIBUCION DE HUEVOS
            // =====================================================
            Forms\Components\Section::make('Distribucion de Huevos')
                ->description('')
                ->schema([
                    // HEADER INFORMATIVO
                    Forms\Components\Placeholder::make('distribucion_info_header')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $lotes = $get('lotes_seleccionados') ?? [];
                            $total = (int) collect($lotes)->sum('cantidad_huevos');
                            $merma = (int)($get('merma') ?? 0);
                            $disponibles = $total - $merma;

                            if ($disponibles <= 0) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-xl bg-gray-100 dark:bg-gray-800 p-4 text-center'>
                                        <p class='text-gray-500'>Selecciona lotes para comenzar a distribuir</p>
                                    </div>
                                ");
                            }

                            $maxC30 = floor($disponibles / 30);
                            $maxC15 = floor($disponibles / 15);

                            return new \Illuminate\Support\HtmlString("
                                <div class='rounded-xl bg-gradient-to-r from-blue-500 to-blue-600 p-5 text-white shadow-lg'>
                                    <div class='flex items-center gap-3 mb-3'>
                                        <span class='text-3xl'>🥚</span>
                                        <div>
                                            <p class='text-blue-100 text-sm'>Huevos disponibles para distribuir</p>
                                            <p class='text-3xl font-bold'>" . number_format($disponibles, 0) . " huevos</p>
                                        </div>
                                    </div>
                                    <div class='border-t border-blue-400/30 pt-3 mt-3'>
                                        <p class='text-blue-100 text-sm mb-1'>Puedes hacer:</p>
                                        <p class='font-semibold'>
                                            <span class='bg-blue-400/30 px-2 py-1 rounded'>{$maxC30} cartones (C30)</span>
                                            <span class='mx-2'>o</span>
                                            <span class='bg-blue-400/30 px-2 py-1 rounded'>{$maxC15} medios (C15)</span>
                                            <span class='mx-2'>o combinaciones</span>
                                        </p>
                                    </div>
                                </div>
                            ");
                        }),

                    // BARRA DE PROGRESO
                    Forms\Components\Placeholder::make('distribucion_progreso')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $lotes = $get('lotes_seleccionados') ?? [];
                            $total = (int) collect($lotes)->sum('cantidad_huevos');
                            $merma = (int)($get('merma') ?? 0);
                            $disponibles = $total - $merma;

                            if ($disponibles <= 0) {
                                return '';
                            }

                            $distribuciones = $get('distribuciones') ?? [];
                            $asignados = 0;
                            $c30Total = 0;
                            $c15Total = 0;
                            foreach ($distribuciones as $dist) {
                                $cantidad = (int)($dist['cantidad'] ?? 0);
                                $tipo = $dist['tipo_empaque'] ?? 'carton_30';
                                if ($tipo === 'carton_30') {
                                    $c30Total += $cantidad;
                                    $asignados += $cantidad * 30;
                                } else {
                                    $c15Total += $cantidad;
                                    $asignados += $cantidad * 15;
                                }
                            }

                            $sueltos = (int)($get('huevos_sueltos') ?? 0);
                            $totalAsignado = $asignados + $sueltos;
                            $pendientes = $disponibles - $totalAsignado;

                            $porcentaje = $disponibles > 0 ? min(100, ($totalAsignado / $disponibles) * 100) : 0;

                            if ($pendientes === 0) {
                                $bgCard = 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800';
                                $barColor = 'bg-green-500';
                                $iconEstado = '✓';
                                $textoEstado = 'Distribucion completa';
                                $colorEstado = 'text-green-600 dark:text-green-400';
                            } elseif ($pendientes > 0) {
                                $bgCard = 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800';
                                $barColor = 'bg-amber-500';
                                $iconEstado = '⚠';
                                $textoEstado = "Faltan {$pendientes} huevos por asignar";
                                $colorEstado = 'text-amber-600 dark:text-amber-400';
                            } else {
                                $bgCard = 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800';
                                $barColor = 'bg-red-500';
                                $iconEstado = '✗';
                                $exceso = abs($pendientes);
                                $textoEstado = "Exceso de {$exceso} huevos";
                                $colorEstado = 'text-red-600 dark:text-red-400';
                            }

                            $sugerencia = '';
                            if ($pendientes > 0) {
                                $sugerenciaC30 = floor($pendientes / 30);
                                $sugerenciaC15 = floor($pendientes / 15);
                                $sugerenciaResto = $pendientes % 15;

                                if ($sugerenciaC30 > 0 || $sugerenciaC15 > 0) {
                                    $sugerencia = "<div class='mt-3 p-3 bg-white/50 dark:bg-gray-800/50 rounded-lg'>
                                        <p class='text-sm font-medium text-gray-700 dark:text-gray-300'>💡 Sugerencia:</p>
                                        <p class='text-sm text-gray-600 dark:text-gray-400'>
                                            Agrega <strong>{$sugerenciaC30} C30</strong> o <strong>{$sugerenciaC15} C15</strong>";
                                    if ($sugerenciaResto > 0) {
                                        $sugerencia .= " + <strong>{$sugerenciaResto} sueltos</strong>";
                                    }
                                    $sugerencia .= " para completar</p></div>";
                                } elseif ($pendientes < 30) {
                                    $sugerencia = "<div class='mt-3 p-3 bg-white/50 dark:bg-gray-800/50 rounded-lg'>
                                        <p class='text-sm font-medium text-gray-700 dark:text-gray-300'>💡 Sugerencia:</p>
                                        <p class='text-sm text-gray-600 dark:text-gray-400'>
                                            Agrega <strong>{$pendientes} huevos sueltos</strong> para completar
                                        </p>
                                    </div>";
                                }
                            }

                            return new \Illuminate\Support\HtmlString("
                                <div class='rounded-xl border-2 {$bgCard} p-4 mt-4'>
                                    <div class='mb-4'>
                                        <div class='flex justify-between text-sm mb-1'>
                                            <span class='font-medium text-gray-700 dark:text-gray-300'>Progreso de distribucion</span>
                                            <span class='font-bold {$colorEstado}'>" . number_format($porcentaje, 0) . "%</span>
                                        </div>
                                        <div class='w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden'>
                                            <div class='{$barColor} h-4 rounded-full transition-all duration-500' style='width: {$porcentaje}%'></div>
                                        </div>
                                    </div>

                                    <div class='grid grid-cols-4 gap-3 text-center'>
                                        <div class='bg-white/60 dark:bg-gray-800/60 rounded-lg p-3'>
                                            <p class='text-xs text-gray-500 dark:text-gray-400'>Asignados</p>
                                            <p class='text-xl font-bold text-green-600 dark:text-green-400'>" . number_format($asignados, 0) . "</p>
                                            <p class='text-xs text-gray-400'>{$c30Total} C30 + {$c15Total} C15</p>
                                        </div>
                                        <div class='bg-white/60 dark:bg-gray-800/60 rounded-lg p-3'>
                                            <p class='text-xs text-gray-500 dark:text-gray-400'>Sueltos</p>
                                            <p class='text-xl font-bold text-purple-600 dark:text-purple-400'>{$sueltos}</p>
                                            <p class='text-xs text-gray-400'>huevos</p>
                                        </div>
                                        <div class='bg-white/60 dark:bg-gray-800/60 rounded-lg p-3'>
                                            <p class='text-xs text-gray-500 dark:text-gray-400'>Pendientes</p>
                                            <p class='text-xl font-bold {$colorEstado}'>" . number_format(max(0, $pendientes), 0) . "</p>
                                            <p class='text-xs text-gray-400'>por asignar</p>
                                        </div>
                                        <div class='bg-white/60 dark:bg-gray-800/60 rounded-lg p-3'>
                                            <p class='text-xs text-gray-500 dark:text-gray-400'>Estado</p>
                                            <p class='text-xl font-bold {$colorEstado}'>{$iconEstado}</p>
                                            <p class='text-xs {$colorEstado}'>" . ($pendientes === 0 ? 'Listo' : ($pendientes > 0 ? 'Pendiente' : 'Error')) . "</p>
                                        </div>
                                    </div>

                                    <div class='mt-3 text-center'>
                                        <p class='font-semibold {$colorEstado}'>{$iconEstado} {$textoEstado}</p>
                                    </div>

                                    {$sugerencia}
                                </div>
                            ");
                        }),

                    // LINEAS DE DISTRIBUCION
                    Forms\Components\Placeholder::make('distribucion_titulo_lineas')
                        ->label('')
                        ->content(new \Illuminate\Support\HtmlString("
                            <div class='flex items-center gap-2 mt-6 mb-2'>
                                <span class='text-lg'>📦</span>
                                <p class='font-semibold text-gray-700 dark:text-gray-300'>Lineas de Distribucion</p>
                            </div>
                        ")),

                    Forms\Components\Repeater::make('distribuciones')
                        ->label('')
                        ->schema([
                            Forms\Components\Grid::make(12)->schema([
                                Forms\Components\Select::make('categoria_id')
                                    ->label('Categoria Destino')
                                    ->options(function () {
                                        return Categoria::where('activo', true)
                                            ->whereHas('unidades', function ($query) {
                                                $query->where(function ($q) {
                                                    $q->where('nombre', 'LIKE', '%carton%')
                                                      ->orWhere('nombre', 'LIKE', '%Carton%')
                                                      ->orWhere('nombre', 'LIKE', '%30%')
                                                      ->orWhere('nombre', 'LIKE', '%15%')
                                                      ->orWhere('nombre', 'LIKE', '%medio%')
                                                      ->orWhere('nombre', 'LIKE', '%Medio%');
                                                });
                                            })
                                            ->pluck('nombre', 'id');
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->columnSpan(4),

                                Forms\Components\Select::make('tipo_empaque')
                                    ->label('Tipo de Empaque')
                                    ->options([
                                        'carton_30' => '📦 Carton (30 huevos)',
                                        'carton_15' => '📦 Medio (15 huevos)',
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('cantidad')
                                    ->label('Cantidad')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->columnSpan(2),

                                Forms\Components\Placeholder::make('total_huevos_linea_display')
                                    ->label('Total')
                                    ->content(function (Forms\Get $get) {
                                        $cantidad = (int)($get('cantidad') ?? 0);
                                        $tipo = $get('tipo_empaque') ?? 'carton_30';
                                        $multiplicador = $tipo === 'carton_30' ? 30 : 15;
                                        $total = $cantidad * $multiplicador;

                                        if ($total > 0) {
                                            return new \Illuminate\Support\HtmlString("
                                                <div class='text-center'>
                                                    <p class='text-xl font-bold text-green-600 dark:text-green-400'>" . number_format($total, 0) . "</p>
                                                    <p class='text-xs text-gray-500'>huevos</p>
                                                </div>
                                            ");
                                        }
                                        return new \Illuminate\Support\HtmlString("
                                            <div class='text-center'>
                                                <p class='text-xl font-bold text-gray-400'>0</p>
                                                <p class='text-xs text-gray-400'>huevos</p>
                                            </div>
                                        ");
                                    })
                                    ->columnSpan(3),
                            ]),
                        ])
                        ->columns(1)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(20)
                        ->addActionLabel('+ Agregar Linea de Distribucion')
                        ->reorderable(false)
                        ->collapsible()
                        ->collapsed(false)
                        ->itemLabel(function (array $state): ?string {
                            $categoriaId = $state['categoria_id'] ?? null;
                            $tipo = $state['tipo_empaque'] ?? 'carton_30';
                            $cantidad = (int)($state['cantidad'] ?? 0);

                            if (!$categoriaId) return '📝 Nueva linea';

                            $categoria = Categoria::find($categoriaId);
                            $tipoLabel = $tipo === 'carton_30' ? 'C30' : 'C15';
                            $huevos = $cantidad * ($tipo === 'carton_30' ? 30 : 15);

                            return "📦 " . ($categoria?->nombre ?? 'Sin categoria') . " - {$cantidad} {$tipoLabel} ({$huevos} huevos)";
                        })
                        ->live()
                        ->disabled(fn(string $operation) => $operation === 'edit')
                        ->dehydrated(),

                    // HUEVOS SUELTOS
                    Forms\Components\Placeholder::make('sueltos_titulo')
                        ->label('')
                        ->content(new \Illuminate\Support\HtmlString("
                            <div class='flex items-center gap-2 mt-4 mb-2'>
                                <span class='text-lg'>🥚</span>
                                <p class='font-semibold text-gray-700 dark:text-gray-300'>Huevos Sueltos (Remanente)</p>
                            </div>
                        "))
                        ->visible(function (Forms\Get $get) {
                            $lotes = $get('lotes_seleccionados') ?? [];
                            $total = (int) collect($lotes)->sum('cantidad_huevos');
                            return $total > 0;
                        }),

                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('huevos_sueltos')
                            ->label('')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(29)
                            ->suffix('huevos')
                            ->placeholder('0')
                            ->live(onBlur: true)
                            ->disabled(fn(string $operation) => $operation === 'edit')
                            ->dehydrated(),

                        Forms\Components\Placeholder::make('sueltos_info')
                            ->label('')
                            ->content(new \Illuminate\Support\HtmlString("
                                <div class='text-sm text-gray-500 dark:text-gray-400 pt-2'>
                                    <p>Maximo 29 huevos</p>
                                    <p class='text-xs'>Se consolidaran en el lote de sueltos de la bodega</p>
                                </div>
                            "))
                            ->columnSpan(2),
                    ])->visible(function (Forms\Get $get) {
                        $lotes = $get('lotes_seleccionados') ?? [];
                        $total = (int) collect($lotes)->sum('cantidad_huevos');
                        return $total > 0;
                    }),

                    // NOTA DE SUELTOS
                    Forms\Components\Placeholder::make('info_sueltos_nota')
                        ->label('')
                        ->content(function (Forms\Get $get) {
                            $sueltos = (int)($get('huevos_sueltos') ?? 0);
                            if ($sueltos <= 0) return '';

                            $lotes = $get('lotes_seleccionados') ?? [];
                            $merma = (int)($get('merma') ?? 0);

                            $huevosRegaloTotales = 0;
                            foreach ($lotes as $loteData) {
                                $huevosRegaloTotales += (int)($loteData['huevos_regalo_disponibles'] ?? 0);
                            }

                            if ($merma <= $huevosRegaloTotales) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-3 mt-2'>
                                        <p class='text-sm text-green-900 dark:text-green-100'>
                                            <strong>💰 Costo de sueltos:</strong> Se guardaran al costo de factura (sin afectar por regalo)
                                        </p>
                                    </div>
                                ");
                            } else {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='rounded-lg bg-amber-50 dark:bg-amber-900/20 p-3 mt-2'>
                                        <p class='text-sm text-amber-900 dark:text-amber-100'>
                                            <strong>💰 Costo de sueltos:</strong> Se guardaran con costo recalculado (huevos pagados perdidos)
                                        </p>
                                    </div>
                                ");
                            }
                        })
                        ->visible(fn(Forms\Get $get) => ($get('huevos_sueltos') ?? 0) > 0),
                ])
                ->visible(fn(Forms\Get $get) => !empty($get('lotes_seleccionados')))
                ->collapsible()
                ->collapsed(false),

            // RESUMEN DE COSTOS - LOGICA CORREGIDA
            Forms\Components\Section::make('Resumen de Costos')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Placeholder::make('costo_total')
                            ->label('Costo Total (Lo que pagaste)')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];

                                $costoTotal = 0;
                                $huevosUsados = 0;

                                foreach ($lotes as $loteData) {
                                    $costoTotal += (float)($loteData['costo_parcial'] ?? 0);
                                    $huevosUsados += (int)($loteData['cantidad_huevos'] ?? 0);
                                }

                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold text-blue-600'>
                                        L " . number_format($costoTotal, 2) . "
                                    </div>
                                    <div class='text-xs text-gray-500 mt-1'>
                                        / " . number_format($huevosUsados, 0) . " huevos usados
                                    </div>
                                ");
                            }),

                        Forms\Components\Placeholder::make('costo_promedio')
                            ->label('Costo por Carton Resultante')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $merma = (int)($get('merma') ?? 0);

                                $costoTotal = 0;
                                $huevosUsados = 0;
                                $huevosRegaloTotales = 0;

                                foreach ($lotes as $loteData) {
                                    $costoTotal += (float)($loteData['costo_parcial'] ?? 0);
                                    $huevosUsados += (int)($loteData['cantidad_huevos'] ?? 0);
                                    $huevosRegaloTotales += (int)($loteData['huevos_regalo_disponibles'] ?? 0);
                                }

                                if ($huevosUsados <= 0) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='text-2xl font-bold text-gray-400'>L 0.00</div>
                                        <div class='text-xs text-gray-500 mt-1'>Sin huevos seleccionados</div>
                                    ");
                                }

                                // LOGICA CORREGIDA:
                                // - Si merma = 0 → Costo = precio factura exacto
                                // - Si merma <= regalo → Costo = precio factura (solo perdiste gratis)
                                // - Si merma > regalo → Costo sube (perdiste huevos pagados)
                                
                                if ($merma <= $huevosRegaloTotales) {
                                    // Sin pérdida económica - costo = precio factura
                                    $costoPorHuevo = $costoTotal / $huevosUsados;
                                    $costoPorCarton = $costoPorHuevo * 30;
                                    $tipoCalculo = 'sin_perdida';
                                    $mensaje = 'Precio de factura';
                                    $colorClass = 'text-green-600';
                                } else {
                                    // Pérdida económica - costo sube
                                    $mermaPagada = $merma - $huevosRegaloTotales;
                                    $huevosUtiles = $huevosUsados - $mermaPagada;
                                    
                                    if ($huevosUtiles <= 0) {
                                        return new \Illuminate\Support\HtmlString("
                                            <div class='text-2xl font-bold text-red-600'>ERROR</div>
                                            <div class='text-xs text-red-500 mt-1'>Pérdida total</div>
                                        ");
                                    }
                                    
                                    $costoPorHuevo = $costoTotal / $huevosUtiles;
                                    $costoPorCarton = $costoPorHuevo * 30;
                                    $tipoCalculo = 'con_perdida';
                                    $mensaje = 'Ajustado por pérdida';
                                    $colorClass = 'text-amber-600';
                                }

                                return new \Illuminate\Support\HtmlString("
                                    <div class='text-2xl font-bold {$colorClass}'>
                                        L " . number_format($costoPorCarton, 2) . "
                                    </div>
                                    <div class='text-xs text-gray-500 mt-1'>{$mensaje}</div>
                                ");
                            }),

                        Forms\Components\Placeholder::make('info_merma')
                            ->label('Analisis de Merma')
                            ->content(function (Forms\Get $get) {
                                $lotes = $get('lotes_seleccionados') ?? [];
                                $merma = (int)($get('merma') ?? 0);

                                $huevosRegaloTotales = 0;
                                foreach ($lotes as $loteData) {
                                    $huevosRegaloTotales += (int)($loteData['huevos_regalo_disponibles'] ?? 0);
                                }

                                if ($merma == 0) {
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-3'>
                                            <p class='text-sm font-bold text-green-700 dark:text-green-300'>
                                                Sin merma
                                            </p>
                                            <p class='text-xs text-green-600 dark:text-green-400 mt-1'>
                                                Costo = precio factura exacto<br>
                                                Regalo disponible: {$huevosRegaloTotales} huevos<br>
                                                <em>(buffer intacto para futuras mermas)</em>
                                            </p>
                                        </div>
                                    ");
                                } elseif ($merma <= $huevosRegaloTotales) {
                                    $regaloRestante = $huevosRegaloTotales - $merma;
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-green-50 dark:bg-green-900/20 p-3'>
                                            <p class='text-sm font-bold text-green-700 dark:text-green-300'>
                                                Sin perdida economica
                                            </p>
                                            <p class='text-xs text-green-600 dark:text-green-400 mt-1'>
                                                Merma: {$merma} huevos<br>
                                                Regalo usado: {$merma} huevos<br>
                                                Solo perdiste huevos gratis
                                            </p>
                                        </div>
                                    ");
                                } else {
                                    $perdidaPagada = $merma - $huevosRegaloTotales;
                                    return new \Illuminate\Support\HtmlString("
                                        <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-4'>
                                            <p class='text-sm font-bold text-red-700 dark:text-red-300'>
                                                ALERTA: Perdida economica
                                            </p>
                                            <p class='text-xs text-red-600 dark:text-red-400 mt-1'>
                                                Merma total: {$merma} huevos<br>
                                                Regalo usado: {$huevosRegaloTotales} huevos<br>
                                                <strong>Huevos PAGADOS perdidos: {$perdidaPagada}</strong>
                                            </p>
                                        </div>
                                    ");
                                }
                            }),
                    ]),
                ])
                ->visible(fn(Forms\Get $get) => !empty($get('lotes_seleccionados'))),

            // NOTAS
            Forms\Components\Section::make('Notas')
                ->schema([
                    Forms\Components\Textarea::make('nota')
                        ->label('Nota (opcional)')
                        ->placeholder('Detalles sobre el reempaque...')
                        ->rows(3),
                ])
                ->collapsible()
                ->collapsed(),
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
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state === 'individual' ? 'Individual' : 'Mezclado')
                    ->color(fn($state) => $state === 'individual' ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('total_huevos_usados')
                    ->label('Huevos Usados')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' huevos')
                    ->sortable(),

                Tables\Columns\TextColumn::make('merma')
                    ->label('Merma')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' huevos')
                    ->color('danger')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('huevos_utiles')
                    ->label('Huevos Utiles')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' huevos')
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('productos_generados')
                    ->label('Productos')
                    ->getStateUsing(function ($record) {
                        $items = [];
                        if ($record->cartones_30 > 0) $items[] = "{$record->cartones_30} C30";
                        if ($record->cartones_15 > 0) $items[] = "{$record->cartones_15} C15";
                        if ($record->huevos_sueltos > 0) $items[] = "{$record->huevos_sueltos} sueltos";
                        return implode(' | ', $items);
                    })
                    ->sortable(false),

                Tables\Columns\TextColumn::make('costo_unitario_promedio')
                    ->label('Costo/Huevo')
                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                    ->sortable()
                    ->toggleable(),

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
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'en_proceso' => 'En Proceso',
                        'completado' => 'Completado',
                        'cancelado' => 'Cancelado',
                    ]),

                Tables\Filters\SelectFilter::make('tipo')
                    ->options([
                        'individual' => 'Individual',
                        'mezclado' => 'Mezclado',
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