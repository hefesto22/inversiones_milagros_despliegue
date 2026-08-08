<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zona de ruta del viaje: qué departamentos y municipios cubre.
 *
 * Sirve para que el vendedor en Punto de Venta en Ruta solo vea los clientes
 * de la zona por la que va a pasar, en lugar de la cartera completa.
 *
 * Decisiones:
 *   - Dos pivotes en vez de una tabla polimórfica: son dos catálogos distintos
 *     con semántica distinta (departamento = área completa, municipio = recorte
 *     dentro de esa área). Ver Cliente::scopeEnZonaDe() para la regla exacta.
 *   - Prefijo viaje_* siguiendo la familia ya existente (viaje_cargas,
 *     viaje_ventas, viaje_descargas, viaje_mermas).
 *   - Sin zona definida = el viaje ve TODOS los clientes. Así los viajes
 *     históricos y los que ya están en ruta no cambian de comportamiento.
 *   - cascadeOnDelete en viaje_id: si se borra un viaje, su zona se va con él.
 *   - unique compuesto: un departamento/municipio no puede repetirse en el
 *     mismo viaje (protege contra doble submit del formulario).
 *   - withTimestamps() en las relaciones: queda registro de cuándo se definió
 *     o amplió la zona de un viaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viaje_departamento', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->foreignId('departamento_id')
                ->constrained('departamentos')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['viaje_id', 'departamento_id'], 'viaje_departamento_unico');
        });

        Schema::create('viaje_municipio', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->foreignId('municipio_id')
                ->constrained('municipios')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['viaje_id', 'municipio_id'], 'viaje_municipio_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viaje_municipio');
        Schema::dropIfExists('viaje_departamento');
    }
};
