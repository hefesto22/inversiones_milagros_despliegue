<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function fkExiste(string $tabla, string $fkName): bool
    {
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$tabla, $fkName, 'FOREIGN KEY']
        );

        return !empty($rows);
    }

    public function up(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('venta_detalles', 'cantidad_de_bodega')) {
                $table->decimal('cantidad_de_bodega', 10, 3)->default(0)->after('costo_unitario')
                    ->comment('Unidades tomadas de bodega_producto');
            }
            if (!Schema::hasColumn('venta_detalles', 'cantidad_de_lote')) {
                $table->decimal('cantidad_de_lote', 10, 3)->default(0)->after('cantidad_de_bodega')
                    ->comment('Unidades tomadas de lote via reempaque automatico');
            }
            if (!Schema::hasColumn('venta_detalles', 'reempaque_id')) {
                $table->unsignedBigInteger('reempaque_id')->nullable()->after('cantidad_de_lote')
                    ->comment('Reempaque creado para unidades de lote');
            }
            if (!Schema::hasColumn('venta_detalles', 'costo_bodega_original')) {
                $table->decimal('costo_bodega_original', 10, 4)->nullable()->after('reempaque_id')
                    ->comment('Costo promedio de bodega al momento de la venta');
            }
            if (!Schema::hasColumn('venta_detalles', 'costo_unitario_lote')) {
                $table->decimal('costo_unitario_lote', 10, 4)->nullable()->after('costo_bodega_original')
                    ->comment('Costo unitario del reempaque');
            }
        });

        if (Schema::hasColumn('venta_detalles', 'reempaque_id')
            && !$this->fkExiste('venta_detalles', 'venta_detalles_reempaque_id_foreign')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->foreign('reempaque_id')->references('id')->on('reempaques')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->fkExiste('venta_detalles', 'venta_detalles_reempaque_id_foreign')) {
            Schema::table('venta_detalles', function (Blueprint $table) {
                $table->dropForeign(['reempaque_id']);
            });
        }

        Schema::table('venta_detalles', function (Blueprint $table) {
            $columnas = array_filter(
                [
                    'cantidad_de_bodega',
                    'cantidad_de_lote',
                    'reempaque_id',
                    'costo_bodega_original',
                    'costo_unitario_lote',
                ],
                fn ($col) => Schema::hasColumn('venta_detalles', $col)
            );

            if (!empty($columnas)) {
                $table->dropColumn($columnas);
            }
        });
    }
};
