<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Application\Services\ReempaqueService;
use App\Enums\LoteEstado;
use App\Models\BodegaProducto;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Reempaque;
use App\Models\ViajeCarga;
use Illuminate\Support\Collection;

/**
 * Foto del inventario: qué hay, cuánto y dónde está, en este momento.
 *
 * El stock del negocio vive en TRES lugares distintos y cada uno lo lleva en
 * su propia unidad. Esa es la razón de ser de este servicio:
 *
 *   1. LOTES     → el huevo suelto, contado en huevos (cantidad_huevos_remanente).
 *                  Por eso bodega_producto.stock del huevo suele estar en cero:
 *                  no es un error, es cómo se lleva.
 *   2. BODEGAS   → bodega_producto.stock, en la unidad del producto (cartones).
 *   3. CAMIONES  → viaje_cargas de viajes que no están cerrados ni cancelados;
 *                  lo que queda a bordo es cantidad − vendida − merma − devuelta.
 *
 * Todo se presenta como lo cuenta la gente en bodega: CARTONES Y SUELTOS
 * ("150 cartones y 20 huevos"), nunca "4,520 huevos".
 *
 * Los huevos por cartón NO se adivinan acá: se piden a
 * ReempaqueService::getHuevosPorUnidad(), que es el mismo criterio que usan la
 * carga del camión y el reempaque. Si algún día cambia, cambia para todos.
 */
class ExistenciasService
{
    public function __construct(private readonly ReempaqueService $reempaqueService) {}

    /**
     * Reporte completo. Las cantidades ya vienen formateadas para que la vista
     * del PDF no tenga que hacer cuentas (DomPDF y la lógica no se llevan bien).
     */
    public function generar(): array
    {
        $lotes = $this->existenciasEnLotes();
        $bodegas = $this->existenciasEnBodegas();
        $camiones = $this->existenciasEnCamiones();

        return [
            'generado_en' => now(),
            'consolidado' => $this->consolidarPorProducto($lotes, $bodegas, $camiones),
            'lotes' => $lotes,
            'bodegas' => $bodegas,
            'camiones' => $camiones,
            'alertas' => $this->alertas(),
            'hay_existencias' => $lotes->isNotEmpty() || $bodegas->isNotEmpty() || $camiones->isNotEmpty(),
        ];
    }

    // =========================================================
    // Las tres ubicaciones
    // =========================================================

    /**
     * Huevo en lotes.
     *
     * Se listan por remanente > 0 y no por estado: si un lote quedó marcado
     * como agotado pero todavía tiene huevos, el huevo está físicamente ahí y
     * tiene que aparecer. La columna Estado deja ver la inconsistencia en vez
     * de esconderla.
     */
    public function existenciasEnLotes(): Collection
    {
        return Lote::query()
            ->conRemanente()
            ->with(['producto', 'bodega'])
            ->orderBy('bodega_id')
            ->orderBy('numero_lote')
            ->get()
            ->map(function (Lote $lote): array {
                $huevos = (float) $lote->cantidad_huevos_remanente;
                $porCarton = max(1, (int) $lote->huevos_por_carton);

                return [
                    'producto_id' => $lote->producto_id,
                    'producto' => $lote->producto?->nombre ?? '—',
                    'bodega' => $lote->bodega?->nombre ?? '—',
                    'numero_lote' => $lote->numero_lote,
                    'estado' => $lote->estado?->label() ?? '—',
                    'huevos' => $huevos,
                    'huevos_por_carton' => $porCarton,
                    'texto' => $this->formatearHuevos($huevos, $porCarton),
                ];
            })
            ->values();
    }

    /**
     * Producto empacado en bodega. Solo lo que tiene stock: un reporte de
     * existencias no debe gastar hojas en ceros.
     */
    public function existenciasEnBodegas(): Collection
    {
        return BodegaProducto::query()
            ->where('stock', '>', 0)
            ->with(['bodega', 'producto.unidad', 'producto.categoria'])
            ->get()
            ->map(function (BodegaProducto $registro): array {
                $unidades = (float) $registro->stock;

                return [
                    'producto_id' => $registro->producto_id,
                    'producto' => $registro->producto?->nombre ?? '—',
                    'bodega' => $registro->bodega?->nombre ?? '—',
                    'unidades' => $unidades,
                    'unidad' => $registro->producto?->unidad?->nombre ?? 'unidades',
                    'texto' => $this->formatearUnidades($registro->producto, $unidades),
                ];
            })
            ->sortBy(fn (array $fila) => $fila['bodega'].'|'.$fila['producto'])
            ->values();
    }

    /**
     * Lo que anda arriba de los camiones, agrupado por viaje.
     *
     * Entra todo viaje que no esté cerrado ni cancelado, incluidos los
     * planificados: la carga ya salió de bodega, así que físicamente está en
     * el camión aunque no haya arrancado.
     */
    public function existenciasEnCamiones(): Collection
    {
        return ViajeCarga::query()
            ->whereHas('viaje', fn ($query) => $query->activos())
            ->with(['viaje.camion', 'viaje.chofer', 'producto.unidad', 'producto.categoria', 'unidad'])
            ->get()
            ->filter(fn (ViajeCarga $carga) => $carga->getCantidadDisponible() > 0)
            ->groupBy('viaje_id')
            ->map(function (Collection $cargas): array {
                $viaje = $cargas->first()->viaje;

                return [
                    'viaje_id' => $viaje?->id,
                    'numero_viaje' => $viaje?->numero_viaje ?? '—',
                    'camion' => $viaje?->camion?->placa ?? '—',
                    'chofer' => $viaje?->chofer?->name ?? '—',
                    'estado' => $viaje?->estado ?? '—',
                    'productos' => $cargas
                        ->map(fn (ViajeCarga $carga): array => [
                            'producto_id' => $carga->producto_id,
                            'producto' => $carga->producto?->nombre ?? '—',
                            'cargado' => (float) $carga->cantidad,
                            'vendido' => (float) $carga->cantidad_vendida,
                            'merma' => (float) $carga->cantidad_merma,
                            'devuelto' => (float) $carga->cantidad_devuelta,
                            'disponible' => $carga->getCantidadDisponible(),
                            'unidad' => $carga->unidad?->nombre ?? $carga->producto?->unidad?->nombre ?? 'unidades',
                            'texto' => $this->formatearUnidades($carga->producto, $carga->getCantidadDisponible()),
                        ])
                        ->sortBy('producto')
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('numero_viaje')
            ->values();
    }

    // =========================================================
    // Consolidado: "cuánto hay en total de cada cosa"
    // =========================================================

    /**
     * Una fila por producto con lo que hay en cada ubicación y el total.
     *
     * Para el huevo, las tres columnas se llevan internamente a huevos (única
     * forma de sumar lotes sueltos con cartones de bodega y de camión) y recién
     * al final se expresan en cartones y sueltos.
     */
    public function consolidarPorProducto(Collection $lotes, Collection $bodegas, Collection $camiones): Collection
    {
        $productosEnCamiones = $camiones->flatMap(fn (array $viaje) => $viaje['productos']);

        $ids = collect()
            ->merge($lotes->pluck('producto_id'))
            ->merge($bodegas->pluck('producto_id'))
            ->merge($productosEnCamiones->pluck('producto_id'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Producto::query()
            ->with(['unidad', 'categoria'])
            ->whereIn('id', $ids)
            ->get()
            ->map(function (Producto $producto) use ($lotes, $bodegas, $productosEnCamiones): array {
                $enLotes = (float) $lotes->where('producto_id', $producto->id)->sum('huevos');
                $enBodega = (float) $bodegas->where('producto_id', $producto->id)->sum('unidades');
                $enCamiones = (float) $productosEnCamiones->where('producto_id', $producto->id)->sum('disponible');

                if ($this->esProductoDeHuevo($producto)) {
                    $porCarton = $this->reempaqueService->getHuevosPorUnidad($producto);

                    $huevosBodega = $enBodega * $porCarton;
                    $huevosCamiones = $enCamiones * $porCarton;
                    $total = $enLotes + $huevosBodega + $huevosCamiones;

                    return [
                        'producto' => $producto->nombre,
                        'unidad' => $producto->unidad?->nombre ?? '—',
                        'orden' => $total,
                        'bodega' => $this->formatearHuevos($huevosBodega, $porCarton),
                        'lotes' => $this->formatearHuevos($enLotes, $porCarton),
                        'camiones' => $this->formatearHuevos($huevosCamiones, $porCarton),
                        'total' => $this->formatearHuevos($total, $porCarton),
                    ];
                }

                // Producto que no se lleva por lotes: se muestra en su propia unidad.
                return [
                    'producto' => $producto->nombre,
                    'unidad' => $producto->unidad?->nombre ?? '—',
                    'orden' => $enBodega + $enCamiones,
                    'bodega' => $this->formatearUnidades($producto, $enBodega),
                    'lotes' => '—',
                    'camiones' => $this->formatearUnidades($producto, $enCamiones),
                    'total' => $this->formatearUnidades($producto, $enBodega + $enCamiones),
                ];
            })
            ->sortBy('producto')
            ->values();
    }

    // =========================================================
    // Alertas: lo que el reporte no puede ver
    // =========================================================

    /**
     * Avisos sobre stock que podría no estar reflejado arriba.
     *
     * Un inventario que se traga en silencio lo que no entiende es peor que no
     * tener inventario: quien cuenta físico necesita saber dónde puede haber
     * diferencia antes de empezar.
     *
     * @return array<int, string>
     */
    public function alertas(): array
    {
        $alertas = [];

        // Los reempaques se crean ya completados; uno en proceso significa que
        // algo quedó a medias y esos huevos pueden no estar ni en el lote ni
        // en bodega.
        $reempaquesAMedias = Reempaque::query()->where('estado', 'en_proceso')->count();

        if ($reempaquesAMedias > 0) {
            $alertas[] = $reempaquesAMedias.' '.($reempaquesAMedias === 1 ? 'reempaque quedó' : 'reempaques quedaron')
                .' en proceso. Esos huevos pueden no estar reflejados ni en el lote ni en bodega.';
        }

        $lotesAgotadosConHuevos = Lote::query()
            ->where('estado', LoteEstado::Agotado)
            ->where('cantidad_huevos_remanente', '>', 0)
            ->count();

        if ($lotesAgotadosConHuevos > 0) {
            $alertas[] = $lotesAgotadosConHuevos.' '.($lotesAgotadosConHuevos === 1 ? 'lote está marcado' : 'lotes están marcados')
                .' como agotado pero todavía tiene huevos. Aparecen en el detalle de lotes.';
        }

        // Por Eloquent el stock nunca baja de cero (BodegaProducto lo clampea en
        // su hook de saving), así que un negativo solo puede haber entrado por
        // un decrement() o un update directo, que no disparan eventos. Si pasó,
        // hay que verlo: es dato corrupto, no una existencia.
        $stockNegativo = BodegaProducto::query()->where('stock', '<', 0)->count();

        if ($stockNegativo > 0) {
            $alertas[] = $stockNegativo.' '.($stockNegativo === 1 ? 'producto tiene' : 'productos tienen')
                .' stock negativo en bodega. No aparecen en el detalle: hay que revisarlos.';
        }

        return $alertas;
    }

    // =========================================================
    // Formato
    // =========================================================

    /**
     * "150 cartones y 20 huevos" — como se cuenta en bodega, no huevo por huevo.
     */
    public function formatearHuevos(float $huevos, int $porCarton): string
    {
        $huevos = (int) round($huevos);

        if ($huevos <= 0) {
            return '—';
        }

        $porCarton = max(1, $porCarton);
        $cartones = intdiv($huevos, $porCarton);
        $sueltos = $huevos % $porCarton;

        $partes = [];

        if ($cartones > 0) {
            $partes[] = number_format($cartones).' '.($cartones === 1 ? 'cartón' : 'cartones');
        }

        if ($sueltos > 0) {
            $partes[] = number_format($sueltos).' '.($sueltos === 1 ? 'huevo' : 'huevos');
        }

        return implode(' y ', $partes);
    }

    /**
     * Cantidad en la unidad del producto. Si el producto es de huevo, se
     * traduce a cartones y sueltos para que toda la hoja hable el mismo idioma.
     */
    public function formatearUnidades(?Producto $producto, float $unidades): string
    {
        if ($unidades <= 0) {
            return '—';
        }

        if ($producto && $this->esProductoDeHuevo($producto)) {
            $porCarton = $this->reempaqueService->getHuevosPorUnidad($producto);

            return $this->formatearHuevos($unidades * $porCarton, $porCarton);
        }

        $cantidad = fmod($unidades, 1.0) === 0.0
            ? number_format($unidades)
            : number_format($unidades, 2);

        $unidad = $producto?->unidad?->nombre;

        return $unidad ? $cantidad.' '.$unidad : $cantidad;
    }

    /**
     * ¿El producto se lleva por lotes de huevo?
     *
     * Se decide por la categoría (Categoria::usaLotes), que es el mismo
     * criterio que usa el resto del inventario.
     */
    private function esProductoDeHuevo(Producto $producto): bool
    {
        return (bool) $producto->categoria?->usaLotes();
    }
}
