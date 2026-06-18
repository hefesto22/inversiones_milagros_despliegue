<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo geográfico de Honduras (18 departamentos / 298 municipios) y su
 * vínculo con clientes para geolocalización, rutas y reportes.
 *
 * Decisiones:
 *   - Catálogo normalizado (no strings sueltos) para integridad y reportes.
 *   - clientes.departamento_id / municipio_id son NULLABLE: los clientes
 *     existentes no tienen ubicación todavía. La obligatoriedad se aplica en
 *     el formulario (Filament), no a nivel de BD, para no romper filas viejas.
 *   - FK con índice (constrained crea el índice; municipios lleva además un
 *     índice compuesto para la consulta de cascada ordenada por nombre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departamentos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 2)->unique()->comment('Código oficial 01-18');
            $table->string('nombre', 100);
            $table->timestamps();
        });

        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamentos')->cascadeOnDelete();
            $table->string('codigo', 4)->nullable()->comment('Código oficial DDMM (departamento + municipio)');
            $table->string('nombre', 120);
            $table->timestamps();

            // Cascada: listar municipios de un departamento ordenados por nombre.
            $table->index(['departamento_id', 'nombre']);
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->foreignId('departamento_id')
                ->nullable()
                ->after('direccion')
                ->constrained('departamentos')
                ->nullOnDelete();

            $table->foreignId('municipio_id')
                ->nullable()
                ->after('departamento_id')
                ->constrained('municipios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropForeign(['municipio_id']);
            $table->dropForeign(['departamento_id']);
            $table->dropColumn(['municipio_id', 'departamento_id']);
        });

        Schema::dropIfExists('municipios');
        Schema::dropIfExists('departamentos');
    }
};
