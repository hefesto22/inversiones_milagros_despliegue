<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =============================
        // UNIDADES DE MEDIDA
        // =============================
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('simbolo', 20)->nullable();
            $table->boolean('es_decimal')->default(false);
            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        // =============================
        // CATEGORÍAS
        // =============================
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->boolean('aplica_isv')->default(false)->comment('Si los productos incluyen ISV en precio de compra (15%)');
            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        // =============================
        // BODEGAS
        // =============================
        Schema::create('bodegas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('codigo', 20)->unique()->nullable();
            $table->string('ubicacion')->nullable();
            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        // =============================
        // PRODUCTOS
        // =============================
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('sku', 50)->unique()->nullable();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->restrictOnDelete();

            $table->decimal('precio_sugerido', 12, 2)->nullable();
            $table->text('descripcion')->nullable();

            // CAMPOS DE FORMATO DE EMPAQUE
            $table->string('formato_empaque', 50)->nullable()
                ->comment('Formato de empaque del proveedor (ej: 1X24X12)');
            $table->unsignedInteger('unidades_por_bulto')->nullable()
                ->comment('Cantidad de unidades por caja/bulto (ej: 24 paquetes por caja)');

            // CAMPOS DE MARGEN E ISV
            $table->decimal('margen_ganancia', 12, 2)->default(5.00)->comment('Margen por defecto L5');
            $table->enum('tipo_margen', ['porcentaje', 'monto'])->default('monto');
            $table->boolean('aplica_isv')->default(false)->comment('Si el producto aplica ISV 15% en venta');

            // 🆕 CAMPOS DE PRECIO MÁXIMO COMPETITIVO
            $table->decimal('precio_venta_maximo', 12, 2)->nullable()
                ->comment('Precio máximo de venta (tope competitivo). Si es null, no hay límite.');
            $table->decimal('margen_minimo_seguridad', 5, 2)->default(3.00)
                ->comment('Margen mínimo de seguridad en % cuando costo >= precio_maximo (default 3%)');

            $table->boolean('activo')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // =============================
        // IMÁGENES DE PRODUCTOS
        // =============================
        Schema::create('producto_imagenes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();

            $table->string('path');
            $table->string('url')->nullable();

            $table->unsignedTinyInteger('orden')->default(0);
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });

        // =============================
        // STOCK POR BODEGA
        // =============================
        Schema::create('bodega_producto', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();

            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('stock_reservado', 12, 3)->default(0)
                ->comment('Stock apartado (pagado pero no entregado)');
            $table->decimal('stock_minimo', 14, 3)->nullable();

            // CAMPOS DE COSTO PROMEDIO CONTINUO (DIARIO)
            $table->decimal('costo_promedio_actual', 12, 4)->default(0)
                ->comment('Costo promedio ponderado (WAC) - se actualiza con cada entrada de stock');

            $table->decimal('precio_venta_sugerido', 12, 4)->nullable()
                ->comment('Precio de venta = costo_promedio_actual + margen (se actualiza automáticamente)');

            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->unique(['bodega_id', 'producto_id']);
        });

        // ======================================
        // TABLA PIVOTE: BODEGA ↔ USUARIOS
        // ======================================
        Schema::create('bodega_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('rol', 50)->nullable();
            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['bodega_id', 'user_id']);
        });

        // ======================================
        // TABLA PIVOTE: CATEGORÍA ↔ UNIDADES
        // ======================================
        Schema::create('categoria_unidad', function (Blueprint $table) {
            $table->id();

            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->foreignId('unidad_id')->constrained('unidades')->cascadeOnDelete();

            $table->boolean('activo')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['categoria_id', 'unidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_unidad');
        Schema::dropIfExists('bodega_user');
        Schema::dropIfExists('bodega_producto');
        Schema::dropIfExists('producto_imagenes');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('bodegas');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('unidades');
    }
};