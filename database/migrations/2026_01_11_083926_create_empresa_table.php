<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de la empresa para facturación
     */
    public function up(): void
    {
        Schema::create('empresa', function (Blueprint $table) {
            $table->id();
            
            // Datos generales
            $table->string('nombre', 150);
            $table->string('logo')->nullable();
            $table->string('rtn', 20)->nullable();
            $table->string('cai', 50)->nullable()
                ->comment('Código de Autorización de Impresión');
            
            // Contacto
            $table->string('telefono', 20)->nullable();
            $table->string('correo_electronico', 100)->nullable();
            $table->text('direccion')->nullable();
            
            // Descripción
            $table->string('lema', 255)->nullable()
                ->comment('Lema o descripción de la empresa');
            
            // Rango de facturación
            $table->string('rango_desde', 25)->nullable()
                ->comment('Ej: 000-001-01-00001601');
            $table->string('rango_hasta', 25)->nullable()
                ->comment('Ej: 000-001-01-00001750');
            $table->string('ultimo_numero_emitido', 25)->nullable()
                ->comment('Ej: 000-001-01-00001708');
            
            $table->date('fecha_limite_emision')->nullable()
                ->comment('Fecha límite para emitir facturas con este CAI');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa');
    }
};