<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug #12: viaje_cargas.costo_unitario estaba declarado como decimal(12,2) en la migración
 * original (2025_11_12_191444), pero el modelo ViajeCarga lo castea como decimal:4.
 * Esta inconsistencia causa que MySQL trunque silenciosamente los decimales 3 y 4 al
 * persistir, perdiendo precisión en el costo histórico del viaje.
 *
 * Mismo problema aplica a viaje_venta_detalles.costo_unitario y viaje_mermas.costo_unitario.
 */
return new class extends Migration
{
    public function up(): void
    {
        // viaje_cargas: costo al momento de cargar el producto al camión
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 4)->comment('Costo al momento de cargar')->change();
            $table->decimal('costo_bodega_original', 12, 4)->nullable()->comment('Costo original de bodega antes de cargar')->change();
        });

        // viaje_venta_detalles: costo del producto al momento de la venta en ruta
        Schema::table('viaje_venta_detalles', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 4)->default(0)->comment('Costo del producto')->change();
        });

        // viaje_mermas: costo del producto perdido/dañado
        Schema::table('viaje_mermas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 4)->change();
        });

        // viaje_descargas: costo del producto que regresa a bodega
        Schema::table('viaje_descargas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 4)->comment('Costo del producto que regresa')->change();
        });
    }

    public function down(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 2)->comment('Costo al momento de cargar')->change();
            $table->decimal('costo_bodega_original', 12, 2)->nullable()->comment('Costo original de bodega antes de cargar')->change();
        });

        Schema::table('viaje_venta_detalles', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 2)->default(0)->comment('Costo del producto')->change();
        });

        Schema::table('viaje_mermas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 2)->change();
        });

        Schema::table('viaje_descargas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 2)->comment('Costo del producto que regresa')->change();
        });
    }
};
