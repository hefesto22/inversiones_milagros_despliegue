<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE viajes MODIFY COLUMN estado ENUM('planificado','cargando','en_ruta','recargando','regresando','descargando','liquidando','cerrado','cancelado') NOT NULL DEFAULT 'planificado'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE viajes MODIFY COLUMN estado ENUM('planificado','cargando','en_ruta','regresando','descargando','liquidando','cerrado','cancelado') NOT NULL DEFAULT 'planificado'");
    }
};