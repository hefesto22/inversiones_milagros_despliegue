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
        if (Schema::hasColumn('cliente_producto', 'descuento_maximo_override')) {
            return;
        }

        Schema::table('cliente_producto', function (Blueprint $table) {
            $table->decimal('descuento_maximo_override', 12, 4)
                ->nullable()
                ->after('cantidad_total_vendida')
                ->comment('Descuento máximo personalizado para este cliente (sobreescribe regla por tipo)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('cliente_producto', 'descuento_maximo_override')) {
            return;
        }

        Schema::table('cliente_producto', function (Blueprint $table) {
            $table->dropColumn('descuento_maximo_override');
        });
    }
};
