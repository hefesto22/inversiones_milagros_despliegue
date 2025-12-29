<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // PROVEEDORES
        // =====================================================
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');
            $table->string('rtn', 30)->nullable()->unique();
            $table->string('telefono', 30)->nullable();
            $table->text('direccion')->nullable();
            $table->string('email', 100)->nullable();

            $table->boolean('estado')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'nombre']);
        });

        // =====================================================
        // PROVEEDOR - PRODUCTO (Último precio)
        // =====================================================
        Schema::create('proveedor_producto', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->decimal('ultimo_precio_compra', 12, 4)->nullable();
            $table->timestamp('actualizado_en')->nullable();

            $table->unique(['proveedor_id', 'producto_id']);
        });

        // =====================================================
        // COMPRAS
        // =====================================================
        Schema::create('compras', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('numero_compra', 50)->unique()->nullable();

            $table->enum('tipo_pago', ['contado', 'credito'])
                ->index();

            $table->decimal('interes_porcentaje', 8, 2)->nullable()->comment('Porcentaje de interés por periodo (ej: 5%)');
            $table->enum('periodo_interes', ['semanal', 'mensual'])->nullable()->comment('Periodo de cobro del interés');
            $table->date('fecha_inicio_credito')->nullable()->comment('Fecha desde que empezó a correr el crédito');

            $table->enum('estado', [
                'borrador',
                'ordenada',
                'recibida_pagada',
                'recibida_pendiente_pago',
                'por_recibir_pagada',
                'por_recibir_pendiente_pago',
                'cancelada',
            ])->default('borrador')->index();

            $table->text('nota')->nullable()->comment('Notas sobre cambios de estado o información adicional');

            $table->decimal('total', 14, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('bodega_id');
            $table->index('numero_compra');
            $table->index(['tipo_pago', 'estado']);
        });

        // =====================================================
        // DETALLES DE COMPRA
        // =====================================================
        Schema::create('compra_detalles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('compra_id')
                ->constrained('compras')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            // CANTIDADES CON REGALOS
            $table->decimal('cantidad_facturada', 12, 2)->comment('Cantidad que aparece en factura (pagada)');
            $table->decimal('cantidad_regalo', 12, 2)->default(0)->comment('Cantidad regalada por proveedor (por merma)');
            $table->decimal('cantidad_recibida', 12, 2)->comment('Total físico recibido (facturada + regalo)');

            $table->decimal('precio_unitario', 12, 4)->comment('Precio por unidad (puede incluir ISV si la categoría aplica)');

            // 🆕 CAMPOS DE ISV (para categorías con aplica_isv = true)
            $table->decimal('precio_con_isv', 12, 4)->nullable()->comment('Precio unitario con ISV incluido (lo que dice la factura)');
            $table->decimal('costo_sin_isv', 12, 4)->nullable()->comment('Costo real sin ISV (precio_con_isv / 1.15)');
            $table->decimal('isv_credito', 12, 4)->nullable()->comment('ISV crédito fiscal por unidad (precio_con_isv - costo_sin_isv)');

            $table->decimal('descuento', 12, 2)->default(0)->comment('Descuento en monto');
            $table->decimal('impuesto', 12, 2)->default(0)->comment('Impuesto en monto (diferente al ISV de compra)');

            $table->decimal('subtotal', 14, 2)->default(0)->comment('cantidad_facturada * precio_unitario - descuento + impuesto');

            $table->timestamps();

            $table->index(['compra_id', 'producto_id']);
        });

        // =====================================================
        // 🆕 REEMPAQUES (ANTES de lotes por dependencia)
        // =====================================================
        Schema::create('reempaques', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->string('numero_reempaque', 50)->unique()->comment('Formato: R-B{bodega_id}-{secuencial}');

            $table->enum('tipo', ['individual', 'mezclado'])->comment('Individual: 1 lote | Mezclado: múltiples lotes');

            // Cantidades (2 decimales)
            $table->decimal('total_huevos_usados', 14, 2)->comment('Total de huevos tomados de los lotes');
            $table->decimal('merma', 14, 2)->default(0)->comment('Huevos rotos/perdidos durante reempaque');
            $table->decimal('huevos_utiles', 14, 2)->comment('Huevos buenos después de merma (usados - merma)');

            // Costos (2 decimales, redondeado hacia arriba)
            $table->decimal('costo_total', 12, 2)->comment('Suma de costos de todos los lotes usados');
            $table->decimal('costo_unitario_promedio', 12, 2)->comment('Costo por huevo (2 decimales, redondeado hacia arriba, incluye merma)');

            // Resultado del empaque
            $table->integer('cartones_30')->default(0)->comment('Cartones de 30 huevos generados');
            $table->integer('cartones_15')->default(0)->comment('Cartones de 15 huevos generados');
            $table->integer('huevos_sueltos')->default(0)->comment('Huevos sueltos → se convierten en lote LS-*');

            // Estado del reempaque
            $table->enum('estado', ['en_proceso', 'completado', 'cancelado'])->default('en_proceso')->index();

            // Notas
            $table->text('nota')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['bodega_id', 'created_at']);
            $table->index(['bodega_id', 'estado']);
            $table->index('tipo');
        });

        // =====================================================
        // 🆕 LOTES (DESPUÉS de reempaques)
        // =====================================================
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();

            // NULLABLE para lotes de sueltos generados por reempaques
            $table->foreignId('compra_id')
                ->nullable()
                ->constrained('compras')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('NULL si es un lote de sueltos generado por reempaque (LS-*)');

            $table->foreignId('compra_detalle_id')
                ->nullable()
                ->constrained('compra_detalles')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('NULL si es un lote de sueltos generado por reempaque (LS-*)');

            // 🆕 TRAZABILIDAD: Reempaque que generó este lote de sueltos
            $table->foreignId('reempaque_origen_id')
                ->nullable()
                ->constrained('reempaques')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Reempaque que generó este lote LS-* (NULL para lotes normales L-*)');

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete()
                ->comment('Producto de entrada/compra (ej: Huevo Grande Cartón)');

            // Proveedor se hereda del lote original en caso de sueltos
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->restrictOnDelete()
                ->comment('Para lotes LS-*: heredado del lote original usado en reempaque');

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->restrictOnDelete();

            $table->string('numero_lote', 50)->unique()->comment('L-B{id}-{sec} para normales | LS-B{id}-{sec} para sueltos de reempaque');

            // CANTIDADES CON REGALOS (2 decimales)
            $table->decimal('cantidad_cartones_facturados', 14, 2)->default(0)->comment('Cartones pagados según factura (0 para lotes LS-*)');
            $table->decimal('cantidad_cartones_regalo', 14, 2)->default(0)->comment('Cartones regalados por proveedor (0 para lotes LS-*)');
            $table->decimal('cantidad_cartones_recibidos', 14, 2)->default(0)->comment('Total físico recibido (0 para lotes LS-*)');

            $table->integer('huevos_por_carton')->default(30)->comment('Huevos por cartón (30 para huevos grandes/normales)');
            $table->decimal('cantidad_huevos_original', 14, 2)->comment('Cantidad inicial en huevos');
            $table->decimal('cantidad_huevos_remanente', 14, 2)->comment('Huevos disponibles sin reempacar');

            // COSTOS
            $table->decimal('costo_total_lote', 12, 2)->comment('Total pagado por este lote (de la factura) o costo calculado para LS-*');
            $table->decimal('costo_por_carton_facturado', 12, 4)->default(0)->comment('Costo según factura (0 para lotes LS-*)');
            $table->decimal('costo_por_huevo', 12, 2)->comment('Costo por huevo (2 decimales, redondeado hacia arriba)');

            // Estado
            $table->enum('estado', ['disponible', 'agotado'])->default('disponible')->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['bodega_id', 'producto_id', 'estado']);
            $table->index(['proveedor_id', 'estado']);
            $table->index('numero_lote');
            $table->index('reempaque_origen_id');
        });

        // =====================================================
        // 🆕 REEMPAQUE_LOTES (Pivot: Lotes usados en cada reempaque)
        // =====================================================
        Schema::create('reempaque_lotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reempaque_id')
                ->constrained('reempaques')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('lote_id')
                ->constrained('lotes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Cantidades usadas de este lote específico (2 decimales)
            $table->decimal('cantidad_cartones_usados', 14, 2)->comment('Cartones usados de este lote');
            $table->decimal('cantidad_huevos_usados', 14, 2)->comment('Huevos usados de este lote');

            // Trazabilidad de regalos
            $table->decimal('cartones_facturados_usados', 14, 2)->comment('De los cartones facturados');
            $table->decimal('cartones_regalo_usados', 14, 2)->default(0)->comment('De los cartones regalados');

            // Costo de esta porción
            $table->decimal('costo_parcial', 12, 2)->comment('Costo total de los huevos de este lote');

            $table->timestamps();

            $table->unique(['reempaque_id', 'lote_id']);
            $table->index('lote_id');
        });

        // =====================================================
        // 🆕 REEMPAQUE_PRODUCTOS (Productos generados por reempaque)
        // =====================================================
        Schema::create('reempaque_productos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reempaque_id')
                ->constrained('reempaques')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete()
                ->comment('Producto final generado (ej: Cartón 30, Cartón 15)');

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->restrictOnDelete();

            // Cantidades (2 decimales)
            $table->decimal('cantidad', 14, 2)->comment('Cantidad de este producto generado');

            // COSTOS (2 decimales, redondeado hacia arriba)
            $table->decimal('costo_unitario', 12, 2)->comment('Costo por unidad (2 decimales, incluye regalos + merma)');
            $table->decimal('costo_total', 12, 2)->comment('cantidad × costo_unitario');

            // Control de agregado al stock
            $table->boolean('agregado_a_stock')->default(false)->comment('Si ya se agregó a bodega_producto');
            $table->timestamp('fecha_agregado_stock')->nullable();

            $table->timestamps();

            $table->index(['reempaque_id', 'producto_id']);
            $table->index(['bodega_id', 'producto_id']);
            $table->index('agregado_a_stock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reempaque_productos');
        Schema::dropIfExists('reempaque_lotes');
        Schema::dropIfExists('lotes');
        Schema::dropIfExists('reempaques');
        Schema::dropIfExists('compra_detalles');
        Schema::dropIfExists('compras');
        Schema::dropIfExists('proveedor_producto');
        Schema::dropIfExists('proveedores');
    }
};
