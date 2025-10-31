<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Tabla: viaje_ventas
         * Ventas realizadas por el chofer durante el viaje (ruta)
         */
        Schema::create('viaje_ventas', function (Blueprint $table) {
            $table->id();

            // Relación con viaje
            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Cliente
            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Datos de la venta
            $table->dateTime('fecha_venta')->default(now());

            // Condiciones de pago
            $table->enum('tipo_pago', ['contado', 'credito'])->default('contado');
            $table->integer('plazo_dias')->nullable()
                ->comment('Días de crédito');

            // Montos
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('impuesto', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('saldo_pendiente', 12, 2)->default(0)
                ->comment('Saldo pendiente de pago (para crédito)');

            // Estado de la venta
            $table->enum('estado', ['borrador', 'confirmada', 'cancelada'])
                ->default('borrador');

            // Datos adicionales
            $table->string('numero_factura')->nullable();
            $table->text('nota')->nullable();

            // Auditoría
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que registró la venta (normalmente el chofer)');

            $table->foreignId('confirmada_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que confirmó la venta');

            $table->dateTime('confirmada_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['viaje_id', 'fecha_venta'], 'ix_viaje_fecha');
            $table->index(['cliente_id', 'fecha_venta'], 'ix_cliente_fecha');
            $table->index(['estado', 'fecha_venta'], 'ix_estado_fecha');
            $table->index('fecha_venta');
        });

        /**
         * Tabla: viaje_venta_detalles
         * Líneas de detalle de cada venta de ruta
         */
        Schema::create('viaje_venta_detalles', function (Blueprint $table) {
            $table->id();

            // Relación con venta de viaje
            $table->foreignId('viaje_venta_id')
                ->constrained('viaje_ventas')
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
                ->comment('Unidad en la que se vendió (cartón 30, cartón 15, pieza, etc.)');

            // Cantidades
            $table->decimal('cantidad_presentacion', 12, 3)
                ->comment('Cantidad en la presentación vendida');

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
            $table->index(['viaje_venta_id', 'producto_id'], 'ix_venta_producto');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        // Eliminar en orden inverso por las foreign keys
        Schema::dropIfExists('viaje_venta_detalles');
        Schema::dropIfExists('viaje_ventas');
    }
};
