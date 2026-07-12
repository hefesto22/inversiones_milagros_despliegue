<?php

declare(strict_types=1);

/**
 * Configuración del módulo de inventario.
 *
 * Consolida feature flags del refactor WAC Perpetuo (docs/AUDITORIA_VALUACION_2026-04-22.md).
 * Los flags son controlados vía .env para permitir cambios sin deploy (rollback instantáneo).
 *
 * Referencia de fases del refactor:
 *   Fase 2 (actual) — shadow_mode=true: dual-write a columnas wac_* en paralelo a legacy.
 *   Fase 4            — log_divergences=true: job nocturno compara wac vs legacy.
 *   Fase 5            — read_source='wac': lectura cambia a wac, legacy todavía se escribe.
 *   Fase 6            — legacy columns droppeadas, read_source='wac' permanente.
 */

return [

    'wac' => [

        /*
        |--------------------------------------------------------------------------
        | Shadow Mode (Dual-Write)
        |--------------------------------------------------------------------------
        |
        | Cuando está activo, cada operación sobre lotes (compra/venta/merma/
        | devolución) dispara eventos que el ActualizarWacListener captura para
        | escribir en las columnas wac_* del lote, en paralelo a la escritura
        | legacy.
        |
        | Apagarlo (false) desactiva instantáneamente el dual-write sin necesidad
        | de deploy. Útil como kill-switch si se detectan problemas en producción.
        |
        | Default: false → hasta que se ejecute el backfill (Fase 3), las columnas
        | wac_* están en NULL y activar el shadow_mode antes rompería invariantes.
        |
        */
        'shadow_mode' => env('INVENTARIO_WAC_SHADOW_MODE', false),

        /*
        |--------------------------------------------------------------------------
        | Fuente de Lectura del Costo de Inventario
        |--------------------------------------------------------------------------
        |
        | Controla de dónde leen los módulos (Filament, PDFs, reportes, ventas)
        | el costo del inventario:
        |   'legacy' → costo_por_huevo, costo_por_carton_facturado (columnas actuales)
        |   'wac'    → wac_costo_por_huevo, wac_costo_por_carton_facturado
        |
        | Durante Fases 2-4 permanece en 'legacy' — el sistema sigue funcionando
        | exactamente como antes. En Fase 5 cambia a 'wac' después de validación
        | de no-divergencia en Fase 4.
        |
        */
        'read_source' => env('INVENTARIO_WAC_READ_SOURCE', 'legacy'),

        /*
        |--------------------------------------------------------------------------
        | Log de Divergencias WAC vs Legacy
        |--------------------------------------------------------------------------
        |
        | Cuando está activo, cada escritura WAC compara el valor resultante
        | contra el valor legacy equivalente y loguea divergencias que excedan
        | la tolerancia. Costo de perfomance insignificante. Muy útil durante
        | Fase 4 (observación) para detectar bugs en el cálculo WAC antes del
        | corte de lectura.
        |
        */
        'log_divergences' => env('INVENTARIO_WAC_LOG_DIVERGENCES', false),

        /*
        |--------------------------------------------------------------------------
        | Tolerancia de Divergencia
        |--------------------------------------------------------------------------
        |
        | Tolerancia absoluta en Lempiras al comparar wac_costo_por_carton_facturado
        | vs costo_por_carton_facturado. Divergencias menores a este umbral se
        | consideran redondeo y no se loguean.
        |
        | Razón: la fórmula legacy acumula redondeos en costo_por_huevo que el
        | WAC evita usando DECIMAL(12,6). Una divergencia de 0.10L por cartón
        | es ruido, no un bug.
        |
        */
        'divergence_tolerance_lempiras' => (float) env('INVENTARIO_WAC_DIVERGENCE_TOLERANCE', 0.10),

    ],

    'ajustes' => [

        /*
        |--------------------------------------------------------------------------
        | Umbral de Aprobación Dual
        |--------------------------------------------------------------------------
        |
        | Cantidad de huevos (valor absoluto) por encima de la cual un ajuste
        | requiere aprobación del dueño antes de aplicarse. Ajustes menores se
        | aplican directamente por el encargado pero quedan en bitácora.
        |
        | Sugerido: 300 huevos (= 10 cartones 1x30). Permite que rotura cotidiana
        | y reclasificaciones pequeñas pasen rápido, pero discrepancias mayores
        | siempre pasen por la mesa del dueño.
        |
        */
        'umbral_aprobacion_huevos' => (float) env('AJUSTES_UMBRAL_APROBACION_HUEVOS', 300),

        /*
        |--------------------------------------------------------------------------
        | Porcentaje Máximo Ajustable por Lote
        |--------------------------------------------------------------------------
        |
        | Máximo porcentaje del lote que se puede ajustar en una sola operación.
        | Previene errores de captura catastróficos (ej. ajustar -100% por error).
        | Operaciones que exceden este % requieren autorización especial.
        |
        */
        'porcentaje_maximo_lote' => (float) env('AJUSTES_PCT_MAX_LOTE', 25),

        /*
        |--------------------------------------------------------------------------
        | Días Máximos para Crear Corrección de un Ajuste Aplicado
        |--------------------------------------------------------------------------
        |
        | Los ajustes aplicados son inmutables, pero un superadmin puede crear
        | un ajuste de corrección (tipo_movimiento=ajuste_correccion) que revierte
        | el efecto. Pasados estos días, ni el superadmin puede revertir — solo
        | queda el reporte histórico.
        |
        */
        'dias_max_correccion' => (int) env('AJUSTES_DIAS_CORRECCION', 30),

    ],

    'kardex' => [

        /*
        |--------------------------------------------------------------------------
        | Kardex Habilitado (kill-switch)
        |--------------------------------------------------------------------------
        |
        | Cuando está activo, cada movimiento de inventario (lote y bodega)
        | asienta una fila inmutable en movimientos_inventario vía el
        | RegistradorMovimientos. Apagarlo detiene el registro sin romper
        | ninguna operación de negocio — solo deja de escribirse el libro.
        |
        */
        'habilitado' => env('KARDEX_HABILITADO', true),

        /*
        |--------------------------------------------------------------------------
        | Modo Estricto
        |--------------------------------------------------------------------------
        |
        | false (rollout): un error al asentar en el Kardex se loguea como error
        |   y la operación de negocio (venta, carga, reempaque) continúa.
        | true (régimen): el asiento es parte de la transacción — si el Kardex
        |   no puede escribir, la operación completa se revierte. Exactitud
        |   garantizada por diseño.
        |
        | Mismo patrón de despliegue seguro usado en el shadow-mode del WAC:
        | arrancar en false, subir a true cuando kardex:verificar reporte cero
        | divergencias por ~2 semanas.
        |
        */
        'estricto' => env('KARDEX_ESTRICTO', false),

    ],

];
