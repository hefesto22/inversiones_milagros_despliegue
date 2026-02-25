<?php

namespace App\Filament\Resources\CompraResource\Pages;

use App\Filament\Resources\CompraResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Auth;
use App\Models\Lote;
use App\Models\BodegaProducto;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;

class ViewCompra extends ViewRecord
{
    protected static string $resource = CompraResource::class;

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

            // Validar que haya al menos alguna cantidad
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

            // Para cartones: validar que si solo hay regalo, tenga sentido
            if ($this->esUnidadCarton($detalle->unidad_id)) {
                if ($cantidadFacturada == 0 && $cantidadRegalo > 0) {
                    // Solo regalo sin facturado - advertir pero permitir
                    Log::warning("Compra {$this->record->numero_compra}: Producto {$detalle->producto_id} tiene solo regalo sin facturados.");
                }
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn() => $this->record->estado === 'borrador'),

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
                ->visible(fn() => $this->record->estado === 'borrador')
                ->action(function (array $data) {
                    $nota = $data['nota'] ?? null;
                    if ($nota && $this->record->nota) {
                        $nota = $this->record->nota . "\n\n" . $nota;
                    } elseif (!$nota) {
                        $nota = $this->record->nota;
                    }

                    $this->record->update([
                        'estado' => 'ordenada',
                        'nota' => $nota,
                        'updated_by' => Auth::id(),
                    ]);

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
                                ->filter(fn($bu) => $bu->bodega && $bu->bodega->activo)
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
                ->visible(fn() => in_array($this->record->estado, ['ordenada', 'por_recibir_pagada', 'por_recibir_pendiente_pago']))
                ->action(function (array $data) {
                    $this->validarDetallesAntesDeRecibir();

                    $nuevoEstado = match ($this->record->estado) {
                        'por_recibir_pagada' => 'recibida_pagada',
                        default => 'recibida_pendiente_pago',
                    };

                    $nota = $data['nota'] ?? null;
                    if ($nota) {
                        $nota = ($this->record->nota ? $this->record->nota . "\n\n" : '') . "RECIBIDA en bodega: " . $nota;
                    } else {
                        $nota = $this->record->nota;
                    }

                    $bodegaId = $data['bodega_id'];
                    $bodega = \App\Models\Bodega::find($bodegaId);

                    $resultado = $this->procesarInventarioDesdeCompra($bodegaId);

                    $this->record->update([
                        'estado' => $nuevoEstado,
                        'nota' => ($nota ? $nota . "\n\n" : '') . "Bodega: {$bodega->nombre}",
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    $mensaje = $nuevoEstado === 'recibida_pagada' ? "Compra completada! " : "";

                    if ($resultado['lotes_actualizados'] > 0) {
                        $mensaje .= "Se actualizaron {$resultado['lotes_actualizados']} lote(s). ";
                    }
                    if ($resultado['productos_stock'] > 0) {
                        $mensaje .= "Se agregaron {$resultado['productos_stock']} producto(s) al stock. ";
                    }
                    $mensaje .= "Bodega: '{$bodega->nombre}'.";

                    if ($nuevoEstado !== 'recibida_pagada') {
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
                ->visible(fn() => in_array($this->record->estado, ['ordenada', 'recibida_pendiente_pago', 'por_recibir_pendiente_pago']))
                ->action(function (array $data) {
                    $nuevoEstado = match ($this->record->estado) {
                        'recibida_pendiente_pago' => 'recibida_pagada',
                        default => 'por_recibir_pagada',
                    };

                    $nota = $data['nota'] ?? null;
                    if ($nota) {
                        $nota = ($this->record->nota ? $this->record->nota . "\n\n" : '') . "PAGADA: " . $nota;
                    } else {
                        $nota = $this->record->nota;
                    }

                    $this->record->update([
                        'estado' => $nuevoEstado,
                        'nota' => $nota,
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    $mensaje = $nuevoEstado === 'recibida_pagada'
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
                                ->filter(fn($bu) => $bu->bodega && $bu->bodega->activo)
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
                ->visible(fn() => in_array($this->record->estado, ['ordenada', 'por_recibir_pendiente_pago']))
                ->action(function (array $data) {
                    $this->validarDetallesAntesDeRecibir();

                    $nota = $data['nota'] ?? null;
                    $bodegaId = $data['bodega_id'];
                    $bodega = \App\Models\Bodega::find($bodegaId);

                    $resultado = $this->procesarInventarioDesdeCompra($bodegaId);

                    if ($nota) {
                        $nota = ($this->record->nota ? $this->record->nota . "\n\n" : '') . "COMPLETADA (recibida y pagada): " . $nota;
                    } else {
                        $nota = $this->record->nota;
                    }

                    $this->record->update([
                        'estado' => 'recibida_pagada',
                        'nota' => ($nota ? $nota . "\n\n" : '') . "Bodega: {$bodega->nombre}",
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    $mensaje = "Compra completada! ";
                    if ($resultado['lotes_actualizados'] > 0) {
                        $mensaje .= "Se actualizaron {$resultado['lotes_actualizados']} lote(s). ";
                    }
                    if ($resultado['productos_stock'] > 0) {
                        $mensaje .= "Se agregaron {$resultado['productos_stock']} producto(s) al stock. ";
                    }
                    $mensaje .= "Bodega: '{$bodega->nombre}'.";

                    Notification::make()
                        ->title('Compra completada!')
                        ->body($mensaje)
                        ->success()
                        ->duration(5000)
                        ->send();
                }),

            // CANCELAR COMPRA (solo si no ha sido recibida)
            Actions\Action::make('cancelar_compra')
                ->label('Cancelar Compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar Compra')
                ->modalDescription('Estas seguro de cancelar esta compra? Debes proporcionar un motivo.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('motivo_cancelacion')
                        ->label('Motivo de Cancelacion')
                        ->required()
                        ->placeholder('Explica por que se cancela esta compra...')
                        ->rows(3)
                        ->helperText('Este motivo quedara registrado en la nota de la compra.'),
                ])
                ->visible(fn() => !in_array($this->record->estado, ['cancelada', 'recibida_pagada', 'recibida_pendiente_pago']))
                ->action(function (array $data) {
                    $nota = "CANCELADA: " . $data['motivo_cancelacion'];
                    if ($this->record->nota) {
                        $nota = $this->record->nota . "\n\n" . $nota;
                    }

                    $this->record->update([
                        'estado' => 'cancelada',
                        'nota' => $nota,
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    Notification::make()
                        ->title('Compra cancelada')
                        ->body('La compra ha sido cancelada.')
                        ->danger()
                        ->send();
                }),

            // INFO: Por que no se puede editar/eliminar compras recibidas
            Actions\Action::make('info_compra_recibida')
                ->label('Info')
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->visible(fn() => in_array($this->record->estado, ['recibida_pagada', 'recibida_pendiente_pago']))
                ->action(function () {
                    Notification::make()
                        ->title('Compra ya procesada')
                        ->body('Esta compra ya fue recibida y procesada en el inventario. No se puede editar ni cancelar para mantener la integridad de los datos. Si hay un error, contacte al administrador para hacer ajustes manuales.')
                        ->warning()
                        ->duration(10000)
                        ->send();
                }),

            // CAMBIO MANUAL DE ESTADO (SOLO JEFE/SUPER ADMIN)
            Actions\Action::make('cambiar_estado_manual')
                ->label('Cambiar Estado')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\Placeholder::make('advertencia')
                        ->content(new \Illuminate\Support\HtmlString("
                            <div class='rounded-lg bg-red-50 dark:bg-red-900/20 p-4 text-red-800 dark:text-red-200'>
                                <p class='font-bold'>ADVERTENCIA</p>
                                <p class='text-sm mt-1'>Cambiar el estado manualmente puede causar inconsistencias en el inventario. Use solo si sabe lo que hace.</p>
                            </div>
                        ")),

                    \Filament\Forms\Components\Select::make('estado')
                        ->label('Nuevo Estado')
                        ->options([
                            'borrador' => 'Borrador',
                            'ordenada' => 'Ordenada',
                            'recibida_pagada' => 'Recibida y Pagada',
                            'recibida_pendiente_pago' => 'Recibida - Pendiente Pago',
                            'por_recibir_pagada' => 'Pagada - Pendiente Recibir',
                            'por_recibir_pendiente_pago' => 'Pendiente Todo',
                            'cancelada' => 'Cancelada',
                        ])
                        ->required()
                        ->native(false)
                        ->default(fn() => $this->record->estado),

                    \Filament\Forms\Components\Textarea::make('motivo_cambio')
                        ->label('Motivo del cambio')
                        ->required()
                        ->placeholder('Por que cambias el estado manualmente?')
                        ->rows(3),
                ])
                ->visible(function () {
                    $user = Auth::user();
                    if (!$user) return false;

                    return $user->roles->whereIn('name', ['Jefe', 'Super Admin'])->isNotEmpty();
                })
                ->action(function (array $data) {
                    $estadoAnterior = $this->getEstadoLabel($this->record->estado);
                    $estadoNuevo = $this->getEstadoLabel($data['estado']);

                    $nota = "CAMBIO MANUAL: {$estadoAnterior} -> {$estadoNuevo}. Motivo: " . $data['motivo_cambio'];
                    if ($this->record->nota) {
                        $nota = $this->record->nota . "\n\n" . $nota;
                    }

                    $this->record->update([
                        'estado' => $data['estado'],
                        'nota' => $nota,
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    Notification::make()
                        ->title('Estado actualizado manualmente')
                        ->body("Estado: {$estadoAnterior} -> {$estadoNuevo}")
                        ->warning()
                        ->send();
                }),

            // VER RESUMEN
            Actions\Action::make('ver_resumen')
                ->label('Ver Resumen')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->action(function () {
                    $totalProductos = $this->record->detalles()->count();
                    $totalUnidadesFacturadas = $this->record->detalles()->sum('cantidad_facturada');
                    $totalUnidadesRegalo = $this->record->detalles()->sum('cantidad_regalo');
                    $totalIsvCredito = $this->record->detalles()->sum('isv_credito');

                    $mensaje = "RESUMEN DE COMPRA #{$this->record->numero_compra}\n\n";
                    $mensaje .= "Proveedor: {$this->record->proveedor->nombre}\n";
                    $mensaje .= "Estado: " . $this->getEstadoLabel($this->record->estado) . "\n";
                    $mensaje .= "Tipo de pago: " . ($this->record->tipo_pago === 'contado' ? 'Contado' : 'Credito') . "\n\n";
                    $mensaje .= "Productos diferentes: {$totalProductos}\n";
                    $mensaje .= "Total unidades facturadas: " . number_format($totalUnidadesFacturadas, 0) . "\n";
                    
                    if ($totalUnidadesRegalo > 0) {
                        $mensaje .= "Total unidades regalo: " . number_format($totalUnidadesRegalo, 0) . "\n";
                    }
                    
                    $mensaje .= "Total a pagar: L " . number_format($this->record->total, 2) . "\n";

                    if ($totalIsvCredito > 0) {
                        $mensaje .= "ISV Credito Fiscal: L " . number_format($totalIsvCredito, 2) . "\n";
                    }

                    Notification::make()
                        ->title('Resumen de Compra')
                        ->body($mensaje)
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->duration(15000)
                        ->send();
                }),

            Actions\DeleteAction::make()
                ->visible(fn() => $this->record->estado === 'borrador'),
        ];
    }

    /**
     * PROCESAR INVENTARIO DESDE LA COMPRA - CON LOTE UNICO
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

    protected function getEstadoLabel(string $estado): string
    {
        return match ($estado) {
            'borrador' => 'Borrador',
            'ordenada' => 'Ordenada',
            'recibida_pagada' => 'Recibida y Pagada',
            'recibida_pendiente_pago' => 'Recibida - Debo Pagar',
            'por_recibir_pagada' => 'Pagada - Falta Recibir',
            'por_recibir_pendiente_pago' => 'Pendiente Todo',
            'cancelada' => 'Cancelada',
            default => $estado,
        };
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
                                    ->formatStateUsing(fn($state) => $state === 'contado' ? 'Contado' : 'Credito')
                                    ->color(fn($state) => $state === 'contado' ? 'success' : 'warning'),

                                Infolists\Components\TextEntry::make('estado')
                                    ->badge()
                                    ->formatStateUsing(fn($state) => $this->getEstadoLabel($state))
                                    ->color(fn($state) => match ($state) {
                                        'borrador' => 'gray',
                                        'ordenada' => 'info',
                                        'recibida_pagada' => 'success',
                                        'recibida_pendiente_pago' => 'warning',
                                        'por_recibir_pagada' => 'info',
                                        'por_recibir_pendiente_pago' => 'danger',
                                        'cancelada' => 'danger',
                                        default => 'gray',
                                    }),

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
                                    ->visible(fn($record) => !empty($record->nota))
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
                                    ->visible(fn($state) => $state > 0),

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