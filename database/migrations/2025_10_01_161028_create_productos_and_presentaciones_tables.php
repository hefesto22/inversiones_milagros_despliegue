<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * productos
         */
        Schema::create('productos', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');
            $table->enum('tipo', ['huevo', 'lacteo', 'abarroteria']);
            $table->foreignId('unidad_base_id')->constrained('unidades'); // unidad principal
            $table->string('sku')->nullable();
            $table->boolean('activo')->default(true);

            // auditoría
            $table->foreignId('user_id')->nullable()->constrained('users');        // quien creó
            $table->foreignId('user_update')->nullable()->constrained('users');    // última actualización

            $table->timestamps();

            $table->index(['tipo', 'activo']);
        });

        /**
         * producto_presentaciones
         */
        Schema::create('producto_presentaciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades'); // Ej: pieza, litro, etc.
            $table->decimal('factor_a_base', 12, 6); // relación contra unidad_base
            $table->decimal('precio_referencia', 12, 2)->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['producto_id', 'unidad_id']); // evitar duplicados
        });

        /**
         * bodega_producto
         * Relación entre bodegas y productos
         */
        Schema::create('bodega_producto', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();

            $table->decimal('stock', 12, 3)->default(0);
            $table->decimal('stock_min', 12, 3)->nullable();
            $table->decimal('precio_base', 12, 2)->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['bodega_id', 'producto_id']); // un producto una sola vez por bodega
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bodega_producto');
        Schema::dropIfExists('producto_presentaciones');
        Schema::dropIfExists('productos');
    }
};
