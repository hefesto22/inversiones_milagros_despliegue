<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bodega_gastos', 'categoria_contable')) {
            return;
        }

        Schema::table('bodega_gastos', function (Blueprint $table) {
            $table->enum('categoria_contable', ['gasto_venta', 'gasto_admin', 'inversion'])
                ->default('gasto_venta')
                ->after('tipo_gasto');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('bodega_gastos', 'categoria_contable')) {
            return;
        }

        Schema::table('bodega_gastos', function (Blueprint $table) {
            $table->dropColumn('categoria_contable');
        });
    }
};
