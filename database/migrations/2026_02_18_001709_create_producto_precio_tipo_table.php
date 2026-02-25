<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('producto_precio_tipo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('tipo_cliente', ['mayorista', 'minorista', 'distribuidor', 'ruta']);
            $table->decimal('descuento_maximo', 12, 4)->default(0)->comment('Descuento máximo permitido en Lempiras');
            $table->decimal('precio_minimo_fijo', 12, 4)->nullable()->comment('Precio mínimo fijo (override directo, si es null se calcula dinámicamente)');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // Un producto solo puede tener una regla por tipo de cliente
            $table->unique(['producto_id', 'tipo_cliente'], 'producto_tipo_cliente_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_precio_tipo');
    }
};