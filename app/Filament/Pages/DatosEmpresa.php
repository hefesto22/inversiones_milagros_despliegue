<?php

namespace App\Filament\Pages;

use App\Models\Empresa;
use Filament\Pages\Page;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class DatosEmpresa extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Datos de Empresa';
    protected static ?string $title = 'Datos de Empresa';
    protected static ?string $slug = 'datos-empresa';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.datos-empresa';

    public ?array $data = [];

    public function mount(): void
    {
        $empresa = Empresa::first();

        if ($empresa) {
            $this->form->fill($empresa->toArray());
        } else {
            $this->form->fill();
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información General')
                    ->description('Datos básicos de la empresa')
                    ->icon('heroicon-o-building-office')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('nombre')
                                ->label('Nombre de la Empresa')
                                ->required()
                                ->maxLength(150)
                                ->placeholder('Ej: Mi Empresa S.A.'),

                            TextInput::make('rtn')
                                ->label('RTN')
                                ->maxLength(20)
                                ->placeholder('Ej: 04011995001234'),
                        ]),

                        FileUpload::make('logo')
                            ->label('Logo de la Empresa')
                            ->image()
                            ->directory('empresa')
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth('200')
                            ->imageResizeTargetHeight('200')
                            ->maxSize(4096)
                            ->helperText('Imagen cuadrada recomendada. Máximo 4MB.'),

                        TextInput::make('cai')
                            ->label('CAI')
                            ->maxLength(50)
                            ->placeholder('Ej: 2D9140-66ECB0-947BE0-63BE03-09090C-7F')
                            ->helperText('Código de Autorización de Impresión'),

                        Textarea::make('lema')
                            ->label('Lema o Descripción')
                            ->maxLength(255)
                            ->rows(2)
                            ->placeholder('Ej: Venta y distribución de productos de calidad'),
                    ]),

                Section::make('Contacto')
                    ->description('Información de contacto')
                    ->icon('heroicon-o-phone')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('telefono')
                                ->label('Teléfono')
                                ->tel()
                                ->maxLength(20)
                                ->placeholder('Ej: 3296-0955'),

                            TextInput::make('correo_electronico')
                                ->label('Correo Electrónico')
                                ->email()
                                ->maxLength(100)
                                ->placeholder('Ej: info@miempresa.com'),
                        ]),

                        Textarea::make('direccion')
                            ->label('Dirección')
                            ->rows(2)
                            ->placeholder('Dirección completa de la empresa'),
                    ]),

                Section::make('Rango de Facturación')
                    ->description('Configuración del rango autorizado para facturación')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('rango_desde')
                                ->label('Rango Desde')
                                ->maxLength(25)
                                ->placeholder('000-001-01-00001601'),

                            TextInput::make('rango_hasta')
                                ->label('Rango Hasta')
                                ->maxLength(25)
                                ->placeholder('000-001-01-00001750'),

                            TextInput::make('ultimo_numero_emitido')
                                ->label('Último Número Emitido')
                                ->maxLength(25)
                                ->placeholder('000-001-01-00001708'),
                        ]),

                        DatePicker::make('fecha_limite_emision')
                            ->label('Fecha Límite de Emisión')
                            ->displayFormat('d/m/Y')
                            ->helperText('Fecha límite para emitir facturas con este CAI'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar Cambios')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $empresa = Empresa::first();

        if ($empresa) {
            $empresa->update($data);
        } else {
            Empresa::create($data);
        }

        Notification::make()
            ->title('Datos guardados')
            ->body('La información de la empresa se ha actualizado correctamente.')
            ->success()
            ->send();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('guardar')
                ->label('Guardar Cambios')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action('save'),
        ];
    }
}
