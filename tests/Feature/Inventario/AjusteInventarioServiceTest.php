<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Application\Services\AjusteInventarioService;
use App\Enums\AjusteEstado;
use App\Enums\AjusteMotivo;
use App\Enums\AjusteTipoMovimiento;
use App\Enums\LoteEstado;
use App\Events\Inventario\AjusteEntradaAplicadoAlLote;
use App\Events\Inventario\AjusteSalidaAplicadaAlLote;
use App\Models\AjusteInventario;
use App\Models\Bodega;
use App\Models\Lote;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests del AjusteInventarioService — módulo de Ajuste de Inventario por conteo físico.
 *
 * Scope:
 *   1. Reclasificación: pareja vinculada, política de costos Opción B (costo origen),
 *      umbral de aprobación dual, validaciones de dominio y % máximo del lote.
 *   2. Merma residual: creación, validación de motivo, aplicación al lote.
 *   3. Workflow de estados: Borrador → PendienteAprobacion → Aprobado → Aplicado,
 *      rechazo, inmutabilidad post-aplicación.
 *   4. Atomicidad: la pareja salida+entrada se aplica o revierte JUNTA.
 *   5. Integración WAC: eventos disparados y efecto en columnas wac_* (shadow mode).
 *
 * Datos base de los helpers:
 *   Lote origen : 3000 huevos, L7,815  → costo legacy 2.605 L/huevo
 *   Lote destino: 3000 huevos, L6,000  → costo legacy 2.0 L/huevo
 *   Umbral de aprobación: 300 huevos (config default)
 *   % máximo por lote: 25% (config default)
 */
class AjusteInventarioServiceTest extends TestCase
{
    use RefreshDatabase;

    private AjusteInventarioService $service;

    private User $solicitante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service     = app(AjusteInventarioService::class);
        $this->solicitante = User::factory()->create();
    }

    // =================================================================
    // HELPERS
    // =================================================================

    /**
     * Par de lotes en la MISMA bodega con compra legacy aplicada.
     *
     * @return array{0: Lote, 1: Lote} [origen, destino]
     */
    private function crearParDeLotes(bool $conWac = false): array
    {
        $bodega = Bodega::factory()->create();

        $origen = $conWac
            ? Lote::factory()->conCompra(3000.0, 7815.0)->wacInicializado(3000.0, 7815.0)->create(['bodega_id' => $bodega->id])
            : Lote::factory()->conCompra(3000.0, 7815.0)->create(['bodega_id' => $bodega->id]);

        $destino = $conWac
            ? Lote::factory()->conCompra(3000.0, 6000.0)->wacInicializado(3000.0, 6000.0)->create(['bodega_id' => $bodega->id])
            : Lote::factory()->conCompra(3000.0, 6000.0)->create(['bodega_id' => $bodega->id]);

        return [$origen, $destino];
    }

    /**
     * @return array{salida: AjusteInventario, entrada: AjusteInventario}
     */
    private function reclasificar(
        Lote   $origen,
        Lote   $destino,
        float  $huevos = 120.0,
        ?float $costoExplicito = null,
    ): array {
        return $this->service->crearReclasificacion(
            loteOrigen:            $origen,
            loteDestino:           $destino,
            huevosAMover:          $huevos,
            costoUnitarioAplicado: $costoExplicito,
            motivo:                AjusteMotivo::ClasificacionIncorrecta,
            descripcion:           'Conteo físico 2026-07-12: clasificación incorrecta al contar',
            evidenciaPath:         null,
            solicitante:           $this->solicitante,
        );
    }

    private function crearMerma(Lote $lote, float $huevos = 90.0): AjusteInventario
    {
        return $this->service->crearMermaResidual(
            lote:          $lote,
            huevosAMermar: $huevos,
            motivo:        AjusteMotivo::RoturaNoDocumentada,
            descripcion:   'Rotura detectada en conteo físico',
            evidenciaPath: null,
            solicitante:   $this->solicitante,
        );
    }

    // =================================================================
    // RECLASIFICACIÓN — CREACIÓN
    // =================================================================

    public function test_reclasificacion_crea_pareja_vinculada_con_deltas_espejo(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida, 'entrada' => $entrada] = $this->reclasificar($origen, $destino, 120.0);

        // Tipos correctos
        $this->assertSame(AjusteTipoMovimiento::SalidaReclasificacion, $salida->tipo_movimiento);
        $this->assertSame(AjusteTipoMovimiento::EntradaReclasificacion, $entrada->tipo_movimiento);

        // Vínculo bidireccional salida ↔ entrada
        $this->assertSame($entrada->id, $salida->ajuste_pareja_id);
        $this->assertSame($salida->id, $entrada->ajuste_pareja_id);

        // Deltas espejo: -120 en origen, +120 en destino
        $this->assertEquals(-120.0, (float) $salida->delta_huevos);
        $this->assertEquals(120.0, (float) $entrada->delta_huevos);

        // Snapshot antes/después coherente con el stock actual
        $this->assertEquals(3000.0, (float) $salida->huevos_antes);
        $this->assertEquals(2880.0, (float) $salida->huevos_despues);
        $this->assertEquals(3000.0, (float) $entrada->huevos_antes);
        $this->assertEquals(3120.0, (float) $entrada->huevos_despues);

        // Contexto de lote correcto en cada lado
        $this->assertSame($origen->id, $salida->lote_id);
        $this->assertSame($destino->id, $entrada->lote_id);

        // Auditoría del creador
        $this->assertSame($this->solicitante->id, $salida->created_by);
        $this->assertSame($this->solicitante->id, $entrada->created_by);
    }

    public function test_reclasificacion_aplica_costo_origen_por_default_opcion_b(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida, 'entrada' => $entrada] = $this->reclasificar($origen, $destino, 120.0);

        // Opción B: los huevos viajan con el costo del ORIGEN (2.605), no el del destino (2.0)
        $this->assertEqualsWithDelta(2.605, (float) $salida->costo_unitario_aplicado, 0.000001);
        $this->assertEqualsWithDelta(2.605, (float) $entrada->costo_unitario_aplicado, 0.000001);

        // Valor contable = 120 × 2.605 = 312.60 en ambos lados (sin pérdida valorativa inmediata)
        $this->assertEqualsWithDelta(312.60, (float) $salida->valor_contable_afectado, 0.01);
        $this->assertEqualsWithDelta(312.60, (float) $entrada->valor_contable_afectado, 0.01);
    }

    public function test_reclasificacion_acepta_costo_explicito_para_perdida_valorativa(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        // Caso edge documentado: ajuste de calidad que sí amerita pérdida inmediata
        ['salida' => $salida, 'entrada' => $entrada] = $this->reclasificar($origen, $destino, 120.0, costoExplicito: 1.50);

        // La salida sigue valuada al costo del origen…
        $this->assertEqualsWithDelta(2.605, (float) $salida->costo_unitario_aplicado, 0.000001);
        $this->assertEqualsWithDelta(312.60, (float) $salida->valor_contable_afectado, 0.01);

        // …pero la entrada usa el costo explícito (120 × 1.50 = 180.00)
        $this->assertEqualsWithDelta(1.50, (float) $entrada->costo_unitario_aplicado, 0.000001);
        $this->assertEqualsWithDelta(180.00, (float) $entrada->valor_contable_afectado, 0.01);
    }

    // =================================================================
    // UMBRAL DE APROBACIÓN DUAL
    // =================================================================

    public function test_reclasificacion_bajo_umbral_queda_en_borrador(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida, 'entrada' => $entrada] = $this->reclasificar($origen, $destino, 120.0);

        $this->assertSame(AjusteEstado::Borrador, $salida->estado);
        $this->assertSame(AjusteEstado::Borrador, $entrada->estado);
        $this->assertFalse($salida->requiere_aprobacion);
        $this->assertFalse($entrada->requiere_aprobacion);
    }

    public function test_reclasificacion_en_umbral_o_mayor_requiere_aprobacion(): void
    {
        Config::set('inventario.ajustes.umbral_aprobacion_huevos', 300);

        [$origen, $destino] = $this->crearParDeLotes();

        // Exactamente el umbral (300) también requiere aprobación (comparación >=)
        ['salida' => $salida, 'entrada' => $entrada] = $this->reclasificar($origen, $destino, 300.0);

        $this->assertSame(AjusteEstado::PendienteAprobacion, $salida->estado);
        $this->assertSame(AjusteEstado::PendienteAprobacion, $entrada->estado);
        $this->assertTrue($salida->requiere_aprobacion);
        $this->assertTrue($entrada->requiere_aprobacion);
    }

    // =================================================================
    // VALIDACIONES DE DOMINIO
    // =================================================================

    public function test_reclasificacion_rechaza_cantidad_no_positiva(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        $this->expectException(InvalidArgumentException::class);
        $this->reclasificar($origen, $destino, 0.0);
    }

    public function test_reclasificacion_rechaza_mismo_lote_origen_y_destino(): void
    {
        [$origen] = $this->crearParDeLotes();

        $this->expectException(InvalidArgumentException::class);
        $this->reclasificar($origen, $origen, 120.0);
    }

    public function test_reclasificacion_rechaza_lotes_de_bodegas_distintas(): void
    {
        $origen  = Lote::factory()->conCompra(3000.0, 7815.0)->create();
        $destino = Lote::factory()->conCompra(3000.0, 6000.0)->create(); // otra bodega (factory crea una nueva)

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('misma bodega');
        $this->reclasificar($origen, $destino, 120.0);
    }

    public function test_reclasificacion_rechaza_motivo_que_no_aplica(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        $this->expectException(InvalidArgumentException::class);

        $this->service->crearReclasificacion(
            loteOrigen:            $origen,
            loteDestino:           $destino,
            huevosAMover:          120.0,
            costoUnitarioAplicado: null,
            motivo:                AjusteMotivo::ConteoFisicoDiferencia, // válido solo para merma
            descripcion:           'Motivo inválido para reclasificación',
            evidenciaPath:         null,
            solicitante:           $this->solicitante,
        );
    }

    public function test_reclasificacion_exige_descripcion(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        $this->expectException(InvalidArgumentException::class);

        $this->service->crearReclasificacion(
            loteOrigen:            $origen,
            loteDestino:           $destino,
            huevosAMover:          120.0,
            costoUnitarioAplicado: null,
            motivo:                AjusteMotivo::ClasificacionIncorrecta,
            descripcion:           '   ',
            evidenciaPath:         null,
            solicitante:           $this->solicitante,
        );
    }

    public function test_reclasificacion_rechaza_movimiento_mayor_al_porcentaje_maximo_del_lote(): void
    {
        Config::set('inventario.ajustes.porcentaje_maximo_lote', 25);

        [$origen, $destino] = $this->crearParDeLotes();

        // 780 de 3000 = 26% > 25% máximo
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede el máximo permitido');
        $this->reclasificar($origen, $destino, 780.0);
    }

    // =================================================================
    // APLICACIÓN — RECLASIFICACIÓN
    // =================================================================

    public function test_aplicar_reclasificacion_mueve_stock_entre_lotes(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);

        $this->service->aplicar($salida, $this->solicitante);

        $origen->refresh();
        $destino->refresh();
        $salida->refresh();
        $entrada = $salida->pareja;

        // Stock movido
        $this->assertEquals(2880.0, (float) $origen->cantidad_huevos_remanente);
        $this->assertEquals(3120.0, (float) $destino->cantidad_huevos_remanente);

        // La salida de reclasificación NO es pérdida: no suma a merma_total_acumulada,
        // pero SÍ a huevos_facturados_acumulados (para que el reporte de salidas cuadre)
        $this->assertEquals(0.0, (float) $origen->merma_total_acumulada);
        $this->assertEquals(3120.0, (float) $origen->huevos_facturados_acumulados);

        // Ambos lados quedan Aplicado con auditoría de quién aplicó
        $this->assertSame(AjusteEstado::Aplicado, $salida->estado);
        $this->assertSame(AjusteEstado::Aplicado, $entrada->estado);
        $this->assertSame($this->solicitante->id, $salida->aplicado_por);
        $this->assertNotNull($salida->aplicado_en);
    }

    public function test_aplicar_reclasificacion_es_atomica_si_falla_la_salida(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);

        // Entre la creación y la aplicación, el stock del origen cayó por debajo
        // de lo requerido (ej. una venta consumió el lote)
        $origen->update(['cantidad_huevos_remanente' => 50.0]);

        try {
            $this->service->aplicar($salida, $this->solicitante);
            $this->fail('Se esperaba DomainException por stock insuficiente');
        } catch (DomainException) {
            // esperado
        }

        $origen->refresh();
        $destino->refresh();
        $salida->refresh();

        // ROLLBACK COMPLETO: nada cambió en ninguno de los dos lados
        $this->assertEquals(50.0, (float) $origen->cantidad_huevos_remanente);
        $this->assertEquals(3000.0, (float) $destino->cantidad_huevos_remanente);
        $this->assertSame(AjusteEstado::Borrador, $salida->estado);
        $this->assertSame(AjusteEstado::Borrador, $salida->pareja->estado);
    }

    // =================================================================
    // WORKFLOW DE ESTADOS
    // =================================================================

    public function test_aplicar_ajuste_pendiente_de_aprobacion_es_rechazado(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        // 600 >= umbral 300 → PendienteAprobacion
        ['salida' => $salida] = $this->reclasificar($origen, $destino, 600.0);

        $this->expectException(DomainException::class);
        $this->service->aplicar($salida, $this->solicitante);
    }

    public function test_aprobar_habilita_aplicacion_y_aprueba_la_pareja(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();
        $aprobador = User::factory()->create();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 600.0);

        $this->service->aprobar($salida, $aprobador);

        $salida->refresh();
        $entrada = $salida->pareja;

        // Ambos lados aprobados con auditoría (reclasificación atómica)
        $this->assertSame(AjusteEstado::Aprobado, $salida->estado);
        $this->assertSame(AjusteEstado::Aprobado, $entrada->estado);
        $this->assertSame($aprobador->id, $salida->aprobado_por);
        $this->assertSame($aprobador->id, $entrada->aprobado_por);
        $this->assertNotNull($salida->aprobado_en);

        // Y ahora sí puede aplicarse
        $this->service->aplicar($salida, $aprobador);

        $origen->refresh();
        $this->assertEquals(2400.0, (float) $origen->cantidad_huevos_remanente);
    }

    public function test_aprobar_desde_borrador_es_rechazado(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        // 120 < umbral → Borrador (no hay nada que aprobar)
        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);

        $this->expectException(DomainException::class);
        $this->service->aprobar($salida, User::factory()->create());
    }

    public function test_rechazar_marca_ambos_lados_con_motivo(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();
        $aprobador = User::factory()->create();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 600.0);

        $this->service->rechazar($salida, $aprobador, 'El conteo físico no coincide con la evidencia');

        $salida->refresh();
        $entrada = $salida->pareja;

        $this->assertSame(AjusteEstado::Rechazado, $salida->estado);
        $this->assertSame(AjusteEstado::Rechazado, $entrada->estado);
        $this->assertSame('El conteo físico no coincide con la evidencia', $salida->motivo_rechazo);
        $this->assertSame($aprobador->id, $salida->rechazado_por);

        // El stock quedó intacto
        $origen->refresh();
        $this->assertEquals(3000.0, (float) $origen->cantidad_huevos_remanente);
    }

    public function test_rechazar_exige_motivo(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 600.0);

        $this->expectException(InvalidArgumentException::class);
        $this->service->rechazar($salida, User::factory()->create(), '  ');
    }

    public function test_aplicar_dos_veces_es_rechazado(): void
    {
        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);

        $this->service->aplicar($salida, $this->solicitante);
        $salida->refresh();

        // Inmutabilidad: un ajuste Aplicado es terminal
        $this->expectException(DomainException::class);
        $this->service->aplicar($salida, $this->solicitante);
    }

    // =================================================================
    // MERMA RESIDUAL
    // =================================================================

    public function test_merma_residual_crea_ajuste_y_aplica_al_lote(): void
    {
        [$lote] = $this->crearParDeLotes();

        $ajuste = $this->crearMerma($lote, 90.0);

        // 90 < umbral 300 → Borrador, sin pareja
        $this->assertSame(AjusteEstado::Borrador, $ajuste->estado);
        $this->assertNull($ajuste->ajuste_pareja_id);
        $this->assertEquals(-90.0, (float) $ajuste->delta_huevos);
        $this->assertEqualsWithDelta(234.45, (float) $ajuste->valor_contable_afectado, 0.01); // 90 × 2.605

        $this->service->aplicar($ajuste, $this->solicitante);

        $lote->refresh();
        $ajuste->refresh();

        // La merma residual SÍ es pérdida: descuenta remanente Y suma a merma acumulada
        $this->assertEquals(2910.0, (float) $lote->cantidad_huevos_remanente);
        $this->assertEquals(90.0, (float) $lote->merma_total_acumulada);
        $this->assertEquals(3090.0, (float) $lote->huevos_facturados_acumulados);
        $this->assertSame(AjusteEstado::Aplicado, $ajuste->estado);
    }

    public function test_merma_residual_rechaza_motivo_de_reclasificacion(): void
    {
        [$lote] = $this->crearParDeLotes();

        $this->expectException(InvalidArgumentException::class);

        $this->service->crearMermaResidual(
            lote:          $lote,
            huevosAMermar: 90.0,
            motivo:        AjusteMotivo::ClasificacionIncorrecta, // válido solo para reclasificación
            descripcion:   'Motivo inválido para merma',
            evidenciaPath: null,
            solicitante:   $this->solicitante,
        );
    }

    public function test_merma_residual_rechaza_stock_insuficiente(): void
    {
        [$lote] = $this->crearParDeLotes();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stock insuficiente');
        $this->crearMerma($lote, 4000.0); // lote solo tiene 3000
    }

    public function test_merma_residual_que_agota_el_lote_lo_marca_agotado(): void
    {
        [$lote] = $this->crearParDeLotes();
        $aprobador = User::factory()->create();

        // Mermar el lote completo (3000 >= umbral → requiere aprobación)
        $ajuste = $this->crearMerma($lote, 3000.0);
        $this->assertSame(AjusteEstado::PendienteAprobacion, $ajuste->estado);

        $this->service->aprobar($ajuste, $aprobador);
        $this->service->aplicar($ajuste->refresh(), $aprobador);

        $lote->refresh();

        $this->assertEquals(0.0, (float) $lote->cantidad_huevos_remanente);
        $this->assertEquals(3000.0, (float) $lote->merma_total_acumulada);
        $this->assertSame(LoteEstado::Agotado, $lote->estado);
    }

    // =================================================================
    // INTEGRACIÓN WAC
    // =================================================================

    public function test_aplicar_reclasificacion_emite_eventos_para_wac(): void
    {
        Event::fake([AjusteSalidaAplicadaAlLote::class, AjusteEntradaAplicadoAlLote::class]);

        [$origen, $destino] = $this->crearParDeLotes();

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);
        $this->service->aplicar($salida, $this->solicitante);

        Event::assertDispatched(
            AjusteSalidaAplicadaAlLote::class,
            fn (AjusteSalidaAplicadaAlLote $e) =>
                $e->lote->id === $origen->id
                && abs($e->huevosSalientes - 120.0) < 0.001
                && $e->ajuste->id === $salida->id
        );

        Event::assertDispatched(
            AjusteEntradaAplicadoAlLote::class,
            fn (AjusteEntradaAplicadoAlLote $e) =>
                $e->lote->id === $destino->id
                && abs($e->huevosEntrantes - 120.0) < 0.001
                && abs($e->costoUnitarioAplicado - 2.605) < 0.000001 // costo origen (Opción B)
        );
    }

    public function test_wac_reclasificacion_actualiza_ambos_lotes_con_shadow_activo(): void
    {
        Config::set('inventario.wac.shadow_mode', true);

        [$origen, $destino] = $this->crearParDeLotes(conWac: true);

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);
        $this->service->aplicar($salida, $this->solicitante);

        $origen->refresh();
        $destino->refresh();

        // ORIGEN — salida: reduce numerador y denominador, PRESERVA costo unitario (invariante WAC)
        //   inv: 7815 − (120 × 2.605) = 7502.40 | huevos: 2880 | unit: 2.605
        $this->assertEqualsWithDelta(7502.40, (float) $origen->wac_costo_inventario, 0.01);
        $this->assertEqualsWithDelta(2880.0, (float) $origen->wac_huevos_inventario, 0.001);
        $this->assertEqualsWithDelta(2.605, (float) $origen->wac_costo_por_huevo, 0.000001);
        $this->assertSame('ajuste_salida', $origen->wac_motivo_ultima_actualizacion);

        // DESTINO — entrada al costo origen: recalcula el promedio ponderado
        //   inv: 6000 + 312.60 = 6312.60 | huevos: 3120 | unit: 6312.60 / 3120 ≈ 2.023269
        $this->assertEqualsWithDelta(6312.60, (float) $destino->wac_costo_inventario, 0.01);
        $this->assertEqualsWithDelta(3120.0, (float) $destino->wac_huevos_inventario, 0.001);
        $this->assertEqualsWithDelta(2.023269, (float) $destino->wac_costo_por_huevo, 0.000001);

        // Trazabilidad completa: la bitácora del destino distingue una entrada
        // por ajuste de una compra real.
        $this->assertSame('ajuste_entrada', $destino->wac_motivo_ultima_actualizacion);
    }

    public function test_reclasificacion_usa_costo_efectivo_wac_cuando_read_source_es_wac(): void
    {
        Config::set('inventario.wac.read_source', 'wac');

        $bodega = Bodega::factory()->create();

        // Lote origen con DRIFT deliberado: legacy 2.605 vs WAC 2.0.
        // El ajuste debe valorarse con el costo que muestran las pantallas (WAC).
        $origen = Lote::factory()
            ->conCompra(3000.0, 7815.0)          // legacy: 2.605 L/huevo
            ->wacInicializado(3000.0, 6000.0)    // wac:    2.0 L/huevo
            ->create(['bodega_id' => $bodega->id]);

        $destino = Lote::factory()->conCompra(3000.0, 6000.0)->create(['bodega_id' => $bodega->id]);

        ['salida' => $salida, 'entrada' => $entrada] = $this->reclasificar($origen, $destino, 120.0);

        // Valorado al costo EFECTIVO (WAC 2.0), no al legacy (2.605)
        $this->assertEqualsWithDelta(2.0, (float) $salida->costo_unitario_aplicado, 0.000001);
        $this->assertEqualsWithDelta(2.0, (float) $entrada->costo_unitario_aplicado, 0.000001);
        $this->assertEqualsWithDelta(240.00, (float) $salida->valor_contable_afectado, 0.01); // 120 × 2.0
    }

    public function test_merma_residual_usa_costo_efectivo_wac_cuando_read_source_es_wac(): void
    {
        Config::set('inventario.wac.read_source', 'wac');

        $lote = Lote::factory()
            ->conCompra(3000.0, 7815.0)          // legacy: 2.605 L/huevo
            ->wacInicializado(3000.0, 6000.0)    // wac:    2.0 L/huevo
            ->create();

        $ajuste = $this->crearMerma($lote, 90.0);

        $this->assertEqualsWithDelta(2.0, (float) $ajuste->costo_unitario_aplicado, 0.000001);
        $this->assertEqualsWithDelta(180.00, (float) $ajuste->valor_contable_afectado, 0.01); // 90 × 2.0
    }

    public function test_wac_intacto_con_shadow_apagado(): void
    {
        Config::set('inventario.wac.shadow_mode', false);

        [$origen, $destino] = $this->crearParDeLotes(conWac: true);

        ['salida' => $salida] = $this->reclasificar($origen, $destino, 120.0);
        $this->service->aplicar($salida, $this->solicitante);

        $origen->refresh();
        $destino->refresh();

        // El stock legacy SÍ se movió…
        $this->assertEquals(2880.0, (float) $origen->cantidad_huevos_remanente);
        $this->assertEquals(3120.0, (float) $destino->cantidad_huevos_remanente);

        // …pero las columnas WAC quedaron exactamente como estaban (kill-switch)
        $this->assertEqualsWithDelta(7815.0, (float) $origen->wac_costo_inventario, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $origen->wac_huevos_inventario, 0.001);
        $this->assertEqualsWithDelta(6000.0, (float) $destino->wac_costo_inventario, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $destino->wac_huevos_inventario, 0.001);
    }
}
