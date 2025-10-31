<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Tabla: unidades
         */
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');              // Ej: pieza, carton_30, libra, litro
            $table->string('simbolo')->nullable(); // Ej: pz, ct30, lb, L
            $table->boolean('es_decimal')->default(false); // true si acepta decimales (libras, litros), false si no (piezas/cartones)

            // Control de usuarios
            $table->foreignId('user_id')->constrained('users');           // quien creó
            $table->foreignId('user_update')->nullable()->constrained('users'); // última actualización

            $table->timestamps();
        });

        /**
         * Tabla: unidad_conversions
         */
        Schema::create('unidad_conversions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('from_unidad_id')->constrained('unidades');
            $table->foreignId('to_unidad_id')->constrained('unidades');
            $table->decimal('factor', 12, 6); // Ej: 1 libra = 16 onzas -> factor = 16
            $table->integer('redondeo')->nullable(); // opcional: cantidad de decimales a redondear

            // Control de usuarios
            $table->foreignId('user_id')->constrained('users');           // quien creó
            $table->foreignId('user_update')->nullable()->constrained('users'); // última actualización

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidad_conversions');
        Schema::dropIfExists('unidades');
    }
};
