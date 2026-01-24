<div class="space-y-4">
    <div class="flex items-start gap-3 p-4 bg-danger-50 dark:bg-danger-900/20 rounded-lg">
        <x-heroicon-o-exclamation-circle class="w-6 h-6 text-danger-500 flex-shrink-0 mt-0.5" />
        <div>
            <p class="font-medium text-danger-700 dark:text-danger-400">
                Este gasto fue rechazado
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                {{ $gasto->motivo_rechazo }}
            </p>
        </div>
    </div>

    <div class="text-sm text-gray-500 dark:text-gray-400 space-y-1">
        @if($gasto->aprobador)
            <p><span class="font-medium">Rechazado por:</span> {{ $gasto->aprobador->name }}</p>
        @endif
        @if($gasto->aprobado_at)
            <p><span class="font-medium">Fecha:</span> {{ $gasto->aprobado_at->format('d/m/Y h:i a') }}</p>
        @endif
    </div>
</div>