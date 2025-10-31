<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Tabla: viajes
         * Registro de viajes de distribución con camiones y choferes
         */
        Schema::create('viajes', function (Blueprint $table) {
            $table->id();

            // Relaciones principales
            $table->foreignId('camion_id')
                ->constrained('camiones')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('chofer_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Usuario que es el chofer');

            $table->foreignId('bodega_origen_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // TODO: Descomentar cuando se cree la tabla rutas
            // $table->foreignId('ruta_id')
            //     ->nullable()
            //     ->constrained('rutas')
            //     ->cascadeOnUpdate()
            //     ->nullOnDelete()
            //     ->comment('Ruta asignada (opcional)');

            // Por ahora, solo el campo sin foreign key
            $table->unsignedBigInteger('ruta_id')->nullable();

            // Datos del viaje
            $table->dateTime('fecha_salida')->default(now());
            $table->dateTime('fecha_regreso')->nullable();

            // Estado del viaje
            $table->enum('estado', ['en_preparacion', 'en_ruta', 'cerrado'])
                ->default('en_preparacion');

            // Datos adicionales
            $table->text('nota')->nullable();

            // Auditoría
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que creó el viaje');

            $table->foreignId('cerrado_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que cerró el viaje');

            $table->dateTime('cerrado_at')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['camion_id', 'fecha_salida'], 'ix_camion_fecha');
            $table->index(['chofer_user_id', 'fecha_salida'], 'ix_chofer_fecha');
            $table->index(['estado', 'fecha_salida'], 'ix_estado_fecha');
            $table->index('fecha_salida');
        });

        /**
         * Tabla: viaje_cargas
         * Productos cargados en el camión para el viaje
         */
        Schema::create('viaje_cargas', function (Blueprint $table) {
            $table->id();

            // Relación con viaje
            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Producto y presentación
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('unidad_id_presentacion')
                ->constrained('unidades')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Unidad en la que se cargó (cartón 30, cartón 15, etc.)');

            // Cantidades
            $table->decimal('cantidad_presentacion', 12, 3)
                ->comment('Cantidad en la presentación cargada');

            $table->decimal('factor_a_base', 12, 6)
                ->comment('Factor de conversión a unidad base');

            $table->decimal('cantidad_base', 14, 3)
                ->comment('Cantidad convertida a unidad base del producto');

            $table->timestamps();

            // Índices
            $table->index(['viaje_id', 'producto_id'], 'ix_viaje_producto');
            $table->index('producto_id');
        });

        /**
         * Tabla: viaje_mermas
         * Registro de mermas durante el viaje (productos dañados/perdidos)
         */
        Schema::create('viaje_mermas', function (Blueprint $table) {
            $table->id();

            // Relación con viaje
            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Producto
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Cantidad en unidad base
            $table->decimal('cantidad_base', 14, 3)
                ->comment('Cantidad de merma en unidad base');

            // Información adicional
            $table->string('motivo')->nullable()
                ->comment('Motivo de la merma: rotura, vencimiento, etc.');

            // Auditoría
            $table->foreignId('registrado_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que registró la merma');

            $table->timestamps();

            // Índices
            $table->index(['viaje_id', 'producto_id'], 'ix_viaje_merma_producto');
            $table->index('producto_id');
        });

        /**
         * Tabla: comisiones_chofer
         * Configuración de comisiones por cartones vendidos
         */
        Schema::create('comisiones_chofer', function (Blueprint $table) {
            $table->id();

            // Chofer
            $table->foreignId('chofer_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Aplicación de la comisión
            $table->enum('aplica_a', ['carton_30', 'carton_15', 'ambos'])
                ->comment('A qué tipo de cartón aplica');

            // Monto de la comisión
            $table->decimal('monto_por_carton', 12, 4)
                ->comment('Monto en Lempiras por cada cartón');

            // Vigencias
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();

            // Auditoría
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que creó la comisión');

            $table->timestamps();

            // Índices
            $table->index(['chofer_user_id', 'vigente_desde'], 'ix_chofer_vigencia');
            $table->index(['chofer_user_id', 'aplica_a'], 'ix_chofer_aplica');
        });

        /**
         * Tabla: comisiones_chofer_liquidaciones
         * Liquidación de comisiones por viaje
         */
        Schema::create('comisiones_chofer_liquidaciones', function (Blueprint $table) {
            $table->id();

            // Relación con viaje
            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Chofer
            $table->foreignId('chofer_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Cartones vendidos
            $table->decimal('cartones_30_vendidos', 12, 3)
                ->default(0)
                ->comment('Cantidad de cartones de 30 vendidos');

            $table->decimal('cartones_15_vendidos', 12, 3)
                ->default(0)
                ->comment('Cantidad de cartones de 15 vendidos');

            // Total de comisión
            $table->decimal('total_comision', 12, 2)
                ->comment('Total de comisión calculada');

            // Fecha de cálculo
            $table->dateTime('calculado_en')->default(now());

            // Auditoría
            $table->foreignId('calculado_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Usuario que calculó la comisión');

            $table->timestamps();

            // Índices
            $table->index(['viaje_id'], 'ix_viaje');
            $table->index(['chofer_user_id', 'calculado_en'], 'ix_chofer_fecha');

            // Un viaje solo puede tener una liquidación
            $table->unique('viaje_id');
        });
    }

    public function down(): void
    {
        // Eliminar en orden inverso por las foreign keys
        Schema::dropIfExists('comisiones_chofer_liquidaciones');
        Schema::dropIfExists('comisiones_chofer');
        Schema::dropIfExists('viaje_mermas');
        Schema::dropIfExists('viaje_cargas');
        Schema::dropIfExists('viajes');
    }
};
