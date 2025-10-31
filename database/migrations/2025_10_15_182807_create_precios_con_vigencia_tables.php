<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Tabla: proveedor_precios
         * Precio pactado de COMPRA por proveedor y presentación (unidad)
         */
        Schema::create('proveedor_precios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('precio_compra', 12, 4);

            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // índices y unicidad
            $table->index(['proveedor_id', 'producto_id', 'unidad_id', 'vigente_desde'], 'ix_prov_precio_fecha');
        });

        /**
         * Tabla: cliente_precios
         * Precio pactado de VENTA por cliente y presentación (unidad)
         */
        Schema::create('cliente_precios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->decimal('precio_venta', 12, 4);

            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['cliente_id', 'producto_id', 'unidad_id', 'vigente_desde'], 'ix_cli_precio_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_precios');
        Schema::dropIfExists('proveedor_precios');
    }
};
