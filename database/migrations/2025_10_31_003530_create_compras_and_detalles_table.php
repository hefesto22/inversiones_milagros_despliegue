<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Tabla: compras
         * Registro de compras a proveedores que generan entrada a bodega
         */
        Schema::create('compras', function (Blueprint $table) {
            $table->id();

            // Relaciones principales
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Datos de la compra
            $table->dateTime('fecha')->default(now());

            // Montos
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('impuesto', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Estado de la compra
            $table->enum('estado', ['borrador', 'confirmada', 'cancelada'])
                ->default('borrador');

            // Datos adicionales opcionales
            $table->string('numero_factura')->nullable();
            $table->text('nota')->nullable();

            // Auditoría
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que registró la compra');

            $table->foreignId('confirmada_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que confirmó la compra');

            $table->dateTime('confirmada_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['proveedor_id', 'fecha'], 'ix_proveedor_fecha');
            $table->index(['bodega_id', 'fecha'], 'ix_bodega_fecha');
            $table->index(['estado', 'fecha'], 'ix_estado_fecha');
            $table->index('fecha');
        });

        /**
         * Tabla: compra_detalles
         * Líneas de detalle de cada compra
         */
        Schema::create('compra_detalles', function (Blueprint $table) {
            $table->id();

            // Relación con compra
            $table->foreignId('compra_id')
                ->constrained('compras')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Producto y presentación
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('unidad_id_presentacion')
                ->constrained('unidades')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Unidad en la que se compró (cartón, pieza, etc.)');

            // Cantidades
            $table->decimal('cantidad_presentacion', 12, 3)
                ->comment('Cantidad en la presentación comprada');

            $table->decimal('factor_a_base', 12, 6)
                ->comment('Factor de conversión a unidad base');

            $table->decimal('cantidad_base', 14, 3)
                ->comment('Cantidad convertida a unidad base del producto');

            // Precios
            $table->decimal('precio_unitario_presentacion', 12, 4)
                ->comment('Precio por unidad de presentación');

            $table->decimal('descuento', 12, 4)
                ->nullable()
                ->default(0)
                ->comment('Descuento aplicado');

            $table->decimal('total_linea', 12, 2)
                ->comment('Total de esta línea (cantidad * precio - descuento)');

            $table->timestamps();

            // Índices
            $table->index(['compra_id', 'producto_id'], 'ix_compra_producto');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        // Eliminar en orden inverso por las foreign keys
        Schema::dropIfExists('compra_detalles');
        Schema::dropIfExists('compras');
    }
};
