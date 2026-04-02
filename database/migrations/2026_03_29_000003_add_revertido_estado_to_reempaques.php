<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE reempaques MODIFY COLUMN estado ENUM('en_proceso', 'completado', 'cancelado', 'revertido') NOT NULL DEFAULT 'en_proceso'");
    }

    public function down(): void
    {
        // Convertir 'revertido' a 'cancelado' antes de quitar el valor del enum
        DB::statement("UPDATE reempaques SET estado = 'cancelado' WHERE estado = 'revertido'");
        DB::statement("ALTER TABLE reempaques MODIFY COLUMN estado ENUM('en_proceso', 'completado', 'cancelado') NOT NULL DEFAULT 'en_proceso'");
    }
};
