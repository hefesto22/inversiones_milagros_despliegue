<?php

namespace Tests\Unit\Services\Fiscal;

use App\Services\Fiscal\IsvCalculator;
use PHPUnit\Framework\TestCase;

class IsvCalculatorTest extends TestCase
{
    // ============================================
    // CONSTANTE ISV
    // ============================================

    public function test_tasa_isv_es_quince_por_ciento(): void
    {
        $this->assertEquals(0.15, IsvCalculator::TASA_ISV);
    }

    // ============================================
    // CALCULAR DESGLOSE (precio con ISV → desglose)
    // Keys: precio_con_isv, costo_sin_isv, isv_credito
    // ============================================

    public function test_desglose_de_cien_lempiras(): void
    {
        $desglose = IsvCalculator::calcularDesglose(100.0);

        // 100 / 1.15 = 86.9565... → 86.96
        $this->assertEqualsWithDelta(86.96, $desglose['costo_sin_isv'], 0.01);
        $this->assertEqualsWithDelta(13.04, $desglose['isv_credito'], 0.01);
        $this->assertEquals(100.0, $desglose['precio_con_isv']);
    }

    public function test_desglose_con_cero(): void
    {
        $desglose = IsvCalculator::calcularDesglose(0.0);

        $this->assertEquals(0.0, $desglose['costo_sin_isv']);
        $this->assertEquals(0.0, $desglose['isv_credito']);
        $this->assertEquals(0.0, $desglose['precio_con_isv']);
    }

    public function test_desglose_suma_costo_sin_isv_mas_isv_es_precio_con_isv(): void
    {
        $precios = [50.0, 100.0, 250.75, 999.99, 1500.00];

        foreach ($precios as $precio) {
            $desglose = IsvCalculator::calcularDesglose($precio);

            $suma = round($desglose['costo_sin_isv'] + $desglose['isv_credito'], 2);
            $this->assertEqualsWithDelta(
                $desglose['precio_con_isv'],
                $suma,
                0.02,
                "Para precio {$precio}: {$desglose['costo_sin_isv']} + {$desglose['isv_credito']} debería ser {$desglose['precio_con_isv']}"
            );
        }
    }

    // ============================================
    // CALCULAR ISV (precio sin ISV → desglose)
    // Keys: precio_sin_isv, isv, precio_con_isv
    // ============================================

    public function test_calcular_isv_de_100(): void
    {
        $resultado = IsvCalculator::calcularIsv(100.0);

        $this->assertIsArray($resultado);
        $this->assertEquals(100.0, $resultado['precio_sin_isv']);
        $this->assertEquals(15.0, $resultado['isv']);
        $this->assertEquals(115.0, $resultado['precio_con_isv']);
    }

    public function test_calcular_isv_de_cero(): void
    {
        $resultado = IsvCalculator::calcularIsv(0.0);

        $this->assertEquals(0.0, $resultado['precio_sin_isv']);
        $this->assertEquals(0.0, $resultado['isv']);
        $this->assertEquals(0.0, $resultado['precio_con_isv']);
    }

    public function test_calcular_isv_consistencia_con_desglose(): void
    {
        // Si calculo ISV de 100 → precio_con_isv = 115
        // Si hago desglose de 115 → costo_sin_isv debería ser ~100
        $isv = IsvCalculator::calcularIsv(100.0);
        $desglose = IsvCalculator::calcularDesglose($isv['precio_con_isv']);

        $this->assertEqualsWithDelta(100.0, $desglose['costo_sin_isv'], 0.01);
    }

    // ============================================
    // PRODUCTO APLICA ISV (requiere DB - Feature test)
    // ============================================

    public function test_producto_aplica_isv_requiere_db(): void
    {
        $this->markTestSkipped('Requiere base de datos - mover a Feature test');
    }
}
