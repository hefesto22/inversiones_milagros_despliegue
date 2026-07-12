<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex de Inventario — libro mayor de movimientos (append-only).
 *
 * Decisiones de diseño:
 *
 * 1. INMUTABILIDAD: las filas nunca se editan ni borran (enforced en el modelo
 *    MovimientoInventario). Correcciones = nuevo movimiento que compensa.
 *
 * 2. DOS NIVELES en una sola tabla, discriminados por `nivel`:
 *      - 'lote'   → huevo suelto; lote_id lleno, unidad='huevos'.
 *      - 'bodega' → producto terminado (empacado, lácteos); bodega_producto_id
 *                   lleno, unidad='unidades'.
 *    producto_id y bodega_id van SIEMPRE denormalizados para que los filtros
 *    del Kardex no requieran joins.
 *
 * 3. saldo_despues: snapshot del saldo del "contenedor" (lote o bodega_producto)
 *    inmediatamente después del movimiento, tomado dentro de la MISMA transacción.
 *    Permite: (a) leer el Kardex sin recalcular saldos, (b) el guardián nocturno
 *    kardex:verificar compara el último saldo vs stock real y detecta cualquier
 *    mutación que haya esquivado los eventos (SQL manual, bug futuro).
 *
 * 4. referencia polimórfica: apunta al documento de negocio origen
 *    (Venta, Viaje, Reempaque, Merma, CompraDetalle, AjusteInventario, ...).
 *    nullableMorphs porque saldo_inicial y movimientos manuales no tienen documento.
 *
 * 5. FK con restrictOnDelete: un lote/producto con historia en el Kardex NO se
 *    puede borrar físicamente — el libro protege su propia integridad.
 *
 * 6. ocurrido_en separado de created_at: ocurrido_en es la fecha del hecho de
 *    negocio (indexada, ordena el Kardex); created_at es la fecha de registro.
 *    Hoy coinciden, pero el diseño soporta asientos retroactivos documentados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();

            $table->timestamp('ocurrido_en')
                ->comment('Fecha/hora del hecho de negocio (ordena el Kardex)');

            $table->string('tipo', 30)
                ->comment('Enum MovimientoInventarioTipo: compra | venta | salida_reempaque | ...');

            $table->string('nivel', 10)
                ->comment("'lote' (huevo suelto) | 'bodega' (producto terminado/lácteos)");

            // Contenedor del movimiento — exactamente uno según nivel
            $table->foreignId('lote_id')->nullable()
                ->constrained('lotes')->restrictOnDelete()
                ->comment('Lote afectado (nivel=lote)');
            $table->foreignId('bodega_producto_id')->nullable()
                ->constrained('bodega_producto')->restrictOnDelete()
                ->comment('Stock de bodega afectado (nivel=bodega)');

            // Denormalizados para filtros sin joins
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();

            $table->string('unidad', 10)
                ->comment("'huevos' (nivel lote) | 'unidades' (nivel bodega)");

            $table->decimal('delta', 14, 3)
                ->comment('Cantidad del movimiento: positivo = entra, negativo = sale');
            $table->decimal('saldo_despues', 14, 3)
                ->comment('Saldo del contenedor DESPUÉS del movimiento (misma transacción)');

            $table->decimal('costo_unitario', 12, 6)->nullable()
                ->comment('Costo unitario aplicado al movimiento (efectivo/WAC)');
            $table->decimal('valor', 14, 4)->nullable()
                ->comment('abs(delta) × costo_unitario, en Lempiras');

            // Documento de negocio origen (Venta, Viaje, Reempaque, Merma, AjusteInventario, ...)
            $table->nullableMorphs('referencia');

            $table->string('descripcion')->nullable()
                ->comment('Resumen legible: "Venta V01-260712-0004", "Carga VJ-171", ...');
            $table->json('contexto')->nullable()
                ->comment('Metadatos adicionales del movimiento');

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Índices para las consultas reales del Kardex
            $table->index(['lote_id', 'id'], 'idx_kardex_lote');
            $table->index(['bodega_producto_id', 'id'], 'idx_kardex_bp');
            $table->index(['producto_id', 'bodega_id', 'id'], 'idx_kardex_prod_bodega');
            $table->index(['tipo', 'ocurrido_en'], 'idx_kardex_tipo_fecha');
            $table->index('ocurrido_en', 'idx_kardex_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
