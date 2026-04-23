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
        if (Schema::hasColumn('lotes', 'huevos_regalo_consumidos')) {
            return;
        }

        Schema::table('lotes', function (Blueprint $table) {
            $table->decimal('huevos_regalo_consumidos', 14, 2)
                ->default(0.00)
                ->after('huevos_regalo_acumulados')
                ->comment('Huevos de regalo usados en reempaques (no mermas)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('lotes', 'huevos_regalo_consumidos')) {
            return;
        }

        Schema::table('lotes', function (Blueprint $table) {
            $table->dropColumn('huevos_regalo_consumidos');
        });
    }
};
