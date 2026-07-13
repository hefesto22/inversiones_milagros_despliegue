<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columna `precio_autorizado` a `cliente_producto`.
 *
 * Esta columna almacena el precio EXACTO autorizado por Admin para que un
 * cliente específico (típicamente "Consumidor Final" con rtn = CF-0000000000000)
 * pueda comprar un producto. Cuando está NULL, se usa el precio default del
 * producto (precio_venta_maximo).
 *
 * Objetivo de negocio: cerrar el hueco de control interno donde vendedores
 * de ruta cobraban el precio normal al consumidor final pero reportaban un
 * precio menor "con descuento", quedándose con la diferencia.
 *
 * NO migra datos — todas las filas existentes quedan en NULL hasta que Admin
 * configure las excepciones específicas por UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cliente_producto', 'precio_autorizado')) {
            return;
        }

        Schema::table('cliente_producto', function (Blueprint $table) {
            $table->decimal('precio_autorizado', 12, 4)
                ->nullable()
                ->after('descuento_maximo_override')
                ->comment('Precio EXACTO autorizado para cliente+producto. Bloquea edición del precio en venta (usado para Consumidor Final).');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cliente_producto', 'precio_autorizado')) {
            return;
        }

        Schema::table('cliente_producto', function (Blueprint $table) {
            $table->dropColumn('precio_autorizado');
        });
    }
};
