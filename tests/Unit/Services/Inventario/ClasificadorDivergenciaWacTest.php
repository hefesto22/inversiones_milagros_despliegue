<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Inventario;

use App\Services\Inventario\ClasificadorDivergenciaWac;
use App\Services\Inventario\Dto\ClasificacionDivergencia;
use Tests\TestCase;

/**
 * Tests unitarios del ClasificadorDivergenciaWac (Fase 4 del refactor WAC Perpetuo).
 *
 * Servicio stateless — no necesita BD ni mocks. Los tests construyen una
 * instancia directa (no resuelven del container) para aislar el contrato puro
 * de clasificacion de cualquier configuracion de Laravel.
 *
 * ATENCION: este clasificador es la fuente unica de verdad de las reglas.
 * BackfillWacService y ReconciliadorWacService lo consumen identicamente.
 * Si alguien cambia un umbral aqui, ambos consumidores se ven afectados —
 * por eso la bateria de tests es deliberadamente exhaustiva en los bordes.
 *
 * Scope cubierto:
 *   1. Clasificacion "ninguna" — legacy sin base comparable y WAC dentro de tolerancia.
 *   2. Clasificacion "anomala" — legacy sin base pero WAC con valor.
 *   3. Clasificacion "ruido" — diferencia dentro de tolerancia (+, - y borde exacto).
 *   4. Clasificacion "esperada" — WAC < legacy con desvio <=15% (caso tipico y borde exacto).
 *   5. Clasificacion "anomala" — WAC > legacy (cualquier diferencia > tolerancia).
 *   6. Clasificacion "anomala" — WAC < legacy pero desvio >15%.
 *   7. Contrato del DTO — helpers esRuido/esEsperada/esAnomala/esDivergenciaReportable.
 */
class ClasificadorDivergenciaWacTest extends TestCase
{
    private ClasificadorDivergenciaWac $clasificador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clasificador = new ClasificadorDivergenciaWac();
    }

    // =================================================================
    // 1. LEGACY sin base comparable (<= 0)
    // =================================================================

    public function test_clasifica_ninguna_cuando_legacy_cero_y_wac_dentro_de_tolerancia(): void
    {
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    0.05,
            legacyCartonFacturado: 0.0,
            diferenciaCarton:      0.05,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_NINGUNA, $resultado->clasificacion);
        $this->assertNull(
            $resultado->detalle,
            'Clase ninguna no ameritar reportarse — detalle no se emite'
        );
    }

    public function test_clasifica_anomala_cuando_legacy_cero_pero_wac_tiene_valor(): void
    {
        // Legacy sin base pero WAC reporta 70 L/carton → anomalo: no tenemos
        // como validar de donde salio ese WAC.
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    70.0,
            legacyCartonFacturado: 0.0,
            diferenciaCarton:      70.0,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_ANOMALA, $resultado->clasificacion);
        $this->assertNotNull($resultado->detalle);
        $this->assertStringContainsString('Legacy', $resultado->detalle);
    }

    // =================================================================
    // 2. RUIDO (|diferencia| <= tolerancia)
    // =================================================================

    public function test_clasifica_ruido_cuando_diferencia_positiva_dentro_de_tolerancia(): void
    {
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    70.05,
            legacyCartonFacturado: 70.00,
            diferenciaCarton:      0.05,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_RUIDO, $resultado->clasificacion);
    }

    public function test_clasifica_ruido_cuando_diferencia_negativa_dentro_de_tolerancia(): void
    {
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    69.95,
            legacyCartonFacturado: 70.00,
            diferenciaCarton:      -0.05,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_RUIDO, $resultado->clasificacion);
    }

    public function test_ruido_en_el_borde_exacto_de_tolerancia_es_inclusivo(): void
    {
        // |diff| == tolerancia exacta → debe caer en ruido (comparacion con <=)
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    70.10,
            legacyCartonFacturado: 70.00,
            diferenciaCarton:      0.10,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(
            ClasificacionDivergencia::CLASIF_RUIDO,
            $resultado->clasificacion,
            'El borde exacto de tolerancia debe ser RUIDO (comparacion inclusiva)'
        );
    }

    // =================================================================
    // 3. ESPERADA — bug legacy documentado (WAC < legacy, <=15%)
    // =================================================================

    public function test_clasifica_esperada_cuando_wac_menor_que_legacy_dentro_del_15_pct(): void
    {
        // Legacy 78.15, WAC 70.00 → diff -8.15 L, |-8.15|/78.15 = 10.43% <= 15%
        // Patron del bug de sobrevaluacion.
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    70.00,
            legacyCartonFacturado: 78.15,
            diferenciaCarton:      -8.15,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_ESPERADA, $resultado->clasificacion);
        $this->assertNotNull($resultado->detalle);
        $this->assertStringContainsString('sobrevaluación', $resultado->detalle);
    }

    public function test_esperada_en_el_borde_exacto_de_15_pct_es_inclusivo(): void
    {
        // Legacy 100, WAC 85 → diff -15, |-15|/100 = 15% exacto
        // Comparacion es <= UMBRAL_ESPERADA_PCT → 15% exacto es esperada.
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    85.00,
            legacyCartonFacturado: 100.00,
            diferenciaCarton:      -15.00,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(
            ClasificacionDivergencia::CLASIF_ESPERADA,
            $resultado->clasificacion,
            '15% exacto debe ser ESPERADA (borde inclusivo)'
        );
    }

    // =================================================================
    // 4. ANOMALA — WAC > legacy
    // =================================================================

    public function test_clasifica_anomala_cuando_wac_mayor_que_legacy(): void
    {
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    80.00,
            legacyCartonFacturado: 70.00,
            diferenciaCarton:      10.00,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_ANOMALA, $resultado->clasificacion);
        $this->assertNotNull($resultado->detalle);
        $this->assertStringContainsString('WAC > legacy', $resultado->detalle);
    }

    public function test_clasifica_anomala_cuando_wac_mayor_por_poco_pero_sobre_tolerancia(): void
    {
        // Incluso un delta pequeno positivo sobre tolerancia es anomalia: el
        // refactor no deberia inflar el costo bajo ningun escenario.
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    70.15,
            legacyCartonFacturado: 70.00,
            diferenciaCarton:      0.15,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_ANOMALA, $resultado->clasificacion);
    }

    // =================================================================
    // 5. ANOMALA — WAC < legacy pero desvio excede 15%
    // =================================================================

    public function test_clasifica_anomala_cuando_wac_menor_pero_desvio_excede_15_pct(): void
    {
        // Legacy 100, WAC 80 → diff -20, desvio 20% > 15% → anomala
        $resultado = $this->clasificador->clasificar(
            wacCartonFacturado:    80.00,
            legacyCartonFacturado: 100.00,
            diferenciaCarton:      -20.00,
            toleranciaLempiras:    0.10,
        );

        $this->assertSame(ClasificacionDivergencia::CLASIF_ANOMALA, $resultado->clasificacion);
        $this->assertStringContainsString('excede umbral', $resultado->detalle ?? '');
    }

    // =================================================================
    // 6. CONTRATO DEL DTO — helpers
    // =================================================================

    public function test_dto_esdivergenciareportable_solo_para_esperada_y_anomala(): void
    {
        $ninguna  = new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_NINGUNA);
        $ruido    = new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_RUIDO);
        $esperada = new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ESPERADA);
        $anomala  = new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ANOMALA);

        $this->assertFalse($ninguna->esDivergenciaReportable(),  'ninguna NO es reportable');
        $this->assertFalse($ruido->esDivergenciaReportable(),    'ruido NO es reportable');
        $this->assertTrue($esperada->esDivergenciaReportable(),  'esperada SI es reportable (log info)');
        $this->assertTrue($anomala->esDivergenciaReportable(),   'anomala SI es reportable (log warning)');
    }

    public function test_dto_helpers_de_clase_son_mutuamente_exclusivos(): void
    {
        $esperada = new ClasificacionDivergencia(ClasificacionDivergencia::CLASIF_ESPERADA);

        $this->assertFalse($esperada->esRuido());
        $this->assertTrue($esperada->esEsperada());
        $this->assertFalse($esperada->esAnomala());
    }
}
