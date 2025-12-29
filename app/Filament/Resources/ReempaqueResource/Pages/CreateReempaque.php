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

        $totalHuevosUsados = (int) collect($lotesSeleccionados)->sum('cantidad_huevos');
        $merma = (int) ($data['merma'] ?? 0);
        $huevosUtiles = $totalHuevosUsados - $merma;

        // Calcular totales de facturados, regalo y costo
        $costoTotalPagado = 0;
        $huevosFacturadosTotales = 0;
        $huevosRegaloTotales = 0;

        foreach ($lotesSeleccionados as $loteData) {
            $lote = Lote::find($loteData['lote_id']);
            if ($lote) {
                $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);

                // Para lotes SUELTOS-*, usar costo_por_huevo directamente
                $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

                if ($esLoteSueltos) {
                    $costoTotalPagado += ($lote->costo_por_huevo ?? 0) * $huevosUsados;
                    if (($lote->costo_por_huevo ?? 0) > 0) {
                        $huevosFacturadosTotales += $huevosUsados;
                    }
                } else {
                    // Lote normal: usar proporcion
                    $proporcion = $lote->cantidad_huevos_original > 0
                        ? $huevosUsados / $lote->cantidad_huevos_original
                        : 0;

                    $costoTotalPagado += ($lote->costo_total_lote ?? 0) * $proporcion;
                    $huevosFacturadosTotales += (int) (($lote->cantidad_cartones_facturados ?? 0) * 30 * $proporcion);
                    $huevosRegaloTotales += (int) (($lote->cantidad_cartones_regalo ?? 0) * 30 * $proporcion);
                }
            }
        }
        $costoTotalPagado = round($costoTotalPagado, 2);

        // CALCULAR COSTO UNITARIO SEGUN LA LOGICA DE MERMA VS REGALO
        $costoUnitarioPromedio = 0;
        if ($huevosFacturadosTotales > 0) {
            if ($merma <= $huevosRegaloTotales) {
                $costoUnitarioPromedio = $costoTotalPagado / $huevosFacturadosTotales;
            } else {
                $mermaPagada = $merma - $huevosRegaloTotales;
                $huevosUtilesPagados = $huevosFacturadosTotales - $mermaPagada;
                if ($huevosUtilesPagados > 0) {
                    $costoUnitarioPromedio = $costoTotalPagado / $huevosUtilesPagados;
                }
            }
        }
        $costoUnitarioPromedio = ceil($costoUnitarioPromedio * 100) / 100;

        // Validar que el empaque cuadre
        $cartones30 = (int) ($data['cartones_30'] ?? 0);
        $cartones15 = (int) ($data['cartones_15'] ?? 0);
        $sueltos = (int) ($data['huevos_sueltos'] ?? 0);
        $totalEmpacado = ($cartones30 * 30) + ($cartones15 * 15) + $sueltos;

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

        // Guardar datos para usar en afterCreate
        $data['_huevos_facturados_totales'] = $huevosFacturadosTotales;
        $data['_huevos_regalo_totales'] = $huevosRegaloTotales;

        return $data;
    }

    protected function afterCreate(): void
    {
        $reempaque = $this->record;
        $data = $this->data;

        DB::transaction(function () use ($reempaque, $data) {
            $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
            $merma = (int) ($data['merma'] ?? 0);

            // =====================================================
            // 🔧 FIX: RECALCULAR huevosRegaloTotales AQUÍ
            // porque $this->data no tiene los valores de mutateFormDataBeforeCreate
            // =====================================================
            $huevosRegaloTotales = 0;
            $huevosFacturadosTotales = 0;
            $costoTotalPagado = 0;

            foreach ($lotesSeleccionados as $loteData) {
                $lote = Lote::find($loteData['lote_id']);
                if ($lote) {
                    $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);
                    $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

                    if ($esLoteSueltos) {
                        $costoTotalPagado += ($lote->costo_por_huevo ?? 0) * $huevosUsados;
                        if (($lote->costo_por_huevo ?? 0) > 0) {
                            $huevosFacturadosTotales += $huevosUsados;
                        }
                    } else {
                        $proporcion = $lote->cantidad_huevos_original > 0
                            ? $huevosUsados / $lote->cantidad_huevos_original
                            : 0;

                        $costoTotalPagado += ($lote->costo_total_lote ?? 0) * $proporcion;
                        $huevosFacturadosTotales += (int) (($lote->cantidad_cartones_facturados ?? 0) * 30 * $proporcion);
                        $huevosRegaloTotales += (int) (($lote->cantidad_cartones_regalo ?? 0) * 30 * $proporcion);
                    }
                }
            }
            // =====================================================
            // FIN DEL FIX
            // =====================================================

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

                // Detectar si es lote de sueltos consolidado
                $esLoteSueltos = str_starts_with($lote->numero_lote ?? '', 'SUELTOS-');

                if ($esLoteSueltos) {
                    $costoParcial = round(($lote->costo_por_huevo ?? 0) * $huevosUsados, 2);
                    $cartonesFacturadosUsados = 0;
                    $cartonesRegaloUsados = 0;
                } else {
                    $proporcion = $lote->cantidad_huevos_original > 0
                        ? $huevosUsados / $lote->cantidad_huevos_original
                        : 0;

                    $costoParcial = round(($lote->costo_total_lote ?? 0) * $proporcion, 2);
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

            // 2. Crear productos finales y agregarlos al stock
            $bodegaId = $reempaque->bodega_id;
            $costoUnitarioPorHuevo = $reempaque->costo_unitario_promedio;
            $tipo = $data['tipo'] ?? 'individual';

            // Cartones de 30
            if ($reempaque->cartones_30 > 0) {
                $categoriaId = $this->obtenerCategoriaProducto(
                    $tipo,
                    $data,
                    'categoria_carton_30_id',
                    30
                );

                $this->crearYAgregarProducto(
                    $reempaque,
                    $categoriaId,
                    30,
                    $reempaque->cartones_30,
                    $costoUnitarioPorHuevo * 30,
                    $bodegaId
                );
            }

            // Cartones de 15
            if ($reempaque->cartones_15 > 0) {
                $categoriaId = $this->obtenerCategoriaProducto(
                    $tipo,
                    $data,
                    'categoria_carton_15_id',
                    15
                );

                $this->crearYAgregarProducto(
                    $reempaque,
                    $categoriaId,
                    15,
                    $reempaque->cartones_15,
                    $costoUnitarioPorHuevo * 15,
                    $bodegaId
                );
            }

            // 3. HUEVOS SUELTOS -> Consolidar en lote unico por bodega
            if ($reempaque->huevos_sueltos > 0) {
                $this->consolidarSueltos(
                    $reempaque,
                    $lotesSeleccionados,
                    $bodegaId,
                    $merma,
                    $huevosRegaloTotales,      // ← Ahora viene del recálculo
                    $huevosFacturadosTotales,  // ← Ahora viene del recálculo
                    $costoUnitarioPorHuevo
                );
            }
        });
    }

    /**
     * Consolidar sueltos en un unico lote por bodega
     *
     * LOGICA DE COSTO:
     * - Si merma <= regalo: Los sueltos son huevos GRATIS (costo = 0)
     * - Si merma > regalo: Los sueltos son huevos PAGADOS (costo = costo recalculado)
     */
    protected function consolidarSueltos(
        $reempaque,
        array $lotesSeleccionados,
        int $bodegaId,
        int $merma,
        int $huevosRegaloTotales,
        int $huevosFacturadosTotales,
        float $costoUnitarioPorHuevo
    ): void {
        // Obtener el primer lote para heredar producto y proveedor
        $primerLoteData = reset($lotesSeleccionados);
        $primerLote = Lote::find($primerLoteData['lote_id']);

        if (!$primerLote) {
            return;
        }

        $cantidadNueva = (int) $reempaque->huevos_sueltos;

        // =====================================================
        // 🎯 LOGICA CORRECTA DE COSTO PARA SUELTOS
        // Si merma <= regalo: los sueltos vienen de huevos gratis = costo 0
        // Si merma > regalo: los sueltos vienen de huevos pagados = costo recalculado
        // =====================================================
        if ($merma <= $huevosRegaloTotales) {
            // Los sueltos son huevos de REGALO (gratis)
            $costoNuevo = 0.00;
        } else {
            // Los sueltos son huevos PAGADOS
            $costoNuevo = round($costoUnitarioPorHuevo, 2);
        }

        // Buscar lote consolidado existente para esta bodega
        $numeroLoteConsolidado = "SUELTOS-B{$bodegaId}";
        $loteExistente = Lote::where('numero_lote', $numeroLoteConsolidado)
            ->where('bodega_id', $bodegaId)
            ->first();

        if ($loteExistente) {
            // ACTUALIZAR: Agregar al lote existente con promedio ponderado
            $cantidadActual = (int) $loteExistente->cantidad_huevos_remanente;
            $costoActual = (float) $loteExistente->costo_por_huevo;

            // Promedio ponderado del costo
            $cantidadTotal = $cantidadActual + $cantidadNueva;

            // Calcular promedio ponderado solo si ambos tienen valores
            if ($cantidadTotal > 0) {
                $costoPromedioPonderado = (($cantidadActual * $costoActual) + ($cantidadNueva * $costoNuevo)) / $cantidadTotal;
            } else {
                $costoPromedioPonderado = 0;
            }

            $costoPromedioPonderado = round($costoPromedioPonderado, 2);
            $costoTotalNuevo = round($cantidadTotal * $costoPromedioPonderado, 2);

            $loteExistente->update([
                'cantidad_huevos_original' => $cantidadTotal,
                'cantidad_huevos_remanente' => $cantidadTotal,
                'costo_total_lote' => $costoTotalNuevo,
                'costo_por_huevo' => $costoPromedioPonderado,
                'estado' => 'disponible',
                'reempaque_origen_id' => $reempaque->id,
            ]);
        } else {
            // CREAR: Nuevo lote consolidado para esta bodega
            $costoTotalSueltos = round($cantidadNueva * $costoNuevo, 2);

            Lote::create([
                'compra_id' => null,
                'compra_detalle_id' => null,
                'reempaque_origen_id' => $reempaque->id,
                'producto_id' => $primerLote->producto_id,
                'proveedor_id' => $primerLote->proveedor_id,
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

    protected function obtenerCategoriaProducto(
        string $tipo,
        array $data,
        string $categoriaFieldName,
        int $huevosPorUnidad
    ): int {
        if ($tipo === 'individual') {
            $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
            if (!empty($lotesSeleccionados)) {
                $primerLoteData = reset($lotesSeleccionados);
                if ($primerLoteData && isset($primerLoteData['lote_id'])) {
                    $primerLote = Lote::find($primerLoteData['lote_id']);
                    if ($primerLote && $primerLote->producto) {
                        return $primerLote->producto->categoria_id;
                    }
                }
            }
            throw new \Exception("No se pudo obtener la categoria del lote original");
        }

        if (!isset($data[$categoriaFieldName])) {
            throw new \Exception("Debes seleccionar la categoria para productos de {$huevosPorUnidad} huevos");
        }

        return $data[$categoriaFieldName];
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
                          ->orWhere('nombre', 'LIKE', '%carton%')
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
