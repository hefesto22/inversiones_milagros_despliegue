<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->foreignId('reempaque_id')
                ->nullable()
                ->after('viaje_id')
                ->constrained('reempaques')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('viaje_cargas', function (Blueprint $table) {
            $table->dropForeign(['reempaque_id']);
            $table->dropColumn('reempaque_id');
        });
    }
};