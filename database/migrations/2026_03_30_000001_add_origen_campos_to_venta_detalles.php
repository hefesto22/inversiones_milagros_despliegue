<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->decimal('cantidad_de_bodega', 10, 3)->default(0)->after('costo_unitario')
                ->comment('Unidades tomadas de bodega_producto');
            $table->decimal('cantidad_de_lote', 10, 3)->default(0)->after('cantidad_de_bodega')
                ->comment('Unidades tomadas de lote via reempaque automatico');
            $table->unsignedBigInteger('reempaque_id')->nullable()->after('cantidad_de_lote')
                ->comment('Reempaque creado para unidades de lote');
            $table->decimal('costo_bodega_original', 10, 4)->nullable()->after('reempaque_id')
                ->comment('Costo promedio de bodega al momento de la venta');
            $table->decimal('costo_unitario_lote', 10, 4)->nullable()->after('costo_bodega_original')
                ->comment('Costo unitario del reempaque');

            $table->foreign('reempaque_id')->references('id')->on('reempaques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropForeign(['reempaque_id']);
            $table->dropColumn([
                'cantidad_de_bodega',
                'cantidad_de_lote',
                'reempaque_id',
                'costo_bodega_original',
                'costo_unitario_lote',
            ]);
        });
    }
};
