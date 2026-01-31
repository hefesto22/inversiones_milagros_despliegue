<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MIGRACIÓN 1: CAMIONES, CHOFERES Y COMISIONES
     *
     * - Camiones (bodegas móviles)
     * - Asignación chofer-camión
     * - Inventario del camión
     * - Configuración de comisiones (una vez por chofer)
     * - Cuenta/saldo del chofer
     */
    public function up(): void
    {
        // =====================================================
        // 🚛 CAMIONES (Bodegas móviles)
        // =====================================================
        Schema::create('camiones', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 20)->unique()->comment('Código interno ej: CAM-001');
            $table->string('placa', 20)->unique();

            // Bodega a la que pertenece el camión
            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Bodega base del camión');

            $table->boolean('activo')->default(true);

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bodega_id', 'activo']);
        });

        // =====================================================
        // 👨‍✈️ ASIGNACIÓN CHOFER ↔ CAMIÓN (con vigencias)
        // =====================================================
        Schema::create('camion_chofer', function (Blueprint $table) {
            $table->id();

            $table->foreignId('camion_id')
                ->constrained('camiones')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Usuario con rol chofer');

            $table->date('fecha_asignacion');
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true);

            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['camion_id', 'activo']);
            $table->index(['user_id', 'activo']);
        });

        // =====================================================
        // 📦 INVENTARIO DEL CAMIÓN (Stock actual)
        // =====================================================
        Schema::create('camion_producto', function (Blueprint $table) {
            $table->id();

            $table->foreignId('camion_id')
                ->constrained('camiones')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnDelete();

            $table->decimal('stock', 12, 3)->default(0)
                ->comment('Stock actual en el camión');

            $table->decimal('costo_promedio', 12, 2)->default(0)
                ->comment('Costo promedio del producto');

            $table->decimal('precio_venta_sugerido', 12, 2)->default(0)
                ->comment('Precio mínimo sugerido de venta');

            $table->timestamps();

            $table->unique(['camion_id', 'producto_id']);
            $table->index(['camion_id', 'stock']);
        });

        // =====================================================
        // 💰 COMISIONES CHOFER - CONFIGURACIÓN
        // Por Categoría + Unidad (se configura una vez por chofer)
        // =====================================================
        Schema::create('chofer_comision_config', function (Blueprint $table) {
            $table->id();
        
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Chofer');
        
            // Por categoría + unidad
            $table->foreignId('categoria_id')
                ->constrained('categorias')
                ->cascadeOnDelete();
        
            $table->foreignId('unidad_id')
                ->nullable()
                ->constrained('unidades')
                ->nullOnDelete()
                ->comment('Si NULL, aplica a cualquier presentación de la categoría');
        
            // Tipo de comisión
            $table->enum('tipo_comision', ['fijo', 'porcentaje'])
                ->default('fijo')
                ->comment('fijo = monto en Lempiras, porcentaje = % sobre precio de venta');
        
            // Comisiones
            $table->decimal('comision_normal', 12, 2)
                ->comment('Comisión cuando vende >= precio sugerido');
        
            $table->decimal('comision_reducida', 12, 2)->default(0.50)
                ->comment('Comisión cuando vende < precio sugerido pero > costo');
        
            // Vigencia
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);
        
            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        
            $table->timestamps();
        
            $table->index(['user_id', 'activo']);
            $table->index(['categoria_id', 'unidad_id']);
        });

        // =====================================================
        // 💰 COMISIONES CHOFER - EXCEPCIONES POR PRODUCTO
        // Para productos específicos con comisión diferente
        // =====================================================
        Schema::create('chofer_comision_producto', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Chofer');

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnDelete();

            $table->decimal('comision_normal', 12, 2);
            $table->decimal('comision_reducida', 12, 2)->default(0.50);

            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'producto_id', 'activo']);
        });

        // =====================================================
        // 💵 SALDO/CUENTA DEL CHOFER
        // =====================================================
        Schema::create('chofer_cuenta', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Chofer');

            $table->decimal('saldo', 14, 2)->default(0)
                ->comment('Positivo = le debemos, Negativo = nos debe');

            $table->decimal('total_comisiones_historico', 14, 2)->default(0)
                ->comment('Total de comisiones ganadas histórico');

            $table->decimal('total_cobros_historico', 14, 2)->default(0)
                ->comment('Total cobrado por devoluciones histórico');

            $table->decimal('total_pagado_historico', 14, 2)->default(0)
                ->comment('Total que se le ha pagado');

            $table->timestamps();

            $table->unique('user_id');
        });

        // =====================================================
        // 💳 MOVIMIENTOS DE CUENTA DEL CHOFER
        // =====================================================
        Schema::create('chofer_cuenta_movimientos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Chofer');

            $table->enum('tipo', [
                'comision',         // + Comisión ganada
                'cobro_devolucion', // - Cobro por devolución
                'cobro_merma',      // - Cobro por merma
                'cobro_faltante',   // - Cobro por faltante de efectivo
                'pago_liquidacion', // - Pago de liquidación
                'ajuste_favor',     // + Ajuste a favor
                'ajuste_contra',    // - Ajuste en contra
            ]);

            $table->decimal('monto', 14, 2)
                ->comment('Siempre positivo, el tipo indica si suma o resta');

            $table->decimal('saldo_anterior', 14, 2);
            $table->decimal('saldo_nuevo', 14, 2);

            // Referencias (se llenarán en migración 2)
            $table->unsignedBigInteger('viaje_id')->nullable();
            $table->unsignedBigInteger('liquidacion_id')->nullable();

            $table->text('concepto')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chofer_cuenta_movimientos');
        Schema::dropIfExists('chofer_cuenta');
        Schema::dropIfExists('chofer_comision_producto');
        Schema::dropIfExists('chofer_comision_config');
        Schema::dropIfExists('camion_producto');
        Schema::dropIfExists('camion_chofer');
        Schema::dropIfExists('camiones');
    }
};
