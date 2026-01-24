<?php

namespace App\Filament\Pages;

use App\Models\CamionGasto;
use App\Models\Camion;
use App\Models\Viaje;
use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class GastosDelCamion extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Gastos del Camión';
    protected static ?string $title = 'Gastos del Camión';
    protected static ?string $navigationGroup = 'Mi Trabajo';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.gastos-del-camion';

    // Propiedades del formulario
    public ?array $data = [];
    public bool $mostrarFormulario = false;

    public function mount(): void
    {
        $camionId = Auth::user()->asignacionCamionActiva?->camion_id;
        
        $this->form->fill([
            'fecha' => now(),
            'camion_id' => $camionId,
            'tiene_factura' => false,
        ]);
    }

    /**
     * Verificar si el usuario puede acceder (solo choferes)
     */
    public static function canAccess(): bool
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole('Chofer');
    }

    /**
     * Obtener el camión asignado al chofer actual
     * Primero busca en asignación directa, luego en el viaje activo
     */
    public function getCamionAsignadoProperty(): ?Camion
    {
        // Primero verificar asignación directa
        $camionDirecto = Auth::user()->asignacionCamionActiva?->camion;
        
        if ($camionDirecto) {
            return $camionDirecto;
        }
        
        // Si no hay asignación directa, buscar en el viaje activo
        return $this->viajeActivo?->camion;
    }

    /**
     * Verificar si el chofer tiene camión asignado (directo o por viaje)
     */
    public function getTieneCamionAsignadoProperty(): bool
    {
        return $this->camionAsignado !== null;
    }

    /**
     * Formulario simple para registrar gasto
     */
    public function form(Form $form): Form
    {
        $tieneCamionAsignado = $this->tieneCamionAsignado;

        return $form
            ->schema([
                Forms\Components\Section::make('Registrar Nuevo Gasto')
                    ->description('Completa los datos del gasto. Luego podrás enviarlo por WhatsApp.')
                    ->schema([
                        // Solo mostrar selector de camión si NO tiene asignación
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('camion_id')
                                    ->label('Camión')
                                    ->options(
                                        Camion::where('activo', true)
                                            ->get()
                                            ->mapWithKeys(fn($c) => [$c->id => "{$c->codigo} - {$c->placa}"])
                                    )
                                    ->required()
                                    ->searchable()
                                    ->visible(fn() => !$tieneCamionAsignado),

                                Forms\Components\DatePicker::make('fecha')
                                    ->label('Fecha')
                                    ->required()
                                    ->default(now())
                                    ->maxDate(now())
                                    ->native(false)
                                    ->columnSpan($tieneCamionAsignado ? 2 : 1),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('tipo_gasto')
                                    ->label('Tipo de Gasto')
                                    ->options(CamionGasto::TIPOS_GASTO)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set) {
                                        $set('litros', null);
                                        $set('kilometraje', null);
                                        $set('precio_por_litro', null);
                                    }),

                                Forms\Components\TextInput::make('monto')
                                    ->label('Monto')
                                    ->required()
                                    ->numeric()
                                    ->prefix('L')
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => $this->calcularPrecioPorLitro($set, $get)),
                            ]),

                        // Campos de gasolina
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('litros')
                                    ->label('Litros')
                                    ->numeric()
                                    ->required(fn(Forms\Get $get) => $get('tipo_gasto') === 'gasolina')
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->suffix('L')
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(fn(Forms\Set $set, Forms\Get $get) => $this->calcularPrecioPorLitro($set, $get)),

                                Forms\Components\TextInput::make('precio_por_litro')
                                    ->label('Precio/Litro')
                                    ->numeric()
                                    ->prefix('L')
                                    ->disabled()
                                    ->dehydrated()
                                    ->helperText('Calculado automáticamente'),

                                Forms\Components\TextInput::make('kilometraje')
                                    ->label('Kilometraje Actual')
                                    ->numeric()
                                    ->suffix('km'),
                            ])
                            ->visible(fn(Forms\Get $get) => $get('tipo_gasto') === 'gasolina'),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('proveedor')
                                    ->label('Proveedor (Opcional)')
                                    ->placeholder('Gasolinera, taller, etc.')
                                    ->maxLength(255),

                                Forms\Components\Toggle::make('tiene_factura')
                                    ->label('¿Tiene Factura?')
                                    ->default(false)
                                    ->inline(false),
                            ]),

                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripción (Opcional)')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('Algún detalle adicional...')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ])
            ->statePath('data');
    }

    /**
     * Calcular precio por litro automáticamente
     */
    protected function calcularPrecioPorLitro(Forms\Set $set, Forms\Get $get): void
    {
        $monto = $get('monto');
        $litros = $get('litros');

        if ($monto && $litros && $litros > 0) {
            $precioPorLitro = round($monto / $litros, 2);
            $set('precio_por_litro', $precioPorLitro);
        } else {
            $set('precio_por_litro', null);
        }
    }

    /**
     * Guardar el gasto
     */
    public function guardarGasto(): void
    {
        $datos = $this->form->getState();

        // Usar camión asignado si existe, sino el del formulario
        $camionId = $this->tieneCamionAsignado 
            ? $this->camionAsignado->id 
            : $datos['camion_id'];

        // Obtener viaje activo para asignarlo al gasto
        $viajeActivo = $this->viajeActivo;

        $gasto = CamionGasto::create([
            'camion_id' => $camionId,
            'chofer_id' => Auth::id(),
            'viaje_id' => $viajeActivo?->id, // Asignar viaje activo automáticamente
            'fecha' => $datos['fecha'],
            'tipo_gasto' => $datos['tipo_gasto'],
            'monto' => $datos['monto'],
            'litros' => $datos['litros'] ?? null,
            'precio_por_litro' => $datos['precio_por_litro'] ?? null,
            'kilometraje' => $datos['kilometraje'] ?? null,
            'proveedor' => $datos['proveedor'] ?? null,
            'tiene_factura' => $datos['tiene_factura'] ?? false,
            'descripcion' => $datos['descripcion'] ?? null,
            'estado' => 'pendiente',
            'created_by' => Auth::id(),
        ]);

        // Limpiar formulario
        $this->form->fill([
            'fecha' => now(),
            'camion_id' => $this->camionAsignado?->id,
            'tipo_gasto' => null,
            'monto' => null,
            'litros' => null,
            'precio_por_litro' => null,
            'kilometraje' => null,
            'proveedor' => null,
            'tiene_factura' => false,
            'descripcion' => null,
        ]);

        $this->mostrarFormulario = false;

        Notification::make()
            ->title('Gasto registrado')
            ->body('El gasto ha sido registrado. Puedes enviarlo por WhatsApp desde la tabla.')
            ->success()
            ->send();
    }

    /**
     * Obtener el viaje activo del chofer
     */
    public function getViajeActivoProperty()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        
        return $user?->getViajeActivo();
    }

    /**
     * Verificar si el chofer puede registrar gastos
     * Solo puede si tiene un viaje en estados operativos (no planificado, no cerrado, no cancelado)
     */
    public function getPuedeRegistrarGastosProperty(): bool
    {
        $viaje = $this->viajeActivo;
        
        if (!$viaje) {
            return false;
        }

        // Estados en los que se pueden registrar gastos
        $estadosPermitidos = [
            Viaje::ESTADO_CARGANDO,
            Viaje::ESTADO_EN_RUTA,
            Viaje::ESTADO_REGRESANDO,
            Viaje::ESTADO_DESCARGANDO,
        ];

        return in_array($viaje->estado, $estadosPermitidos);
    }

    /**
     * Tabla de gastos del chofer (solo del viaje activo)
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                CamionGasto::query()
                    ->where('chofer_id', Auth::id())
                    ->when(
                        $this->viajeActivo,
                        // Si hay viaje activo, solo mostrar gastos de ese viaje
                        fn($query) => $query->where('viaje_id', $this->viajeActivo->id),
                        // Si no hay viaje activo, mostrar gastos sin viaje asignado
                        fn($query) => $query->whereNull('viaje_id')
                    )
                    ->latest('fecha')
            )
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_gasto')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn($state) => CamionGasto::TIPOS_GASTO[$state] ?? $state)
                    ->color(fn($state) => match ($state) {
                        'gasolina' => 'warning',
                        'mantenimiento' => 'info',
                        'reparacion' => 'danger',
                        'peaje' => 'gray',
                        'viaticos' => 'success',
                        'lavado' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('monto')
                    ->label('Monto')
                    ->money('HNL')
                    ->sortable(),

                Tables\Columns\TextColumn::make('litros')
                    ->label('Litros')
                    ->suffix(' L')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('precio_por_litro')
                    ->label('L/Litro')
                    ->money('HNL')
                    ->placeholder('-')
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                Tables\Columns\IconColumn::make('tiene_factura')
                    ->label('Factura')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-check')
                    ->falseIcon('heroicon-o-document')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn($state) => match($state) {
                        'pendiente' => '⏳ Pendiente',
                        'aprobado' => '✅ Aprobado',
                        'rechazado' => '❌ Rechazado',
                        default => $state,
                    })
                    ->color(fn($state) => match ($state) {
                        'pendiente' => 'warning',
                        'aprobado' => 'success',
                        'rechazado' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn($record) => $record->estado === 'rechazado' && $record->motivo_rechazo 
                        ? "Motivo: {$record->motivo_rechazo}" 
                        : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo_gasto')
                    ->label('Tipo')
                    ->options(CamionGasto::TIPOS_GASTO),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(CamionGasto::ESTADOS),

                Tables\Filters\Filter::make('fecha')
                    ->form([
                        Forms\Components\Select::make('periodo')
                            ->label('Período')
                            ->options([
                                'hoy' => 'Hoy',
                                'esta_semana' => 'Esta Semana',
                                'este_mes' => 'Este Mes',
                                'personalizado' => 'Personalizado',
                            ])
                            ->live(),
                        Forms\Components\DatePicker::make('fecha_desde')
                            ->label('Desde')
                            ->visible(fn(Forms\Get $get) => $get('periodo') === 'personalizado'),
                        Forms\Components\DatePicker::make('fecha_hasta')
                            ->label('Hasta')
                            ->visible(fn(Forms\Get $get) => $get('periodo') === 'personalizado'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['periodo'] === 'hoy', fn($q) => $q->whereDate('fecha', now()))
                            ->when($data['periodo'] === 'esta_semana', fn($q) => $q->whereBetween('fecha', [now()->startOfWeek(), now()->endOfWeek()]))
                            ->when($data['periodo'] === 'este_mes', fn($q) => $q->whereBetween('fecha', [now()->startOfMonth(), now()->endOfMonth()]))
                            ->when($data['periodo'] === 'personalizado' && $data['fecha_desde'], fn($q) => $q->whereDate('fecha', '>=', $data['fecha_desde']))
                            ->when($data['periodo'] === 'personalizado' && $data['fecha_hasta'], fn($q) => $q->whereDate('fecha', '<=', $data['fecha_hasta']));
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (!$data['periodo']) {
                            return null;
                        }
                        return match($data['periodo']) {
                            'hoy' => 'Hoy',
                            'esta_semana' => 'Esta Semana',
                            'este_mes' => 'Este Mes',
                            'personalizado' => 'Personalizado',
                            default => null,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('ver_rechazo')
                    ->label('Ver Motivo')
                    ->icon('heroicon-o-exclamation-circle')
                    ->color('danger')
                    ->visible(fn($record) => $record->estado === 'rechazado' && $record->motivo_rechazo)
                    ->modalHeading('Motivo del Rechazo')
                    ->modalContent(fn($record) => view('filament.components.motivo-rechazo', ['gasto' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
            ])
            ->emptyStateHeading('No tienes gastos registrados')
            ->emptyStateDescription('Presiona el botón "Nuevo Gasto" para registrar uno.')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->defaultSort('fecha', 'desc')
            ->poll('30s');
    }

    /**
     * Acciones del header
     */
    protected function getHeaderActions(): array
    {
        // Si no puede registrar gastos, no mostrar botón
        if (!$this->puedeRegistrarGastos) {
            return [];
        }

        return [
            \Filament\Actions\Action::make('nuevo_gasto')
                ->label($this->mostrarFormulario ? 'Cancelar' : 'Nuevo Gasto')
                ->icon($this->mostrarFormulario ? 'heroicon-o-x-mark' : 'heroicon-o-plus')
                ->color($this->mostrarFormulario ? 'gray' : 'primary')
                ->action(fn() => $this->mostrarFormulario = !$this->mostrarFormulario),
        ];
    }

    /**
     * Obtener el total gastado hoy (del viaje activo)
     */
    public function getTotalHoyProperty(): float
    {
        return CamionGasto::where('chofer_id', Auth::id())
            ->when(
                $this->viajeActivo,
                fn($query) => $query->where('viaje_id', $this->viajeActivo->id),
                fn($query) => $query->whereNull('viaje_id')
            )
            ->whereDate('fecha', now())
            ->sum('monto');
    }

    /**
     * Obtener el total gastado en el viaje activo (o este mes si no hay viaje)
     */
    public function getTotalMesProperty(): float
    {
        $query = CamionGasto::where('chofer_id', Auth::id());
        
        if ($this->viajeActivo) {
            // Total del viaje activo
            return $query->where('viaje_id', $this->viajeActivo->id)->sum('monto');
        }
        
        // Si no hay viaje, mostrar total del mes
        return $query->whereNull('viaje_id')
            ->whereBetween('fecha', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('monto');
    }
}