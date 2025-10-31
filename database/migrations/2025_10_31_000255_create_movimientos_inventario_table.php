<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();

            // Relaciones principales
            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Tipo de movimiento
            $table->enum('tipo', ['entrada', 'salida', 'merma', 'ajuste']);

            // Cantidad en unidad base (pieza, mL, lb)
            $table->decimal('cantidad_base', 14, 3);

            // Referencia al documento/evento que originó el movimiento
            $table->string('referencia_tipo')->nullable()
                ->comment('compra, venta, viaje_carga, viaje_merma, ajuste, traspaso, devolucion, etc.');
            $table->unsignedBigInteger('referencia_id')->nullable()
                ->comment('ID del documento origen (compra_id, venta_id, viaje_id, etc.)');

            // Información adicional
            $table->text('nota')->nullable();
            $table->dateTime('fecha')->default(now());

            // Auditoría
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que registró el movimiento');

            $table->timestamps();

            // Índices para optimizar consultas
            $table->index(['bodega_id', 'producto_id', 'fecha'], 'ix_bodega_producto_fecha');
            $table->index(['referencia_tipo', 'referencia_id'], 'ix_referencia');
            $table->index(['tipo', 'fecha'], 'ix_tipo_fecha');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
