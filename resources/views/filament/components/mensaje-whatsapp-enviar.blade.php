<div class="space-y-4">
    {{-- Mensaje formateado --}}
    <div class="p-4 bg-gray-100 dark:bg-gray-800 rounded-lg">
        <pre id="mensaje-whatsapp-content" class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300 font-mono">{{ $gasto->generarMensajeWhatsApp() }}</pre>
    </div>

    {{-- Botón Copiar --}}
    <div class="flex justify-center">
        <button
            type="button"
            onclick="copiarMensajeWhatsApp()"
            class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition-colors"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
            </svg>
            <span id="btn-copiar-text">Copiar Mensaje</span>
        </button>
    </div>

    {{-- Instrucciones --}}
    <div class="p-3 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-700 rounded-lg">
        <div class="flex items-start gap-2 text-warning-700 dark:text-warning-400 text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0 mt-0.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
            <div>
                <p class="font-medium">Pasos:</p>
                <ol class="list-decimal list-inside mt-1 space-y-1">
                    <li>Presiona "Copiar Mensaje"</li>
                    <li>Presiona "Abrir WhatsApp"</li>
                    <li>Pega el mensaje en el grupo</li>
                    <li>Adjunta la foto del comprobante</li>
                    <li>Envía</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function copiarMensajeWhatsApp() {
    var mensaje = document.getElementById('mensaje-whatsapp-content').innerText;
    var btnText = document.getElementById('btn-copiar-text');
    
    // Intentar con clipboard API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(mensaje).then(function() {
            btnText.innerText = '✓ Copiado!';
            btnText.parentElement.classList.remove('bg-primary-600', 'hover:bg-primary-700');
            btnText.parentElement.classList.add('bg-green-600', 'hover:bg-green-700');
            setTimeout(function() {
                btnText.innerText = 'Copiar Mensaje';
                btnText.parentElement.classList.remove('bg-green-600', 'hover:bg-green-700');
                btnText.parentElement.classList.add('bg-primary-600', 'hover:bg-primary-700');
            }, 3000);
        }).catch(function() {
            copiarFallback(mensaje, btnText);
        });
    } else {
        copiarFallback(mensaje, btnText);
    }
}

function copiarFallback(mensaje, btnText) {
    var textarea = document.createElement('textarea');
    textarea.value = mensaje;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        btnText.innerText = '✓ Copiado!';
        btnText.parentElement.classList.remove('bg-primary-600', 'hover:bg-primary-700');
        btnText.parentElement.classList.add('bg-green-600', 'hover:bg-green-700');
        setTimeout(function() {
            btnText.innerText = 'Copiar Mensaje';
            btnText.parentElement.classList.remove('bg-green-600', 'hover:bg-green-700');
            btnText.parentElement.classList.add('bg-primary-600', 'hover:bg-primary-700');
        }, 3000);
    } catch (err) {
        btnText.innerText = 'Error al copiar';
        setTimeout(function() {
            btnText.innerText = 'Copiar Mensaje';
        }, 2000);
    }
    
    document.body.removeChild(textarea);
}
</script>