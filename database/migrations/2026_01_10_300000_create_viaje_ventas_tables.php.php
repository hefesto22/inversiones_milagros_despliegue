<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MIGRACIÓN: VENTAS EN RUTA (Punto de Venta del Chofer)
     * 
     * Tablas para registrar las ventas realizadas durante un viaje
     */
    public function up(): void
    {
        // =====================================================
        // 🛒 VENTAS EN VIAJE (Ventas del chofer en ruta)
        // =====================================================
        Schema::create('viaje_ventas', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('viaje_id');

            $table->foreignId('cliente_id')
                ->nullable()
                ->constrained('clientes')
                ->nullOnDelete();

            $table->string('numero_venta', 50)->unique()
                ->comment('Código único ej: VR-2-0001');

            $table->dateTime('fecha_venta');

            $table->enum('tipo_pago', ['contado', 'credito'])->default('contado');
            $table->integer('plazo_dias')->default(0)
                ->comment('Días de plazo si es crédito');

            // Totales
            $table->decimal('subtotal', 14, 2)->default(0)
                ->comment('Subtotal sin ISV');
            $table->decimal('impuesto', 14, 2)->default(0)
                ->comment('Total ISV');
            $table->decimal('descuento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0)
                ->comment('Total final (subtotal + impuesto - descuento)');

            $table->decimal('saldo_pendiente', 14, 2)->default(0)
                ->comment('Saldo pendiente si es crédito');

            // Estado
            $table->enum('estado', ['borrador', 'confirmada', 'completada', 'cancelada'])
                ->default('borrador');

            $table->string('numero_factura', 50)->nullable();
            $table->text('nota')->nullable();

            // Auditoría
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que creó la venta');

            $table->foreignId('confirmada_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('confirmada_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Índices
            $table->index(['viaje_id', 'estado']);
            $table->index(['cliente_id', 'fecha_venta']);
            $table->index('fecha_venta');
            $table->index('tipo_pago');
        });

        // =====================================================
        // 📦 DETALLE DE VENTAS EN VIAJE
        // =====================================================
        Schema::create('viaje_venta_detalles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_venta_id')
                ->constrained('viaje_ventas')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('viaje_carga_id')->nullable()
                ->comment('Referencia a la carga del viaje');

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->decimal('cantidad', 12, 3);

            $table->decimal('precio_base', 12, 2)
                ->comment('Precio sin ISV');

            $table->decimal('precio_con_isv', 12, 2)
                ->comment('Precio con ISV (lo que paga el cliente)');

            $table->decimal('monto_isv', 12, 2)->default(0)
                ->comment('Monto del ISV por unidad');

            $table->decimal('costo_unitario', 12, 2)->default(0)
                ->comment('Costo del producto');

            $table->boolean('aplica_isv')->default(false);

            // Totales de línea
            $table->decimal('subtotal', 14, 2)
                ->comment('cantidad * precio_base');

            $table->decimal('total_isv', 14, 2)->default(0)
                ->comment('cantidad * monto_isv');

            $table->decimal('total_linea', 14, 2)
                ->comment('cantidad * precio_con_isv');

            $table->timestamps();

            // Índices
            $table->index('viaje_venta_id');
            $table->index('producto_id');
            $table->index('viaje_carga_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viaje_venta_detalles');
        Schema::dropIfExists('viaje_ventas');
    }
};