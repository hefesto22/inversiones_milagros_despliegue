<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Enums\CompraEstado;
use App\Filament\Resources\CompraResource;
use App\Services\Compra\CompraStateManager;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Lote;
use App\Models\BodegaProducto;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;

class ViewCompra extends ViewRecord
{
    protected static string $resource = CompraResource::class;

    /**
     * Relaciones que se cargan siempre con el record.
     * Previene LazyLoadingViolationException en Laravel 12.
     */
    private const EAGER_RELATIONS = [
        'detalles.producto',
        'detalles.unidad',
        'proveedor',
        'bodega',
    ];

    /**
     * Eager load en mount inicial (GET request).
     */
    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return \App\Models\Compra::with(self::EAGER_RELATIONS)->findOrFail($key);
    }

    /**
     * Eager load en cada request subsiguiente (POST/Livewire).
     * Livewire rehidrata el model como Compra::find($id) sin relaciones.
     * Este hook corre después de la hidratación, antes de cualquier acción.
     */
    public function hydrate(): void
    {
        if ($this->record) {
            $this->record->loadMissing(self::EAGER_RELATIONS);
        }
    }

    /**
     * Recarga el record con lock pesimista + eager loading.
     * Usar dentro de DB::transaction() para garantizar atomicidad.
     */
    protected function lockAndReloadRecord(): void
    {
        \App\Models\Compra::where('id', $this->record->id)->lockForUpdate()->first();
        $this->record = \App\Models\Compra::with(self::EAGER_RELATIONS)->find($this->record->id);
    }

    /**
     * Después de refrescar datos, recargar relaciones que parent::refreshFormData() pudo limpiar.
     */
    public function refreshFormData(array $attributes): void
    {
        parent::refreshFormData($attributes);
        $this->record->load(self::EAGER_RELATIONS);
    }

    /**
     * Verificar si una unidad es de tipo carton (para huevos)
     */
    protected function esUnidadCarton(?int $unidadId): bool
    {
        if (!$unidadId) {
            return false;
        }

        $unidad = \App\Models\Unidad::find($unidadId);

        if (!$unidad) {
            return false;
        }

        $nombreLower = strtolower($unidad->nombre);

        return str_contains($nombreLower, 'carton')
            || str_contains($nombreLower, 'cartón')
            || str_contains($nombreLower, '1x30')
            || str_contains($nombreLower, '30');
    }

    /**
     * Validar detalles antes de recibir la compra
     */
    protected function validarDetallesAntesDeRecibir(): void
    {
        foreach ($this->record->detalles as $detalle) {
            $cantidadFacturada = $detalle->cantidad_facturada ?? $detalle->cantidad ?? 0;
            $cantidadRegalo = $detalle->cantidad_regalo ?? 0;
            $cantidadTotal = $cantidadFacturada + $cantidadRegalo;

            if ($cantidadTotal <= 0) {
                $producto = \App\Models\Producto::find($detalle->producto_id);
                $nombreProducto = $producto ? $producto->nombre : "ID: {$detalle->producto_id}";

                Notification::make()
                    ->title('Error en detalle de compra')
                    ->body("El producto '{$nombreProducto}' tiene cantidad 0. Edite la compra para corregir.")
                    ->danger()
                    ->send();

                throw new \Exception("Producto '{$nombreProducto}' tiene cantidad 0.");
            }

            if ($this->esUnidadCarton($detalle->unidad_id)) {
                if ($cantidadFacturada == 0 && $cantidadRegalo > 0) {
                    Log::warning("Compra {$this->record->numero_compra}: Producto {$detalle->producto_id} tiene solo regalo sin facturados.");
                }
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => $this->record->estado === CompraEstado::Borrador),

            // CONFIRMAR ORDEN
            Actions\Action::make('confirmar_orden')
                ->label('Confirmar Orden')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirmar Orden de Compra')
                ->modalDescription('Confirmar que la orden fue enviada al proveedor?')
                ->form([
                    \Filament\Forms\Components\Textarea::make('nota')
                        ->label('Nota (opcional)')
                        ->placeholder('Agregar alguna nota sobre esta orden...')
                        ->rows(2),
                ])
                ->visible(fn () => $this->record->estado === CompraEstado::Borrador)
                ->action(function (array $data) {
                    DB::transaction(function () use ($data) {
                        $this->lockAndReloadRecord();

                        $nota = $this->buildNota($data['nota'] ?? null);

                        CompraStateManager::transicionar($this->record, CompraEstado::Ordenada);

                        $this->record->update([
                            'nota' => $nota,
                            'updated_by' => Auth::id(),
                        ]);
                    });

                    $this->refreshFormData(['estado', 'nota']);

                    Notification::make()
                        ->title('Orden confirmada')
                        ->body('La orden ha sido enviada al proveedor.')
                        ->success()
                        ->send();
                }),

            // MARCAR COMO RECIBIDA
            Actions\Action::make('marcar_recibida')
                ->label('Marcar como Recibida')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Registrar Recepcion de Mercancia')
                ->modalDescription('Confirmar que la mercancia fue recibida? Se procesara el inventario automaticamente.')
                ->form([
                    \Filament\Forms\Components\Select::make('bodega_id')
                        ->label('Bodega de Destino')
                        ->required()
                        ->options(function () {
                            $user = Auth::user();

                            if ($user && ($user->roles->contains('name', 'Jefe') || $user->roles->contains('name', 'Super Admin'))) {
                                return \App\Models\Bodega::where('activo', true)
                                    ->pluck('nombre', 'id');
                            }

                            return \App\Models\BodegaUser::where('user_id', $user->id)
                                ->where('activo', true)
                                ->with('bodega')
                                ->get()
                                ->filter(fn ($bu) => $bu->bodega && $bu->bodega->activo)
                                ->pluck('bodega.nombre', 'bodega.id');
                        })
                        ->searchable()
                        ->preload()
                        ->helperText('Selecciona la bodega donde se recibira la mercancia'),

                    \Filament\Forms\Components\Textarea::make('nota')
                        ->label('Nota sobre la recepcion')
                        ->placeholder('Detalles sobre la recepcion de mercancia...')
                        ->rows(2),
                ])
                ->visible(fn () => in_array($this->record->estado, [
                    CompraEstado::Ordenada,
                    CompraEstado::PorRecibirPagada,
                    CompraEstado::PorRecibirPendientePago,
                ]))
                ->action(function (array $data) {
                    $this->validarDetallesAntesDeRecibir();

                    $resultado = DB::transaction(function () use ($data) {
                        $this->lockAndReloadRecord();

                        // Guard de idempotencia
                        if (CompraStateManager::yaFueProcesadoInventario($this->record)) {
                            throw new \RuntimeException('Esta compra ya tiene inventario procesado. No se puede procesar de nuevo.');
                        }

                        $nuevoEstado = match ($this->record->estado) {
                            CompraEstado::PorRecibirPagada => CompraEstado::RecibidaPagada,
                            default => CompraEstado::RecibidaPendientePago,
                        };

                        $bodegaId = $data['bodega_id'];
                        $resultado = $this->procesarInventarioDesdeCompra($bodegaId);

                        CompraStateManager::transicionar($this->record, $nuevoEstado);

                        $nota = $data['nota'] ?? null;
                        $notaTexto = $nota
                            ? ($this->record->nota ? $this->record->nota . "\n\n" : '') . "RECIBIDA en bodega: " . $nota
                            : $this->record->nota;

                        $bodega = \App\Models\Bodega::find($bodegaId);
                        $this->record->update([
                            'bodega_id' => $bodegaId,
                            'nota' => ($notaTexto ? $notaTexto . "\n\n" : '') . "Bodega: {$bodega->nombre}",
                            'updated_by' => Auth::id(),
                        ]);

                        return ['resultado' => $resultado, 'nuevoEstado' => $nuevoEstado, 'bodega' => $bodega];
                    });

                    $this->refreshFormData(['estado', 'nota']);

                    $res = $resultado['resultado'];
                    $mensaje = $resultado['nuevoEstado'] === CompraEstado::RecibidaPagada ? "Compra completada! " : "";

                    if ($res['lotes_actualizados'] > 0) {
                        $mensaje .= "Se actualizaron {$res['lotes_actualizados']} lote(s). ";
                    }
                    if ($res['productos_stock'] > 0) {
                        $mensaje .= "Se agregaron {$res['productos_stock']} producto(s) al stock. ";
                    }
                    $mensaje .= "Bodega: '{$resultado['bodega']->nombre}'.";

                    if ($resultado['nuevoEstado'] !== CompraEstado::RecibidaPagada) {
                        $mensaje .= " Pendiente de pago.";
                    }

                    Notification::make()
                        ->title('Mercancia recibida')
                        ->body($mensaje)
                        ->success()
                        ->duration(5000)
                        ->send();
                }),

            // MARCAR COMO PAGADA
            Actions\Action::make('marcar_pagada')
                ->label('Marcar como Pagada')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Registrar Pago al Proveedor')
                ->modalDescription('Confirmar que se ha pagado esta compra al proveedor?')
                ->form([
                    \Filament\Forms\Components\Textarea::make('nota')
                        ->label('Nota sobre el pago')
                        ->placeholder('Detalles sobre el pago realizado...')
                        ->rows(2),
                ])
                ->visible(fn () => in_array($this->record->estado, [
                    CompraEstado::Ordenada,
                    CompraEstado::RecibidaPendientePago,
                    CompraEstado::PorRecibirPendientePago,
                ]))
                ->action(function (array $data) {
                    DB::transaction(function () use ($data) {
                        $this->lockAndReloadRecord();

                        $nuevoEstado = match ($this->record->estado) {
                            CompraEstado::RecibidaPendientePago => CompraEstado::RecibidaPagada,
                            CompraEstado::PorRecibirPendientePago => CompraEstado::PorRecibirPagada,
                            CompraEstado::Ordenada => CompraEstado::PorRecibirPagada,
                            default => CompraEstado::PorRecibirPagada,
                        };

                        CompraStateManager::transicionar($this->record, $nuevoEstado);

                        $nota = $data['nota'] ?? null;
                        $notaTexto = $nota
                            ? ($this->record->nota ? $this->record->nota . "\n\n" : '') . "PAGADA: " . $nota
                            : $this->record->nota;

                        $this->record->update([
                            'nota' => $notaTexto,
                            'updated_by' => Auth::id(),
                        ]);
                    });

                    $this->refreshFormData(['estado', 'nota']);

                    $esCompleta = $this->record->estado === CompraEstado::RecibidaPagada;
                    $mensaje = $esCompleta
                        ? 'Compra completada! Pagada y recibida.'
                        : 'Pago registrado. Pendiente de recibir mercancia.';

                    Notification::make()
                        ->title('Pago registrado')
                        ->body($mensaje)
                        ->success()
                        ->send();
                }),

            // RECIBIR Y PAGAR
            Actions\Action::make('recibir_y_pagar')
                ->label('Recibir y Pagar')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Completar Compra')
                ->modalDescription('Confirmar que la mercancia fue recibida y pagada? Se procesara el inventario automaticamente.')
                ->form([
                    \Filament\Forms\Components\Select::make('bodega_id')
                        ->label('Bodega de Destino')
                        ->required()
                        ->options(function () {
                            $user = Auth::user();

                            if ($user && ($user->roles->contains('name', 'Jefe') || $user->roles->contains('name', 'Super Admin'))) {
                                return \App\Models\Bodega::where('activo', true)
                                    ->pluck('nombre', 'id');
                            }

                            return \App\Models\BodegaUser::where('user_id', $user->id)
                                ->where('activo', true)
                                ->with('bodega')
                                ->get()
                                ->filter(fn ($bu) => $bu->bodega && $bu->bodega->activo)
                                ->pluck('bodega.nombre', 'bodega.id');
                        })
                        ->searchable()
                        ->preload()
                        ->helperText('Selecciona la bodega donde se recibira la mercancia'),

                    \Filament\Forms\Components\Textarea::make('nota')
                        ->label('Nota (opcional)')
                        ->placeholder('Detalles sobre la recepcion y pago...')
                        ->rows(2),
                ])
                ->visible(fn () => in_array($this->record->estado, [
                    CompraEstado::Ordenada,
                    CompraEstado::PorRecibirPendientePago,
                ]))
                ->action(function (array $data) {
                    $this->validarDetallesAntesDeRecibir();

                    $resultado = DB::transaction(function () use ($data) {
                        $this->lockAndReloadRecord();

                        // Guard de idempotencia
                        if (CompraStateManager::yaFueProcesadoInventario($this->record)) {
                            throw new \RuntimeException('Esta compra ya tiene inventario procesado. No se puede procesar de nuevo.');
                        }

                        $bodegaId = $data['bodega_id'];
                        $resultado = $this->procesarInventarioDesdeCompra($bodegaId);

                        CompraStateManager::transicionar($this->record, CompraEstado::RecibidaPagada);

                        $nota = $data['nota'] ?? null;
                        $notaTexto = $nota
                            ? ($this->record->nota ? $this->record->nota . "\n\n" : '') . "COMPLETADA (recibida y pagada): " . $nota
                            : $this->record->nota;

                        $bodega = \App\Models\Bodega::find($bodegaId);
                        $this->record->update([
                            'bodega_id' => $bodegaId,
                            'nota' => ($notaTexto ? $notaTexto . "\n\n" : '') . "Bodega: {$bodega->nombre}",
                            'updated_by' => Auth::id(),
                        ]);

                        return ['resultado' => $resultado, 'bodega' => $bodega];
                    });

                    $this->refreshFormData(['estado', 'nota']);

                    $res = $resultado['resultado'];
                    $mensaje = "Compra completada! ";
                    if ($res['lotes_actualizados'] > 0) {
                        $mensaje .= "Se actualizaron {$res['lotes_actualizados']} lote(s). ";
                    }
                    if ($res['productos_stock'] > 0) {
                        $mensaje .= "Se agregaron {$res['productos_stock']} producto(s) al stock. ";
                    }
                    $mensaje .= "Bodega: '{$resultado['bodega']->nombre}'.";

                    Notification::make()
                        ->title('Compra completada!')
                        ->body($mensaje)
                        ->success()
                        ->duration(5000)
                        ->send();
                }),

            // CANCELAR COMPRA — alineada con CompraStateManager::puedeCancelarse()
            Actions\Action::make('cancelar_compra')
                ->label('Cancelar Compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar Compra')
                ->modalDescription(function () {
                    if (CompraStateManager::tieneInventarioProcesado($this->record->estado)) {
                        return 'ATENCION: Esta compra ya tiene inventario procesado. Cancelar revertira el inventario (lotes y stock). Esto solo es posible si no se han vendido productos de esta compra.';
                    }
                    return 'Estas seguro de cancelar esta compra? Debes proporcionar un motivo.';
                })
                ->form([
                    \Filament\Forms\Components\Textarea::make('motivo_cancelacion')
                        ->label('Motivo de Cancelacion')
                        ->required()
                        ->placeholder('Explica por que se cancela esta compra...')
                        ->rows(3)
                        ->helperText('Este motivo quedara registrado en la nota de la compra.'),
                ])
                ->visible(fn () => CompraStateManager::puedeCancelarse($this->record->estado))
                ->action(function (array $data) {
                    try {
                        $reversionInfo = DB::transaction(function () use ($data) {
                            $this->lockAndReloadRecord();

                            $reversionResult = null;

                            // Si tiene inventario procesado, revertirlo primero
                            if (CompraStateManager::tieneInventarioProcesado($this->record->estado)) {
                                $reversionResult = CompraStateManager::revertirInventario($this->record);
                            }

                            CompraStateManager::transicionar($this->record, CompraEstado::Cancelada);

                            $nota = "CANCELADA: " . $data['motivo_cancelacion'];
                            if ($reversionResult) {
                                $nota .= "\n[Inventario revertido: {$reversionResult['lotes_revertidos']} lote(s), {$reversionResult['productos_revertidos']} producto(s)]";
                            }
                            if ($this->record->nota) {
                                $nota = $this->record->nota . "\n\n" . $nota;
                            }

                            $this->record->update([
                                'nota' => $nota,
                                'updated_by' => Auth::id(),
                            ]);

                            return $reversionResult;
                        });

                        $this->refreshFormData(['estado', 'nota']);

                        $mensaje = 'La compra ha sido cancelada.';
                        if ($reversionInfo) {
                            $mensaje .= " Se revirtio inventario: {$reversionInfo['lotes_revertidos']} lote(s), {$reversionInfo['productos_revertidos']} producto(s).";
                            if (!empty($reversionInfo['advertencias'])) {
                                $mensaje .= " Advertencias: " . implode('; ', $reversionInfo['advertencias']);
                            }
                        }

                        Notification::make()
                            ->title('Compra cancelada')
                            ->body($mensaje)
                            ->danger()
                            ->duration(8000)
                            ->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title('No se puede cancelar')
                            ->body($e->getMessage())
                            ->danger()
                            ->duration(10000)
                            ->send();
                    }
                }),

            // INFO: Por que no se puede editar compras completadas
            Actions\Action::make('info_compra_recibida')
                ->label('Info')
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->visible(fn () => $this->record->estado === CompraEstado::RecibidaPagada)
                ->action(function () {
                    Notification::make()
                        ->title('Compra completada')
                        ->body('Esta compra esta completa (recibida y pagada). No se puede editar para mantener la integridad de los datos.')
                        ->warning()
                        ->duration(10000)
                        ->send();
                }),

            // CAMBIO MANUAL DE ESTADO — BLINDADO con CompraStateManager
            Actions\Action::make('cambiar_estado_manual')
                ->label('Cambiar Estado')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->form(fn () => [
                    \Filament\Forms\Components\Placeholder::make('advertencia')
                        ->content(new \Illuminate\Support\HtmlString("
                            <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-4 text-red-800 dark:text-red-200'>
                                <p class='font-bold'>ADVERTENCIA</p>
                                <p class='text-sm mt-1'>Solo se permiten las transiciones validas desde el estado actual. Cambios que afectan inventario ejecutaran los procesos correspondientes.</p>
                            </div>
                        ")),

                    \Filament\Forms\Components\Select::make('estado')
                        ->label('Nuevo Estado')
                        ->options(CompraStateManager::opcionesTransicion($this->record->estado))
                        ->required()
                        ->native(false),

                    \Filament\Forms\Components\Textarea::make('motivo_cambio')
                        ->label('Motivo del cambio')
                        ->required()
                        ->placeholder('Por que cambias el estado manualmente?')
                        ->rows(3),
                ])
                ->visible(function () {
                    $user = Auth::user();
                    if (!$user) {
                        return false;
                    }
                    // Solo visible si hay transiciones disponibles Y es admin
                    return $user->roles->whereIn('name', ['Jefe', 'Super Admin'])->isNotEmpty()
                        && !CompraStateManager::esEstadoFinal($this->record->estado);
                })
                ->action(function (array $data) {
                    try {
                        $nuevoEstado = CompraEstado::from($data['estado']);

                        DB::transaction(function () use ($data, $nuevoEstado) {
                            $this->lockAndReloadRecord();

                            $estadoAnterior = $this->record->estado;

                            // Si va a Cancelada y tiene inventario, revertir
                            if ($nuevoEstado === CompraEstado::Cancelada
                                && CompraStateManager::tieneInventarioProcesado($estadoAnterior)) {
                                CompraStateManager::revertirInventario($this->record);
                            }

                            // Validar y ejecutar transición vía State Machine
                            CompraStateManager::transicionar($this->record, $nuevoEstado);

                            $nota = "CAMBIO MANUAL: {$estadoAnterior->label()} -> {$nuevoEstado->label()}. Motivo: " . $data['motivo_cambio'];
                            if ($this->record->nota) {
                                $nota = $this->record->nota . "\n\n" . $nota;
                            }

                            $this->record->update([
                                'nota' => $nota,
                                'updated_by' => Auth::id(),
                            ]);
                        });

                        $this->refreshFormData(['estado', 'nota']);

                        Notification::make()
                            ->title('Estado actualizado')
                            ->body("Estado cambiado a: {$nuevoEstado->label()}")
                            ->warning()
                            ->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Transición no permitida')
                            ->body($e->getMessage())
                            ->danger()
                            ->duration(8000)
                            ->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title('No se puede completar')
                            ->body($e->getMessage())
                            ->danger()
                            ->duration(10000)
                            ->send();
                    }
                }),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->estado === CompraEstado::Borrador),
        ];
    }

    // ========================================
    // HELPERS PRIVADOS
    // ========================================

    /**
     * Construir nota concatenando con la existente.
     */
    private function buildNota(?string $nuevaNota, ?string $prefijo = null): ?string
    {
        if (!$nuevaNota && !$prefijo) {
            return $this->record->nota;
        }

        $texto = $prefijo ? "{$prefijo}: {$nuevaNota}" : $nuevaNota;

        if ($this->record->nota && $texto) {
            return $this->record->nota . "\n\n" . $texto;
        }

        return $texto ?: $this->record->nota;
    }

    /**
     * PROCESAR INVENTARIO DESDE LA COMPRA - CON LOTE UNICO
     * DEBE llamarse dentro de una DB::transaction()
     */
    protected function procesarInventarioDesdeCompra(int $bodegaId): array
    {
        $lotesActualizados = 0;
        $productosStock = 0;

        foreach ($this->record->detalles as $detalle) {
            $producto = \App\Models\Producto::find($detalle->producto_id);

            if (!$producto) {
                continue;
            }

            if ($this->esUnidadCarton($detalle->unidad_id)) {
                $this->agregarALoteUnico($detalle, $bodegaId);
                $lotesActualizados++;
            } else {
                $this->agregarStockDesdeDetalle($detalle, $bodegaId);
                $productosStock++;
            }
        }

        return [
            'lotes_actualizados' => $lotesActualizados,
            'productos_stock' => $productosStock,
        ];
    }

    /**
     * AGREGAR A LOTE UNICO (para cartones de huevos)
     */
    protected function agregarALoteUnico($detalle, int $bodegaId): void
    {
        $huevosPorCarton = 30;

        $cantidadFacturada = $detalle->cantidad_facturada ?? $detalle->cantidad ?? 0;
        $cantidadRegalo = $detalle->cantidad_regalo ?? 0;

        if (($cantidadFacturada + $cantidadRegalo) <= 0) {
            return;
        }

        $costoCompra = $detalle->subtotal ?? ($cantidadFacturada * $detalle->precio_unitario);

        $lote = Lote::obtenerOCrearLoteUnico(
            $detalle->producto_id,
            $bodegaId,
            $huevosPorCarton,
            Auth::id()
        );

        $resultado = $lote->agregarCompra(
            $cantidadFacturada,
            $cantidadRegalo,
            $costoCompra,
            $this->record->id,
            $detalle->id,
            $this->record->proveedor_id
        );

        Log::info("Compra agregada a lote unico", [
            'lote' => $lote->numero_lote,
            'compra' => $this->record->numero_compra,
            'resultado' => $resultado,
        ]);
    }

    /**
     * AGREGAR STOCK DESDE DETALLE DE COMPRA (para productos que no son cartones)
     */
    protected function agregarStockDesdeDetalle($detalle, int $bodegaId): void
    {
        $cantidadFacturada = $detalle->cantidad_facturada ?? $detalle->cantidad ?? 0;
        $cantidadRegalo = $detalle->cantidad_regalo ?? 0;
        $cantidadRecibida = $cantidadFacturada + $cantidadRegalo;

        if ($cantidadRecibida <= 0) {
            return;
        }

        $costoUnitario = 0;

        if (!empty($detalle->costo_sin_isv) && $detalle->costo_sin_isv > 0) {
            $costoUnitario = (float) $detalle->costo_sin_isv;
        } else {
            $costoTotal = $detalle->subtotal ?? ($cantidadFacturada * $detalle->precio_unitario);
            $costoUnitario = $cantidadFacturada > 0
                ? $costoTotal / $cantidadFacturada
                : 0;
        }

        $costoUnitario = round($costoUnitario, 2);

        $bodegaProducto = BodegaProducto::firstOrCreate(
            [
                'bodega_id' => $bodegaId,
                'producto_id' => $detalle->producto_id,
            ],
            [
                'stock' => 0,
                'stock_minimo' => 0,
                'costo_promedio_actual' => 0,
                'precio_venta_sugerido' => 0,
                'activo' => true,
            ]
        );

        if ($cantidadFacturada > 0) {
            $bodegaProducto->actualizarCostoPromedio($cantidadFacturada, $costoUnitario);
        }

        if ($cantidadRegalo > 0) {
            $bodegaProducto->agregarStockSinCosto($cantidadRegalo);
        }
    }

    protected function getEstadoLabel(string|\App\Enums\CompraEstado $estado): string
    {
        if ($estado instanceof \App\Enums\CompraEstado) {
            return $estado->label();
        }

        $enum = \App\Enums\CompraEstado::tryFrom($estado);
        return $enum ? $enum->label() : $estado;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Informacion General')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('numero_compra')
                                    ->label('No. Compra')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('proveedor.nombre')
                                    ->label('Proveedor')
                                    ->size('lg')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('bodega.nombre')
                                    ->label('Bodega')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('tipo_pago')
                                    ->label('Tipo de Pago')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => $state === 'contado' ? 'Contado' : 'Credito')
                                    ->color(fn ($state) => $state === 'contado' ? 'success' : 'warning'),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => $this->getEstadoLabel($state))
                                    ->color(fn ($state) => $state instanceof CompraEstado ? $state->color() : (CompraEstado::tryFrom($state)?->color() ?? 'gray')),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha de Creacion')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('total')
                                    ->label('Total')
                                    ->money('HNL')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('nota')
                                    ->label('Notas')
                                    ->columnSpanFull()
                                    ->visible(fn ($record) => !empty($record->nota))
                                    ->markdown()
                                    ->color('warning'),
                            ]),
                    ]),

                Infolists\Components\Section::make('Productos')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('detalles')
                            ->schema([
                                Infolists\Components\TextEntry::make('producto.nombre')
                                    ->label('Producto')
                                    ->weight('bold'),

                                Infolists\Components\TextEntry::make('unidad.nombre')
                                    ->label('Unidad')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('cantidad_facturada')
                                    ->label('Facturada')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' fact.'),

                                Infolists\Components\TextEntry::make('cantidad_regalo')
                                    ->label('Regalo')
                                    ->numeric(decimalPlaces: 0)
                                    ->suffix(' reg.')
                                    ->color('success')
                                    ->visible(fn ($state) => $state > 0),

                                Infolists\Components\TextEntry::make('precio_unitario')
                                    ->label('Precio Unit.')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('subtotal')
                                    ->label('Subtotal')
                                    ->money('HNL')
                                    ->weight('bold'),
                            ])
                            ->columns(6),
                    ]),
            ]);
    }
}
