<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // CLIENTES
        // =====================================================
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();

            $table->string('nombre');
            $table->string('rtn', 30)->nullable()->unique();
            $table->string('telefono', 30)->nullable();
            $table->text('direccion')->nullable();
            $table->string('email', 100)->nullable();

            $table->enum('tipo', ['mayorista', 'minorista', 'distribuidor', 'ruta'])
                ->default('minorista')
                ->index();

            // 💰 CONTROL DE CRÉDITO
            $table->decimal('limite_credito', 12, 2)->default(0)->comment('Límite máximo de crédito permitido');
            $table->decimal('saldo_pendiente', 12, 2)->default(0)->comment('Deuda actual del cliente');
            $table->integer('dias_credito')->default(0)->comment('Días de plazo para pagar (0 = solo contado)');

            // 🔄 POLÍTICA DE DEVOLUCIONES/REPOSICIONES
            $table->boolean('acepta_devolucion')->default(false)->comment('Si se le acepta devolución por daño');
            $table->decimal('porcentaje_devolucion_max', 5, 2)->default(0)->comment('% máximo de devolución permitido');
            $table->integer('dias_devolucion')->default(0)->comment('Días máximos para aceptar devolución (ej: 3 días)');
            $table->text('notas_acuerdo')->nullable()->comment('Acuerdos especiales con el cliente');

            $table->boolean('estado')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['estado', 'nombre']);
            $table->index('saldo_pendiente');
        });

        // =====================================================
        // CLIENTE - PRODUCTO (Historial de precios)
        // =====================================================
        Schema::create('cliente_producto', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->decimal('ultimo_precio_venta', 12, 2)->nullable()->comment('Último precio SIN ISV');
            $table->decimal('ultimo_precio_con_isv', 12, 2)->nullable()->comment('Último precio CON ISV');
            $table->decimal('cantidad_ultima_venta', 12, 2)->nullable()->comment('Cantidad de última venta');
            $table->timestamp('fecha_ultima_venta')->nullable();

            // Estadísticas
            $table->integer('total_ventas')->default(0)->comment('Cantidad de veces que se le ha vendido');
            $table->decimal('cantidad_total_vendida', 14, 2)->default(0)->comment('Total de unidades vendidas');

            $table->unique(['cliente_id', 'producto_id']);
            $table->index('fecha_ultima_venta');
        });

        // =====================================================
        // VENTAS
        // =====================================================
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('numero_venta', 50)->unique()->nullable();

            $table->enum('tipo_pago', ['efectivo', 'transferencia', 'tarjeta', 'credito'])
                ->default('efectivo')
                ->index();

            // 💵 TOTALES
            $table->decimal('subtotal', 14, 2)->default(0)->comment('Suma de productos sin ISV');
            $table->decimal('total_isv', 14, 2)->default(0)->comment('Total de ISV (15%)');
            $table->decimal('descuento', 14, 2)->default(0)->comment('Descuento global');
            $table->decimal('total', 14, 2)->default(0)->comment('subtotal + total_isv - descuento');

            // 💰 CONTROL DE PAGOS (para crédito)
            $table->decimal('monto_pagado', 14, 2)->default(0)->comment('Cuánto ha pagado');
            $table->decimal('saldo_pendiente', 14, 2)->default(0)->comment('Cuánto debe de esta venta');
            $table->date('fecha_vencimiento')->nullable()->comment('Fecha límite de pago (crédito)');

            $table->enum('estado', ['borrador', 'completada', 'pendiente_pago', 'pagada', 'cancelada'])
                ->default('borrador')
                ->index();

            $table->enum('estado_pago', ['pendiente', 'parcial', 'pagado'])
                ->default('pendiente')
                ->index();

            $table->text('nota')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['bodega_id', 'estado']);
            $table->index(['cliente_id', 'estado']);
            $table->index(['cliente_id', 'estado_pago']);
            $table->index('fecha_vencimiento');
        });

        // =====================================================
        // DETALLES DE VENTA
        // =====================================================
        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venta_id')
                ->constrained('ventas')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('unidad_id')
                ->constrained('unidades')
                ->restrictOnDelete();

            $table->decimal('cantidad', 12, 2);

            // PRECIOS
            $table->decimal('precio_unitario', 12, 2)->comment('Precio sin ISV');
            $table->decimal('precio_con_isv', 12, 2)->nullable()->comment('Precio con ISV (si aplica)');
            $table->decimal('costo_unitario', 12, 2)->default(0)->comment('Costo al momento de venta (para calcular ganancia)');

            // ISV
            $table->boolean('aplica_isv')->default(true);
            $table->decimal('isv_unitario', 12, 2)->default(0)->comment('ISV por unidad');

            // DESCUENTOS
            $table->decimal('descuento_porcentaje', 5, 2)->default(0);
            $table->decimal('descuento_monto', 12, 2)->default(0);

            // TOTALES DE LÍNEA
            $table->decimal('subtotal', 14, 2)->comment('cantidad * precio_unitario');
            $table->decimal('total_isv', 14, 2)->default(0)->comment('cantidad * isv_unitario');
            $table->decimal('total_linea', 14, 2)->comment('subtotal + total_isv - descuento_monto');

            // 📊 REFERENCIA: Último precio a este cliente
            $table->decimal('precio_anterior', 12, 2)->nullable()->comment('Precio que se le vendió antes (referencia)');

            $table->timestamps();

            $table->index(['venta_id', 'producto_id']);
        });

        // =====================================================
        // 💳 PAGOS DE VENTAS (Para ventas a crédito)
        // =====================================================
        Schema::create('venta_pagos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venta_id')
                ->constrained('ventas')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->decimal('monto', 14, 2);

            $table->enum('metodo_pago', ['efectivo', 'transferencia', 'tarjeta', 'cheque'])
                ->default('efectivo');

            $table->string('referencia', 100)->nullable()->comment('# de transferencia, cheque, etc');
            $table->text('nota')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['venta_id', 'created_at']);
        });

        // =====================================================
        // 🔄 DEVOLUCIONES / NOTAS DE CRÉDITO
        // =====================================================
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venta_id')
                ->constrained('ventas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('numero_devolucion', 50)->unique()->nullable();

            $table->enum('tipo', ['devolucion', 'nota_credito', 'reposicion'])
                ->default('devolucion')
                ->comment('devolucion=regresa producto | nota_credito=descuento futuro | reposicion=producto nuevo');

            $table->enum('motivo', [
                'producto_danado_entrega',    // Llegó dañado (culpa vendedor)
                'producto_vencido',           // Producto vencido
                'error_pedido',               // Se pidió mal
                'acuerdo_comercial',          // Política de reposición acordada
                'otro'
            ])->default('producto_danado_entrega');

            $table->text('descripcion_motivo')->nullable();

            // Totales
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_isv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // ¿Qué se hace con el monto?
            $table->enum('accion', ['reembolso_efectivo', 'credito_cuenta', 'reposicion_producto'])
                ->default('credito_cuenta');

            $table->boolean('aplicado')->default(false)->comment('Si ya se aplicó al saldo del cliente');
            $table->boolean('stock_reingresado')->default(false)->comment('Si el producto volvió al inventario');

            $table->enum('estado', ['borrador', 'aprobada', 'aplicada', 'cancelada'])
                ->default('borrador')
                ->index();

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_aprobacion')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['cliente_id', 'estado']);
            $table->index(['venta_id']);
        });

        // =====================================================
        // DETALLE DE DEVOLUCIONES
        // =====================================================
        Schema::create('devolucion_detalles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('devolucion_id')
                ->constrained('devoluciones')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();

            $table->foreignId('venta_detalle_id')
                ->nullable()
                ->constrained('venta_detalles')
                ->nullOnDelete()
                ->comment('Línea original de la venta');

            $table->decimal('cantidad', 12, 2);
            $table->decimal('precio_unitario', 12, 2)->comment('Precio al que se vendió');
            $table->boolean('aplica_isv')->default(true);
            $table->decimal('isv_unitario', 12, 2)->default(0);

            $table->decimal('subtotal', 14, 2);
            $table->decimal('total_isv', 14, 2)->default(0);
            $table->decimal('total_linea', 14, 2);

            $table->enum('estado_producto', ['bueno', 'danado', 'vencido'])
                ->default('danado')
                ->comment('Estado del producto devuelto');

            $table->boolean('reingresa_stock')->default(false)->comment('Si puede volver al inventario');

            $table->timestamps();

            $table->index(['devolucion_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucion_detalles');
        Schema::dropIfExists('devoluciones');
        Schema::dropIfExists('venta_pagos');
        Schema::dropIfExists('venta_detalles');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('cliente_producto');
        Schema::dropIfExists('clientes');
    }
};
