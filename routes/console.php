<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 🆕 Actualizar precios de productos cada lunes a las 00:01
Schedule::command('precios:actualizar-semanales')
    ->weeklyOn(1, '00:01') // 1 = Lunes, 00:01 AM
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info('✅ Precios semanales actualizados correctamente');
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('❌ Error al actualizar precios semanales');
    });
