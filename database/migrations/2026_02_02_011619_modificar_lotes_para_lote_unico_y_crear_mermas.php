<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // MODIFICAR TABLA LOTES - Agregar campos para lote único
        // =====================================================
        Schema::table('lotes', function (Blueprint $table) {
            // Hacer nullable los campos que ya no aplican para lote único
            $table->foreignId('compra_id')->nullable()->change();
            $table->foreignId('compra_detalle_id')->nullable()->change();
            $table->foreignId('proveedor_id')->nullable()->change();

            // NUEVOS CAMPOS PARA LOTE ÚNICO CON COSTO PROMEDIO
            $table->decimal('huevos_facturados_acumulados', 14, 2)
                ->default(0)
                ->after('cantidad_huevos_remanente')
                ->comment('Total histórico de huevos facturados (pagados)');

            $table->decimal('huevos_regalo_acumulados', 14, 2)
                ->default(0)
                ->after('huevos_facturados_acumulados')
                ->comment('Total histórico de huevos regalados (buffer para mermas)');

            $table->decimal('merma_total_acumulada', 14, 2)
                ->default(0)
                ->after('huevos_regalo_acumulados')
                ->comment('Total de mermas registradas en este lote');

            $table->decimal('costo_total_acumulado', 14, 2)
                ->default(0)
                ->after('merma_total_acumulada')
                ->comment('Suma total de lo pagado por todas las compras');

            // Índice para búsqueda rápida de lote único
            $table->unique(['producto_id', 'bodega_id', 'estado'], 'lote_unico_producto_bodega');
        });

        // =====================================================
        // CREAR TABLA MERMAS - Registro de mermas directas
        // =====================================================
        Schema::create('mermas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lote_id')
                ->constrained('lotes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('numero_merma', 50)
                ->unique()
                ->comment('Formato: M-B{bodega_id}-{secuencial}');

            // Cantidades
            $table->decimal('cantidad_huevos', 14, 2)
                ->comment('Huevos perdidos en esta merma');

            $table->decimal('cubierto_por_regalo', 14, 2)
                ->default(0)
                ->comment('Huevos cubiertos por el buffer de regalos');

            $table->decimal('perdida_real_huevos', 14, 2)
                ->default(0)
                ->comment('Huevos que representan pérdida económica (exceden el buffer)');

            $table->decimal('perdida_real_lempiras', 14, 2)
                ->default(0)
                ->comment('Pérdida en lempiras (perdida_real_huevos × costo_por_huevo)');

            // Motivo
            $table->enum('motivo', [
                'rotos',
                'podridos',
                'vencidos',
                'dañados_transporte',
                'otros'
            ])->default('rotos');

            $table->text('descripcion')->nullable();

            // Estado del buffer después de esta merma
            $table->decimal('buffer_antes', 14, 2)
                ->comment('Buffer de regalos antes de esta merma');

            $table->decimal('buffer_despues', 14, 2)
                ->comment('Buffer de regalos después de esta merma');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['lote_id', 'created_at']);
            $table->index(['bodega_id', 'created_at']);
            $table->index('numero_merma');
        });

        // =====================================================
        // CREAR TABLA HISTORIAL_COMPRAS_LOTE - Trazabilidad
        // =====================================================
        Schema::create('historial_compras_lote', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lote_id')
                ->constrained('lotes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('compra_id')
                ->constrained('compras')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('compra_detalle_id')
                ->constrained('compra_detalles')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Cantidades de esta compra específica
            $table->decimal('cartones_facturados', 14, 2);
            $table->decimal('cartones_regalo', 14, 2)->default(0);
            $table->decimal('huevos_agregados', 14, 2);
            $table->decimal('costo_compra', 14, 2);
            $table->decimal('costo_por_huevo_compra', 14, 4);

            // Estado del lote DESPUÉS de esta compra
            $table->decimal('costo_promedio_resultante', 14, 4)
                ->comment('Costo por huevo del lote después de agregar esta compra');

            $table->decimal('huevos_totales_resultante', 14, 2)
                ->comment('Total de huevos en el lote después de esta compra');

            $table->timestamps();

            $table->index(['lote_id', 'created_at']);
            $table->index('compra_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_compras_lote');
        Schema::dropIfExists('mermas');

        Schema::table('lotes', function (Blueprint $table) {
            $table->dropUnique('lote_unico_producto_bodega');
            $table->dropColumn([
                'huevos_facturados_acumulados',
                'huevos_regalo_acumulados',
                'merma_total_acumulada',
                'costo_total_acumulado',
            ]);
        });
    }
};