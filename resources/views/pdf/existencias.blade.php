@php
    /*
    |--------------------------------------------------------------------------
    | Existencias de Inventario - PDF (DomPDF)
    |--------------------------------------------------------------------------
    | Qué hay, cuánto y dónde está, al momento del corte.
    |
    | Reglas de este archivo:
    |   - Sin costos ni valores: es una hoja para contar físico, no financiera.
    |   - Las cantidades vienen ya formateadas desde ExistenciasService.
    |   - CSS compatible DomPDF: nada de flexbox, grid ni :not() — DomPDF
    |     descarta la regla entera en silencio (ver incidente de julio).
    |--------------------------------------------------------------------------
    */

    $empresaNombre = $empresa?->nombre ?? 'OPOA';
    $empresaRtn = $empresa?->rtn ?? '';

    $estadosViaje = [
        'planificado' => 'Planificado',
        'cargando' => 'Cargando',
        'en_ruta' => 'En Ruta',
        'recargando' => 'Recargando',
        'regresando' => 'Regresando',
        'descargando' => 'Descargando',
        'liquidando' => 'Liquidando',
    ];
@endphp
    <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Existencias de Inventario</title>
    <style>
        @page {
            margin: 90px 35px 60px 35px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
        }

        header {
            position: fixed;
            top: -70px;
            left: 0;
            right: 0;
            height: 60px;
        }

        footer {
            position: fixed;
            bottom: -40px;
            left: 0;
            right: 0;
            height: 30px;
            font-size: 8px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
        }

        .footer-left {
            float: left;
        }

        .footer-right {
            float: right;
        }

        /* Numeración de página con contador CSS: DomPDF lo soporta y no
           depende de isPhpEnabled, que en este hosting está apagado. */
        .pagenum:before {
            content: counter(page);
        }

        .company-name {
            font-size: 15px;
            font-weight: bold;
            color: #111827;
        }

        .company-meta {
            font-size: 8px;
            color: #6b7280;
        }

        .doc-title {
            font-size: 13px;
            font-weight: bold;
            text-align: right;
            color: #111827;
        }

        .doc-meta {
            font-size: 8px;
            text-align: right;
            color: #6b7280;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: top;
            border: none;
            padding: 0;
        }

        h2.seccion {
            font-size: 11px;
            font-weight: bold;
            color: #111827;
            margin: 16px 0 6px 0;
            padding-bottom: 3px;
            border-bottom: 2px solid #111827;
        }

        h3.subseccion {
            font-size: 9px;
            font-weight: bold;
            color: #374151;
            margin: 10px 0 4px 0;
        }

        table.datos {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        table.datos th {
            background-color: #111827;
            color: #ffffff;
            font-size: 8px;
            font-weight: bold;
            text-align: left;
            padding: 5px 6px;
        }

        table.datos td {
            font-size: 8.5px;
            padding: 4px 6px;
            border-bottom: 1px solid #e5e7eb;
        }

        table.datos tr.par td {
            background-color: #f9fafb;
        }

        .num {
            text-align: right;
        }

        .centro {
            text-align: center;
        }

        .fuerte {
            font-weight: bold;
        }

        .total-fila td {
            background-color: #f3f4f6;
            font-weight: bold;
            border-top: 2px solid #111827;
            border-bottom: none;
        }

        .apagado {
            color: #9ca3af;
        }

        .aviso {
            border: 1px solid #f59e0b;
            background-color: #fffbeb;
            padding: 7px 9px;
            margin-bottom: 10px;
        }

        .aviso-titulo {
            font-weight: bold;
            font-size: 9px;
            color: #92400e;
            margin-bottom: 3px;
        }

        .aviso p {
            margin: 2px 0;
            font-size: 8.5px;
            color: #78350f;
        }

        .vacio {
            text-align: center;
            padding: 25px;
            color: #6b7280;
            font-size: 10px;
        }

        .nota {
            font-size: 8px;
            color: #6b7280;
            margin-top: 3px;
        }

        .viaje-cabecera {
            background-color: #eef2ff;
            padding: 5px 7px;
            font-size: 9px;
            font-weight: bold;
            color: #312e81;
            margin-top: 8px;
        }

        .firmas {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
        }

        .firmas td {
            width: 33%;
            text-align: center;
            font-size: 8px;
            color: #6b7280;
            padding-top: 28px;
            border-top: none;
        }

        .linea-firma {
            border-top: 1px solid #9ca3af;
            padding-top: 4px;
            margin: 0 12px;
        }
    </style>
</head>
<body>

<header>
    <table class="header-table">
        <tr>
            <td>
                <span class="company-name">{{ $empresaNombre }}</span><br>
                @if ($empresaRtn)
                    <span class="company-meta">RTN: {{ $empresaRtn }}</span>
                @endif
            </td>
            <td>
                <div class="doc-title">EXISTENCIAS DE INVENTARIO</div>
                <div class="doc-meta">
                    Corte: {{ $generado_en->format('d/m/Y') }} a las {{ $generado_en->format('h:i A') }}
                </div>
            </td>
        </tr>
    </table>
</header>

<footer>
    <span class="footer-left">{{ $empresaNombre }} — Existencias al {{ $generado_en->format('d/m/Y H:i') }}</span>
    <span class="footer-right">Página <span class="pagenum"></span></span>
</footer>

@if (! empty($alertas))
    <div class="aviso">
        <div class="aviso-titulo">Antes de contar, tome en cuenta:</div>
        @foreach ($alertas as $alerta)
            <p>• {{ $alerta }}</p>
        @endforeach
    </div>
@endif

@if (! $hay_existencias)
    <div class="vacio">
        No hay existencias registradas en bodegas, lotes ni camiones al momento del corte.
    </div>
@else

    {{-- ============================================================
         1. CONSOLIDADO — cuánto hay de cada cosa y dónde
         ============================================================ --}}
    <h2 class="seccion">Resumen por producto</h2>

    <table class="datos">
        <thead>
        <tr>
            <th style="width: 34%;">Producto</th>
            <th style="width: 18%;">En bodegas</th>
            <th style="width: 18%;">En lotes</th>
            <th style="width: 16%;">En camiones</th>
            <th style="width: 14%;">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($consolidado as $indice => $fila)
            <tr class="{{ $indice % 2 === 1 ? 'par' : '' }}">
                <td class="fuerte">{{ $fila['producto'] }}</td>
                <td>{{ $fila['bodega'] }}</td>
                <td>{{ $fila['lotes'] }}</td>
                <td>{{ $fila['camiones'] }}</td>
                <td class="fuerte">{{ $fila['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p class="nota">
        El huevo se cuenta en cartones y sueltos. En los lotes está suelto; en bodega y camión va empacado.
    </p>

    {{-- ============================================================
         2. LOTES
         ============================================================ --}}
    <h2 class="seccion">Detalle: huevo en lotes</h2>

    @if ($lotes->isEmpty())
        <p class="apagado">Sin lotes con remanente.</p>
    @else
        <table class="datos">
            <thead>
            <tr>
                <th style="width: 18%;">Lote</th>
                <th style="width: 27%;">Producto</th>
                <th style="width: 22%;">Bodega</th>
                <th style="width: 13%;">Estado</th>
                <th style="width: 20%;">Existencia</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lotes as $indice => $lote)
                <tr class="{{ $indice % 2 === 1 ? 'par' : '' }}">
                    <td class="fuerte">{{ $lote['numero_lote'] }}</td>
                    <td>{{ $lote['producto'] }}</td>
                    <td>{{ $lote['bodega'] }}</td>
                    <td>{{ $lote['estado'] }}</td>
                    <td class="fuerte">{{ $lote['texto'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- ============================================================
         3. BODEGAS
         ============================================================ --}}
    <h2 class="seccion">Detalle: producto empacado en bodegas</h2>

    @if ($bodegas->isEmpty())
        <p class="apagado">Sin producto empacado en bodegas.</p>
    @else
        @foreach ($bodegas->groupBy('bodega') as $nombreBodega => $filas)
            <h3 class="subseccion">{{ $nombreBodega }}</h3>

            <table class="datos">
                <thead>
                <tr>
                    <th style="width: 55%;">Producto</th>
                    <th style="width: 20%;">Unidad</th>
                    <th style="width: 25%;">Existencia</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($filas->values() as $indice => $fila)
                    <tr class="{{ $indice % 2 === 1 ? 'par' : '' }}">
                        <td>{{ $fila['producto'] }}</td>
                        <td>{{ $fila['unidad'] }}</td>
                        <td class="fuerte">{{ $fila['texto'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    @endif

    {{-- ============================================================
         4. CAMIONES
         ============================================================ --}}
    <h2 class="seccion">Detalle: carga a bordo de los camiones</h2>

    @if ($camiones->isEmpty())
        <p class="apagado">Ningún camión lleva carga pendiente.</p>
    @else
        @foreach ($camiones as $viaje)
            <div class="viaje-cabecera">
                Viaje {{ $viaje['numero_viaje'] }} — Camión {{ $viaje['camion'] }} — Chofer {{ $viaje['chofer'] }}
                ({{ $estadosViaje[$viaje['estado']] ?? $viaje['estado'] }})
            </div>

            <table class="datos">
                <thead>
                <tr>
                    <th style="width: 30%;">Producto</th>
                    <th style="width: 12%;" class="num">Cargado</th>
                    <th style="width: 12%;" class="num">Vendido</th>
                    <th style="width: 11%;" class="num">Merma</th>
                    <th style="width: 12%;" class="num">Devuelto</th>
                    <th style="width: 23%;">Queda a bordo</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($viaje['productos'] as $indice => $fila)
                    <tr class="{{ $indice % 2 === 1 ? 'par' : '' }}">
                        <td>{{ $fila['producto'] }}</td>
                        <td class="num">{{ number_format($fila['cargado'], 0) }}</td>
                        <td class="num">{{ number_format($fila['vendido'], 0) }}</td>
                        <td class="num">{{ number_format($fila['merma'], 0) }}</td>
                        <td class="num">{{ number_format($fila['devuelto'], 0) }}</td>
                        <td class="fuerte">{{ $fila['texto'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach

        <p class="nota">
            Cargado, vendido, merma y devuelto van en la unidad de la carga ({{ $camiones->first()['productos'][0]['unidad'] ?? 'unidades' }} y similares).
            La última columna es lo que debe estar físicamente arriba del camión.
        </p>
    @endif

    <table class="firmas">
        <tr>
            <td>
                <div class="linea-firma">Contó</div>
            </td>
            <td>
                <div class="linea-firma">Revisó</div>
            </td>
            <td>
                <div class="linea-firma">Autorizó</div>
            </td>
        </tr>
    </table>
@endif

</body>
</html>
