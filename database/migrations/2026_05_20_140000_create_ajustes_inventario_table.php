<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla ajustes_inventario para el módulo de Ajustes de Inventario
 * por Conteo Físico, con soporte para reclasificaciones entre productos.
 *
 * Decisiones de diseño:
 *
 * 1. Auto-referencia ajuste_pareja_id:
 *    Una reclasificación entre productos genera 2 registros vinculados
 *    (salida + entrada). El campo apunta al "otro" registro de la pareja.
 *    Para mermas residuales y correcciones no aplica (NULL).
 *
 * 2. lote_id nullable:
 *    Permite ajustes contra bodega_producto (no contra un lote específico).
 *    En esa caso bodega_producto_id se llena y lote_id queda NULL.
 *
 * 3. costo_unitario_aplicado:
 *    Para una entrada de reclasificación, este es el costo del lote destino
 *    (no el del lote origen). Eso preserva el WAC del destino sin alteración
 *    y materializa la pérdida valorativa en el origen.
 *
 * 4. valor_contable_afectado:
 *    Cantidad de Lempiras de diferencia contable. Para salidas, es
 *    huevos * costo_origen. Para entradas, es huevos * costo_destino.
 *    La pérdida neta de una reclasificación es la diferencia entre la salida
 *    y la entrada vinculadas.
 *
 * 5. Estados: borrador → pendiente_aprobacion → aprobado/rechazado → aplicado
 *    Cuando se aplica, los registros pasan a INMUTABLES (enforced por Policy).
 *
 * 6. Índices: optimizados para los reportes esperados (por bodega/producto/fecha,
 *    por lote, por estado y aprobador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajustes_inventario', function (Blueprint $table) {
            $table->id();

            // Origen del movimiento — uno de los dos debe estar lleno
            $table->foreignId('lote_id')->nullable()
                ->constrained('lotes')->cascadeOnDelete()
                ->comment('Lote afectado (NULL si el ajuste es sobre bodega_producto)');
            $table->foreignId('bodega_producto_id')->nullable()
                ->constrained('bodega_producto')->cascadeOnDelete()
                ->comment('Stock de bodega afectado (NULL si el ajuste es sobre un lote)');

            // Contexto obligatorio
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();

            // Tipo y motivo (enums)
            $table->string('tipo_movimiento', 30)
                ->comment('salida_reclasificacion | entrada_reclasificacion | merma_residual | ajuste_correccion');
            $table->string('motivo', 40)
                ->comment('Enum AjusteMotivo: conteo_fisico_diferencia | clasificacion_incorrecta | ...');

            // Vínculo a la pareja (solo para reclasificaciones)
            $table->foreignId('ajuste_pareja_id')->nullable()
                ->constrained('ajustes_inventario')->nullOnDelete()
                ->comment('Si es reclasificación, apunta al ajuste vinculado (salida<->entrada)');

            // Movimiento numérico
            $table->decimal('huevos_antes', 12, 2)
                ->comment('Saldo del lote/bp ANTES de aplicar el ajuste');
            $table->decimal('huevos_despues', 12, 2)
                ->comment('Saldo del lote/bp DESPUÉS de aplicar el ajuste');
            $table->decimal('delta_huevos', 12, 2)
                ->comment('Diferencia: positivo = entra, negativo = sale');

            // Valoración contable
            $table->decimal('costo_unitario_aplicado', 12, 6)
                ->comment('Costo por huevo aplicado al movimiento (puede ser distinto del costo del lote origen)');
            $table->decimal('valor_contable_afectado', 12, 2)
                ->comment('Lempiras de impacto contable = abs(delta_huevos) * costo_unitario_aplicado');

            // Documentación
            $table->text('descripcion')
                ->comment('Justificación libre, obligatoria, ingresada por el solicitante');
            $table->string('evidencia_path')->nullable()
                ->comment('Ruta de la foto del conteo físico (opcional)');

            // Workflow de aprobación
            $table->string('estado', 25)->default('borrador')
                ->comment('Enum AjusteEstado: borrador | pendiente_aprobacion | aprobado | rechazado | aplicado');
            $table->boolean('requiere_aprobacion')->default(false);

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable();

            $table->foreignId('rechazado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rechazado_en')->nullable();
            $table->text('motivo_rechazo')->nullable();

            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aplicado_en')->nullable();

            // Auditoría
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Índices para queries reales
            $table->index(['bodega_id', 'producto_id', 'created_at'], 'idx_ajustes_bodega_prod_fecha');
            $table->index(['lote_id', 'created_at'], 'idx_ajustes_lote_fecha');
            $table->index(['estado', 'created_at'], 'idx_ajustes_estado_fecha');
            $table->index('ajuste_pareja_id', 'idx_ajustes_pareja');
            $table->index(['created_by', 'created_at'], 'idx_ajustes_creador_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_inventario');
    }
};
