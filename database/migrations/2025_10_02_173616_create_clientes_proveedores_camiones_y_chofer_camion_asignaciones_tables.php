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
         * CLIENTES
         */
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('rtn')->nullable();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        /**
         * PROVEEDORES
         */
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('rtn')->nullable();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        /**
         * CAMIONES
         */
        Schema::create('camiones', function (Blueprint $table) {
            $table->id();
            $table->string('placa')->unique();
            $table->unsignedInteger('capacidad_cartones_30')->nullable();
            $table->unsignedInteger('capacidad_cartones_15')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        /**
         * CHOFER ↔ CAMIÓN (asignaciones con vigencia)
         * Requiere tabla users existente.
         */
        Schema::create('chofer_camion_asignaciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('camion_id')
                ->constrained('camiones')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->index(['camion_id', 'user_id', 'activo'], 'ix_camion_user_activo');
            // Si quieres evitar dos asignaciones activas del mismo user al mismo camión,
            // podrías usar una restricción parcial a nivel app (o un trigger).
        });

        /**
         * HISTORIAL DE PRECIOS DE VENTA POR CLIENTE Y PRODUCTO
         * Requiere tablas: clientes, productos, users (opcional para auditoría).
         * Regla: un solo precio ACTIVO (vigente_hasta NULL) por (cliente, producto).
         */
        Schema::create('cliente_producto_precios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Precio de VENTA al cliente (Lempiras)
            $table->decimal('precio', 12, 2);

            // Vigencias
            $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            // Auditoría (opcional)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Columna generada: 1 cuando vigente_hasta es NULL (precio activo)
            $table->boolean('is_activa')->storedAs('IF(vigente_hasta IS NULL, 1, 0)');

            $table->timestamps();

            // Garantiza 1 solo activo por cliente+producto
            $table->unique(['cliente_id', 'producto_id', 'is_activa'], 'uniq_cli_prod_activo');

            // Evita duplicar misma fecha de inicio
            $table->unique(['cliente_id', 'producto_id', 'vigente_desde'], 'uniq_cli_prod_inicio');

            $table->index(['cliente_id', 'producto_id', 'vigente_desde'], 'ix_cli_prod_fecha');
        });

        /**
         * HISTORIAL DE PRECIOS DE COMPRA POR PROVEEDOR Y PRODUCTO
         * Regla: un solo precio ACTIVO por (proveedor, producto).
         */
        Schema::create('proveedor_producto_precios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Precio de COMPRA al proveedor
            $table->decimal('precio', 12, 2);

           $table->date('vigente_desde')->nullable();
            $table->date('vigente_hasta')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_activa')->storedAs('IF(vigente_hasta IS NULL, 1, 0)');

            $table->timestamps();

            $table->unique(['proveedor_id', 'producto_id', 'is_activa'], 'uniq_prov_prod_activo');
            $table->unique(['proveedor_id', 'producto_id', 'vigente_desde'], 'uniq_prov_prod_inicio');

            $table->index(['proveedor_id', 'producto_id', 'vigente_desde'], 'ix_prov_prod_fecha');
        });
    }

    public function down(): void
    {
        // Eliminar en orden inverso para respetar FKs
        Schema::dropIfExists('proveedor_producto_precios');
        Schema::dropIfExists('cliente_producto_precios');
        Schema::dropIfExists('chofer_camion_asignaciones');
        Schema::dropIfExists('camiones');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('clientes');
    }
};
