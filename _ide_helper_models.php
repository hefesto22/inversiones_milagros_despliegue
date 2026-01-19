<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $codigo
 * @property string|null $ubicacion
 * @property int $activo
 * @property int $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Compra> $compras
 * @property-read int|null $compras_count
 * @property-read \App\Models\User $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodegaProducto> $productos
 * @property-read int|null $productos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReempaqueProducto> $reempaqueProductos
 * @property-read int|null $reempaque_productos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Reempaque> $reempaques
 * @property-read int|null $reempaques_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $usuarios
 * @property-read int|null $usuarios_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereCodigo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereUbicacion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bodega whereUpdatedBy($value)
 */
	class Bodega extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $bodega_id
 * @property int $producto_id
 * @property numeric $stock
 * @property numeric $stock_reservado Stock apartado (pagado pero no entregado)
 * @property numeric|null $stock_minimo
 * @property numeric $costo_promedio_actual Costo promedio ponderado (WAC) - se actualiza con cada entrada de stock
 * @property numeric|null $precio_venta_sugerido Precio de venta = costo_promedio_actual + margen (se actualiza automáticamente)
 * @property bool $activo
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\Producto $producto
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereCostoPromedioActual($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto wherePrecioVentaSugerido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereStockMinimo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereStockReservado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaProducto whereUpdatedAt($value)
 */
	class BodegaProducto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $bodega_id
 * @property int $user_id
 * @property string|null $rol
 * @property int $activo
 * @property int $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\User $creador
 * @property-read \App\Models\User $usuario
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereRol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BodegaUser whereUserId($value)
 */
	class BodegaUser extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $codigo Código interno ej: CAM-001
 * @property string $placa
 * @property int $bodega_id
 * @property bool $activo
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\CamionChofer|null $asignacionActiva
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CamionChofer> $asignacionesChofer
 * @property-read int|null $asignaciones_chofer_count
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CamionProducto> $productos
 * @property-read int|null $productos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Viaje> $viajes
 * @property-read int|null $viajes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion activos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion conChofer()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion deBodega(int $bodegaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion disponibles()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion sinChofer()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereCodigo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion wherePlaca($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Camion withoutTrashed()
 */
	class Camion extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $camion_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $fecha_asignacion
 * @property \Illuminate\Support\Carbon|null $fecha_fin
 * @property bool $activo
 * @property int|null $asignado_por
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $asignadoPor
 * @property-read \App\Models\Camion $camion
 * @property-read \App\Models\User $chofer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer activas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer delCamion(int $camionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer delChofer(int $userId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereAsignadoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereCamionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereFechaAsignacion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereFechaFin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionChofer whereUserId($value)
 */
	class CamionChofer extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $camion_id
 * @property int|null $chofer_id
 * @property int|null $viaje_id Si el gasto fue durante un viaje específico
 * @property string $tipo_gasto Tipo de gasto
 * @property \Illuminate\Support\Carbon $fecha
 * @property numeric $monto
 * @property string|null $descripcion
 * @property numeric|null $litros Litros de combustible (solo para gasolina)
 * @property numeric|null $precio_por_litro Precio por litro (solo para gasolina)
 * @property numeric|null $kilometraje Kilometraje al momento del gasto
 * @property string|null $proveedor Gasolinera, taller, etc.
 * @property bool $tiene_factura ¿El gasto tiene factura?
 * @property bool $enviado_whatsapp ¿Se envió comprobante por WhatsApp?
 * @property \Illuminate\Support\Carbon|null $enviado_whatsapp_at Fecha/hora cuando se envió por WhatsApp
 * @property string $estado
 * @property string|null $motivo_rechazo
 * @property int|null $aprobado_por
 * @property \Illuminate\Support\Carbon|null $aprobado_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\User|null $aprobador
 * @property-read \App\Models\Camion $camion
 * @property-read \App\Models\User|null $chofer
 * @property-read \App\Models\User|null $creador
 * @property-read bool $es_gasolina
 * @property-read string $estado_label
 * @property-read string $tipo_gasto_label
 * @property-read \App\Models\Viaje|null $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto aprobados()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto delCamion(int $camionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto delChofer(int $choferId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto delMes(int $anio, int $mes)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto entreFechas($desde, $hasta)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto pendientes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto rechazados()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto tipoGasolina()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereAprobadoAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereAprobadoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereCamionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereChoferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereEnviadoWhatsapp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereEnviadoWhatsappAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereFecha($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereKilometraje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereLitros($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereMonto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereMotivoRechazo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto wherePrecioPorLitro($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereProveedor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereTieneFactura($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereTipoGasto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto whereViajeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionGasto withoutTrashed()
 */
	class CamionGasto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $camion_id
 * @property int $producto_id
 * @property numeric $stock Stock actual en el camión
 * @property numeric $costo_promedio Costo promedio del producto
 * @property numeric $precio_venta_sugerido Precio mínimo sugerido de venta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Camion $camion
 * @property-read \App\Models\Producto $producto
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto conStock()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto delCamion(int $camionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereCamionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereCostoPromedio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto wherePrecioVentaSugerido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CamionProducto whereUpdatedAt($value)
 */
	class CamionProducto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property bool $aplica_isv Si los productos incluyen ISV en precio de compra (15%)
 * @property bool $activo
 * @property int $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\User $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Producto> $productos
 * @property-read int|null $productos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Unidad> $unidades
 * @property-read int|null $unidades_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereAplicaIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Categoria whereUpdatedBy($value)
 */
	class Categoria extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $categoria_id
 * @property int $unidad_id
 * @property int $activo
 * @property int $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\Categoria $categoria
 * @property-read \App\Models\User $creador
 * @property-read \App\Models\Unidad $unidad
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereCategoriaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CategoriaUnidad whereUpdatedBy($value)
 */
	class CategoriaUnidad extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $categoria_id
 * @property int|null $unidad_id
 * @property numeric $comision_normal Comisión cuando vende >= precio sugerido
 * @property numeric $comision_reducida Comisión cuando vende < precio sugerido pero > costo
 * @property \Illuminate\Support\Carbon $vigente_desde
 * @property \Illuminate\Support\Carbon|null $vigente_hasta
 * @property bool $activo
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Categoria $categoria
 * @property-read \App\Models\User $chofer
 * @property-read \App\Models\User|null $creadoPor
 * @property-read \App\Models\Unidad|null $unidad
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig activas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig deCategoria(int $categoriaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig delChofer(int $userId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig vigentes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereCategoriaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereComisionNormal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereComisionReducida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereVigenteDesde($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionConfig whereVigenteHasta($value)
 */
	class ChoferComisionConfig extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $producto_id
 * @property numeric $comision_normal
 * @property numeric $comision_reducida
 * @property \Illuminate\Support\Carbon $vigente_desde
 * @property \Illuminate\Support\Carbon|null $vigente_hasta
 * @property bool $activo
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $chofer
 * @property-read \App\Models\User|null $creador
 * @property-read \App\Models\Producto $producto
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto activas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto delChofer(int $userId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto delProducto(int $productoId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto vigentes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereComisionNormal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereComisionReducida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereVigenteDesde($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferComisionProducto whereVigenteHasta($value)
 */
	class ChoferComisionProducto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property numeric $saldo Positivo = le debemos, Negativo = nos debe
 * @property numeric $total_comisiones_historico Total de comisiones ganadas histórico
 * @property numeric $total_cobros_historico Total cobrado por devoluciones histórico
 * @property numeric $total_pagado_historico Total que se le ha pagado
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $chofer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChoferCuentaMovimiento> $movimientos
 * @property-read int|null $movimientos_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta conSaldoNegativo()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta conSaldoPositivo()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereSaldo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereTotalCobrosHistorico($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereTotalComisionesHistorico($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereTotalPagadoHistorico($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuenta whereUserId($value)
 */
	class ChoferCuenta extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property string $tipo
 * @property numeric $monto Siempre positivo, el tipo indica si suma o resta
 * @property numeric $saldo_anterior
 * @property numeric $saldo_nuevo
 * @property int|null $viaje_id
 * @property int|null $liquidacion_id
 * @property string|null $concepto
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $chofer
 * @property-read \App\Models\User|null $creador
 * @property-read \App\Models\Liquidacion|null $liquidacion
 * @property-read \App\Models\Viaje|null $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento ajustes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento cobros()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento comisiones()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento deLiquidacion(int $liquidacionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento delChofer(int $userId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento delPeriodo($fechaInicio, $fechaFin)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento delViaje(int $viajeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento pagos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereConcepto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereLiquidacionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereMonto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereSaldoAnterior($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereSaldoNuevo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereTipo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ChoferCuentaMovimiento whereViajeId($value)
 */
	class ChoferCuentaMovimiento extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string|null $direccion
 * @property string|null $email
 * @property string $tipo
 * @property numeric $limite_credito Límite máximo de crédito permitido
 * @property numeric $saldo_pendiente Deuda actual del cliente
 * @property int $dias_credito Días de plazo para pagar (0 = solo contado)
 * @property bool $acepta_devolucion Si se le acepta devolución por daño
 * @property numeric $porcentaje_devolucion_max % máximo de devolución permitido
 * @property int $dias_devolucion Días máximos para aceptar devolución (ej: 3 días)
 * @property string|null $notas_acuerdo Acuerdos especiales con el cliente
 * @property bool $estado
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Devolucion> $devoluciones
 * @property-read int|null $devoluciones_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClienteProducto> $preciosCliente
 * @property-read int|null $precios_cliente_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Producto> $productos
 * @property-read int|null $productos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Venta> $ventas
 * @property-read int|null $ventas_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente activos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente conDeuda()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente conDeudaVencida()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente distribuidores()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente mayoristas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente minoristas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente porTipo(string $tipo)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente ruta()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereAceptaDevolucion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereDiasCredito($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereDiasDevolucion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereDireccion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereLimiteCredito($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereNotasAcuerdo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente wherePorcentajeDevolucionMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereRtn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereSaldoPendiente($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereTelefono($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereTipo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cliente withoutTrashed()
 */
	class Cliente extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cliente_id
 * @property int $producto_id
 * @property numeric|null $ultimo_precio_venta Último precio SIN ISV
 * @property numeric|null $ultimo_precio_con_isv Último precio CON ISV
 * @property numeric|null $cantidad_ultima_venta Cantidad de última venta
 * @property \Illuminate\Support\Carbon|null $fecha_ultima_venta
 * @property int $total_ventas Cantidad de veces que se le ha vendido
 * @property numeric $cantidad_total_vendida Total de unidades vendidas
 * @property-read \App\Models\Cliente $cliente
 * @property-read \App\Models\Producto $producto
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereCantidadTotalVendida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereCantidadUltimaVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereClienteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereFechaUltimaVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereTotalVentas($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereUltimoPrecioConIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ClienteProducto whereUltimoPrecioVenta($value)
 */
	class ClienteProducto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $proveedor_id
 * @property int $bodega_id
 * @property string|null $numero_compra
 * @property string $tipo_pago
 * @property numeric|null $interes_porcentaje Porcentaje de interés por periodo (ej: 5%)
 * @property string|null $periodo_interes Periodo de cobro del interés
 * @property \Illuminate\Support\Carbon|null $fecha_inicio_credito Fecha desde que empezó a correr el crédito
 * @property string $estado
 * @property string|null $nota Notas sobre cambios de estado o información adicional
 * @property numeric $total
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CompraDetalle> $detalles
 * @property-read int|null $detalles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \App\Models\Proveedor $proveedor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra activas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra borrador()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra cancelada()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra completadas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra ordenada()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra pendientePago()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra pendienteRecibir()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra porRecibirPagada()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra porRecibirPendientePago()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra recibidaPagada()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra recibidaPendientePago()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereFechaInicioCredito($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereInteresPorcentaje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereNota($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereNumeroCompra($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra wherePeriodoInteres($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereProveedorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereTipoPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Compra whereUpdatedBy($value)
 */
	class Compra extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $compra_id
 * @property int $producto_id
 * @property int $unidad_id
 * @property numeric $cantidad_facturada Cantidad que aparece en factura (pagada)
 * @property numeric $cantidad_regalo Cantidad regalada por proveedor (por merma)
 * @property numeric $cantidad_recibida Total físico recibido (facturada + regalo)
 * @property numeric $precio_unitario Precio por unidad (puede incluir ISV si la categoría aplica)
 * @property numeric|null $precio_con_isv Precio unitario con ISV incluido (lo que dice la factura)
 * @property numeric|null $costo_sin_isv Costo real sin ISV (precio_con_isv / 1.15)
 * @property numeric|null $isv_credito ISV crédito fiscal por unidad (precio_con_isv - costo_sin_isv)
 * @property numeric $descuento Descuento en monto
 * @property numeric $impuesto Impuesto en monto (diferente al ISV de compra)
 * @property numeric $subtotal cantidad_facturada * precio_unitario - descuento + impuesto
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Compra $compra
 * @property-read \App\Models\Lote|null $lote
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Unidad $unidad
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereCantidadFacturada($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereCantidadRecibida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereCantidadRegalo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereCompraId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereCostoSinIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereDescuento($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereImpuesto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereIsvCredito($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle wherePrecioConIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle wherePrecioUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CompraDetalle whereUpdatedAt($value)
 */
	class CompraDetalle extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $venta_id
 * @property int $cliente_id
 * @property int $bodega_id
 * @property string|null $numero_devolucion
 * @property string $tipo devolucion=regresa producto | nota_credito=descuento futuro | reposicion=producto nuevo
 * @property string $motivo
 * @property string|null $descripcion_motivo
 * @property numeric $subtotal
 * @property numeric $total_isv
 * @property numeric $total
 * @property string $accion
 * @property bool $aplicado Si ya se aplicó al saldo del cliente
 * @property bool $stock_reingresado Si el producto volvió al inventario
 * @property string $estado
 * @property int|null $aprobado_por
 * @property \Illuminate\Support\Carbon|null $fecha_aprobacion
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\User|null $aprobador
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\Cliente $cliente
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DevolucionDetalle> $detalles
 * @property-read int|null $detalles_count
 * @property-read \App\Models\Venta $venta
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion aplicadas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion delCliente(int $clienteId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion pendientes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereAccion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereAplicado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereAprobadoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereClienteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereDescripcionMotivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereFechaAprobacion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereMotivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereNumeroDevolucion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereStockReingresado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereTipo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereTotalIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Devolucion whereVentaId($value)
 */
	class Devolucion extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $devolucion_id
 * @property int $producto_id
 * @property int|null $venta_detalle_id
 * @property numeric $cantidad
 * @property numeric $precio_unitario Precio al que se vendió
 * @property bool $aplica_isv
 * @property numeric $isv_unitario
 * @property numeric $subtotal
 * @property numeric $total_isv
 * @property numeric $total_linea
 * @property string $estado_producto Estado del producto devuelto
 * @property bool $reingresa_stock Si puede volver al inventario
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Devolucion $devolucion
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\VentaDetalle|null $ventaDetalle
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereAplicaIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereDevolucionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereEstadoProducto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereIsvUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle wherePrecioUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereReingresaStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereTotalIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereTotalLinea($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DevolucionDetalle whereVentaDetalleId($value)
 */
	class DevolucionDetalle extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $logo
 * @property string|null $rtn
 * @property string|null $cai Código de Autorización de Impresión
 * @property string|null $telefono
 * @property string|null $correo_electronico
 * @property string|null $direccion
 * @property string|null $lema Lema o descripción de la empresa
 * @property string|null $rango_desde Ej: 000-001-01-00001601
 * @property string|null $rango_hasta Ej: 000-001-01-00001750
 * @property string|null $ultimo_numero_emitido Ej: 000-001-01-00001708
 * @property \Illuminate\Support\Carbon|null $fecha_limite_emision Fecha límite para emitir facturas con este CAI
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereCai($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereCorreoElectronico($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereDireccion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereFechaLimiteEmision($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereLema($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereRangoDesde($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereRangoHasta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereRtn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereTelefono($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereUltimoNumeroEmitido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Empresa whereUpdatedAt($value)
 */
	class Empresa extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string|null $numero_liquidacion Código único ej: LIQ-2024-001
 * @property int $chofer_id
 * @property string $tipo_periodo
 * @property \Illuminate\Support\Carbon $fecha_inicio
 * @property \Illuminate\Support\Carbon $fecha_fin
 * @property int $total_viajes Cantidad de viajes en el periodo
 * @property numeric $total_ventas Total vendido en el periodo
 * @property numeric $total_comisiones Comisiones ganadas
 * @property numeric $total_cobros Cobros por devoluciones/mermas
 * @property numeric $saldo_anterior Saldo que traía (+ favor, - en contra)
 * @property numeric $total_pagar comisiones - cobros + saldo_anterior
 * @property string $estado
 * @property \Illuminate\Support\Carbon|null $fecha_pago
 * @property string|null $metodo_pago
 * @property string|null $referencia_pago
 * @property int|null $created_by
 * @property int|null $aprobado_por
 * @property int|null $pagado_por
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $aprobadoPor
 * @property-read \App\Models\User $chofer
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChoferCuentaMovimiento> $movimientosCuenta
 * @property-read int|null $movimientos_cuenta_count
 * @property-read \App\Models\User|null $pagadoPor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LiquidacionViaje> $viajes
 * @property-read int|null $viajes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion delChofer(int $choferId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion delPeriodo($fechaInicio, $fechaFin)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion pagadas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion pendientes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereAprobadoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereChoferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereFechaFin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereFechaInicio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereFechaPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereMetodoPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereNumeroLiquidacion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion wherePagadoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereReferenciaPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereSaldoAnterior($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereTipoPeriodo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereTotalCobros($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereTotalComisiones($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereTotalPagar($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereTotalVentas($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereTotalViajes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Liquidacion whereUpdatedAt($value)
 */
	class Liquidacion extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $liquidacion_id
 * @property int $viaje_id
 * @property numeric $comision_viaje
 * @property numeric $cobros_viaje
 * @property numeric $neto_viaje
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Liquidacion $liquidacion
 * @property-read \App\Models\Viaje $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereCobrosViaje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereComisionViaje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereLiquidacionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereNetoViaje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LiquidacionViaje whereViajeId($value)
 */
	class LiquidacionViaje extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int|null $compra_id
 * @property int|null $compra_detalle_id
 * @property int|null $reempaque_origen_id
 * @property int $producto_id
 * @property int $proveedor_id
 * @property int $bodega_id
 * @property string $numero_lote L-B{id}-{sec} para normales | LS-B{id}-{sec} para sueltos de reempaque
 * @property numeric $cantidad_cartones_facturados Cartones pagados según factura (0 para lotes LS-*)
 * @property numeric $cantidad_cartones_regalo Cartones regalados por proveedor (0 para lotes LS-*)
 * @property numeric $cantidad_cartones_recibidos Total físico recibido (0 para lotes LS-*)
 * @property int $huevos_por_carton Huevos por cartón (30 para huevos grandes/normales)
 * @property numeric $cantidad_huevos_original Cantidad inicial en huevos
 * @property numeric $cantidad_huevos_remanente Huevos disponibles sin reempacar
 * @property numeric $costo_total_lote Total pagado por este lote (de la factura) o costo calculado para LS-*
 * @property numeric $costo_por_carton_facturado Costo según factura (0 para lotes LS-*)
 * @property numeric $costo_por_huevo Costo por huevo (2 decimales, redondeado hacia arriba)
 * @property string $estado
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\Compra|null $compra
 * @property-read \App\Models\CompraDetalle|null $compraDetalle
 * @property-read \App\Models\User|null $creador
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Proveedor $proveedor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReempaqueLote> $reempaqueLotes
 * @property-read int|null $reempaque_lotes_count
 * @property-read \App\Models\Reempaque|null $reempaqueOrigen
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote agotado()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote conRemanente()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote disponible()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote normales()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote porBodega(int $bodegaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote porProveedor(int $proveedorId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote porReempaqueOrigen(int $reempaqueId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote sueltos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCantidadCartonesFacturados($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCantidadCartonesRecibidos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCantidadCartonesRegalo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCantidadHuevosOriginal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCantidadHuevosRemanente($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCompraDetalleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCompraId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCostoPorCartonFacturado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCostoPorHuevo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCostoTotalLote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereHuevosPorCarton($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereNumeroLote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereProveedorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereReempaqueOrigenId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lote whereUpdatedAt($value)
 */
	class Lote extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $sku
 * @property int $categoria_id
 * @property int $unidad_id
 * @property numeric|null $precio_sugerido
 * @property string|null $descripcion
 * @property numeric $margen_ganancia Margen por defecto L5
 * @property string $tipo_margen
 * @property bool $aplica_isv Si el producto aplica ISV 15% en venta
 * @property bool $activo
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BodegaProducto> $bodegaProductos
 * @property-read int|null $bodega_productos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Bodega> $bodegas
 * @property-read int|null $bodegas_count
 * @property-read \App\Models\Categoria $categoria
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CompraDetalle> $compraDetalles
 * @property-read int|null $compra_detalles_count
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductoImagen> $imagenes
 * @property-read int|null $imagenes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReempaqueProducto> $reempaqueProductos
 * @property-read int|null $reempaque_productos_count
 * @property-read \App\Models\Unidad $unidad
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereAplicaIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereCategoriaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereMargenGanancia($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto wherePrecioSugerido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereTipoMargen($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Producto withoutTrashed()
 */
	class Producto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $producto_id
 * @property string $path
 * @property string|null $url
 * @property int $orden
 * @property int $activo
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereOrden($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductoImagen whereUrl($value)
 */
	class ProductoImagen extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string|null $direccion
 * @property string|null $email
 * @property bool $estado
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Compra> $compras
 * @property-read int|null $compras_count
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProveedorProducto> $preciosProveedor
 * @property-read int|null $precios_proveedor_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor activos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereDireccion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereRtn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereTelefono($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Proveedor withoutTrashed()
 */
	class Proveedor extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $proveedor_id
 * @property int $producto_id
 * @property numeric|null $ultimo_precio_compra
 * @property \Illuminate\Support\Carbon|null $actualizado_en
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Proveedor $proveedor
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto whereActualizadoEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto whereProveedorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProveedorProducto whereUltimoPrecioCompra($value)
 */
	class ProveedorProducto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $bodega_id
 * @property string $numero_reempaque Formato: R-B{bodega_id}-{secuencial}
 * @property string $tipo Individual: 1 lote | Mezclado: múltiples lotes
 * @property numeric $total_huevos_usados Total de huevos tomados de los lotes
 * @property numeric $merma Huevos rotos/perdidos durante reempaque
 * @property numeric $huevos_utiles Huevos buenos después de merma (usados - merma)
 * @property numeric $costo_total Suma de costos de todos los lotes usados
 * @property numeric $costo_unitario_promedio Costo por huevo (2 decimales, redondeado hacia arriba, incluye merma)
 * @property int $cartones_30 Cartones de 30 huevos generados
 * @property int $cartones_15 Cartones de 15 huevos generados
 * @property int $huevos_sueltos Huevos sueltos → se convierten en lote LS-*
 * @property string $estado
 * @property string|null $nota
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Lote> $lotes
 * @property-read int|null $lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReempaqueLote> $reempaqueLotes
 * @property-read int|null $reempaque_lotes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReempaqueProducto> $reempaqueProductos
 * @property-read int|null $reempaque_productos_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque cancelado()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque completado()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque enProceso()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque individual()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque mezclado()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque porBodega(int $bodegaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereCartones15($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereCartones30($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereCostoTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereCostoUnitarioPromedio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereHuevosSueltos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereHuevosUtiles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereMerma($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereNota($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereNumeroReempaque($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereTipo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereTotalHuevosUsados($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reempaque whereUpdatedAt($value)
 */
	class Reempaque extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $reempaque_id
 * @property int $lote_id
 * @property numeric $cantidad_cartones_usados Cartones usados de este lote
 * @property numeric $cantidad_huevos_usados Huevos usados de este lote
 * @property numeric $cartones_facturados_usados De los cartones facturados
 * @property numeric $cartones_regalo_usados De los cartones regalados
 * @property numeric $costo_parcial Costo total de los huevos de este lote
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Lote $lote
 * @property-read \App\Models\Reempaque $reempaque
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereCantidadCartonesUsados($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereCantidadHuevosUsados($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereCartonesFacturadosUsados($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereCartonesRegaloUsados($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereCostoParcial($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereLoteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereReempaqueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueLote whereUpdatedAt($value)
 */
	class ReempaqueLote extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $reempaque_id
 * @property int $producto_id
 * @property int $bodega_id
 * @property numeric $cantidad Cantidad de este producto generado
 * @property numeric $costo_unitario Costo por unidad (2 decimales, incluye regalos + merma)
 * @property numeric $costo_total cantidad × costo_unitario
 * @property bool $agregado_a_stock Si ya se agregó a bodega_producto
 * @property \Illuminate\Support\Carbon|null $fecha_agregado_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Reempaque $reempaque
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto agregadoAStock()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto pendienteAgregar()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereAgregadoAStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereCostoTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereCostoUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereFechaAgregadoStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereReempaqueId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReempaqueProducto whereUpdatedAt($value)
 */
	class ReempaqueProducto extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $nombre
 * @property string|null $simbolo
 * @property int $es_decimal
 * @property int $activo
 * @property int $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Categoria> $categorias
 * @property-read int|null $categorias_count
 * @property-read \App\Models\User $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Producto> $productos
 * @property-read int|null $productos_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereActivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereEsDecimal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereNombre($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereSimbolo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unidad whereUpdatedBy($value)
 */
	class Unidad extends \Eloquent {}
}

namespace App\Models{
/**
 * @method bool hasRole(string|array|\Spatie\Permission\Contracts\Role $roles, string $guard = null)
 * @method bool hasAnyRole(string|array|\Spatie\Permission\Contracts\Role $roles, string $guard = null)
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $created_by
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\CamionChofer|null $asignacionCamionActiva
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CamionChofer> $asignacionesCamion
 * @property-read int|null $asignaciones_camion_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Bodega> $bodegas
 * @property-read int|null $bodegas_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChoferComisionConfig> $comisionesConfig
 * @property-read int|null $comisiones_config_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChoferComisionProducto> $comisionesProducto
 * @property-read int|null $comisiones_producto_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Compra> $comprasCreadas
 * @property-read int|null $compras_creadas_count
 * @property-read User|null $creador
 * @property-read \App\Models\ChoferCuenta|null $cuenta
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChoferCuentaMovimiento> $cuentaMovimientos
 * @property-read int|null $cuenta_movimientos_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Liquidacion> $liquidaciones
 * @property-read int|null $liquidaciones_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $usuariosCreados
 * @property-read int|null $usuarios_creados_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Venta> $ventasCreadas
 * @property-read int|null $ventas_creadas_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Viaje> $viajesComoChofer
 * @property-read int|null $viajes_como_chofer_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Viaje> $viajesCreados
 * @property-read int|null $viajes_creados_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User choferes()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User conAccesoBodega(int $bodegaId)
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $cliente_id
 * @property int $bodega_id
 * @property string $origen
 * @property int|null $viaje_id
 * @property string|null $numero_venta
 * @property string $tipo_pago
 * @property numeric $subtotal Suma de productos sin ISV
 * @property numeric $total_isv Total de ISV (15%)
 * @property numeric $descuento Descuento global
 * @property numeric $total subtotal + total_isv - descuento
 * @property numeric $monto_pagado Cuánto ha pagado
 * @property numeric $saldo_pendiente Cuánto debe de esta venta
 * @property \Illuminate\Support\Carbon|null $fecha_vencimiento Fecha límite de pago (crédito)
 * @property string $estado
 * @property string $estado_pago
 * @property string $estado_entrega
 * @property string|null $fecha_entrega
 * @property int|null $entregado_por
 * @property string|null $nota
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actualizador
 * @property-read \App\Models\Bodega $bodega
 * @property-read \App\Models\Cliente $cliente
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\VentaDetalle> $detalles
 * @property-read int|null $detalles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Devolucion> $devoluciones
 * @property-read int|null $devoluciones_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\VentaPago> $pagos
 * @property-read int|null $pagos_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta borrador()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta cancelada()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta completadas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta delCliente(int $clienteId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta delDia($fecha = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta delMes($mes = null, $anio = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta pagada()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta pendientesPago()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta vencidas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereBodegaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereClienteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereDescuento($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereEntregadoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereEstadoEntrega($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereEstadoPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereFechaEntrega($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereFechaVencimiento($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereMontoPagado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereNota($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereNumeroVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereOrigen($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereSaldoPendiente($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereTipoPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereTotalIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Venta whereViajeId($value)
 */
	class Venta extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $venta_id
 * @property int $producto_id
 * @property int $unidad_id
 * @property numeric $cantidad
 * @property numeric $cantidad_regalo Unidades regaladas (sin costo para cliente)
 * @property numeric $costo_regalo Costo de los regalos (pérdida nuestra)
 * @property numeric $precio_unitario Precio sin ISV
 * @property numeric|null $precio_con_isv Precio con ISV (si aplica)
 * @property numeric $costo_unitario Costo al momento de venta (para calcular ganancia)
 * @property bool $aplica_isv
 * @property numeric $isv_unitario ISV por unidad
 * @property numeric $descuento_porcentaje
 * @property numeric $descuento_monto
 * @property numeric $subtotal cantidad * precio_unitario
 * @property numeric $total_isv cantidad * isv_unitario
 * @property numeric $total_linea subtotal + total_isv - descuento_monto
 * @property numeric|null $precio_anterior Precio que se le vendió antes (referencia)
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Unidad $unidad
 * @property-read \App\Models\Venta $venta
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereAplicaIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereCantidadRegalo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereCostoRegalo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereCostoUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereDescuentoMonto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereDescuentoPorcentaje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereIsvUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle wherePrecioAnterior($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle wherePrecioConIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle wherePrecioUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereTotalIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereTotalLinea($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaDetalle whereVentaId($value)
 */
	class VentaDetalle extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $venta_id
 * @property numeric $monto
 * @property string $metodo_pago
 * @property string|null $referencia # de transferencia, cheque, etc
 * @property string|null $nota
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $creador
 * @property-read \App\Models\Venta $venta
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereMetodoPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereMonto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereNota($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereReferencia($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VentaPago whereVentaId($value)
 */
	class VentaPago extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string|null $numero_viaje Código único ej: VJ-CAM001-241219-001
 * @property int $camion_id
 * @property int $chofer_id
 * @property int $bodega_origen_id
 * @property \Illuminate\Support\Carbon|null $fecha_salida
 * @property \Illuminate\Support\Carbon|null $fecha_regreso
 * @property int|null $km_salida
 * @property int|null $km_regreso
 * @property string $estado
 * @property numeric $total_cargado_costo Costo total de productos cargados
 * @property numeric $total_cargado_venta Valor de venta esperado de productos cargados
 * @property numeric $total_vendido Total real de ventas
 * @property numeric $total_merma_costo Costo de merma/pérdida
 * @property numeric $total_devuelto_costo Costo de productos devueltos
 * @property numeric $comision_ganada Comisión total ganada en este viaje
 * @property numeric $cobros_devoluciones Total cobrado al chofer por devoluciones
 * @property numeric $neto_chofer comision_ganada - cobros_devoluciones
 * @property int $comision_pagada
 * @property string|null $fecha_pago_comision
 * @property numeric $efectivo_inicial Efectivo que lleva al salir (para cambio)
 * @property numeric $efectivo_esperado Efectivo que debería traer
 * @property numeric $efectivo_entregado Efectivo que entregó al regresar
 * @property numeric $diferencia_efectivo Diferencia (+ sobrante, - faltante)
 * @property string|null $observaciones
 * @property int|null $created_by
 * @property int|null $cerrado_por
 * @property \Illuminate\Support\Carbon|null $cerrado_en
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Bodega $bodegaOrigen
 * @property-read \App\Models\Camion $camion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ViajeCarga> $cargas
 * @property-read int|null $cargas_count
 * @property-read \App\Models\User|null $cerradoPor
 * @property-read \App\Models\User $chofer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ViajeComisionDetalle> $comisionesDetalle
 * @property-read int|null $comisiones_detalle_count
 * @property-read \App\Models\User|null $creador
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ViajeDescarga> $descargas
 * @property-read int|null $descargas_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LiquidacionViaje> $liquidacionViajes
 * @property-read int|null $liquidacion_viajes_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ViajeMerma> $mermas
 * @property-read int|null $mermas_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChoferCuentaMovimiento> $movimientosCuenta
 * @property-read int|null $movimientos_cuenta_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Venta> $ventas
 * @property-read int|null $ventas_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ViajeVenta> $ventasRuta
 * @property-read int|null $ventas_ruta_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje activos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje cerrados()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje deBodega(int $bodegaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje delCamion(int $camionId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje delChofer(int $choferId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje delPeriodo($fechaInicio, $fechaFin)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje pendientesLiquidar()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereBodegaOrigenId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereCamionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereCerradoEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereCerradoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereChoferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereCobrosDevoluciones($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereComisionGanada($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereComisionPagada($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereDiferenciaEfectivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereEfectivoEntregado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereEfectivoEsperado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereEfectivoInicial($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereFechaPagoComision($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereFechaRegreso($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereFechaSalida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereKmRegreso($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereKmSalida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereNetoChofer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereNumeroViaje($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereObservaciones($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereTotalCargadoCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereTotalCargadoVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereTotalDevueltoCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereTotalMermaCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereTotalVendido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Viaje whereUpdatedAt($value)
 */
	class Viaje extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $viaje_id
 * @property int $producto_id
 * @property int $unidad_id
 * @property numeric $cantidad Cantidad cargada
 * @property numeric $costo_unitario Costo al momento de cargar
 * @property numeric $precio_venta_sugerido Precio mínimo de venta sugerido
 * @property numeric $precio_venta_minimo Precio mínimo absoluto (= costo, no puede vender menos)
 * @property numeric $subtotal_costo cantidad * costo_unitario
 * @property numeric $subtotal_venta cantidad * precio_venta_sugerido
 * @property numeric $cantidad_vendida
 * @property numeric $cantidad_merma
 * @property numeric $cantidad_devuelta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Unidad $unidad
 * @property-read \App\Models\Viaje $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga completas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga conDisponible()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga delViaje(int $viajeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereCantidadDevuelta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereCantidadMerma($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereCantidadVendida($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereCostoUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga wherePrecioVentaMinimo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga wherePrecioVentaSugerido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereSubtotalCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereSubtotalVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeCarga whereViajeId($value)
 */
	class ViajeCarga extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $viaje_id
 * @property int $viaje_venta_id
 * @property int $viaje_venta_detalle_id
 * @property int $producto_id
 * @property numeric $cantidad
 * @property numeric $precio_vendido
 * @property numeric $precio_sugerido
 * @property numeric $costo
 * @property string $tipo_comision normal = vendió >= sugerido, reducida = vendió < sugerido
 * @property numeric $comision_unitaria
 * @property numeric $comision_total
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\ViajeVenta|null $venta
 * @property-read \App\Models\ViajeVentaDetalle|null $ventaDetalle
 * @property-read \App\Models\Viaje $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle deLaVenta(int $ventaId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle delViaje(int $viajeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle normales()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle reducidas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereComisionTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereComisionUnitaria($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle wherePrecioSugerido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle wherePrecioVendido($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereTipoComision($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereViajeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereViajeVentaDetalleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeComisionDetalle whereViajeVentaId($value)
 */
	class ViajeComisionDetalle extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $viaje_id
 * @property int $producto_id
 * @property int $unidad_id
 * @property numeric $cantidad Cantidad que regresa
 * @property numeric $costo_unitario Costo del producto
 * @property numeric $subtotal_costo cantidad * costo_unitario
 * @property string $estado_producto
 * @property bool $reingresa_stock Si el producto vuelve al inventario de bodega
 * @property bool $cobrar_chofer Si se le descuenta al chofer
 * @property numeric $monto_cobrar Monto a cobrar (normalmente = subtotal_costo)
 * @property string|null $observaciones
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\Unidad $unidad
 * @property-read \App\Models\Viaje $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga cobradosAlChofer()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga danados()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga delViaje(int $viajeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga enBuenEstado()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga queReingresanStock()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga vencidos()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereCobrarChofer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereCostoUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereEstadoProducto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereMontoCobrar($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereObservaciones($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereReingresaStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereSubtotalCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeDescarga whereViajeId($value)
 */
	class ViajeDescarga extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $viaje_id
 * @property int $producto_id
 * @property int $unidad_id
 * @property numeric $cantidad Cantidad perdida/dañada
 * @property numeric $costo_unitario
 * @property numeric $subtotal_costo Pérdida en lempiras
 * @property string $motivo
 * @property string|null $descripcion
 * @property bool $cobrar_chofer
 * @property numeric $monto_cobrar
 * @property int|null $registrado_por
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\User|null $registradoPor
 * @property-read \App\Models\Unidad $unidad
 * @property-read \App\Models\Viaje $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma cobradosAlChofer()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma delViaje(int $viajeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma noCobrados()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma porMotivo(string $motivo)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereCobrarChofer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereCostoUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereDescripcion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereMontoCobrar($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereMotivo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereRegistradoPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereSubtotalCosto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereUnidadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeMerma whereViajeId($value)
 */
	class ViajeMerma extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $viaje_id
 * @property int|null $cliente_id
 * @property string $numero_venta Código único ej: VR-2-0001
 * @property \Illuminate\Support\Carbon $fecha_venta
 * @property string $tipo_pago
 * @property int $plazo_dias Días de plazo si es crédito
 * @property numeric $subtotal Subtotal sin ISV
 * @property numeric $impuesto Total ISV
 * @property numeric $descuento
 * @property numeric $total Total final (subtotal + impuesto - descuento)
 * @property numeric $saldo_pendiente Saldo pendiente si es crédito
 * @property string $estado
 * @property string|null $numero_factura
 * @property string|null $nota
 * @property int|null $user_id
 * @property int|null $confirmada_por
 * @property \Illuminate\Support\Carbon|null $confirmada_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Cliente|null $cliente
 * @property-read \App\Models\User|null $confirmadaPor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ViajeVentaDetalle> $detalles
 * @property-read int|null $detalles_count
 * @property-read \App\Models\User|null $userCreador
 * @property-read \App\Models\Viaje|null $viaje
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta borradores()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta canceladas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta completadas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta confirmadas()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta contado()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta credito()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta porCliente(int $clienteId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta porViaje(int $viajeId)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereClienteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereConfirmadaAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereConfirmadaPor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereDescuento($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereEstado($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereFechaVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereImpuesto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereNota($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereNumeroFactura($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereNumeroVenta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta wherePlazoDias($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereSaldoPendiente($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereTipoPago($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta whereViajeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVenta withoutTrashed()
 */
	class ViajeVenta extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $viaje_venta_id
 * @property int|null $viaje_carga_id Referencia a la carga del viaje
 * @property int $producto_id
 * @property numeric $cantidad
 * @property numeric $precio_base Precio sin ISV
 * @property numeric $precio_con_isv Precio con ISV (lo que paga el cliente)
 * @property numeric $monto_isv Monto del ISV por unidad
 * @property numeric $costo_unitario Costo del producto
 * @property bool $aplica_isv
 * @property numeric $subtotal cantidad * precio_base
 * @property numeric $total_isv cantidad * monto_isv
 * @property numeric $total_linea cantidad * precio_con_isv
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Producto $producto
 * @property-read \App\Models\ViajeVenta $venta
 * @property-read \App\Models\ViajeCarga|null $viajeCarga
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereAplicaIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereCantidad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereCostoUnitario($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereMontoIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle wherePrecioBase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle wherePrecioConIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereProductoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereTotalIsv($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereTotalLinea($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereViajeCargaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ViajeVentaDetalle whereViajeVentaId($value)
 */
	class ViajeVentaDetalle extends \Eloquent {}
}

