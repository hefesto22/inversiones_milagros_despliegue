<?php

namespace Tests\Unit\Enums;

use App\Enums\CompraEstado;
use PHPUnit\Framework\TestCase;

class CompraEstadoTest extends TestCase
{
    public function test_tiene_siete_estados(): void
    {
        $this->assertCount(7, CompraEstado::cases());
    }

    public function test_labels_no_estan_vacios(): void
    {
        foreach (CompraEstado::cases() as $estado) {
            $this->assertNotEmpty($estado->label(), "Label vacío para {$estado->value}");
        }
    }

    public function test_colores_no_estan_vacios(): void
    {
        foreach (CompraEstado::cases() as $estado) {
            $this->assertNotEmpty($estado->color(), "Color vacío para {$estado->value}");
        }
    }

    public function test_options_retorna_array_con_siete_elementos(): void
    {
        $options = CompraEstado::options();

        $this->assertCount(7, $options);
        $this->assertArrayHasKey('borrador', $options);
        $this->assertArrayHasKey('cancelada', $options);
    }

    public function test_con_deuda_pendiente_contiene_estados_correctos(): void
    {
        $pendientes = CompraEstado::conDeudaPendiente();

        $this->assertContains(CompraEstado::RecibidaPendientePago, $pendientes);
        $this->assertContains(CompraEstado::PorRecibirPendientePago, $pendientes);
        $this->assertCount(2, $pendientes);
    }

    public function test_recibidas_contiene_estados_correctos(): void
    {
        $recibidas = CompraEstado::recibidas();

        $this->assertContains(CompraEstado::RecibidaPagada, $recibidas);
        $this->assertContains(CompraEstado::RecibidaPendientePago, $recibidas);
        $this->assertCount(2, $recibidas);
    }

    public function test_from_string_funciona(): void
    {
        $estado = CompraEstado::from('borrador');
        $this->assertEquals(CompraEstado::Borrador, $estado);
    }

    public function test_try_from_invalido_retorna_null(): void
    {
        $estado = CompraEstado::tryFrom('estado_inexistente');
        $this->assertNull($estado);
    }
}
