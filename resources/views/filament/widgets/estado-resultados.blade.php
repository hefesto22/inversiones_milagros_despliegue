<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-200">
                    Estado de Resultados
                </h3>
                <p class="text-sm text-gray-400 mt-0.5">
                    Reporte financiero del periodo seleccionado
                </p>
            </div>

            <div class="flex items-center gap-3">
                {{-- Boton Ver PDF (abre en nueva pestana) --}}
                <a
                    href="{{ $this->getPdfUrl() }}"
                    target="_blank"
                    class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-lg
                           bg-white/5 text-gray-300 ring-1 ring-white/10
                           hover:bg-white/10 hover:text-white
                           transition-all duration-150"
                >
                    <x-heroicon-m-eye class="w-4 h-4" />
                    Vista Previa
                </a>

                {{-- Boton Descargar PDF --}}
                <a
                    href="{{ $this->getDownloadUrl() }}"
                    class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-lg
                           bg-primary-600 text-white
                           hover:bg-primary-500
                           transition-all duration-150"
                >
                    <x-heroicon-m-arrow-down-tray class="w-4 h-4" />
                    Descargar PDF
                </a>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>