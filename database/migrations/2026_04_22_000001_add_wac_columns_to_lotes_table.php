<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 del refactor WAC Perpetuo — migración aditiva de columnas shadow.
 *
 * Agrega columnas "wac_*" a la tabla `lotes` para almacenar los valores
 * calculados bajo el modelo de Moving Weighted Average Cost Perpetuo.
 *
 * Estas columnas conviven con las columnas legacy durante las fases 2-5:
 *   - costo_total_acumulado               (legacy, se mantiene)
 *   - huevos_facturados_acumulados        (legacy, se mantiene)
 *   - costo_por_huevo                     (legacy, se mantiene)
 *   - costo_por_carton_facturado          (legacy, se mantiene)
 *
 * En la Fase 6 (deprecación) las columnas legacy se removerán en una
 * migración separada, dejando solo las wac_* como fuente de verdad.
 *
 * Características de esta migración:
 *   - 100% aditiva: no modifica ni borra datos existentes.
 *   - Todas las columnas son NULLABLE: las filas existentes quedan en NULL
 *     hasta que el comando BackfillWacCommand (Fase 3) las pueble.
 *   - INSTANT DDL en MySQL 8+ / MariaDB 10.3+: no bloquea tabla ni reescribe filas.
 *   - Reversible: down() remueve todas las columnas wac_* sin tocar legacy.
 *
 * Referencia: docs/AUDITORIA_VALUACION_2026-04-22.md
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            // Numerador WAC: valor monetario del inventario facturado actual
            // DECIMAL(16,4) → hasta 999,999,999,999.9999 Lempiras
            // Precisión de 4 decimales alineada con requisitos SAR de facturación
            $table->decimal('wac_costo_inventario', 16, 4)
                ->nullable()
                ->after('costo_total_acumulado')
                ->comment('WAC: valor monetario del stock facturado actual (numerador del costo promedio perpetuo)');

            // Denominador WAC: cantidad de huevos facturados actualmente en stock
            // BIGINT UNSIGNED → headroom futuro si el volumen del negocio crece 10x-100x
            // Se guarda solo huevos facturados (no incluye regalo, que no tiene costo)
            $table->unsignedBigInteger('wac_huevos_inventario')
                ->nullable()
                ->after('wac_costo_inventario')
                ->comment('WAC: cantidad de huevos facturados actualmente en stock (denominador del costo promedio perpetuo)');

            // Derivado persistido: costo por huevo unitario bajo WAC
            // DECIMAL(12,6) → 6 decimales evitan pérdida de precisión al multiplicar
            // por cantidades grandes (ej: 2.605000 × 10,000 huevos = 26,050.0000)
            $table->decimal('wac_costo_por_huevo', 12, 6)
                ->nullable()
                ->after('wac_huevos_inventario')
                ->comment('WAC: costo unitario por huevo (derivado = wac_costo_inventario / wac_huevos_inventario)');

            // Derivado persistido: costo por cartón bajo WAC
            // Se persiste (no se calcula on-the-fly) para mantener consistencia de
            // shape con la columna legacy costo_por_carton_facturado y permitir
            // swap limpio en Fase 5 sin refactorizar lecturas en Filament/PDFs/Excel
            $table->decimal('wac_costo_por_carton_facturado', 14, 4)
                ->nullable()
                ->after('wac_costo_por_huevo')
                ->comment('WAC: costo por cartón facturado (derivado = wac_costo_por_huevo × huevos_por_carton)');

            // Auditoría: timestamp de última escritura WAC
            // Permite: job de reconciliación filtrar lotes actualizados recientemente,
            // detectar lotes "estancados" sin movimiento, debugging de producción
            $table->timestamp('wac_ultima_actualizacion')
                ->nullable()
                ->after('wac_costo_por_carton_facturado')
                ->comment('WAC: timestamp de última escritura por WacService o BackfillWacCommand');

            // Auditoría: motivo de la última escritura WAC
            // Valores esperados: 'compra' | 'venta' | 'merma' | 'devolucion' | 'backfill' | 'reempaque'
            // VARCHAR(40) en vez de ENUM para flexibilidad de agregar eventos sin migración
            $table->string('wac_motivo_ultima_actualizacion', 40)
                ->nullable()
                ->after('wac_ultima_actualizacion')
                ->comment('WAC: motivo de la última actualización (compra|venta|merma|devolucion|backfill|reempaque)');
        });
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table): void {
            $table->dropColumn([
                'wac_costo_inventario',
                'wac_huevos_inventario',
                'wac_costo_por_huevo',
                'wac_costo_por_carton_facturado',
                'wac_ultima_actualizacion',
                'wac_motivo_ultima_actualizacion',
            ]);
        });
    }
};
