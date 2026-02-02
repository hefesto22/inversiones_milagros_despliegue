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

class ViewCompra extends ViewRecord
{
    protected static string $resource = CompraResource::class;

    /**
     * Verificar si una unidad es de tipo cartón (para huevos)
     * Los cartones pasan por el flujo de LOTES
     * Las demás unidades van directo a STOCK
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

        // Reconocer cartones: "carton", "cartón", "1x30", o cualquier unidad con "30"
        return str_contains($nombreLower, 'carton') 
            || str_contains($nombreLower, 'cartón')
            || str_contains($nombreLower, '1x30')
            || str_contains($nombreLower, '30');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn() => $this->record->estado === 'borrador'),

            // ========================================
            // FLUJO DE ESTADOS - CONFIRMAR ORDEN
            // ========================================

            Actions\Action::make('confirmar_orden')
                ->label('Confirmar Orden')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirmar Orden de Compra')
                ->modalDescription('¿Confirmar que la orden fue enviada al proveedor?')
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

                    \Filament\Notifications\Notification::make()
                        ->title('Orden confirmada')
                        ->body('La orden ha sido enviada al proveedor.')
                        ->success()
                        ->send();
                }),

            // ========================================
            // 🎯 MARCAR COMO RECIBIDA (AGREGA A LOTE ÚNICO O STOCK)
            // ========================================

            Actions\Action::make('marcar_recibida')
                ->label('Marcar como Recibida')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Registrar Recepción de Mercancía')
                ->modalDescription('¿Confirmar que la mercancía fue recibida? Se procesará el inventario automáticamente.')
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
                        ->helperText('Selecciona la bodega donde se recibirá la mercancía'),

                    \Filament\Forms\Components\Textarea::make('nota')
                        ->label('Nota sobre la recepción')
                        ->placeholder('Detalles sobre la recepción de mercancía...')
                        ->rows(2),
                ])
                ->visible(fn() => in_array($this->record->estado, ['ordenada', 'por_recibir_pagada', 'por_recibir_pendiente_pago']))
                ->action(function (array $data) {
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

                    // 🎯 PROCESAR INVENTARIO CON LOTE ÚNICO
                    $resultado = $this->procesarInventarioDesdeCompra($bodegaId);

                    $this->record->update([
                        'estado' => $nuevoEstado,
                        'nota' => ($nota ? $nota . "\n\n" : '') . "Bodega: {$bodega->nombre}",
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    $mensaje = $nuevoEstado === 'recibida_pagada'
                        ? "¡Compra completada! "
                        : "";

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

                    \Filament\Notifications\Notification::make()
                        ->title('Mercancía recibida')
                        ->body($mensaje)
                        ->success()
                        ->duration(5000)
                        ->send();
                }),

            // ========================================
            // MARCAR COMO PAGADA (SIN CREAR LOTES)
            // ========================================

            Actions\Action::make('marcar_pagada')
                ->label('Marcar como Pagada')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Registrar Pago al Proveedor')
                ->modalDescription('¿Confirmar que se ha pagado esta compra al proveedor?')
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
                        ? '¡Compra completada! Pagada y recibida.'
                        : 'Pago registrado. Pendiente de recibir mercancía.';

                    \Filament\Notifications\Notification::make()
                        ->title('Pago registrado')
                        ->body($mensaje)
                        ->success()
                        ->send();
                }),

            // ========================================
            // 🎯 RECIBIR Y PAGAR (AGREGA A LOTE ÚNICO O STOCK)
            // ========================================

            Actions\Action::make('recibir_y_pagar')
                ->label('Recibir y Pagar')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Completar Compra')
                ->modalDescription('¿Confirmar que la mercancía fue recibida y pagada? Se procesará el inventario automáticamente.')
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
                        ->helperText('Selecciona la bodega donde se recibirá la mercancía'),

                    \Filament\Forms\Components\Textarea::make('nota')
                        ->label('Nota (opcional)')
                        ->placeholder('Detalles sobre la recepción y pago...')
                        ->rows(2),
                ])
                ->visible(fn() => in_array($this->record->estado, ['ordenada', 'por_recibir_pendiente_pago']))
                ->action(function (array $data) {
                    $nota = $data['nota'] ?? null;
                    $bodegaId = $data['bodega_id'];
                    $bodega = \App\Models\Bodega::find($bodegaId);

                    // 🎯 PROCESAR INVENTARIO CON LOTE ÚNICO
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

                    $mensaje = "¡Compra completada! ";
                    if ($resultado['lotes_actualizados'] > 0) {
                        $mensaje .= "Se actualizaron {$resultado['lotes_actualizados']} lote(s). ";
                    }
                    if ($resultado['productos_stock'] > 0) {
                        $mensaje .= "Se agregaron {$resultado['productos_stock']} producto(s) al stock. ";
                    }
                    $mensaje .= "Bodega: '{$bodega->nombre}'.";

                    \Filament\Notifications\Notification::make()
                        ->title('¡Compra completada!')
                        ->body($mensaje)
                        ->success()
                        ->duration(5000)
                        ->send();
                }),

            // ========================================
            // CANCELAR COMPRA
            // ========================================

            Actions\Action::make('cancelar_compra')
                ->label('Cancelar Compra')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar Compra')
                ->modalDescription('¿Estás seguro de cancelar esta compra? Debes proporcionar un motivo.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('motivo_cancelacion')
                        ->label('Motivo de Cancelación')
                        ->required()
                        ->placeholder('Explica por qué se cancela esta compra...')
                        ->rows(3)
                        ->helperText('Este motivo quedará registrado en la nota de la compra.'),
                ])
                ->visible(fn() => !in_array($this->record->estado, ['cancelada', 'recibida_pagada']))
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

                    \Filament\Notifications\Notification::make()
                        ->title('Compra cancelada')
                        ->body('La compra ha sido cancelada.')
                        ->danger()
                        ->send();
                }),

            // ========================================
            // CAMBIO MANUAL DE ESTADO (SOLO JEFE)
            // ========================================

            Actions\Action::make('cambiar_estado_manual')
                ->label('Cambiar Estado')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\Select::make('estado')
                        ->label('Nuevo Estado')
                        ->options([
                            'borrador' => 'Borrador',
                            'ordenada' => 'Ordenada',
                            'recibida_pagada' => 'Recibida y Pagada ✅',
                            'recibida_pendiente_pago' => 'Recibida - Pendiente Pago 📦',
                            'por_recibir_pagada' => 'Pagada - Pendiente Recibir 💰',
                            'por_recibir_pendiente_pago' => 'Pendiente Todo ⏳',
                            'cancelada' => 'Cancelada ❌',
                        ])
                        ->required()
                        ->native(false)
                        ->default(fn() => $this->record->estado)
                        ->helperText('Usa esta opción solo si necesitas cambiar el estado manualmente.'),

                    \Filament\Forms\Components\Textarea::make('motivo_cambio')
                        ->label('Motivo del cambio')
                        ->required()
                        ->placeholder('¿Por qué cambias el estado manualmente?')
                        ->rows(3),
                ])
                ->visible(function () {
                    $user = Auth::user();

                    if (!$user) {
                        return false;
                    }

                    return !in_array($this->record->estado, ['recibida_pagada', 'cancelada'])
                        && $user->roles->contains('name', 'Jefe');
                })
                ->action(function (array $data) {
                    $estadoAnterior = $this->getEstadoLabel($this->record->estado);
                    $estadoNuevo = $this->getEstadoLabel($data['estado']);

                    $nota = "Cambio manual: {$estadoAnterior} → {$estadoNuevo}. Motivo: " . $data['motivo_cambio'];
                    if ($this->record->nota) {
                        $nota = $this->record->nota . "\n\n" . $nota;
                    }

                    $this->record->update([
                        'estado' => $data['estado'],
                        'nota' => $nota,
                        'updated_by' => Auth::id(),
                    ]);

                    $this->refreshFormData(['estado', 'nota']);

                    \Filament\Notifications\Notification::make()
                        ->title('Estado actualizado manualmente')
                        ->body("Estado: {$estadoAnterior} → {$estadoNuevo}")
                        ->warning()
                        ->send();
                }),

            // ========================================
            // INFORMACIÓN Y UTILIDADES
            // ========================================

            Actions\Action::make('ver_resumen')
                ->label('Ver Resumen')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->action(function () {
                    $totalProductos = $this->record->detalles()->count();
                    $totalUnidadesFacturadas = $this->record->detalles()->sum('cantidad_facturada');
                    $totalUnidadesRecibidas = $this->record->detalles()->sum('cantidad_recibida');
                    $totalIsvCredito = $this->record->detalles()->sum('isv_credito');

                    $mensaje = "📦 RESUMEN DE COMPRA #{$this->record->numero_compra}\n\n";
                    $mensaje .= "Proveedor: {$this->record->proveedor->nombre}\n";
                    $mensaje .= "Estado: " . $this->getEstadoLabel($this->record->estado) . "\n";
                    $mensaje .= "Tipo de pago: " . ($this->record->tipo_pago === 'contado' ? 'Contado' : 'Crédito') . "\n\n";
                    $mensaje .= "Productos diferentes: {$totalProductos}\n";
                    $mensaje .= "Total unidades facturadas: " . number_format($totalUnidadesFacturadas, 2) . "\n";
                    $mensaje .= "Total unidades recibidas: " . number_format($totalUnidadesRecibidas, 2) . "\n";
                    $mensaje .= "Total a pagar: L " . number_format($this->record->total, 2) . "\n";

                    if ($totalIsvCredito > 0) {
                        $mensaje .= "💰 ISV Crédito Fiscal: L " . number_format($totalIsvCredito, 2) . "\n";
                    }

                    if ($this->record->tipo_pago === 'credito') {
                        $interesAcumulado = $this->record->getInteresAcumulado();
                        if ($interesAcumulado > 0) {
                            $periodos = $this->record->getPeriodosTranscurridos();
                            $periodo = $this->record->periodo_interes === 'semanal' ? 'semanas' : 'meses';
                            $mensaje .= "Interés acumulado: L " . number_format($interesAcumulado, 2) . " ({$periodos} {$periodo})\n";
                            $mensaje .= "Saldo total: L " . number_format($this->record->getSaldoConIntereses(), 2) . "\n";
                        }
                    }

                    $mensaje .= "\n\nCreado: " . $this->record->created_at->format('d/m/Y H:i');

                    \Filament\Notifications\Notification::make()
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
     * 🎯 PROCESAR INVENTARIO DESDE LA COMPRA - CON LOTE ÚNICO
     *
     * - Productos con unidad CARTON/1x30 → Agregar a LOTE ÚNICO (costo promedio ponderado)
     * - Productos con OTRAS unidades → Agregar directo a STOCK
     *
     * @param int $bodegaId
     * @return array ['lotes_actualizados' => int, 'productos_stock' => int]
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

            // 🎯 VERIFICAR SI ES UNIDAD TIPO CARTÓN (incluye 1x30)
            if ($this->esUnidadCarton($detalle->unidad_id)) {
                // ========================================
                // FLUJO DE LOTE ÚNICO (para huevos en cartones)
                // ========================================
                $this->agregarALoteUnico($detalle, $bodegaId);
                $lotesActualizados++;
            } else {
                // ========================================
                // FLUJO DE STOCK DIRECTO (otros productos)
                // ========================================
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
     * 🎯 AGREGAR A LOTE ÚNICO (para cartones de huevos)
     *
     * Busca el lote único del producto en la bodega y agrega la compra.
     * Si no existe, lo crea. Usa costo promedio ponderado.
     */
    protected function agregarALoteUnico($detalle, int $bodegaId): void
    {
        $huevosPorCarton = 30;

        $cantidadFacturada = $detalle->cantidad_facturada ?? $detalle->cantidad ?? 0;
        $cantidadRegalo = $detalle->cantidad_regalo ?? 0;

        if (($cantidadFacturada + $cantidadRegalo) <= 0) {
            return;
        }

        // Calcular costo total de esta compra
        $costoCompra = $detalle->subtotal ?? ($cantidadFacturada * $detalle->precio_unitario);

        // Obtener o crear el lote único
        $lote = Lote::obtenerOCrearLoteUnico(
            $detalle->producto_id,
            $bodegaId,
            $huevosPorCarton,
            Auth::id()
        );

        // Agregar la compra al lote (recalcula costo promedio)
        $resultado = $lote->agregarCompra(
            $cantidadFacturada,
            $cantidadRegalo,
            $costoCompra,
            $this->record->id,
            $detalle->id,
            $this->record->proveedor_id
        );

        // Log para debugging (opcional)
        Log::info("Compra agregada a lote único", [
            'lote' => $lote->numero_lote,
            'compra' => $this->record->numero_compra,
            'resultado' => $resultado,
        ]);
    }

    /**
     * 🎯 AGREGAR STOCK DESDE DETALLE DE COMPRA (para productos que no son cartones)
     *
     * Usa el método de COSTO PROMEDIO PONDERADO de BodegaProducto
     */
    protected function agregarStockDesdeDetalle($detalle, int $bodegaId): void
    {
        $cantidadFacturada = $detalle->cantidad_facturada ?? $detalle->cantidad ?? 0;
        $cantidadRegalo = $detalle->cantidad_regalo ?? 0;
        $cantidadRecibida = $cantidadFacturada + $cantidadRegalo;

        if ($cantidadRecibida <= 0) {
            return;
        }

        // Determinar el costo unitario correcto
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

        // Buscar o crear registro en bodega_producto
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

        // Agregar stock con costo promedio ponderado
        if ($cantidadFacturada > 0) {
            $bodegaProducto->actualizarCostoPromedio($cantidadFacturada, $costoUnitario);
        }

        // Agregar regalo sin costo
        if ($cantidadRegalo > 0) {
            $bodegaProducto->agregarStockSinCosto($cantidadRegalo);
        }
    }

    protected function getEstadoLabel(string $estado): string
    {
        return match ($estado) {
            'borrador' => 'Borrador',
            'ordenada' => 'Ordenada',
            'recibida_pagada' => 'Recibida y Pagada ✅',
            'recibida_pendiente_pago' => 'Recibida - Debo Pagar 📦💰',
            'por_recibir_pagada' => 'Pagada - Falta Recibir 💰📦',
            'por_recibir_pendiente_pago' => 'Pendiente Todo ⏳',
            'cancelada' => 'Cancelada ❌',
            default => $estado,
        };
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información General')
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
                                    ->formatStateUsing(fn($state) => $state === 'contado' ? 'Contado' : 'Crédito')
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
                                    ->label('Fecha de Creación')
                                    ->dateTime('d/m/Y H:i'),

                                Infolists\Components\TextEntry::make('total')
                                    ->label('Total')
                                    ->money('HNL')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('success'),

                                Infolists\Components\TextEntry::make('interes_acumulado')
                                    ->label('Interés Acumulado')
                                    ->money('HNL')
                                    ->getStateUsing(fn($record) => $record->getInteresAcumulado())
                                    ->visible(fn($record) => $record->tipo_pago === 'credito' && $record->getInteresAcumulado() > 0)
                                    ->color('warning'),

                                Infolists\Components\TextEntry::make('saldo_total')
                                    ->label('Saldo Total (con intereses)')
                                    ->money('HNL')
                                    ->getStateUsing(fn($record) => $record->getSaldoConIntereses())
                                    ->visible(fn($record) => $record->tipo_pago === 'credito' && $record->getInteresAcumulado() > 0)
                                    ->weight('bold')
                                    ->color('danger'),

                                Infolists\Components\TextEntry::make('nota')
                                    ->label('Notas')
                                    ->columnSpanFull()
                                    ->visible(fn($record) => !empty($record->nota))
                                    ->markdown()
                                    ->color('warning'),
                            ]),
                    ]),

                // ISV Crédito Fiscal
                Infolists\Components\Section::make('ISV Crédito Fiscal')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('total_isv_credito')
                                    ->label('Total ISV Crédito')
                                    ->money('HNL')
                                    ->getStateUsing(fn($record) => $record->detalles()->sum('isv_credito') ?? 0)
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('productos_con_isv')
                                    ->label('Productos con ISV')
                                    ->getStateUsing(fn($record) => $record->detalles()->whereNotNull('costo_sin_isv')->where('costo_sin_isv', '>', 0)->count())
                                    ->suffix(' producto(s)'),

                                Infolists\Components\TextEntry::make('info_isv')
                                    ->label('')
                                    ->getStateUsing(fn() => 'Este monto es deducible en tu declaración fiscal')
                                    ->color('gray'),
                            ]),
                    ])
                    ->visible(fn($record) => $record->detalles()->whereNotNull('isv_credito')->where('isv_credito', '>', 0)->exists())
                    ->collapsible()
                    ->collapsed(),

                Infolists\Components\Section::make('Información de Crédito')
                    ->schema([
                        Infolists\Components\Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('interes_porcentaje')
                                    ->label('Tasa de Interés')
                                    ->suffix('%')
                                    ->numeric(decimalPlaces: 2),

                                Infolists\Components\TextEntry::make('periodo_interes')
                                    ->label('Periodo de Interés')
                                    ->formatStateUsing(fn($state) => $state === 'semanal' ? 'Semanal' : 'Mensual')
                                    ->badge()
                                    ->color('info'),

                                Infolists\Components\TextEntry::make('fecha_inicio_credito')
                                    ->label('Fecha Inicio Crédito')
                                    ->date('d/m/Y'),

                                Infolists\Components\TextEntry::make('periodos_transcurridos')
                                    ->label('Periodos Transcurridos')
                                    ->getStateUsing(fn($record) => $record->getPeriodosTranscurridos())
                                    ->suffix(fn($record) => ' ' . ($record->periodo_interes === 'semanal' ? 'semanas' : 'meses')),
                            ]),
                    ])
                    ->visible(fn($record) => $record->tipo_pago === 'credito')
                    ->collapsible(),

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

                                Infolists\Components\TextEntry::make('cantidad_recibida')
                                    ->label('Total')
                                    ->numeric(decimalPlaces: 0)
                                    ->weight('bold')
                                    ->suffix(' total'),

                                Infolists\Components\TextEntry::make('precio_unitario')
                                    ->label('Precio Unit.')
                                    ->money('HNL'),

                                Infolists\Components\TextEntry::make('costo_sin_isv')
                                    ->label('Costo Real')
                                    ->money('HNL')
                                    ->color('success')
                                    ->visible(fn($record) => !empty($record->costo_sin_isv) && $record->costo_sin_isv > 0)
                                    ->helperText('Sin ISV'),

                                Infolists\Components\TextEntry::make('isv_credito')
                                    ->label('ISV')
                                    ->money('HNL')
                                    ->color('info')
                                    ->visible(fn($record) => !empty($record->isv_credito) && $record->isv_credito > 0),

                                Infolists\Components\TextEntry::make('descuento')
                                    ->label('Descuento')
                                    ->money('HNL')
                                    ->visible(fn($record) => $record->descuento > 0),

                                Infolists\Components\TextEntry::make('impuesto')
                                    ->label('Impuesto')
                                    ->money('HNL')
                                    ->visible(fn($record) => $record->impuesto > 0),

                                Infolists\Components\TextEntry::make('subtotal')
                                    ->label('Subtotal')
                                    ->money('HNL')
                                    ->weight('bold'),
                            ])
                            ->columns(10),
                    ]),
            ]);
    }
}