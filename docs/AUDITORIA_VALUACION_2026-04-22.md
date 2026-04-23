# Auditoría de Valuación de Inventario — Lotes de Huevos

**Fecha:** 2026-04-22
**Autor:** Mauricio (Grupo Olympo)
**Tipo de documento:** Auditoría técnica + plan de remediación
**Rama de trabajo:** `feature/wac-perpetuo-inventario`
**Severidad:** 🔴 Crítica — afecta valuación financiera en producción
**Sistema afectado:** Huevería (Laravel 12 + Filament 3.3, MySQL/MariaDB en Hostinger)

---

## 1. Resumen ejecutivo

El sistema reporta un costo por cartón de huevo mediano de **78.15 Lempiras**, cuando las compras recientes de ese mismo producto han sido entre **70 y 75 Lempiras por cartón**. La diferencia (~5–11%) corresponde a una distorsión acumulativa en el cálculo del costo promedio ponderado que **nunca se ajusta** cuando el inventario sale del lote por ventas.

**Causa raíz:** el modelo `Lote` acumula el numerador (`costo_total_acumulado`) y el denominador (`huevos_facturados_acumulados`) a lo largo de toda la vida del lote, sin decrementarlos cuando los huevos se venden. El costo promedio resultante incluye compras históricas que ya salieron físicamente del stock.

**Solución propuesta:** migrar a **Moving Weighted Average Cost (WAC) Perpetuo** — el mismo patrón que ya se usa correctamente en `BodegaProducto::actualizarCostoPromedio()` para productos sin lote.

**Estrategia de despliegue:** refactor en 6 fases con *feature flags*, shadow mode, backfill verificado y deprecación gradual. Cero downtime, reversible en cualquier fase.

---

## 2. Evidencia cuantitativa del bug

Datos reales extraídos del dump de producción para el `producto_id = 12` (Huevo Mediano 1x30), Lote 3 (`LU-B1-P12`):

| Campo | Valor actual en DB | Interpretación |
|---|---:|---|
| `costo_total_acumulado` | 1,213,755.00 | Suma histórica de todas las compras al lote |
| `huevos_facturados_acumulados` | 465,930 | Huevos comprados a lo largo de la vida del lote |
| `costo_por_huevo` (calculado) | 2.605 | 1,213,755 / 465,930 |
| `costo_por_carton_facturado` | 78.15 | 2.605 × 30 |
| `cantidad_huevos_remanente` | 24,060 | Inventario **realmente disponible ahora** |
| Equivalente en cartones actuales | 802 | 24,060 / 30 |

**Observación clave:** el cálculo divide el costo acumulado de **465,930 huevos comprados en toda la historia** entre sí mismo — pero solo **24,060 huevos (5.2%)** siguen en inventario. El otro **94.8%** ya se vendió, y sin embargo sus compras viejas (a precios distintos) siguen pesando en el promedio actual.

**Valor del costo actual real** (si valuáramos solo lo que queda en stock, compras recientes 70–75 por cartón):
- Entre 56,140 L (a 70/cartón) y 60,150 L (a 75/cartón) para los 802 cartones en bodega.

**Valor contable actual según el sistema:**
- 802 × 78.15 = **62,696 L**

**Sobrevaluación estimada:** entre **2,546 y 6,556 Lempiras** solo en este lote, solo en huevo mediano. Con decenas de lotes activos, el impacto agregado es significativo.

---

## 3. Análisis de raíz — localización en el código

### 3.1 Archivo: `app/Models/Lote.php`

#### Problema #1 — `agregarCompra()` (líneas 198–290)

El método es correcto conceptualmente para **la primera compra** de un lote, pero incorrecto para compras subsecuentes porque los acumuladores nunca se "resetean" contra las salidas de inventario:

```php
// Línea 221–228
$huevosFacturadosActuales = $this->huevos_facturados_acumulados ?? 0;
$costoActual = $this->costo_total_acumulado ?? 0;

$costoTotalNuevo = $costoActual + $costoCompra;
$huevosFacturadosTotales = $huevosFacturadosActuales + $huevosFacturadosNuevos;

$nuevoCostoPorHuevo = $huevosFacturadosTotales > 0
    ? $costoTotalNuevo / $huevosFacturadosTotales
    : 0;
```

**Lo que hace:** suma la compra nueva sobre el histórico completo.
**Lo que debería hacer:** combinar la compra nueva con el **inventario actual valuado**, no con el histórico.

Formula correcta (Moving WAC):
```
nuevo_costo_por_huevo = (stock_actual × costo_actual + compra_nueva_valor)
                       / (stock_actual + compra_nueva_cantidad)
```

#### Problema #2 — `reducirRemanente()` (líneas 457–484)

```php
$this->cantidad_huevos_remanente -= $cantidadHuevos;
```

Este método se llama cuando se vende inventario. Solo reduce el stock físico, pero **no ajusta los acumuladores de costo**. Como resultado, `costo_total_acumulado` y `huevos_facturados_acumulados` quedan reflejando la historia completa, sin que las ventas los reduzcan nunca.

**Este es el bug raíz.** En un sistema WAC perpetuo correcto, las ventas reducen proporcionalmente el valor del inventario, manteniendo el costo por unidad estable.

#### Problema #3 — `registrarMerma()` (líneas 395–411)

```php
if ($perdidaReal > 0) {
    $this->huevos_facturados_acumulados = max(0, $this->huevos_facturados_acumulados - $perdidaReal);
    if ($this->huevos_facturados_acumulados > 0) {
        $this->costo_por_huevo = round($this->costo_total_acumulado / $this->huevos_facturados_acumulados, 4);
        $this->costo_por_carton_facturado = round($this->costo_por_huevo * ($this->huevos_por_carton ?? 30), 4);
    }
}
```

**Bug secundario crítico:** decrementa el denominador (`huevos_facturados_acumulados`) pero **no el numerador** (`costo_total_acumulado`). Resultado: cada merma **inflaciona artificialmente** el costo por huevo.

Ejemplo numérico:
- Antes de la merma: 1,000 L / 500 huevos = 2.00 L/huevo
- Después de merma de 100 huevos: 1,000 L / 400 huevos = **2.50 L/huevo** ← subió 25% sin razón

---

## 4. Patrón correcto ya existente en el código

El mismo equipo ya implementó WAC correctamente en otro lugar del sistema. Esto es **buena noticia**: el patrón ya está validado y solo hay que replicarlo.

### Referencia: `app/Models/BodegaProducto.php::actualizarCostoPromedio()` (líneas 111–150)

Este método, usado para productos **sin sistema de lotes**, implementa Moving WAC correctamente:

```php
// Pseudocódigo de lo que hace (ver archivo real para implementación completa):
$valorInventarioActual = $stockActual * $costoPromedioActual;
$valorCompraNueva = $cantidadCompra * $costoCompra;
$stockNuevo = $stockActual + $cantidadCompra;

$nuevoCostoPromedio = ($valorInventarioActual + $valorCompraNueva) / $stockNuevo;
```

**Acción de remediación:** aplicar exactamente este patrón a nivel de `Lote`, con las adaptaciones necesarias para manejar huevos facturados vs. huevos de regalo (ver plan en sección 6).

---

## 5. Impacto del bug

### 5.1 Impacto financiero directo

- **Sobrevaluación contable** del inventario en libros. Afecta balance, estados de resultados, reportes SAR.
- **Decisiones de pricing incorrectas.** Si el dueño fija precio de venta en función del costo reportado, está fijando precios más altos de lo necesario → pierde ventas por no ser competitivo.
- **Márgenes reportados** engañosamente bajos. Los reportes de rentabilidad muestran menos ganancia de la real.

### 5.2 Impacto fiscal (contexto hondureño)

- Cálculo de **ISV** sobre costos distorsionados en informes internos (aunque el ISV final se calcula sobre venta, no costo).
- Reportes de **margen** para la SAR usan esta base: distorsión sistemática en todos los informes.

### 5.3 Impacto en confianza del sistema

- El dueño ya notó la discrepancia. Si no se corrige, **se pierde confianza en los números del sistema** — y una vez perdida, se arrastra a toda decisión futura.

---

## 6. Solución propuesta — Plan en 6 fases

> Todas las fases son **aditivas y reversibles**. En cualquier momento se puede volver al comportamiento anterior sin migración de datos.

### Fase 0 — Auditoría y setup (hoy, en curso)

- ✅ Documento de auditoría (este archivo)
- ✅ Rama `feature/wac-perpetuo-inventario` creada
- ✅ Entorno de testing contra MySQL configurado (no SQLite)
- ⏳ Pendiente: tests de línea base que capturen el comportamiento actual

### Fase 1 — Migración de columnas (aditiva, no destructiva)

Crear columnas nuevas en `lotes` sin modificar las existentes:
- `wac_costo_inventario` (decimal 14,4) — valor monetario del inventario actual
- `wac_huevos_inventario` (integer) — cantidad física actual valorada
- `wac_costo_por_huevo` (decimal 10,6) — costo por unidad bajo WAC perpetuo
- `wac_ultima_actualizacion` (timestamp) — auditoría

**Las columnas legacy siguen vivas** durante toda la migración.

### Fase 2 — Servicio WAC + shadow mode

Crear `App\Services\Inventario\WacService` con la lógica correcta, e integrarlo en `Lote::agregarCompra()`, `Lote::reducirRemanente()`, `Lote::registrarMerma()` y `Lote::devolverHuevos()` en modalidad **shadow**:

- El código legacy sigue ejecutándose y escribiendo a las columnas legacy.
- El nuevo código WAC escribe en paralelo a las columnas `wac_*`.
- Lectura: un `CostoInventarioReader` con feature flag decide qué columna usar (`legacy` o `wac`).
- Un job `ReconciliarWacVsLegacyJob` corre cada hora comparando ambos valores y registra las diferencias para análisis.

**Sin cambios visibles para el usuario final.** El sistema se comporta idéntico al de hoy.

### Fase 3 — Backfill verificado

Comando `php artisan wac:backfill --dry-run` que:
1. Lee la tabla `HistorialCompraLote` (inmutable, autoritativa).
2. Reconstruye el estado WAC correcto para cada lote reproduciendo la historia paso a paso.
3. En modo `--dry-run`: solo reporta qué escribiría.
4. Modo ejecución real: popula las columnas `wac_*` con valores correctos para lotes existentes.

### Fase 4 — Observación en shadow (1 semana)

Con shadow mode activo y backfill hecho, observamos durante ~7 días:
- Discrepancias reportadas por el job de reconciliación.
- Casos edge que el backfill no cubrió.
- Performance del nuevo código bajo carga real.

### Fase 5 — Cambio de lectura (feature flag)

Cuando las métricas indican cero discrepancias durante una semana:
- Se cambia `config/inventario.php` → `lectura_activa` de `legacy` a `wac`.
- El sistema empieza a **mostrar** los valores WAC correctos.
- El código legacy sigue escribiendo (por si hay que revertir).

### Fase 6 — Deprecación (después de 2 semanas estables)

- Se remueve el código legacy de `Lote`.
- Se eliminan las columnas legacy (migración final).
- Se documenta el sistema WAC como la fuente única de verdad.

---

## 7. Riesgos identificados y mitigaciones

| # | Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | Bug en el nuevo `WacService` que corrompe valores | Media | Alto | Shadow mode: nunca se confía en WAC hasta que coincida con legacy por 1 semana |
| R2 | Backfill incorrecto por data sucia en `HistorialCompraLote` | Media | Alto | Dry-run obligatorio; revisión manual de muestras antes de ejecución real |
| R3 | Race condition en compras concurrentes | Baja | Alto | `lockForUpdate()` ya presente en `agregarCompra`; se mantiene y extiende a WAC |
| R4 | Deploy en producción rompe reportes PDF/Excel | Baja | Medio | Feature flag permite rollback instantáneo cambiando una línea de config |
| R5 | Dependencia oculta en valor de `costo_total_acumulado` en otros módulos | Media | Medio | Grep exhaustivo del código durante Fase 2 antes de activar lectura WAC |
| R6 | Diferencia fiscal entre valuación antes/después del cambio | Baja | Medio | Documentar la fecha del cambio; mantener snapshot histórico; consultar con contador si valores oficiales cambian significativamente |

---

## 8. Deuda técnica adyacente detectada

Durante el análisis se identificaron problemas no bloqueantes pero que deben documentarse para próximos ciclos:

### 8.1 Tests con sintaxis deprecada de PHPUnit

**Ubicación:** `tests/Unit/Models/VentaTest.php` y otros
**Problema:** usan `/** @test */` en docblocks en vez de atributos PHP `#[Test]`.
**Impacto:** PHPUnit 12 los va a rechazar; al actualizar Pest, tests dejarán de ejecutarse.
**Acción:** backlog separado — migrar a atributos cuando se actualice Pest.

### 8.2 Dependencia `barryvdh/laravel-dompdf`

**Problema:** el stack oficial de Grupo Olympo usa `spatie/browsershot` para PDFs.
**Impacto:** inconsistencia con otros proyectos; DomPDF tiene limitaciones con CSS moderno.
**Acción:** backlog separado — migrar generación de PDFs a Browsershot en iteración futura.

### 8.3 Test boilerplate roto

**Ubicación:** `tests/Feature/ExampleTest.php`
**Problema:** asume que `GET /` devuelve 200, pero la app redirige (302).
**Impacto:** falla ruidoso en cada `php artisan test`. No es crítico pero molesta.
**Acción:** ajustar o remover cuando tengamos suite real de feature tests.

---

## 9. Criterios de éxito del refactor

El refactor se considera exitoso cuando:

1. ✅ El costo reportado de un lote refleja el **costo ponderado del inventario actualmente en stock**, no el histórico de vida.
2. ✅ Ventas y mermas reducen proporcionalmente el valor del inventario sin distorsionar el costo unitario.
3. ✅ Cero downtime durante todas las fases del deploy.
4. ✅ El backfill reproduce los valores WAC correctos para todos los lotes activos sin pérdida de datos históricos.
5. ✅ Tests automatizados cubren: caso huevo mediano (este bug), casos edge de merma, race conditions en compras concurrentes.
6. ✅ Feature flag permite revertir a legacy en < 1 minuto si algo falla en producción.

---

## 10. Apéndice — Comandos de verificación post-refactor

Una vez completado el refactor, estos comandos deben arrojar resultados consistentes:

```sql
-- Verificar que el costo por cartón del Lote 3 (huevo mediano)
-- esté en el rango esperado de 70–75 Lempiras
SELECT
    id,
    numero_lote,
    wac_costo_por_huevo * 30 AS costo_carton_wac,
    costo_por_carton_facturado AS costo_carton_legacy
FROM lotes
WHERE producto_id = 12 AND estado = 'disponible';
```

```bash
# Correr el test específico del caso huevo mediano
php artisan test --filter=CasoHuevoMedianoTest
```

---

## 11. Historial de revisiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-04-22 | Mauricio / asistente técnico | Documento inicial de auditoría |

---

*Este documento acompaña el primer commit del refactor para dejar contexto permanente en el historial git.*
