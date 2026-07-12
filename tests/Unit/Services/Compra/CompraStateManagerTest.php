<?php

namespace Tests\Unit\Services\Compra;

use App\Enums\CompraEstado;
use App\Services\Compra\CompraStateManager;
use PHPUnit\Framework\TestCase;

class CompraStateManagerTest extends TestCase
{
    // ============================================
    // TRANSICIONES VÁLIDAS
    // ============================================

    public function test_borrador_puede_transicionar_a_ordenada(): void
    {
        $this->assertTrue(
            CompraStateManager::puedeTransicionar(CompraEstado::Borrador, CompraEstado::Ordenada)
        );
    }

    public function test_borrador_puede_transicionar_a_estados_intermedios_permitidos(): void
    {
        // El state manager fue refactorizado para BLOQUEAR el salto directo
        // de Borrador a estados "recibidos" (RecibidaPagada y RecibidaPendientePago)
        // porque eso bypassea el procesamiento de inventario.
        // Ver: app/Services/Compra/CompraStateManager.php const TRANSITIONS.
        //
        // Transiciones permitidas desde Borrador:
        //   - Ordenada (flujo normal)
        //   - PorRecibirPagada (pago anticipado, sin recibir)
        //   - PorRecibirPendientePago (orden a crédito, sin recibir)
        //   - Cancelada (siempre permitido)
        $destinosPermitidos = [
            CompraEstado::Ordenada,
            CompraEstado::PorRecibirPagada,
            CompraEstado::PorRecibirPendientePago,
            CompraEstado::Cancelada,
        ];

        foreach ($destinosPermitidos as $destino) {
            $this->assertTrue(
                CompraStateManager::puedeTransicionar(CompraEstado::Borrador, $destino),
                "Borrador debería poder transicionar a {$destino->value}"
            );
        }
    }

    public function test_borrador_no_puede_saltar_directo_a_estados_recibidos(): void
    {
        // Guard arquitectónico: si Borrador → Recibida* fuera válido, se podría
        // marcar la compra como recibida sin procesar el inventario en lotes.
        // El flujo correcto exige pasar por Ordenada primero, o por
        // PorRecibir* si hay pago anticipado.
        $destinosBloqueados = [
            CompraEstado::RecibidaPagada,
            CompraEstado::RecibidaPendientePago,
        ];

        foreach ($destinosBloqueados as $destino) {
            $this->assertFalse(
                CompraStateManager::puedeTransicionar(CompraEstado::Borrador, $destino),
                "Borrador NO debería poder saltar a {$destino->value} (bypaseo de inventario)"
            );
        }
    }

    public function test_recibida_pendiente_pago_puede_ir_a_recibida_pagada(): void
    {
        $this->assertTrue(
            CompraStateManager::puedeTransicionar(
                CompraEstado::RecibidaPendientePago,
                CompraEstado::RecibidaPagada
            )
        );
    }

    public function test_por_recibir_pagada_puede_ir_a_recibida_pagada(): void
    {
        $this->assertTrue(
            CompraStateManager::puedeTransicionar(
                CompraEstado::PorRecibirPagada,
                CompraEstado::RecibidaPagada
            )
        );
    }

    // ============================================
    // TRANSICIONES INVÁLIDAS
    // ============================================

    public function test_recibida_pagada_es_estado_final(): void
    {
        $this->assertTrue(
            CompraStateManager::esEstadoFinal(CompraEstado::RecibidaPagada)
        );
    }

    public function test_cancelada_es_estado_final(): void
    {
        $this->assertTrue(
            CompraStateManager::esEstadoFinal(CompraEstado::Cancelada)
        );
    }

    public function test_recibida_pagada_no_puede_transicionar_a_ningun_estado(): void
    {
        foreach (CompraEstado::cases() as $destino) {
            $this->assertFalse(
                CompraStateManager::puedeTransicionar(CompraEstado::RecibidaPagada, $destino),
                "Recibida y Pagada no debería transicionar a {$destino->value}"
            );
        }
    }

    public function test_cancelada_no_puede_transicionar_a_ningun_estado(): void
    {
        foreach (CompraEstado::cases() as $destino) {
            $this->assertFalse(
                CompraStateManager::puedeTransicionar(CompraEstado::Cancelada, $destino),
                "Cancelada no debería transicionar a {$destino->value}"
            );
        }
    }

    public function test_borrador_no_puede_transicionar_a_borrador(): void
    {
        $this->assertFalse(
            CompraStateManager::puedeTransicionar(CompraEstado::Borrador, CompraEstado::Borrador)
        );
    }

    // ============================================
    // TRANSICIONES DISPONIBLES
    // ============================================

    public function test_transiciones_disponibles_desde_borrador_tiene_4_opciones(): void
    {
        // Tras refactor que prohibe salto directo Borrador → Recibida*, las
        // transiciones válidas desde Borrador son: Ordenada, PorRecibirPagada,
        // PorRecibirPendientePago, Cancelada. Total: 4.
        $transiciones = CompraStateManager::transicionesDisponibles(CompraEstado::Borrador);

        $this->assertCount(4, $transiciones);
    }

    public function test_transiciones_disponibles_desde_estado_final_es_vacio(): void
    {
        $this->assertEmpty(
            CompraStateManager::transicionesDisponibles(CompraEstado::RecibidaPagada)
        );
        $this->assertEmpty(
            CompraStateManager::transicionesDisponibles(CompraEstado::Cancelada)
        );
    }

    // ============================================
    // OPCIONES DE TRANSICIÓN (para Filament)
    // ============================================

    public function test_opciones_transicion_retorna_array_asociativo(): void
    {
        $opciones = CompraStateManager::opcionesTransicion(CompraEstado::Borrador);

        $this->assertIsArray($opciones);
        $this->assertArrayHasKey('ordenada', $opciones);
        $this->assertArrayHasKey('cancelada', $opciones);
    }

    // ============================================
    // PUEDE CANCELARSE
    // ============================================

    public function test_borrador_puede_cancelarse(): void
    {
        $this->assertTrue(CompraStateManager::puedeCancelarse(CompraEstado::Borrador));
    }

    public function test_ordenada_puede_cancelarse(): void
    {
        $this->assertTrue(CompraStateManager::puedeCancelarse(CompraEstado::Ordenada));
    }

    public function test_recibida_pagada_no_puede_cancelarse(): void
    {
        $this->assertFalse(CompraStateManager::puedeCancelarse(CompraEstado::RecibidaPagada));
    }

    public function test_cancelada_no_puede_cancelarse(): void
    {
        $this->assertFalse(CompraStateManager::puedeCancelarse(CompraEstado::Cancelada));
    }

    // ============================================
    // FLUJO COMPLETO (happy path)
    // ============================================

    public function test_flujo_completo_contado(): void
    {
        // Borrador → Ordenada → Recibida y Pagada
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::Borrador, CompraEstado::Ordenada));
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::Ordenada, CompraEstado::RecibidaPagada));
    }

    public function test_flujo_completo_credito(): void
    {
        // Borrador → Ordenada → Recibida Pendiente → Recibida Pagada
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::Borrador, CompraEstado::Ordenada));
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::Ordenada, CompraEstado::RecibidaPendientePago));
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::RecibidaPendientePago, CompraEstado::RecibidaPagada));
    }

    public function test_flujo_pago_anticipado(): void
    {
        // Borrador → Ordenada → Por Recibir Pagada → Recibida Pagada
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::Borrador, CompraEstado::Ordenada));
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::Ordenada, CompraEstado::PorRecibirPagada));
        $this->assertTrue(CompraStateManager::puedeTransicionar(CompraEstado::PorRecibirPagada, CompraEstado::RecibidaPagada));
    }
}
