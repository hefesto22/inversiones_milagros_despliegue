<?php

namespace Tests\Unit\Models;

use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Models\VentaPago;
use App\Models\Cliente;
use App\Models\Bodega;
use App\Models\Producto;
use App\Models\BodegaProducto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;
    private Bodega $bodega;
    private Producto $producto;
    private BodegaProducto $bodegaProducto;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear datos de prueba
        $this->user = User::factory()->create();
        $this->cliente = Cliente::factory()->create(['estado' => true]);
        $this->bodega = Bodega::factory()->create();
        $this->producto = Producto::factory()->create(['aplica_isv' => true]);

        $this->bodegaProducto = BodegaProducto::factory()->create([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'stock' => 100,
            'costo_promedio_actual' => 10.00,
            'precio_venta_sugerido' => 20.00,
        ]);
    }

    // ============================================
    // TESTS DE CREACIÓN
    // ============================================

    /** @test */
    public function una_venta_se_crea_en_estado_borrador()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'created_by' => $this->user->id,
        ]);

        $this->assertEquals('borrador', $venta->estado);
        $this->assertEquals('pendiente', $venta->estado_pago);
        $this->assertNull($venta->numero_venta);
    }

    /** @test */
    public function una_venta_tiene_relacion_con_cliente()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
        ]);

        $this->assertInstanceOf(Cliente::class, $venta->cliente);
        $this->assertEquals($this->cliente->id, $venta->cliente->id);
    }

    /** @test */
    public function una_venta_tiene_relacion_con_bodega()
    {
        $venta = Venta::factory()->create([
            'bodega_id' => $this->bodega->id,
            'cliente_id' => $this->cliente->id,
        ]);

        $this->assertInstanceOf(Bodega::class, $venta->bodega);
        $this->assertEquals($this->bodega->id, $venta->bodega->id);
    }

    // ============================================
    // TESTS DE DETALLES Y CÁLCULOS
    // ============================================

    /** @test */
    public function una_venta_puede_tener_detalles()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
        ]);

        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
            'cantidad' => 10,
            'precio_unitario' => 20,
            'costo_unitario' => 10,
        ]);

        $this->assertCount(1, $venta->detalles);
        $this->assertInstanceOf(VentaDetalle::class, $venta->detalles->first());
    }

    /** @test */
    public function recalcular_totales_suma_correctamente()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'subtotal' => 0,
            'total_isv' => 0,
            'descuento' => 0,
            'total' => 0,
            'saldo_pendiente' => 0,
        ]);

        // Detalle 1: 10 x 20 = 200 + 30 ISV = 230
        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
            'cantidad' => 10,
            'precio_unitario' => 20.00,
            'costo_unitario' => 10.00,
            'aplica_isv' => true,
            'isv_unitario' => 3.00,
            'precio_con_isv' => 23.00,
            'subtotal' => 200.00,
            'total_isv' => 30.00,
            'total_linea' => 230.00,
        ]);

        // Detalle 2: 5 x 30 = 150 + 22.5 ISV = 172.5
        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
            'cantidad' => 5,
            'precio_unitario' => 30.00,
            'costo_unitario' => 15.00,
            'aplica_isv' => true,
            'isv_unitario' => 4.50,
            'precio_con_isv' => 34.50,
            'subtotal' => 150.00,
            'total_isv' => 22.50,
            'total_linea' => 172.50,
        ]);

        $venta->recalcularTotales();

        $this->assertEquals(350.00, $venta->subtotal);      // 200 + 150
        $this->assertEquals(52.50, $venta->total_isv);      // 30 + 22.5
        $this->assertEquals(402.50, $venta->total);         // 350 + 52.5
        $this->assertEquals(402.50, $venta->saldo_pendiente);
    }

    /** @test */
    public function recalcular_totales_aplica_descuento()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'descuento' => 50.00,
            'monto_pagado' => 0,
        ]);

        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
            'cantidad' => 10,
            'precio_unitario' => 20.00,
            'costo_unitario' => 10.00,
            'subtotal' => 200.00,
            'total_isv' => 30.00,
            'total_linea' => 230.00,
        ]);

        $venta->recalcularTotales();

        // Total = subtotal + ISV - descuento = 200 + 30 - 50 = 180
        $this->assertEquals(180.00, $venta->total);
        $this->assertEquals(180.00, $venta->saldo_pendiente);
    }

    // ============================================
    // TESTS DE GANANCIA
    // ============================================

    /** @test */
    public function calcular_ganancia_correctamente()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'subtotal' => 200,
        ]);

        // Costo total = (10 * 10) + (5 * 15) = 100 + 75 = 175
        // Ganancia = subtotal - costo = 200 - 175 = 25
        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'cantidad' => 10,
            'costo_unitario' => 10.00,
        ]);

        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'cantidad' => 5,
            'costo_unitario' => 15.00,
        ]);

        $ganancia = $venta->calcularGanancia();
        $this->assertEquals(25.00, $ganancia);
    }

    // ============================================
    // TESTS DE PAGOS
    // ============================================

    /** @test */
    public function registrar_pago_actualiza_saldos()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'tipo_pago' => 'credito',
            'total' => 1000.00,
            'monto_pagado' => 0,
            'saldo_pendiente' => 1000.00,
            'estado_pago' => 'pendiente',
        ]);

        $pago = $venta->registrarPago(300.00, 'efectivo', null, 'Pago parcial');

        $venta->refresh();

        $this->assertInstanceOf(VentaPago::class, $pago);
        $this->assertEquals(300.00, $venta->monto_pagado);
        $this->assertEquals(700.00, $venta->saldo_pendiente);
        $this->assertEquals('parcial', $venta->estado_pago);
    }

    /** @test */
    public function registrar_pago_completo_marca_pagado()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'tipo_pago' => 'credito',
            'total' => 1000.00,
            'monto_pagado' => 700.00,
            'saldo_pendiente' => 300.00,
            'estado_pago' => 'parcial',
        ]);

        $venta->registrarPago(300.00, 'transferencia');
        $venta->refresh();

        $this->assertEquals(1000.00, $venta->monto_pagado);
        $this->assertEquals(0, $venta->saldo_pendiente);
        $this->assertEquals('pagado', $venta->estado_pago);
    }

    /** @test */
    public function registrar_pago_superior_al_saldo_ajusta_saldo_a_cero()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'total' => 1000.00,
            'monto_pagado' => 0,
            'saldo_pendiente' => 1000.00,
        ]);

        $venta->registrarPago(1500.00);
        $venta->refresh();

        $this->assertEquals(0, $venta->saldo_pendiente);
        $this->assertEquals('pagado', $venta->estado_pago);
    }

    // ============================================
    // TESTS DE VALIDACIÓN DE PAGO
    // ============================================

    /** @test */
    public function esta_pagada_retorna_true_si_estado_es_pagado()
    {
        $venta = Venta::factory()->create([
            'estado_pago' => 'pagado',
        ]);

        $this->assertTrue($venta->estaPagada());
    }

    /** @test */
    public function esta_pagada_retorna_false_si_estado_no_es_pagado()
    {
        $venta = Venta::factory()->create([
            'estado_pago' => 'pendiente',
        ]);

        $this->assertFalse($venta->estaPagada());
    }

    // ============================================
    // TESTS DE VENCIMIENTO
    // ============================================

    /** @test */
    public function esta_vencida_retorna_false_si_esta_pagada()
    {
        $venta = Venta::factory()->create([
            'estado_pago' => 'pagado',
            'fecha_vencimiento' => now()->subDays(10),
        ]);

        $this->assertFalse($venta->estaVencida());
    }

    /** @test */
    public function esta_vencida_retorna_false_si_sin_fecha_vencimiento()
    {
        $venta = Venta::factory()->create([
            'estado_pago' => 'pendiente',
            'fecha_vencimiento' => null,
        ]);

        $this->assertFalse($venta->estaVencida());
    }

    /** @test */
    public function esta_vencida_retorna_true_si_fecha_es_pasada()
    {
        $venta = Venta::factory()->create([
            'estado_pago' => 'pendiente',
            'fecha_vencimiento' => now()->subDays(5),
        ]);

        $this->assertTrue($venta->estaVencida());
    }

    /** @test */
    public function esta_vencida_retorna_false_si_fecha_es_futura()
    {
        $venta = Venta::factory()->create([
            'estado_pago' => 'pendiente',
            'fecha_vencimiento' => now()->addDays(5),
        ]);

        $this->assertFalse($venta->estaVencida());
    }

    /** @test */
    public function get_dias_vencimiento_retorna_dias_correctos()
    {
        $venta = Venta::factory()->create([
            'fecha_vencimiento' => now()->addDays(10),
        ]);

        $dias = $venta->getDiasVencimiento();

        $this->assertEquals(10, $dias);
    }

    /** @test */
    public function get_dias_vencimiento_retorna_null_sin_fecha()
    {
        $venta = Venta::factory()->create([
            'fecha_vencimiento' => null,
        ]);

        $this->assertNull($venta->getDiasVencimiento());
    }

    // ============================================
    // TESTS DE COMPLETAR VENTA
    // ============================================

    /** @test */
    public function completar_venta_genera_numero_venta()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'estado' => 'borrador',
            'numero_venta' => null,
        ]);

        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
            'cantidad' => 5,
        ]);

        $venta->completar();
        $venta->refresh();

        $this->assertNotNull($venta->numero_venta);
        $this->assertStringContainsString('V', $venta->numero_venta);
    }

    /** @test */
    public function completar_venta_cambia_estado_a_pendiente_pago_si_credito()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'estado' => 'borrador',
            'tipo_pago' => 'credito',
            'total' => 500.00,
        ]);

        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
        ]);

        $venta->completar();
        $venta->refresh();

        $this->assertEquals('pendiente_pago', $venta->estado);
        $this->assertEquals('pendiente', $venta->estado_pago);
    }

    /** @test */
    public function completar_venta_cambia_estado_a_pagada_si_contado()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'estado' => 'borrador',
            'tipo_pago' => 'contado',
            'total' => 500.00,
        ]);

        VentaDetalle::factory()->create([
            'venta_id' => $venta->id,
            'producto_id' => $this->producto->id,
        ]);

        $venta->completar();
        $venta->refresh();

        $this->assertEquals('pagada', $venta->estado);
        $this->assertEquals('pagado', $venta->estado_pago);
        $this->assertEquals(500.00, $venta->monto_pagado);
        $this->assertEquals(0, $venta->saldo_pendiente);
    }

    /** @test */
    public function completar_venta_no_completa_si_no_esta_en_borrador()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'estado' => 'pagada',
        ]);

        $resultado = $venta->completar();

        $this->assertFalse($resultado);
    }

    // ============================================
    // TESTS DE CANCELACIÓN
    // ============================================

    /** @test */
    public function cancelar_venta_cambia_estado()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'bodega_id' => $this->bodega->id,
            'estado' => 'pendiente_pago',
        ]);

        $venta->cancelar('Cambio de cliente');
        $venta->refresh();

        $this->assertEquals('cancelada', $venta->estado);
        $this->assertStringContainsString('[CANCELADA]', $venta->nota);
        $this->assertStringContainsString('Cambio de cliente', $venta->nota);
    }

    /** @test */
    public function cancelar_venta_no_cancela_dos_veces()
    {
        $venta = Venta::factory()->create([
            'estado' => 'cancelada',
        ]);

        $resultado = $venta->cancelar('Motivo');

        $this->assertFalse($resultado);
    }

    // ============================================
    // TESTS DE SCOPES
    // ============================================

    /** @test */
    public function scope_borrador_filtra_correctamente()
    {
        Venta::factory(3)->create(['estado' => 'borrador']);
        Venta::factory(2)->create(['estado' => 'pagada']);

        $borradores = Venta::borrador()->get();

        $this->assertCount(3, $borradores);
    }

    /** @test */
    public function scope_completadas_filtra_correctamente()
    {
        Venta::factory()->create(['estado' => 'completada']);
        Venta::factory()->create(['estado' => 'pendiente_pago']);
        Venta::factory()->create(['estado' => 'pagada']);
        Venta::factory()->create(['estado' => 'borrador']);

        $completadas = Venta::completadas()->get();

        $this->assertCount(3, $completadas);
    }

    /** @test */
    public function scope_pendientes_pago_filtra_correctamente()
    {
        Venta::factory()->create(['estado_pago' => 'pendiente']);
        Venta::factory()->create(['estado_pago' => 'parcial']);
        Venta::factory()->create(['estado_pago' => 'pagado']);

        $pendientes = Venta::pendientesPago()->get();

        $this->assertCount(2, $pendientes);
    }

    /** @test */
    public function scope_del_cliente_filtra_correctamente()
    {
        $cliente1 = Cliente::factory()->create();
        $cliente2 = Cliente::factory()->create();

        Venta::factory(3)->create(['cliente_id' => $cliente1->id]);
        Venta::factory(2)->create(['cliente_id' => $cliente2->id]);

        $ventasCliente1 = Venta::delCliente($cliente1->id)->get();

        $this->assertCount(3, $ventasCliente1);
    }

    // ============================================
    // TESTS DE HELPERS
    // ============================================

    /** @test */
    public function get_resumen_retorna_array_completo()
    {
        $venta = Venta::factory()->create([
            'cliente_id' => $this->cliente->id,
            'numero_venta' => 'V01-260327-0001',
            'subtotal' => 200,
            'total_isv' => 30,
            'descuento' => 10,
            'total' => 220,
            'estado' => 'pagada',
            'estado_pago' => 'pagado',
            'monto_pagado' => 220,
            'saldo_pendiente' => 0,
        ]);

        $resumen = $venta->getResumen();

        $this->assertArrayHasKey('numero_venta', $resumen);
        $this->assertArrayHasKey('cliente', $resumen);
        $this->assertArrayHasKey('fecha', $resumen);
        $this->assertArrayHasKey('subtotal', $resumen);
        $this->assertArrayHasKey('isv', $resumen);
        $this->assertArrayHasKey('descuento', $resumen);
        $this->assertArrayHasKey('total', $resumen);
        $this->assertArrayHasKey('estado', $resumen);
        $this->assertArrayHasKey('estado_pago', $resumen);
        $this->assertEquals($this->cliente->nombre, $resumen['cliente']);
    }

    // ============================================
    // TESTS DE GENERADOR DE NÚMERO
    // ============================================

    /** @test */
    public function generar_numero_venta_crea_numero_unico()
    {
        $venta1 = Venta::factory()->create([
            'bodega_id' => $this->bodega->id,
            'numero_venta' => null,
        ]);

        $numero1 = $venta1->generarNumeroVenta();

        $venta2 = Venta::factory()->create([
            'bodega_id' => $this->bodega->id,
            'numero_venta' => null,
        ]);

        $numero2 = $venta2->generarNumeroVenta();

        $this->assertNotEquals($numero1, $numero2);
        $this->assertStringStartsWith('V', $numero1);
        $this->assertStringStartsWith('V', $numero2);
    }
}
