<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones para una segunda venta de ruta al mismo cliente en el día.
 *
 * Regla del negocio: un cliente registrado recibe UNA sola factura de ruta por
 * día calendario, en cualquier camión. Es un control para que no le carguen
 * varias facturas (o todas) a un mismo cliente. Consumidor Final queda fuera
 * de la regla porque no es un cliente real, es el mostrador.
 *
 * Esta tabla es la válvula: el Jefe o Encargado autoriza UNA venta extra
 * puntual, con motivo, y queda el rastro de quién la habilitó. Así el control
 * no se pierde, se vuelve trazable.
 *
 * Decisiones:
 *   - Una fila = una venta extra. Al usarse se marca consumida y no sirve más;
 *     para una tercera venta hay que autorizar de nuevo.
 *   - `fecha` es DATE (no datetime): la autorización vale para el día, no para
 *     un instante. La app corre en America/Tegucigalpa, así que "el día" es el
 *     día local, que es lo que entiende el chofer.
 *   - NO se agrega índice (cliente_id, fecha_venta) en viaje_ventas: esa tabla
 *     ya lo trae desde su migración original (2026_01_10_300000, línea 76).
 *   - Nombre de índice prefijado con la tabla, para no chocar con índices
 *     homónimos de otras tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones_segunda_venta', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnDelete();

            $table->date('fecha')
                ->comment('Día (local) para el que vale la autorización');

            $table->foreignId('autorizado_por')
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('Jefe o Encargado que la habilitó');

            $table->string('motivo', 255)
                ->comment('Por qué se autorizó — obligatorio, es la razón de ser del registro');

            $table->foreignId('consumida_en_viaje_venta_id')
                ->nullable()
                ->constrained('viaje_ventas')
                ->nullOnDelete()
                ->comment('Venta que la usó; null = sigue disponible');

            $table->timestamp('consumida_at')->nullable();

            $table->timestamps();

            // Búsqueda del servicio: autorizaciones vivas de un cliente para hoy.
            $table->index(['cliente_id', 'fecha', 'consumida_at'], 'autorizaciones_sv_cliente_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizaciones_segunda_venta');
    }
};
