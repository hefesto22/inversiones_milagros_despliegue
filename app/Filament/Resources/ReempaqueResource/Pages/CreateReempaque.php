<?php

namespace App\Filament\Resources\ReempaqueResource\Pages;

use App\Filament\Resources\ReempaqueResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ReempaqueLote;
use App\Models\ReempaqueProducto;

class CreateReempaque extends CreateRecord
{
    protected static string $resource = ReempaqueResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
        $distribuciones = $data['distribuciones'] ?? [];

        $totalHuevosUsados = (int) collect($lotesSeleccionados)->sum('cantidad_huevos');
        $merma = (int) ($data['merma'] ?? 0);
        $huevosUtiles = $totalHuevosUsados - $merma;

        // =====================================================
        // CALCULAR COSTO Y REGALO DISPONIBLE (ACUMULADO)
        // =====================================================
        $costoTotalPagado = 0;
        $huevosFacturadosTotales = 0;
        $regaloDisponibleTotal = 0; // 🆕 Regalo REAL disponible (ya descontando mermas anteriores)

        foreach ($lotesSeleccionados as $loteData) {
            $lote = Lote::find($loteData['lote_id']);
            if (!$lote) continue;

            $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);
            $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

            if ($esLoteSueltos) {
                // Lote consolidado de sueltos - usar costo promedio almacenado
                $costoTotalPagado += ($lote->costo_por_huevo ?? 0) * $huevosUsados;
                $huevosFacturadosTotales += $huevosUsados;
                // Lotes sueltos NO tienen regalo
            } else {
                // Lote normal - calcular basado en precio de factura
                $huevosFacturadosLote = ($lote->cantidad_cartones_facturados ?? 0) * 30;

                // Costo por huevo basado SOLO en facturados
                $costoPorHuevoFacturado = $huevosFacturadosLote > 0
                    ? ($lote->costo_total_lote ?? 0) / $huevosFacturadosLote
                    : 0;

                // El costo parcial es: huevos usados × costo por huevo facturado
                $costoTotalPagado += $huevosUsados * $costoPorHuevoFacturado;
                $huevosFacturadosTotales += $huevosUsados;

                // 🆕 REGALO DISPONIBLE REAL (ya considera mermas anteriores)
                $regaloDisponibleTotal += $lote->getHuevosRegaloDisponibles();
            }
        }
        $costoTotalPagado = round($costoTotalPagado, 2);

        // =====================================================
        // CALCULAR COSTO UNITARIO SEGUN MERMA VS REGALO DISPONIBLE
        // =====================================================
        // Regla: Si merma <= regalo_disponible, el costo NO cambia
        $costoUnitarioPromedio = 0;
        if ($huevosFacturadosTotales > 0) {
            if ($merma <= $regaloDisponibleTotal) {
                // Sin pérdida económica - costo = precio factura exacto
                $costoUnitarioPromedio = $costoTotalPagado / $huevosFacturadosTotales;
            } else {
                // Pérdida económica - la merma superó el regalo disponible
                $mermaPagada = $merma - $regaloDisponibleTotal;
                $huevosUtilesPagados = $huevosFacturadosTotales - $mermaPagada;
                if ($huevosUtilesPagados > 0) {
                    $costoUnitarioPromedio = $costoTotalPagado / $huevosUtilesPagados;
                }
            }
        }
        // Redondear a 4 decimales para precisión
        $costoUnitarioPromedio = round($costoUnitarioPromedio, 4);

        // Calcular totales desde distribuciones
        $cartones30Total = 0;
        $cartones15Total = 0;

        foreach ($distribuciones as $dist) {
            $cantidad = (int) ($dist['cantidad'] ?? 0);
            $tipo = $dist['tipo_empaque'] ?? 'carton_30';

            if ($tipo === 'carton_30') {
                $cartones30Total += $cantidad;
            } else {
                $cartones15Total += $cantidad;
            }
        }

        $sueltos = (int) ($data['huevos_sueltos'] ?? 0);

        // Validar que el empaque cuadre
        $totalEmpacado = ($cartones30Total * 30) + ($cartones15Total * 15) + $sueltos;

        if (abs($totalEmpacado - $huevosUtiles) > 0.01) {
            throw new \Exception("Error: El total empacado ({$totalEmpacado}) no coincide con los huevos utiles ({$huevosUtiles})");
        }

        $data['numero_reempaque'] = $this->generarNumeroReempaque($data['bodega_id']);
        $data['total_huevos_usados'] = $totalHuevosUsados;
        $data['huevos_utiles'] = $huevosUtiles;
        $data['costo_total'] = $costoTotalPagado;
        $data['costo_unitario_promedio'] = $costoUnitarioPromedio;
        $data['estado'] = 'completado';
        $data['created_by'] = Auth::id();

        // Guardar totales para compatibilidad con tabla existente
        $data['cartones_30'] = $cartones30Total;
        $data['cartones_15'] = $cartones15Total;
        $data['huevos_sueltos'] = $sueltos;

        // Guardar datos para usar en afterCreate
        $data['_huevos_facturados_totales'] = $huevosFacturadosTotales;
        $data['_regalo_disponible_total'] = $regaloDisponibleTotal;

        return $data;
    }

    protected function afterCreate(): void
    {
        $reempaque = $this->record;
        $data = $this->data;

        DB::transaction(function () use ($reempaque, $data) {
            $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
            $distribuciones = $data['distribuciones'] ?? [];
            $merma = (int) ($data['merma'] ?? 0);

            // Recalcular valores necesarios
            $regaloDisponibleTotal = 0;
            $huevosFacturadosTotales = 0;
            $costoTotalPagado = 0;

            foreach ($lotesSeleccionados as $loteData) {
                $lote = Lote::find($loteData['lote_id']);
                if (!$lote) continue;

                $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);
                $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

                if ($esLoteSueltos) {
                    $costoTotalPagado += ($lote->costo_por_huevo ?? 0) * $huevosUsados;
                    $huevosFacturadosTotales += $huevosUsados;
                } else {
                    $huevosFacturadosLote = ($lote->cantidad_cartones_facturados ?? 0) * 30;
                    $costoPorHuevoFacturado = $huevosFacturadosLote > 0
                        ? ($lote->costo_total_lote ?? 0) / $huevosFacturadosLote
                        : 0;

                    $costoTotalPagado += $huevosUsados * $costoPorHuevoFacturado;
                    $huevosFacturadosTotales += $huevosUsados;
                    $regaloDisponibleTotal += $lote->getHuevosRegaloDisponibles();
                }
            }

            // 1. Crear registros en reempaque_lotes y reducir remanente
            foreach ($lotesSeleccionados as $loteData) {
                $lote = Lote::find($loteData['lote_id']);

                if (!$lote) {
                    continue;
                }

                $cartonesC30 = (int) ($loteData['cantidad_c30'] ?? 0);
                $cartonesC15 = (int) ($loteData['cantidad_c15'] ?? 0);
                $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);

                $cartonesEquivalentes = $cartonesC30 + ($cartonesC15 * 0.5);
                $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

                if ($esLoteSueltos) {
                    $costoParcial = round(($lote->costo_por_huevo ?? 0) * $huevosUsados, 2);
                    $cartonesFacturadosUsados = 0;
                    $cartonesRegaloUsados = 0;
                } else {
                    // Calcular costo parcial basado en precio de factura
                    $huevosFacturadosLote = ($lote->cantidad_cartones_facturados ?? 0) * 30;
                    $costoPorHuevoFacturado = $huevosFacturadosLote > 0
                        ? ($lote->costo_total_lote ?? 0) / $huevosFacturadosLote
                        : 0;

                    $costoParcial = round($huevosUsados * $costoPorHuevoFacturado, 2);

                    // Para registro histórico, calcular proporciones
                    $proporcion = $lote->cantidad_huevos_original > 0
                        ? $huevosUsados / $lote->cantidad_huevos_original
                        : 0;
                    $cartonesFacturadosUsados = round(($lote->cantidad_cartones_facturados ?? 0) * $proporcion, 2);
                    $cartonesRegaloUsados = round(($lote->cantidad_cartones_regalo ?? 0) * $proporcion, 2);
                }

                ReempaqueLote::create([
                    'reempaque_id' => $reempaque->id,
                    'lote_id' => $loteData['lote_id'],
                    'cantidad_cartones_usados' => round($cartonesEquivalentes, 2),
                    'cantidad_huevos_usados' => $huevosUsados,
                    'cartones_facturados_usados' => $cartonesFacturadosUsados,
                    'cartones_regalo_usados' => $cartonesRegaloUsados,
                    'costo_parcial' => $costoParcial,
                ]);

                // Reducir remanente del lote origen
                $lote->reducirRemanente($huevosUsados);
            }

            // 2. Procesar cada linea de distribucion
            $bodegaId = $reempaque->bodega_id;
            $costoUnitarioPorHuevo = $reempaque->costo_unitario_promedio;

            foreach ($distribuciones as $dist) {
                $categoriaId = $dist['categoria_id'] ?? null;
                $tipoEmpaque = $dist['tipo_empaque'] ?? 'carton_30';
                $cantidad = (int) ($dist['cantidad'] ?? 0);

                if (!$categoriaId || $cantidad <= 0) {
                    continue;
                }

                $huevosPorUnidad = $tipoEmpaque === 'carton_30' ? 30 : 15;
                $costoUnitarioProducto = $costoUnitarioPorHuevo * $huevosPorUnidad;

                $this->crearYAgregarProducto(
                    $reempaque,
                    $categoriaId,
                    $huevosPorUnidad,
                    $cantidad,
                    $costoUnitarioProducto,
                    $bodegaId
                );
            }

            // 3. HUEVOS SUELTOS -> Consolidar en lote unico por bodega y producto
            if ($reempaque->huevos_sueltos > 0) {
                $this->consolidarSueltos(
                    $reempaque,
                    $lotesSeleccionados,
                    $bodegaId,
                    $merma,
                    $regaloDisponibleTotal,
                    $huevosFacturadosTotales,
                    $costoUnitarioPorHuevo
                );
            }
        });
    }

    /**
     * Consolidar sueltos en un unico lote por bodega y producto
     */
    protected function consolidarSueltos(
        $reempaque,
        array $lotesSeleccionados,
        int $bodegaId,
        int $merma,
        float $regaloDisponibleTotal,
        int $huevosFacturadosTotales,
        float $costoUnitarioPorHuevo
    ): void {
        $primerLoteData = reset($lotesSeleccionados);
        $primerLote = Lote::find($primerLoteData['lote_id']);

        if (!$primerLote) {
            return;
        }

        $productoId = $primerLote->producto_id;
        $proveedorId = $primerLote->proveedor_id; // 🎯 Usar proveedor del primer lote
        $cantidadNueva = (int) $reempaque->huevos_sueltos;

        // Logica de costo para sueltos
        // Si merma <= regalo disponible, los sueltos tienen costo normal (no gratis)
        // porque el regalo es buffer de merma, no reduce el costo base
        // 🎯 COSTO PARA SUELTOS: Siempre usar costo ORIGINAL (sin ajuste por merma)
        // La merma es una pérdida, NO debe inflar el costo de los huevos que sobrevivieron
        // Calcular costo original: costo_total / huevos_usados (antes de merma)
        $costoTotalLotes = 0;
        $huevosTotalLotes = 0;

        foreach ($lotesSeleccionados as $loteData) {
            $lote = Lote::find($loteData['lote_id']);
            if (!$lote) continue;

            $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);
            $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

            if ($esLoteSueltos) {
                $costoTotalLotes += ($lote->costo_por_huevo ?? 0) * $huevosUsados;
            } else {
                $huevosFacturadosLote = ($lote->cantidad_cartones_facturados ?? 0) * 30;
                $costoPorHuevoFacturado = $huevosFacturadosLote > 0
                    ? ($lote->costo_total_lote ?? 0) / $huevosFacturadosLote
                    : 0;
                $costoTotalLotes += $huevosUsados * $costoPorHuevoFacturado;
            }
            $huevosTotalLotes += $huevosUsados;
        }

        // Costo por huevo ORIGINAL (sin merma)
        $costoNuevo = $huevosTotalLotes > 0
            ? round($costoTotalLotes / $huevosTotalLotes, 4)
            : 0;

        // 🎯 Formato incluye producto_id para evitar conflicto de índice único
        $numeroLoteConsolidado = "SUELTOS-B{$bodegaId}-P{$productoId}";

        // Buscar lote existente (sin importar estado)
        $loteExistente = Lote::where('numero_lote', $numeroLoteConsolidado)
            ->where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->first();

        if ($loteExistente) {
            $cantidadActual = (int) $loteExistente->cantidad_huevos_remanente;
            $costoActual = (float) $loteExistente->costo_por_huevo;

            $cantidadTotal = $cantidadActual + $cantidadNueva;

            if ($cantidadTotal > 0 && $cantidadActual > 0) {
                $costoPromedioPonderado = (($cantidadActual * $costoActual) + ($cantidadNueva * $costoNuevo)) / $cantidadTotal;
            } else {
                $costoPromedioPonderado = $costoNuevo;
            }

            $costoPromedioPonderado = round($costoPromedioPonderado, 4);
            $costoTotalNuevo = round($cantidadTotal * $costoPromedioPonderado, 2);

            $loteExistente->update([
                'cantidad_huevos_original' => $loteExistente->cantidad_huevos_original + $cantidadNueva,
                'cantidad_huevos_remanente' => $cantidadTotal,
                'costo_total_lote' => $costoTotalNuevo,
                'costo_por_huevo' => $costoPromedioPonderado,
                'estado' => 'disponible', // 🎯 Reactivar si estaba agotado
                'reempaque_origen_id' => $reempaque->id,
            ]);
        } else {
            $costoTotalSueltos = round($cantidadNueva * $costoNuevo, 2);

            Lote::create([
                'compra_id' => null,
                'compra_detalle_id' => null,
                'reempaque_origen_id' => $reempaque->id,
                'producto_id' => $productoId,
                'proveedor_id' => $proveedorId, // 🎯 CORREGIDO: Usar proveedor del lote origen
                'bodega_id' => $bodegaId,
                'numero_lote' => $numeroLoteConsolidado,
                'cantidad_cartones_facturados' => 0,
                'cantidad_cartones_regalo' => 0,
                'cantidad_cartones_recibidos' => 0,
                'huevos_por_carton' => 30,
                'cantidad_huevos_original' => $cantidadNueva,
                'cantidad_huevos_remanente' => $cantidadNueva,
                'costo_total_lote' => $costoTotalSueltos,
                'costo_por_carton_facturado' => 0,
                'costo_por_huevo' => $costoNuevo,
                'estado' => 'disponible',
                'created_by' => Auth::id(),
            ]);
        }
    }

    protected function crearYAgregarProducto(
        $reempaque,
        int $categoriaId,
        int $huevosPorUnidad,
        float $cantidad,
        float $costoUnitarioProducto,
        int $bodegaId
    ): void {
        // Buscar producto por categoria y unidad
        $producto = Producto::where('categoria_id', $categoriaId)
            ->where('activo', true)
            ->whereHas('unidad', function ($query) use ($huevosPorUnidad) {
                if ($huevosPorUnidad == 30) {
                    $query->where(function ($q) {
                        $q->where('nombre', 'LIKE', '%carton%')
                            ->orWhere('nombre', 'LIKE', '%Carton%')
                            ->orWhere('nombre', 'LIKE', '%30%');
                    })
                        ->where('nombre', 'NOT LIKE', '%medio%')
                        ->where('nombre', 'NOT LIKE', '%15%');
                } elseif ($huevosPorUnidad == 15) {
                    $query->where(function ($q) {
                        $q->where('nombre', 'LIKE', '%medio%')
                            ->orWhere('nombre', 'LIKE', '%15%');
                    });
                }
            })
            ->first();

        if (!$producto) {
            $tipoUnidad = $huevosPorUnidad == 30 ? 'Carton (30 huevos)' : 'Medio Carton (15 huevos)';
            throw new \Exception(
                "No se encontro un producto para la categoria ID {$categoriaId} " .
                    "con unidad tipo '{$tipoUnidad}'. " .
                    "Verifica que exista un producto con esa categoria y unidad."
            );
        }

        // Redondear hacia arriba el costo unitario (2 decimales)
        $costoUnitarioProducto = ceil($costoUnitarioProducto * 100) / 100;
        $costoTotal = round($cantidad * $costoUnitarioProducto, 2);

        $reempaqueProducto = ReempaqueProducto::create([
            'reempaque_id' => $reempaque->id,
            'producto_id' => $producto->id,
            'bodega_id' => $bodegaId,
            'categoria_id' => $categoriaId,
            'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitarioProducto,
            'costo_total' => $costoTotal,
            'agregado_a_stock' => false,
        ]);

        $reempaqueProducto->agregarAStock();
    }

    protected function generarNumeroReempaque(int $bodegaId): string
    {
        $ultimoReempaque = \App\Models\Reempaque::where('numero_reempaque', 'LIKE', "R-B{$bodegaId}-%")
            ->orderBy('numero_reempaque', 'desc')
            ->value('numero_reempaque');

        if ($ultimoReempaque) {
            $prefijo = "R-B{$bodegaId}-";
            $numeroSecuencial = (int) str_replace($prefijo, '', $ultimoReempaque);
            $nuevoNumero = $numeroSecuencial + 1;
        } else {
            $nuevoNumero = 1;
        }

        $numeroFormateado = str_pad($nuevoNumero, 6, '0', STR_PAD_LEFT);
        return "R-B{$bodegaId}-{$numeroFormateado}";
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
