<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 3 del refactor WAC Perpetuo — infraestructura de observabilidad del backfill.
 *
 * Crea dos tablas de soporte para BackfillWacCommand:
 *
 *   wac_backfill_runs
 *     Un registro por ejecución del command. Captura el modo (dry-run vs apply),
 *     filtros aplicados, contadores agregados, timestamps y estado final.
 *     Fuente única de auditoría de "cuándo y con qué parámetros se corrió".
 *
 *   wac_backfill_items
 *     Un registro por lote procesado dentro de un run. Captura valores antes/después,
 *     clasificación de divergencia (ruido/esperada/anómala/ninguna) y motivo detallado.
 *     Permite reanudación (saltar ítems ya procesados en runs previos exitosos) y
 *     análisis post-mortem.
 *
 * Diseño — tablas persistentes, no tabla temporal:
 *   Un backfill de costos financieros necesita trazabilidad permanente. Si mañana
 *   alguien se pregunta "¿por qué el lote X tiene este WAC?", debe poder encontrar
 *   el run que lo escribió, con los valores antes/después y su clasificación.
 *
 * Diseño — items nivel lote, no movimiento:
 *   El grano de procesamiento del backfill es el lote (compras agregadas del ciclo
 *   activo). No replayamos movimiento por movimiento porque la invariante del WAC
 *   hace equivalente el cálculo agregado al replay completo, y los movimientos de
 *   salida viven dispersos en 5+ tablas sin vínculo directo a lote_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wac_backfill_runs', function (Blueprint $table): void {
            $table->id();

            // Identidad del run
            $table->uuid('run_uuid')->unique()
                ->comment('UUID estable para referenciar este run desde logs externos');
            $table->enum('modo', ['dry-run', 'apply'])
                ->comment('dry-run: solo reporte. apply: escribe wac_* en lotes');
            $table->enum('estado', ['en_curso', 'completado', 'fallido', 'abortado'])
                ->default('en_curso');

            // Filtros aplicados
            $table->unsignedBigInteger('bodega_id_filtro')->nullable()
                ->comment('NULL = todas las bodegas. FK sin constraint por simplicidad (snapshot histórico)');
            $table->unsignedBigInteger('producto_id_filtro')->nullable()
                ->comment('NULL = todos los productos');
            $table->boolean('force_aplicado')->default(false)
                ->comment('true si se pasó --force para tolerar divergencias anómalas');

            // Contadores agregados
            $table->unsignedInteger('total_lotes')->default(0);
            $table->unsignedInteger('lotes_procesados')->default(0);
            $table->unsignedInteger('lotes_saltados')->default(0)
                ->comment('Ya procesados en run previo exitoso o sin compras aplicables');
            $table->unsignedInteger('lotes_fallidos')->default(0);
            $table->unsignedInteger('divergencias_ruido')->default(0);
            $table->unsignedInteger('divergencias_esperadas')->default(0);
            $table->unsignedInteger('divergencias_anomalas')->default(0);

            // Timestamps de ejecución
            $table->timestamp('iniciado_en');
            $table->timestamp('finalizado_en')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();

            // Trazabilidad
            $table->unsignedBigInteger('ejecutado_por')->nullable()
                ->comment('user_id si se corrió autenticado, NULL si se corrió desde CLI directo');
            $table->text('notas')->nullable()
                ->comment('Observaciones del operador al momento de correr');

            $table->timestamps();

            // Índices con nombres explícitos cortos (MySQL limita identificadores a 64 chars).
            $table->index(['estado', 'modo'], 'wac_runs_estado_modo_idx');
            $table->index('iniciado_en', 'wac_runs_iniciado_en_idx');
            $table->index(
                ['bodega_id_filtro', 'producto_id_filtro'],
                'wac_runs_bodega_producto_idx'
            );
        });

        Schema::create('wac_backfill_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('wac_backfill_run_id')
                ->constrained('wac_backfill_runs')
                ->cascadeOnDelete();
            $table->foreignId('lote_id')
                ->constrained('lotes')
                ->cascadeOnDelete();

            // Estado del procesamiento del ítem
            $table->enum('estado', ['procesado', 'saltado', 'fallido'])
                ->default('procesado');
            $table->string('motivo_salto', 80)->nullable()
                ->comment('Si estado=saltado: razón (ya procesado | sin compras | lote vacío)');

            // Clasificación de divergencia vs legacy
            $table->enum('clasificacion_divergencia', ['ninguna', 'ruido', 'esperada', 'anomala'])
                ->default('ninguna');
            $table->text('detalle_divergencia')->nullable()
                ->comment('Si clasificacion != ninguna: explicación numérica + motivo');

            // Valores calculados (snapshot para auditoría)
            $table->decimal('wac_costo_inventario_calculado', 16, 4)->nullable();
            $table->decimal('wac_huevos_inventario_calculado', 16, 4)->nullable();
            $table->decimal('wac_costo_por_huevo_calculado', 12, 6)->nullable();
            $table->decimal('wac_costo_por_carton_calculado', 14, 4)->nullable();

            // Valores legacy (snapshot al momento de evaluar)
            $table->decimal('costo_por_huevo_legacy', 12, 6)->nullable();
            $table->decimal('costo_por_carton_legacy', 14, 4)->nullable();
            $table->decimal('diferencia_por_carton', 14, 4)->nullable()
                ->comment('wac_costo_por_carton_calculado - costo_por_carton_legacy');

            // Metadatos del cálculo
            $table->unsignedInteger('compras_consideradas')->default(0)
                ->comment('# de filas de historial_compras_lote incluidas en el cálculo WAC');
            $table->text('error_mensaje')->nullable()
                ->comment('Si estado=fallido: traza corta del error');

            $table->timestamps();

            // Un lote solo puede aparecer una vez por run
            $table->unique(['wac_backfill_run_id', 'lote_id'], 'wac_items_run_lote_unico_idx');

            // Índices con nombres explícitos cortos (MySQL limita identificadores a 64 chars).
            // El auto-generado 'wac_backfill_items_wac_backfill_run_id_clasificacion_divergencia_index'
            // excedía el límite (71 chars) — por eso todos los índices de esta tabla llevan nombre manual.
            $table->index(['wac_backfill_run_id', 'estado'], 'wac_items_run_estado_idx');
            $table->index(['wac_backfill_run_id', 'clasificacion_divergencia'], 'wac_items_run_clasif_idx');
            $table->index('lote_id', 'wac_items_lote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wac_backfill_items');
        Schema::dropIfExists('wac_backfill_runs');
    }
};
