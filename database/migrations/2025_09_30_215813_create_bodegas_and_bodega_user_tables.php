<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 1) Tabla: bodegas
         */
        Schema::create('bodegas', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');                 // Ej: Bodega Central, Sucursal 1
            $table->string('ubicacion')->nullable();  // Dirección o referencia
            $table->boolean('activo')->default(true);

            // Control de usuarios (quién creó / quién actualizó por última vez)
            $table->foreignId('user_id')
                ->constrained('users');               // no se borra en cascada para mantener trazabilidad
            $table->foreignId('user_update')
                ->nullable()
                ->constrained('users');

            $table->timestamps();

            $table->index(['activo', 'nombre']);      // búsquedas comunes
        });

        /**
         * 2) PIVOT: bodega_user (Many-to-Many users <-> bodegas)
         *    - Un usuario puede estar en varias bodegas
         *    - Una bodega puede tener varios usuarios
         */
        Schema::create('bodega_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnDelete();                  // si se elimina la bodega, se limpian sus filas pivot
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();                  // si se elimina el usuario, se limpian sus filas pivot

            $table->timestamps();

            $table->unique(['bodega_id', 'user_id']); // evita duplicados
            $table->index(['user_id', 'bodega_id']);  // consultas rápidas por cualquiera de las dos
        });
    }

    public function down(): void
    {
        // Orden inverso: primero pivot, luego la principal
        Schema::dropIfExists('bodega_user');
        Schema::dropIfExists('bodegas');
    }
};
