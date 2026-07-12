<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columna `email_verified_at` a la tabla `users`.
 *
 * CONTEXTO
 * --------
 * La migración original de `users` removió esta columna por una decisión de
 * "no implementamos verificación de email". Sin embargo, parte del código
 * scaffolding de Laravel se quedó (Profile Livewire component, VerifyEmail,
 * EmailVerificationTest, ProfileUpdateTest) y todo ese código asume que la
 * columna existe.
 *
 * Agregar la columna NO obliga a implementar verificación — solo permite que
 * el código existente (y los tests) funcionen sin lanzar excepciones por
 * columna inexistente. Si en el futuro se quiere activar la feature, solo
 * hay que configurar el flujo de notificaciones.
 *
 * IDEMPOTENTE
 * -----------
 * Se valida si la columna ya existe antes de agregarla para que la migración
 * sea segura si se corre dos veces (ej. ambientes mixtos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'email_verified_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')
                ->nullable()
                ->after('email')
                ->comment('Fecha de verificación de email. NULL = no verificado.');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'email_verified_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }
};
