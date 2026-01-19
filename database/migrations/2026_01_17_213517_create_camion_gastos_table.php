<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MIGRACIÓN: GASTOS DE CAMIÓN
     *
     * Registro de gastos operativos (gasolina, mantenimiento, reparaciones, etc.)
     * para calcular rentabilidad real de cada ruta/camión.
     *
     * Tipos de gasto definidos en código (enum):
     * - gasolina, mantenimiento, reparacion, peaje, viaticos, lavado, otros
     *
     * Flujo:
     * 1. Chofer registra gasto rápido (tipo, monto)
     * 2. Presiona "Enviar por WhatsApp" → Abre WhatsApp con datos prellenados
     * 3. Chofer adjunta foto y envía
     * 4. Sistema marca como enviado con timestamp
     */
    public function up(): void
    {
        Schema::create('camion_gastos', function (Blueprint $table) {
            $table->id();

            // =====================================================
            // RELACIONES PRINCIPALES
            // =====================================================

            $table->foreignId('camion_id')
                ->constrained('camiones')
                ->cascadeOnDelete();

            $table->foreignId('chofer_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Chofer que realizó/reportó el gasto');

            $table->unsignedBigInteger('viaje_id')
                ->nullable()
                ->comment('Si el gasto fue durante un viaje específico');

            // =====================================================
            // TIPO Y DATOS DEL GASTO
            // =====================================================

            $table->enum('tipo_gasto', [
                'gasolina',
                'mantenimiento',
                'reparacion',
                'peaje',
                'viaticos',
                'lavado',
                'otros',
            ])->comment('Tipo de gasto');

            $table->date('fecha');
            $table->decimal('monto', 12, 2);
            $table->text('descripcion')->nullable();

            // =====================================================
            // CAMPOS ESPECÍFICOS PARA GASOLINA
            // =====================================================

            $table->decimal('litros', 10, 3)->nullable()
                ->comment('Litros de combustible (solo para gasolina)');

            $table->decimal('precio_por_litro', 8, 2)->nullable()
                ->comment('Precio por litro (solo para gasolina)');

            $table->decimal('kilometraje', 12, 2)->nullable()
                ->comment('Kilometraje al momento del gasto');

            $table->string('proveedor', 255)->nullable()
                ->comment('Gasolinera, taller, etc.');

            // =====================================================
            // CONTROL DE FACTURA Y WHATSAPP
            // =====================================================

            $table->boolean('tiene_factura')->default(false)
                ->comment('¿El gasto tiene factura?');

            $table->boolean('enviado_whatsapp')->default(false)
                ->comment('¿Se envió comprobante por WhatsApp?');

            $table->timestamp('enviado_whatsapp_at')->nullable()
                ->comment('Fecha/hora cuando se envió por WhatsApp');

            // =====================================================
            // ESTADO Y APROBACIÓN
            // =====================================================

            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])
                ->default('pendiente');

            $table->text('motivo_rechazo')->nullable();

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

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // =====================================================
            // ÍNDICES PARA REPORTES
            // =====================================================

            $table->index(['camion_id', 'fecha'], 'idx_camion_fecha');
            $table->index(['chofer_id', 'fecha'], 'idx_chofer_fecha');
            $table->index(['tipo_gasto', 'fecha'], 'idx_tipo_fecha');
            $table->index(['viaje_id'], 'idx_viaje');
            $table->index(['estado', 'fecha'], 'idx_estado_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camion_gastos');
    }
};