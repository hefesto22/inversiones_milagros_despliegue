<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MIGRACIÓN 2: VIAJES, CARGAS, LIQUIDACIONES
     *
     * FLUJO:
     * 1. Crear VIAJE → Cargar camión (bodega → camión)
     * 2. Salir de RUTA → Vender (descuenta de carga)
     * 3. Registrar MERMA (opcional)
     * 4. Regresar → DESCARGAR lo no vendido (camión → bodega)
     * 5. Decidir qué COBRAR al chofer
     * 6. Cerrar viaje → Calcular comisiones
     * 7. LIQUIDACIÓN periódica (semanal/quincenal/mensual)
     */
    public function up(): void
    {
        // =====================================================
        // 🚀 VIAJES (Cada salida del camión)
        // =====================================================
        Schema::create('viajes', function (Blueprint $table) {
            $table->id();

            $table->string('numero_viaje', 50)->unique()->nullable()
                ->comment('Código único ej: VJ-CAM001-241219-001');

            $table->foreignId('camion_id')
                ->constrained('camiones')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('chofer_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Chofer asignado al viaje');

            $table->foreignId('bodega_origen_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Bodega de donde sale la carga');

            // Fechas y horas
            $table->dateTime('fecha_salida')->nullable();
            $table->dateTime('fecha_regreso')->nullable();

            // Kilometraje (opcional)
            $table->unsignedInteger('km_salida')->nullable();
            $table->unsignedInteger('km_regreso')->nullable();

            // Estado del viaje
            $table->enum('estado', [
                'planificado',      // Creado pero no ha salido
                'cargando',         // En proceso de carga
                'en_ruta',          // Salió, vendiendo
                'regresando',       // Terminó ventas, volviendo
                'descargando',      // Descargando lo no vendido
                'liquidando',       // Revisando cobros y comisiones
                'cerrado',          // Viaje finalizado
                'cancelado'
            ])->default('planificado')->index();

            // Totales del viaje (se calculan al cerrar)
            $table->decimal('total_cargado_costo', 14, 2)->default(0)
                ->comment('Costo total de productos cargados');
            $table->decimal('total_cargado_venta', 14, 2)->default(0)
                ->comment('Valor de venta esperado de productos cargados');

            $table->decimal('total_vendido', 14, 2)->default(0)
                ->comment('Total real de ventas');
            $table->decimal('total_merma_costo', 14, 2)->default(0)
                ->comment('Costo de merma/pérdida');
            $table->decimal('total_devuelto_costo', 14, 2)->default(0)
                ->comment('Costo de productos devueltos');

            // Comisiones del viaje
            $table->decimal('comision_ganada', 14, 2)->default(0)
                ->comment('Comisión total ganada en este viaje');
            $table->decimal('cobros_devoluciones', 14, 2)->default(0)
                ->comment('Total cobrado al chofer por devoluciones');
            $table->decimal('neto_chofer', 14, 2)->default(0)
                ->comment('comision_ganada - cobros_devoluciones');

            // Efectivo
            $table->decimal('efectivo_inicial', 14, 2)->default(0)
                ->comment('Efectivo que lleva al salir (para cambio)');
            $table->decimal('efectivo_esperado', 14, 2)->default(0)
                ->comment('Efectivo que debería traer');
            $table->decimal('efectivo_entregado', 14, 2)->default(0)
                ->comment('Efectivo que entregó al regresar');
            $table->decimal('diferencia_efectivo', 14, 2)->default(0)
                ->comment('Diferencia (+ sobrante, - faltante)');

            $table->text('observaciones')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cerrado_en')->nullable();

            $table->timestamps();

            $table->index(['camion_id', 'estado']);
            $table->index(['chofer_id', 'estado']);
            $table->index(['fecha_salida', 'estado']);
        });

        // =====================================================
        // 📥 CARGA DE VIAJE (bodega → camión)
        // =====================================================
        Schema::create('viaje_cargas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $table->decimal('cantidad', 12, 3)
                ->comment('Cantidad cargada');

            $table->decimal('costo_unitario', 12, 2)
                ->comment('Costo al momento de cargar');

            $table->decimal('precio_venta_sugerido', 12, 2)
                ->comment('Precio mínimo de venta sugerido');

            $table->decimal('precio_venta_minimo', 12, 2)
                ->comment('Precio mínimo absoluto (= costo, no puede vender menos)');

            $table->decimal('subtotal_costo', 14, 2)
                ->comment('cantidad * costo_unitario');

            $table->decimal('subtotal_venta', 14, 2)
                ->comment('cantidad * precio_venta_sugerido');

            // Control de qué pasó con esta carga
            $table->decimal('cantidad_vendida', 12, 3)->default(0);
            $table->decimal('cantidad_merma', 12, 3)->default(0);
            $table->decimal('cantidad_devuelta', 12, 3)->default(0);

            $table->timestamps();

            $table->unique(['viaje_id', 'producto_id']);
            $table->index('viaje_id');
        });

        // =====================================================
        // 📤 DESCARGA DE VIAJE (camión → bodega)
        // =====================================================
        Schema::create('viaje_descargas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $table->decimal('cantidad', 12, 3)
                ->comment('Cantidad que regresa');

            $table->decimal('costo_unitario', 12, 2)
                ->comment('Costo del producto');

            $table->decimal('subtotal_costo', 14, 2)
                ->comment('cantidad * costo_unitario');

            // Estado del producto devuelto
            $table->enum('estado_producto', ['bueno', 'danado', 'vencido'])
                ->default('bueno');

            // ¿Regresa al inventario?
            $table->boolean('reingresa_stock')->default(true)
                ->comment('Si el producto vuelve al inventario de bodega');

            // ¿Se le cobra al chofer? (OPCIONAL)
            $table->boolean('cobrar_chofer')->default(false)
                ->comment('Si se le descuenta al chofer');

            $table->decimal('monto_cobrar', 12, 2)->default(0)
                ->comment('Monto a cobrar (normalmente = subtotal_costo)');

            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->index('viaje_id');
        });

        // =====================================================
        // 💔 MERMAS DE VIAJE (pérdidas en ruta)
        // =====================================================
        Schema::create('viaje_mermas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $table->decimal('cantidad', 12, 3)
                ->comment('Cantidad perdida/dañada');

            $table->decimal('costo_unitario', 12, 2);

            $table->decimal('subtotal_costo', 14, 2)
                ->comment('Pérdida en lempiras');

            $table->enum('motivo', [
                'rotura',           // Se rompió/quebró
                'vencimiento',      // Se venció
                'robo',             // Robo/pérdida
                'dano_transporte',  // Daño por transporte
                'regalo_cliente',   // Regalado (compensación)
                'otro'
            ])->default('rotura');

            $table->text('descripcion')->nullable();

            // ¿Se le cobra al chofer?
            $table->boolean('cobrar_chofer')->default(false);
            $table->decimal('monto_cobrar', 12, 2)->default(0);

            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['viaje_id', 'motivo']);
        });

        // =====================================================
        // 📊 DETALLE DE COMISIONES POR VIAJE
        // =====================================================
        Schema::create('viaje_comision_detalle', function (Blueprint $table) {
            $table->id();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->foreignId('venta_id')
                ->constrained('ventas')
                ->cascadeOnDelete();

            $table->foreignId('venta_detalle_id')
                ->constrained('venta_detalles')
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            // Datos de la venta
            $table->decimal('cantidad', 12, 3);
            $table->decimal('precio_vendido', 12, 2);
            $table->decimal('precio_sugerido', 12, 2);
            $table->decimal('costo', 12, 2);

            // Tipo de comisión aplicada
            $table->enum('tipo_comision', ['normal', 'reducida'])
                ->comment('normal = vendió >= sugerido, reducida = vendió < sugerido');

            $table->decimal('comision_unitaria', 12, 2);
            $table->decimal('comision_total', 12, 2);

            $table->timestamps();

            $table->index('viaje_id');
        });

        // =====================================================
        // 💰 LIQUIDACIONES (Pago periódico al chofer)
        // =====================================================
        Schema::create('liquidaciones', function (Blueprint $table) {
            $table->id();

            $table->string('numero_liquidacion', 50)->unique()->nullable()
                ->comment('Código único ej: LIQ-2024-001');

            $table->foreignId('chofer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Periodo
            $table->enum('tipo_periodo', ['semanal', 'quincenal', 'mensual'])
                ->default('quincenal');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            // Totales
            $table->integer('total_viajes')->default(0)
                ->comment('Cantidad de viajes en el periodo');

            $table->decimal('total_ventas', 14, 2)->default(0)
                ->comment('Total vendido en el periodo');

            $table->decimal('total_comisiones', 14, 2)->default(0)
                ->comment('Comisiones ganadas');

            $table->decimal('total_cobros', 14, 2)->default(0)
                ->comment('Cobros por devoluciones/mermas');

            $table->decimal('saldo_anterior', 14, 2)->default(0)
                ->comment('Saldo que traía (+ favor, - en contra)');

            $table->decimal('total_pagar', 14, 2)->default(0)
                ->comment('comisiones - cobros + saldo_anterior');

            // Estado
            $table->enum('estado', ['borrador', 'aprobada', 'pagada', 'anulada'])
                ->default('borrador');

            $table->date('fecha_pago')->nullable();
            $table->string('metodo_pago', 50)->nullable();
            $table->string('referencia_pago', 100)->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['chofer_id', 'estado']);
            $table->index(['fecha_inicio', 'fecha_fin']);
        });

        // =====================================================
        // 📋 VIAJES INCLUIDOS EN LIQUIDACIÓN
        // =====================================================
        Schema::create('liquidacion_viajes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('liquidacion_id')
                ->constrained('liquidaciones')
                ->cascadeOnDelete();

            $table->foreignId('viaje_id')
                ->constrained('viajes')
                ->cascadeOnDelete();

            $table->decimal('comision_viaje', 14, 2)->default(0);
            $table->decimal('cobros_viaje', 14, 2)->default(0);
            $table->decimal('neto_viaje', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['liquidacion_id', 'viaje_id']);
        });

        // =====================================================
        // 🔄 AGREGAR FK A MOVIMIENTOS DE CUENTA (de migración 1)
        // =====================================================
        Schema::table('chofer_cuenta_movimientos', function (Blueprint $table) {
            $table->foreign('viaje_id')
                ->references('id')
                ->on('viajes')
                ->nullOnDelete();

            $table->foreign('liquidacion_id')
                ->references('id')
                ->on('liquidaciones')
                ->nullOnDelete();
        });

        // =====================================================
        // 🔄 AGREGAR CAMPOS A TABLAS EXISTENTES
        // =====================================================

        // ventas: agregar campos de origen y entrega
        Schema::table('ventas', function (Blueprint $table) {
            // Origen de la venta
            $table->enum('origen', ['bodega', 'viaje'])
                ->default('bodega')
                ->after('bodega_id');

            $table->foreignId('viaje_id')
                ->nullable()
                ->after('origen')
                ->constrained('viajes')
                ->nullOnDelete();

            // Estado de entrega (separado del pago)
            $table->enum('estado_entrega', ['pendiente', 'entregado', 'parcial'])
                ->default('pendiente')
                ->after('estado_pago');

            $table->dateTime('fecha_entrega')->nullable()->after('estado_entrega');

            $table->foreignId('entregado_por')
                ->nullable()
                ->after('fecha_entrega')
                ->constrained('users')
                ->nullOnDelete();
        });

        // venta_detalles: agregar cantidad de regalo
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->decimal('cantidad_regalo', 12, 3)->default(0)
                ->after('cantidad')
                ->comment('Unidades regaladas (sin costo para cliente)');

            $table->decimal('costo_regalo', 12, 2)->default(0)
                ->after('cantidad_regalo')
                ->comment('Costo de los regalos (pérdida nuestra)');
        });
    }

    public function down(): void
    {
        // Quitar columnas agregadas
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropColumn(['cantidad_regalo', 'costo_regalo']);
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropForeign(['viaje_id']);
            $table->dropForeign(['entregado_por']);
            $table->dropColumn(['origen', 'viaje_id', 'estado_entrega', 'fecha_entrega', 'entregado_por']);
        });

        // Quitar FK de movimientos
        Schema::table('chofer_cuenta_movimientos', function (Blueprint $table) {
            $table->dropForeign(['viaje_id']);
            $table->dropForeign(['liquidacion_id']);
        });

        // Eliminar tablas
        Schema::dropIfExists('liquidacion_viajes');
        Schema::dropIfExists('liquidaciones');
        Schema::dropIfExists('viaje_comision_detalle');
        Schema::dropIfExists('viaje_mermas');
        Schema::dropIfExists('viaje_descargas');
        Schema::dropIfExists('viaje_cargas');
        Schema::dropIfExists('viajes');
    }
};
