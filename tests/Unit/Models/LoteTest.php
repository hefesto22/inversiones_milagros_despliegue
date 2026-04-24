<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Lote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de los accessors y helpers WAC del modelo Lote.
 *
 * Scope:
 *   - Accessor costo_por_huevo_efectivo: respeta read_source, maneja NULL.
 *   - Accessor costo_por_carton_facturado_efectivo: respeta read_source, maneja NULL.
 *   - Helpers SQL estáticos: devuelven el identificador de columna correcto.
 *   - Fallback a 'legacy' si el flag tiene un valor inesperado.
 *
 * Estos accessors son el punto único de lectura del costo del lote en Fase 5.
 * Romperlos corrompe todos los reportes financieros — son tests críticos.
 */
class LoteTest extends TestCase
{
    use RefreshDatabase;

    // ============================================
    // ACCESSOR: costo_por_huevo_efectivo
    // ============================================

    /** @test */
    public function costo_por_huevo_efectivo_devuelve_legacy_cuando_read_source_es_legacy(): void
    {
        config(['inventario.wac.read_source' => 'legacy']);

        $lote = Lote::factory()
            ->conCompra(huevosFacturados: 3000, costoCompra: 7815)
            ->wacInicializado(huevos: 3000, costoInventario: 9000) // WAC distinto intencionalmente
            ->create();

        // Legacy: 7815 / 3000 = 2.605
        $this->assertEquals(2.605, $lote->costo_por_huevo_efectivo);
        $this->assertNotEquals(3.0, $lote->costo_por_huevo_efectivo, 'No debe leer WAC');
    }

    /** @test */
    public function costo_por_huevo_efectivo_devuelve_wac_cuando_read_source_es_wac(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        $lote = Lote::factory()
            ->conCompra(huevosFacturados: 3000, costoCompra: 7815)        // legacy = 2.605
            ->wacInicializado(huevos: 3000, costoInventario: 9000)        // wac = 3.000000
            ->create();

        // WAC: 9000 / 3000 = 3.000000
        $this->assertEquals(3.0, $lote->costo_por_huevo_efectivo);
        $this->assertNotEquals(2.605, $lote->costo_por_huevo_efectivo, 'No debe leer legacy');
    }

    /** @test */
    public function costo_por_huevo_efectivo_retorna_cero_cuando_wac_es_null_y_flag_es_wac(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        // Default factory: wac_* = NULL (lote pre-backfill)
        $lote = Lote::factory()->create([
            'costo_por_huevo' => 5.0, // legacy tiene valor, pero leemos WAC
        ]);

        $this->assertEquals(0.0, $lote->costo_por_huevo_efectivo);
    }

    /** @test */
    public function costo_por_huevo_efectivo_retorna_cero_cuando_legacy_es_null_y_flag_es_legacy(): void
    {
        config(['inventario.wac.read_source' => 'legacy']);

        $lote = Lote::factory()->create([
            'costo_por_huevo' => null, // forzar NULL legacy
        ]);

        $this->assertEquals(0.0, $lote->costo_por_huevo_efectivo);
    }

    /** @test */
    public function costo_por_huevo_efectivo_siempre_retorna_tipo_float(): void
    {
        config(['inventario.wac.read_source' => 'legacy']);

        $lote = Lote::factory()->conCompra()->create();

        $this->assertIsFloat($lote->costo_por_huevo_efectivo);
    }

    /** @test */
    public function costo_por_huevo_efectivo_usa_legacy_como_default_cuando_flag_tiene_valor_invalido(): void
    {
        // Valor no documentado — fallback a legacy por seguridad
        config(['inventario.wac.read_source' => 'desconocido']);

        $lote = Lote::factory()
            ->conCompra(huevosFacturados: 3000, costoCompra: 7815)
            ->wacInicializado(huevos: 3000, costoInventario: 9000)
            ->create();

        $this->assertEquals(2.605, $lote->costo_por_huevo_efectivo, 'Flag inválido debe caer a legacy');
    }

    // ============================================
    // ACCESSOR: costo_por_carton_facturado_efectivo
    // ============================================

    /** @test */
    public function costo_por_carton_efectivo_devuelve_legacy_cuando_read_source_es_legacy(): void
    {
        config(['inventario.wac.read_source' => 'legacy']);

        $lote = Lote::factory()
            ->conCompra(huevosFacturados: 3000, costoCompra: 7815)
            ->wacInicializado(huevos: 3000, costoInventario: 9000)
            ->create();

        // Legacy conCompra: costo_por_carton = 78.15
        $this->assertEquals(78.15, $lote->costo_por_carton_facturado_efectivo);
    }

    /** @test */
    public function costo_por_carton_efectivo_devuelve_wac_cuando_read_source_es_wac(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        $lote = Lote::factory()
            ->conCompra(huevosFacturados: 3000, costoCompra: 7815)
            ->wacInicializado(huevos: 3000, costoInventario: 9000)
            ->create();

        // WAC: 3.0 × 30 = 90.0 por cartón
        $this->assertEquals(90.0, $lote->costo_por_carton_facturado_efectivo);
    }

    /** @test */
    public function costo_por_carton_efectivo_retorna_cero_cuando_wac_es_null_y_flag_es_wac(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        $lote = Lote::factory()->create([
            'costo_por_carton_facturado' => 100.0, // legacy tiene valor
        ]);

        $this->assertEquals(0.0, $lote->costo_por_carton_facturado_efectivo);
    }

    /** @test */
    public function costo_por_carton_efectivo_siempre_retorna_tipo_float(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        $lote = Lote::factory()->wacInicializado()->create();

        $this->assertIsFloat($lote->costo_por_carton_facturado_efectivo);
    }

    // ============================================
    // HELPER: Lote::columnaSqlCostoPorHuevo()
    // ============================================

    /** @test */
    public function columna_sql_costo_por_huevo_retorna_legacy_por_default(): void
    {
        config(['inventario.wac.read_source' => 'legacy']);

        $this->assertEquals('lotes.costo_por_huevo', Lote::columnaSqlCostoPorHuevo());
    }

    /** @test */
    public function columna_sql_costo_por_huevo_retorna_wac_cuando_flag_es_wac(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        $this->assertEquals('lotes.wac_costo_por_huevo', Lote::columnaSqlCostoPorHuevo());
    }

    /** @test */
    public function columna_sql_costo_por_huevo_cae_a_legacy_cuando_flag_es_invalido(): void
    {
        config(['inventario.wac.read_source' => 'otro_valor']);

        $this->assertEquals('lotes.costo_por_huevo', Lote::columnaSqlCostoPorHuevo());
    }

    /** @test */
    public function columna_sql_costo_por_huevo_devuelve_identificador_calificado_con_tabla(): void
    {
        // Garantizar que el formato "tabla.columna" se mantiene para uso seguro
        // en JOINs con otras tablas que tengan columnas homónimas.
        config(['inventario.wac.read_source' => 'legacy']);
        $this->assertStringStartsWith('lotes.', Lote::columnaSqlCostoPorHuevo());

        config(['inventario.wac.read_source' => 'wac']);
        $this->assertStringStartsWith('lotes.', Lote::columnaSqlCostoPorHuevo());
    }

    // ============================================
    // HELPER: Lote::columnaSqlCostoPorCartonFacturado()
    // ============================================

    /** @test */
    public function columna_sql_costo_por_carton_retorna_legacy_por_default(): void
    {
        config(['inventario.wac.read_source' => 'legacy']);

        $this->assertEquals(
            'lotes.costo_por_carton_facturado',
            Lote::columnaSqlCostoPorCartonFacturado()
        );
    }

    /** @test */
    public function columna_sql_costo_por_carton_retorna_wac_cuando_flag_es_wac(): void
    {
        config(['inventario.wac.read_source' => 'wac']);

        $this->assertEquals(
            'lotes.wac_costo_por_carton_facturado',
            Lote::columnaSqlCostoPorCartonFacturado()
        );
    }

    /** @test */
    public function columna_sql_costo_por_carton_cae_a_legacy_cuando_flag_es_invalido(): void
    {
        config(['inventario.wac.read_source' => 'banana']);

        $this->assertEquals(
            'lotes.costo_por_carton_facturado',
            Lote::columnaSqlCostoPorCartonFacturado()
        );
    }
}
