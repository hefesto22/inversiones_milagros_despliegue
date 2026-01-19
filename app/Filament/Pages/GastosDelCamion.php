<?php

namespace App\Filament\Pages;

use App\Models\CamionGasto;
use App\Models\Camion;
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

    // Link del grupo de WhatsApp (configurable)
    protected string $whatsappGrupo = ''; // Aquí irá el link del grupo

    public function mount(): void
    {
        $this->form->fill([
            'fecha' => now()->format('Y-m-d'),
            'camion_id' => Auth::user()->asignacionCamionActiva?->camion_id,
        ]);
    }

    /**
     * Verificar si el usuario puede acceder (solo choferes)
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole('Chofer');
    }

    /**
     * Formulario simple para registrar gasto
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Registrar Nuevo Gasto')
                    ->description('Completa los datos del gasto. Luego podrás enviarlo por WhatsApp.')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('camion_id')
                                    ->label('Camión')
                                    ->options(function () {
                                        $user = Auth::user();
                                        $camionAsignado = $user->asignacionCamionActiva?->camion;
                                        
                                        if ($camionAsignado) {
                                            return [$camionAsignado->id => "{$camionAsignado->codigo} - {$camionAsignado->placa}"];
                                        }
                                        
                                        return Camion::where('activo', true)
                                            ->get()
                                            ->mapWithKeys(fn($c) => [$c->id => "{$c->codigo} - {$c->placa}"]);
                                    })
                                    ->required()
                                    ->disabled(fn() => Auth::user()->asignacionCamionActiva !== null)
                                    ->dehydrated(),

                                Forms\Components\DatePicker::make('fecha')
                                    ->label('Fecha')
                                    ->required()
                                    ->default(now())
                                    ->maxDate(now())
                                    ->native(false),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('tipo_gasto')
                                    ->label('Tipo de Gasto')
                                    ->options(CamionGasto::TIPOS_GASTO)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(fn(Forms\Set $set) => $set('litros', null)),

                                Forms\Components\TextInput::make('monto')
                                    ->label('Monto')
                                    ->required()
                                    ->numeric()
                                    ->prefix('L')
                                    ->minValue(0.01)
                                    ->step(0.01)
                                    ->placeholder('0.00'),
                            ]),

                        // Campos de gasolina
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('litros')
                                    ->label('Litros')
                                    ->numeric()
                                    ->minValue(0.001)
                                    ->step(0.001)
                                    ->suffix('L')
                                    ->placeholder('0.000'),

                                Forms\Components\TextInput::make('kilometraje')
                                    ->label('Kilometraje Actual')
                                    ->numeric()
                                    ->suffix('km')
                                    ->placeholder('0'),
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
     * Guardar el gasto
     */
    public function guardarGasto(): void
    {
        $datos = $this->form->getState();

        $gasto = CamionGasto::create([
            'camion_id' => $datos['camion_id'],
            'chofer_id' => Auth::id(),
            'fecha' => $datos['fecha'],
            'tipo_gasto' => $datos['tipo_gasto'],
            'monto' => $datos['monto'],
            'litros' => $datos['litros'] ?? null,
            'kilometraje' => $datos['kilometraje'] ?? null,
            'proveedor' => $datos['proveedor'] ?? null,
            'tiene_factura' => $datos['tiene_factura'] ?? false,
            'descripcion' => $datos['descripcion'] ?? null,
            'estado' => 'pendiente',
            'created_by' => Auth::id(),
        ]);

        // Limpiar formulario
        $this->form->fill([
            'fecha' => now()->format('Y-m-d'),
            'camion_id' => Auth::user()->asignacionCamionActiva?->camion_id,
            'tipo_gasto' => null,
            'monto' => null,
            'litros' => null,
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
     * Tabla de gastos del chofer
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                CamionGasto::query()
                    ->where('chofer_id', Auth::id())
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

                Tables\Columns\IconColumn::make('tiene_factura')
                    ->label('Factura')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-check')
                    ->falseIcon('heroicon-o-document')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('enviado_whatsapp')
                    ->label('WhatsApp')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),

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
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo_gasto')
                    ->label('Tipo')
                    ->options(CamionGasto::TIPOS_GASTO),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(CamionGasto::ESTADOS),

                Tables\Filters\Filter::make('hoy')
                    ->label('Solo Hoy')
                    ->query(fn(Builder $query) => $query->whereDate('fecha', now()))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\Action::make('enviar_whatsapp')
                    ->label('Enviar WhatsApp')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn($record) => !$record->enviado_whatsapp)
                    ->url(fn($record) => $this->generarUrlWhatsApp($record), shouldOpenInNewTab: true)
                    ->after(function ($record) {
                        $record->marcarEnviadoWhatsApp();
                        
                        Notification::make()
                            ->title('Marcado como enviado')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('ver_mensaje')
                    ->label('Ver Mensaje')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Mensaje de WhatsApp')
                    ->modalContent(fn($record) => view('filament.components.mensaje-whatsapp', ['gasto' => $record]))
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
     * Generar URL de WhatsApp con mensaje prellenado
     */
    protected function generarUrlWhatsApp(CamionGasto $gasto): string
    {
        $mensaje = $gasto->generarMensajeWhatsApp();
        $mensajeCodificado = urlencode($mensaje);

        // Si hay un grupo configurado
        if (!empty($this->whatsappGrupo)) {
            // Para grupos se usa el link directo (el mensaje no se puede prellenar en grupos)
            return $this->whatsappGrupo;
        }

        // Sin grupo, abre WhatsApp con el mensaje
        return "https://wa.me/?text={$mensajeCodificado}";
    }

    /**
     * Acciones del header
     */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('nuevo_gasto')
                ->label($this->mostrarFormulario ? 'Cancelar' : 'Nuevo Gasto')
                ->icon($this->mostrarFormulario ? 'heroicon-o-x-mark' : 'heroicon-o-plus')
                ->color($this->mostrarFormulario ? 'gray' : 'primary')
                ->action(fn() => $this->mostrarFormulario = !$this->mostrarFormulario),
        ];
    }

    /**
     * Obtener el total de gastos pendientes
     */
    public function getGastosPendientesProperty(): int
    {
        return CamionGasto::where('chofer_id', Auth::id())
            ->where('estado', 'pendiente')
            ->count();
    }

    /**
     * Obtener el total gastado hoy
     */
    public function getTotalHoyProperty(): float
    {
        return CamionGasto::where('chofer_id', Auth::id())
            ->whereDate('fecha', now())
            ->sum('monto');
    }
}