<?php

namespace App\Filament\Pages;

use App\Models\Venta;
use App\Models\ViajeVenta;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class TodasLasFacturas extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Todas las Facturas';
    protected static ?string $title = 'Facturas';
    protected static ?string $slug = 'todas-las-facturas';
    protected static ?string $navigationGroup = 'Ventas';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.todas-las-facturas';

    // Filtros
    #[Url]
    public string $filtroTipo = '';
    
    #[Url]
    public string $filtroPago = '';
    
    #[Url]
    public string $filtroEstado = '';
    
    #[Url]
    public string $busqueda = '';
    
    #[Url]
    public string $fechaDesde = '';
    
    #[Url]
    public string $fechaHasta = '';

    public int $porPagina = 15;

    /**
     * Verificar acceso - Super Admin, Jefe, Encargado
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($user))
            ->where('model_has_roles.model_id', '=', $user->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe', 'Encargado'])
            ->exists();
    }

    /**
     * Verificar si es Super Admin o Jefe
     */
    protected static function esSuperAdminOJefe(): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($user))
            ->where('model_has_roles.model_id', '=', $user->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();
    }

    /**
     * Obtener bodegas del usuario
     */
    protected static function getBodegasUsuario(): array
    {
        $user = Auth::user();
        if (!$user) return [];

        if (static::esSuperAdminOJefe()) {
            return [];
        }

        return DB::table('bodega_user')
            ->where('user_id', $user->id)
            ->where('activo', true)
            ->pluck('bodega_id')
            ->toArray();
    }

    /**
     * Obtener todas las facturas combinadas
     */
    #[Computed]
    public function facturas(): Collection
    {
        $bodegasUsuario = static::getBodegasUsuario();
        $esSuperAdmin = static::esSuperAdminOJefe();

        // Ventas normales (procesadas)
        $ventasQuery = Venta::query()
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->whereNotNull('numero_venta')
            ->with(['cliente', 'bodega', 'creador']);

        if (!$esSuperAdmin && !empty($bodegasUsuario)) {
            $ventasQuery->whereIn('bodega_id', $bodegasUsuario);
        }

        $ventasNormales = $ventasQuery->get()->map(function ($venta) {
            return (object) [
                'id' => $venta->id,
                'tipo' => 'bodega',
                'numero_factura' => $venta->numero_venta,
                'fecha' => $venta->created_at,
                'cliente_nombre' => $venta->cliente?->nombre ?? 'Sin cliente',
                'cliente_rtn' => $venta->cliente?->rtn,
                'origen' => $venta->bodega?->nombre ?? 'N/A',
                'vendedor' => $venta->creador?->name ?? 'N/A',
                'tipo_pago' => $venta->tipo_pago,
                'subtotal' => (float) $venta->subtotal,
                'isv' => (float) $venta->total_isv,
                'descuento' => (float) $venta->descuento,
                'total' => (float) $venta->total,
                'saldo_pendiente' => (float) $venta->saldo_pendiente,
                'estado' => $venta->estado,
                'estado_pago' => $venta->estado_pago,
                'url_imprimir' => route('venta.imprimir', $venta->id),
                'url_ver' => route('filament.admin.resources.ventas.view', $venta->id),
            ];
        });

        // Ventas de viaje/ruta
        $viajeVentasQuery = ViajeVenta::query()
            ->whereIn('estado', ['completada', 'confirmada'])
            ->whereNotNull('numero_venta')
            ->with(['cliente', 'viaje.bodegaOrigen', 'viaje.chofer', 'userCreador']);

        if (!$esSuperAdmin && !empty($bodegasUsuario)) {
            $viajeVentasQuery->whereHas('viaje', function ($q) use ($bodegasUsuario) {
                $q->whereIn('bodega_origen_id', $bodegasUsuario);
            });
        }

        $ventasViaje = $viajeVentasQuery->get()->map(function ($venta) {
            return (object) [
                'id' => $venta->id,
                'tipo' => 'viaje',
                'numero_factura' => $venta->numero_venta,
                'fecha' => $venta->fecha_venta ?? $venta->created_at,
                'cliente_nombre' => $venta->cliente?->nombre ?? 'Consumidor Final',
                'cliente_rtn' => $venta->cliente?->rtn,
                'origen' => 'Viaje: ' . ($venta->viaje?->numero_viaje ?? 'N/A'),
                'vendedor' => $venta->viaje?->chofer?->name ?? $venta->userCreador?->name ?? 'N/A',
                'tipo_pago' => $venta->tipo_pago,
                'subtotal' => (float) $venta->subtotal,
                'isv' => (float) $venta->impuesto,
                'descuento' => (float) $venta->descuento,
                'total' => (float) $venta->total,
                'saldo_pendiente' => (float) $venta->saldo_pendiente,
                'estado' => $venta->estado,
                'estado_pago' => $venta->saldo_pendiente <= 0 ? 'pagado' : 'pendiente',
                'url_imprimir' => route('viaje-venta.imprimir', $venta->id),
                'url_ver' => null,
            ];
        });

        // Combinar
        $todas = $ventasNormales->concat($ventasViaje);

        // Aplicar filtros
        if ($this->filtroTipo) {
            $todas = $todas->where('tipo', $this->filtroTipo);
        }

        if ($this->filtroPago) {
            $todas = $todas->where('tipo_pago', $this->filtroPago);
        }

        if ($this->filtroEstado) {
            $todas = $todas->where('estado_pago', $this->filtroEstado);
        }

        if ($this->busqueda) {
            $busqueda = strtolower($this->busqueda);
            $todas = $todas->filter(function ($f) use ($busqueda) {
                return str_contains(strtolower($f->numero_factura ?? ''), $busqueda) ||
                       str_contains(strtolower($f->cliente_nombre ?? ''), $busqueda) ||
                       str_contains(strtolower($f->vendedor ?? ''), $busqueda);
            });
        }

        if ($this->fechaDesde) {
            $desde = \Carbon\Carbon::parse($this->fechaDesde)->startOfDay();
            $todas = $todas->filter(fn($f) => $f->fecha >= $desde);
        }

        if ($this->fechaHasta) {
            $hasta = \Carbon\Carbon::parse($this->fechaHasta)->endOfDay();
            $todas = $todas->filter(fn($f) => $f->fecha <= $hasta);
        }

        // Ordenar por fecha descendente
        return $todas->sortByDesc('fecha')->values();
    }

    /**
     * Facturas paginadas
     */
    #[Computed]
    public function facturasPaginadas()
    {
        $facturas = $this->facturas;
        $page = $this->getPage();
        $offset = ($page - 1) * $this->porPagina;

        return [
            'data' => $facturas->slice($offset, $this->porPagina)->values(),
            'total' => $facturas->count(),
            'per_page' => $this->porPagina,
            'current_page' => $page,
            'last_page' => ceil($facturas->count() / $this->porPagina),
        ];
    }

    /**
     * Estadísticas rápidas
     */
    #[Computed]
    public function estadisticas(): array
    {
        // Usar datos sin filtrar para estadísticas globales
        $bodegasUsuario = static::getBodegasUsuario();
        $esSuperAdmin = static::esSuperAdminOJefe();

        $ventasQuery = Venta::query()
            ->whereIn('estado', ['completada', 'pendiente_pago', 'pagada'])
            ->whereNotNull('numero_venta');

        if (!$esSuperAdmin && !empty($bodegasUsuario)) {
            $ventasQuery->whereIn('bodega_id', $bodegasUsuario);
        }

        $viajeVentasQuery = ViajeVenta::query()
            ->whereIn('estado', ['completada', 'confirmada'])
            ->whereNotNull('numero_venta');

        if (!$esSuperAdmin && !empty($bodegasUsuario)) {
            $viajeVentasQuery->whereHas('viaje', function ($q) use ($bodegasUsuario) {
                $q->whereIn('bodega_origen_id', $bodegasUsuario);
            });
        }

        $hoy = now()->startOfDay();
        $inicioMes = now()->startOfMonth();

        // Ventas bodega
        $ventasBodega = $ventasQuery->count();
        $ventasBodegaHoy = (clone $ventasQuery)->whereDate('created_at', today())->count();
        $totalBodegaHoy = (clone $ventasQuery)->whereDate('created_at', today())->sum('total');
        $totalBodegaMes = (clone $ventasQuery)->where('created_at', '>=', $inicioMes)->sum('total');
        $saldoBodega = $ventasQuery->sum('saldo_pendiente');

        // Ventas viaje
        $ventasViaje = $viajeVentasQuery->count();
        $ventasViajeHoy = (clone $viajeVentasQuery)->whereDate('fecha_venta', today())->count();
        $totalViajeHoy = (clone $viajeVentasQuery)->whereDate('fecha_venta', today())->sum('total');
        $totalViajeMes = (clone $viajeVentasQuery)->where('fecha_venta', '>=', $inicioMes)->sum('total');
        $saldoViaje = $viajeVentasQuery->sum('saldo_pendiente');

        return [
            'total_facturas' => $ventasBodega + $ventasViaje,
            'facturas_hoy' => $ventasBodegaHoy + $ventasViajeHoy,
            'ventas_bodega' => $ventasBodega,
            'ventas_viaje' => $ventasViaje,
            'total_vendido_hoy' => $totalBodegaHoy + $totalViajeHoy,
            'total_vendido_mes' => $totalBodegaMes + $totalViajeMes,
            'pendiente_cobro' => $saldoBodega + $saldoViaje,
        ];
    }

    /**
     * Limpiar filtros
     */
    public function limpiarFiltros(): void
    {
        $this->filtroTipo = '';
        $this->filtroPago = '';
        $this->filtroEstado = '';
        $this->busqueda = '';
        $this->fechaDesde = '';
        $this->fechaHasta = '';
        $this->resetPage();
    }

    /**
     * Filtrar solo hoy
     */
    public function filtrarHoy(): void
    {
        $this->fechaDesde = now()->format('Y-m-d');
        $this->fechaHasta = now()->format('Y-m-d');
        $this->resetPage();
    }
}