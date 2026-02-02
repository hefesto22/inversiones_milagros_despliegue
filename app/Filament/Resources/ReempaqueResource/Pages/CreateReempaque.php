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

    // Datos calculados para compartir entre métodos
    private array $datosCalculados = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
        $distribuciones = $data['distribuciones'] ?? [];

        // Calcular datos una sola vez
        $this->datosCalculados = $this->calcularDatosLotes($lotesSeleccionados);

        $totalHuevosUsados = $this->datosCalculados['total_huevos'];
        $huevosUtiles = $totalHuevosUsados; // Ya no hay merma aquí

        // Calcular costo unitario (sin merma)
        $costoUnitarioPromedio = $this->datosCalculados['total_huevos'] > 0
            ? round($this->datosCalculados['costo_total'] / $this->datosCalculados['total_huevos'], 4)
            : 0;

        // Calcular totales de distribuciones usando unidad_id
        $cartones30Total = 0;
        $cartones15Total = 0;

        foreach ($distribuciones as $dist) {
            $cantidad = (int) ($dist['cantidad'] ?? 0);
            $unidadId = $dist['unidad_id'] ?? null;
            
            if ($unidadId) {
                $unidad = \App\Models\Unidad::find($unidadId);
                if ($unidad && str_contains($unidad->nombre, '15')) {
                    $cartones15Total += $cantidad;
                } else {
                    $cartones30Total += $cantidad;
                }
            }
        }

        // Validar que el empaque cuadre
        $totalEmpacado = ($cartones30Total * 30) + ($cartones15Total * 15);

        if (abs($totalEmpacado - $huevosUtiles) > 0.01) {
            throw new \Exception("Error: El total empacado ({$totalEmpacado}) no coincide con los huevos útiles ({$huevosUtiles})");
        }

        $data['numero_reempaque'] = $this->generarNumeroReempaque($data['bodega_id']);
        $data['total_huevos_usados'] = $totalHuevosUsados;
        $data['huevos_utiles'] = $huevosUtiles;
        $data['costo_total'] = $this->datosCalculados['costo_total'];
        $data['costo_unitario_promedio'] = $costoUnitarioPromedio;
        $data['estado'] = 'completado';
        $data['created_by'] = Auth::id();
        $data['cartones_30'] = $cartones30Total;
        $data['cartones_15'] = $cartones15Total;
        $data['huevos_sueltos'] = 0; // Ya no hay sueltos
        $data['merma'] = 0; // Ya no hay merma aquí

        return $data;
    }

    protected function afterCreate(): void
    {
        $reempaque = $this->record;
        $data = $this->data;

        DB::transaction(function () use ($reempaque, $data) {
            $lotesSeleccionados = $data['lotes_seleccionados'] ?? [];
            $distribuciones = $data['distribuciones'] ?? [];
            $bodegaId = $reempaque->bodega_id;

            // 1. Procesar lotes origen
            foreach ($lotesSeleccionados as $loteData) {
                $this->procesarLoteOrigen($reempaque, $loteData);
            }

            // 2. Crear productos destino
            foreach ($distribuciones as $dist) {
                $this->crearProductoDestino($reempaque, $dist, $bodegaId);
            }
        });
    }

    /**
     * Calcular datos de los lotes una sola vez
     * IMPORTANTE: Usa costo_por_carton_facturado del lote para mayor precisión
     */
    private function calcularDatosLotes(array $lotesSeleccionados): array
    {
        $costoTotal = 0;
        $totalHuevos = 0;

        foreach ($lotesSeleccionados as $loteData) {
            $lote = Lote::find($loteData['lote_id']);
            if (!$lote) continue;

            $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);
            $totalHuevos += $huevosUsados;

            // Usar costo_por_carton_facturado para mayor precisión
            // (es el mismo valor que se muestra en el formulario)
            $cartonesUsados = (int) ($loteData['cantidad_c30'] ?? 0);
            $costoPorCarton = $lote->costo_por_carton_facturado ?? 0;
            $costoTotal += $cartonesUsados * $costoPorCarton;
        }

        return [
            'costo_total' => round($costoTotal, 2),
            'total_huevos' => $totalHuevos,
        ];
    }
    private function procesarLoteOrigen($reempaque, array $loteData): void
    {
        $lote = Lote::find($loteData['lote_id']);
        if (!$lote) return;

        $cartonesC30 = (int) ($loteData['cantidad_c30'] ?? 0);
        $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);

        // Usar costo_por_carton_facturado para mayor precisión
        $costoPorCarton = $lote->costo_por_carton_facturado ?? 0;
        $costoParcial = round($cartonesC30 * $costoPorCarton, 2);

        // Calcular proporción de cartones usados
        $proporcion = $lote->cantidad_huevos_original > 0
            ? $huevosUsados / $lote->cantidad_huevos_original
            : 0;
        $cartonesFacturadosUsados = round(($lote->cantidad_cartones_facturados ?? 0) * $proporcion, 2);
        $cartonesRegaloUsados = round(($lote->cantidad_cartones_regalo ?? 0) * $proporcion, 2);

        ReempaqueLote::create([
            'reempaque_id' => $reempaque->id,
            'lote_id' => $loteData['lote_id'],
            'cantidad_cartones_usados' => $cartonesC30,
            'cantidad_huevos_usados' => $huevosUsados,
            'cartones_facturados_usados' => $cartonesFacturadosUsados,
            'cartones_regalo_usados' => $cartonesRegaloUsados,
            'costo_parcial' => $costoParcial,
        ]);

        $lote->reducirRemanente($huevosUsados);
    }

    /**
     * Crear producto destino desde distribución
     */
    private function crearProductoDestino($reempaque, array $dist, int $bodegaId): void
    {
        $categoriaId = $dist['categoria_id'] ?? null;
        $unidadId = $dist['unidad_id'] ?? null;
        $cantidad = (int) ($dist['cantidad'] ?? 0);

        if (!$categoriaId || !$unidadId || $cantidad <= 0) return;

        // Determinar huevos por unidad basado en nombre
        $unidad = \App\Models\Unidad::find($unidadId);
        $huevosPorUnidad = ($unidad && str_contains($unidad->nombre, '15')) ? 15 : 30;

        // Buscar producto por categoría y unidad
        $producto = Producto::where('categoria_id', $categoriaId)
            ->where('unidad_id', $unidadId)
            ->where('activo', true)
            ->first();

        if (!$producto) {
            $categoria = \App\Models\Categoria::find($categoriaId);
            throw new \Exception(
                "No se encontró producto para categoría '{$categoria?->nombre}' con unidad '{$unidad?->nombre}'."
            );
        }

        $costoUnitario = ceil($reempaque->costo_unitario_promedio * $huevosPorUnidad * 100) / 100;
        $costoTotal = round($cantidad * $costoUnitario, 2);

        $reempaqueProducto = ReempaqueProducto::create([
            'reempaque_id' => $reempaque->id,
            'producto_id' => $producto->id,
            'bodega_id' => $bodegaId,
            'categoria_id' => $categoriaId,
            'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitario,
            'costo_total' => $costoTotal,
            'agregado_a_stock' => false,
        ]);

        $reempaqueProducto->agregarAStock();
    }

    /**
     * Consolidar sueltos en lote único por bodega y producto
     */
    private function consolidarSueltos($reempaque, array $lotesSeleccionados, int $bodegaId): void
    {
        $primerLoteData = reset($lotesSeleccionados);
        $primerLote = Lote::find($primerLoteData['lote_id']);

        if (!$primerLote) return;

        $productoId = $primerLote->producto_id;
        $proveedorId = $primerLote->proveedor_id;
        $cantidadNueva = (int) $reempaque->huevos_sueltos;

        // Calcular costo original (sin ajuste por merma)
        $costoNuevo = $this->calcularCostoOriginalLotes($lotesSeleccionados);

        $numeroLote = "SUELTOS-B{$bodegaId}-P{$productoId}";

        $loteExistente = Lote::where('numero_lote', $numeroLote)
            ->where('bodega_id', $bodegaId)
            ->where('producto_id', $productoId)
            ->first();

        if ($loteExistente) {
            $cantidadActual = (int) $loteExistente->cantidad_huevos_remanente;
            $costoActual = (float) $loteExistente->costo_por_huevo;
            $cantidadTotal = $cantidadActual + $cantidadNueva;

            $costoPromedio = ($cantidadTotal > 0 && $cantidadActual > 0)
                ? (($cantidadActual * $costoActual) + ($cantidadNueva * $costoNuevo)) / $cantidadTotal
                : $costoNuevo;

            $loteExistente->update([
                'cantidad_huevos_original' => $loteExistente->cantidad_huevos_original + $cantidadNueva,
                'cantidad_huevos_remanente' => $cantidadTotal,
                'costo_total_lote' => round($cantidadTotal * $costoPromedio, 2),
                'costo_por_huevo' => round($costoPromedio, 4),
                'estado' => 'disponible',
                'reempaque_origen_id' => $reempaque->id,
            ]);
        } else {
            Lote::create([
                'reempaque_origen_id' => $reempaque->id,
                'producto_id' => $productoId,
                'proveedor_id' => $proveedorId,
                'bodega_id' => $bodegaId,
                'numero_lote' => $numeroLote,
                'cantidad_cartones_facturados' => 0,
                'cantidad_cartones_regalo' => 0,
                'cantidad_cartones_recibidos' => 0,
                'huevos_por_carton' => 30,
                'cantidad_huevos_original' => $cantidadNueva,
                'cantidad_huevos_remanente' => $cantidadNueva,
                'costo_total_lote' => round($cantidadNueva * $costoNuevo, 2),
                'costo_por_carton_facturado' => 0,
                'costo_por_huevo' => $costoNuevo,
                'estado' => 'disponible',
                'created_by' => Auth::id(),
            ]);
        }
    }

    /**
     * Calcular costo original de los lotes (sin ajuste por merma)
     */
    private function calcularCostoOriginalLotes(array $lotesSeleccionados): float
    {
        $costoTotal = 0;
        $huevosTotal = 0;

        foreach ($lotesSeleccionados as $loteData) {
            $lote = Lote::find($loteData['lote_id']);
            if (!$lote) continue;

            $huevosUsados = (int) ($loteData['cantidad_huevos'] ?? 0);

            if ($lote->esLoteSueltos()) {
                $costoTotal += ($lote->costo_por_huevo ?? 0) * $huevosUsados;
            } else {
                $huevosFacturados = ($lote->cantidad_cartones_facturados ?? 0) * 30;
                $costoPorHuevo = $huevosFacturados > 0
                    ? ($lote->costo_total_lote ?? 0) / $huevosFacturados
                    : 0;
                $costoTotal += $huevosUsados * $costoPorHuevo;
            }
            $huevosTotal += $huevosUsados;
        }

        return $huevosTotal > 0 ? round($costoTotal / $huevosTotal, 4) : 0;
    }

    private function generarNumeroReempaque(int $bodegaId): string
    {
        $ultimo = \App\Models\Reempaque::where('numero_reempaque', 'LIKE', "R-B{$bodegaId}-%")
            ->orderBy('numero_reempaque', 'desc')
            ->value('numero_reempaque');

        $numero = $ultimo
            ? (int) str_replace("R-B{$bodegaId}-", '', $ultimo) + 1
            : 1;

        return "R-B{$bodegaId}-" . str_pad($numero, 6, '0', STR_PAD_LEFT);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}