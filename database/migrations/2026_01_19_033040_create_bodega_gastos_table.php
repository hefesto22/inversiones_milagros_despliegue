<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MIGRACIÓN: GASTOS DE BODEGA
     *
     * Registro de gastos operativos de cada bodega (cartones, limpieza, papelería, etc.)
     * para control y aprobación de gastos.
     *
     * Tipos de gasto definidos en array dentro del modelo:
     * - cartones, empaque, limpieza, papeleria, herramientas,
     *   mantenimiento, servicios, uniformes, fumigacion, transporte_local, otros
     *
     * Flujo:
     * 1. Encargado registra gasto (categoría, detalle, monto, tiene_factura)
     * 2. Guarda el registro
     * 3. Aparece botón "Enviar por WhatsApp"
     * 4. Al presionar → Abre WhatsApp con datos del gasto
     *    - Si tiene factura → Encargado adjunta foto manualmente
     *    - Si no tiene → Solo se envía el texto
     * 5. Jefe aprueba o rechaza (soft delete)
     */
    public function up(): void
    {
        Schema::create('bodega_gastos', function (Blueprint $table) {
            $table->id();

            // =====================================================
            // RELACIONES
            // =====================================================

            $table->foreignId('bodega_id')
                ->constrained('bodegas')
                ->cascadeOnDelete();

            $table->foreignId('registrado_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Encargado que registró el gasto');

            // =====================================================
            // DATOS DEL GASTO
            // =====================================================

            $table->string('tipo_gasto', 50)
                ->comment('Categoría del gasto (definida en array del modelo)');

            $table->date('fecha');

            $table->text('detalle')
                ->comment('Descripción libre: "Cartulina opaca x35", "2 galones de cloro"');

            $table->decimal('monto', 12, 2);

            $table->boolean('tiene_factura')->default(false)
                ->comment('¿El gasto tiene factura física?');

            // =====================================================
            // CONTROL DE WHATSAPP
            // =====================================================

            $table->boolean('enviado_whatsapp')->default(false)
                ->comment('¿Ya se envió por WhatsApp?');

            $table->timestamp('enviado_whatsapp_at')->nullable()
                ->comment('Fecha/hora cuando se envió por WhatsApp');

            // =====================================================
            // APROBACIÓN
            // =====================================================

            $table->enum('estado', ['pendiente', 'aprobado'])
                ->default('pendiente');

            $table->foreignId('aprobado_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('aprobado_at')->nullable();

            // =====================================================
            // AUDITORÍA
            // =====================================================

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // =====================================================
            // ÍNDICES PARA REPORTES
            // =====================================================

            $table->index(['bodega_id', 'fecha'], 'idx_bodega_fecha');
            $table->index(['tipo_gasto', 'fecha'], 'idx_tipo_fecha');
            $table->index(['estado', 'fecha'], 'idx_estado_fecha');
            $table->index(['registrado_por', 'fecha'], 'idx_registrado_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bodega_gastos');
    }
};