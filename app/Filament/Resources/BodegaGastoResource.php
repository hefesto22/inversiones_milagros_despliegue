<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BodegaGastoResource\Pages;
use App\Models\BodegaGasto;
use App\Models\Bodega;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class BodegaGastoResource extends Resource
{
    protected static ?string $model = BodegaGasto::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Inventario';
    protected static ?int $navigationSort = 6;
    protected static ?string $modelLabel = 'Gasto de Bodega';
    protected static ?string $pluralModelLabel = 'Gastos de Bodega';
    protected static ?string $slug = 'bodega-gastos';

    // =========================================================
    // FILTRAR POR BODEGA DEL USUARIO
    // =========================================================

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $currentUser = Auth::user();

        if (!$currentUser) {
            return $query->whereRaw('1 = 0');
        }

        // Super Admin y Jefe ven todos los gastos
        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        if ($esSuperAdminOJefe) {
            return $query;
        }

        // Encargados solo ven gastos de sus bodegas
        $bodegasUsuario = DB::table('bodega_user')
            ->where('user_id', $currentUser->id)
            ->where('activo', true)
            ->pluck('bodega_id')
            ->toArray();

        return $query->whereIn('bodega_id', $bodegasUsuario);
    }

    // =========================================================
    // FORMULARIO
    // =========================================================

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del Gasto')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('bodega_id')
                            ->label('Bodega')
                            ->options(function () {
                                $currentUser = Auth::user();
                                if (!$currentUser) return [];

                                $esSuperAdminOJefe = DB::table('model_has_roles')
                                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                    ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                    ->where('model_has_roles.model_id', '=', $currentUser->id)
                                    ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                    ->exists();

                                if ($esSuperAdminOJefe) {
                                    return Bodega::where('activo', true)->pluck('nombre', 'id')->toArray();
                                }

                                return DB::table('bodega_user')
                                    ->join('bodegas', 'bodega_user.bodega_id', '=', 'bodegas.id')
                                    ->where('bodega_user.user_id', $currentUser->id)
                                    ->where('bodega_user.activo', true)
                                    ->where('bodegas.activo', true)
                                    ->pluck('bodegas.nombre', 'bodegas.id')
                                    ->toArray();
                            })
                            ->default(function () {
                                $currentUser = Auth::user();
                                if (!$currentUser) return null;

                                return DB::table('bodega_user')
                                    ->where('user_id', $currentUser->id)
                                    ->where('activo', true)
                                    ->value('bodega_id');
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn(string $operation) => $operation === 'edit')
                            ->dehydrated(),

                        Forms\Components\DatePicker::make('fecha')
                            ->label('Fecha del Gasto')
                            ->required()
                            ->default(now())
                            ->maxDate(now())
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ]),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('tipo_gasto')
                            ->label('Categoría')
                            ->options(BodegaGasto::TIPOS_GASTO)
                            ->required()
                            ->searchable()
                            ->native(false),

                        Forms\Components\TextInput::make('monto')
                            ->label('Monto Total')
                            ->required()
                            ->numeric()
                            ->prefix('L')
                            ->minValue(0.01)
                            ->maxValue(999999.99),
                    ]),

                    Forms\Components\Textarea::make('detalle')
                        ->label('Detalle del Gasto')
                        ->required()
                        ->placeholder('Ej: Cartulina opaca x35, 2 galones de cloro, etc.')
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('tiene_factura')
                        ->label('¿Tiene factura?')
                        ->default(false)
                        ->helperText('Si tiene factura, podrás adjuntar la foto al enviar por WhatsApp')
                        ->inline(false),
                ]),

            // Sección visible solo en edición
            Forms\Components\Section::make('Estado del Gasto')
                ->schema([
                    Forms\Components\Placeholder::make('estado_info')
                        ->label('')
                        ->content(function ($record) {
                            if (!$record) return '';

                            $estado = $record->estado;
                            $color = $estado === 'aprobado' ? 'green' : 'amber';
                            $icon = $estado === 'aprobado' ? '✓' : '⏳';
                            $label = $estado === 'aprobado' ? 'Aprobado' : 'Pendiente de Aprobación';

                            $html = "<div class='flex items-center gap-2'>
                                <span class='text-{$color}-600 text-xl'>{$icon}</span>
                                <span class='font-semibold text-{$color}-600'>{$label}</span>
                            </div>";

                            if ($record->isAprobado() && $record->aprobador) {
                                $html .= "<p class='text-sm text-gray-500 mt-1'>
                                    Aprobado por {$record->aprobador->name} el {$record->aprobado_at->format('d/m/Y H:i')}
                                </p>";
                            }

                            return new \Illuminate\Support\HtmlString($html);
                        }),

                    Forms\Components\Placeholder::make('whatsapp_info')
                        ->label('')
                        ->content(function ($record) {
                            if (!$record) return '';

                            if ($record->enviado_whatsapp) {
                                return new \Illuminate\Support\HtmlString("
                                    <div class='flex items-center gap-2 text-green-600'>
                                        <span>✓</span>
                                        <span>Enviado por WhatsApp el {$record->enviado_whatsapp_at->format('d/m/Y H:i')}</span>
                                    </div>
                                ");
                            }

                            return new \Illuminate\Support\HtmlString("
                                <div class='flex items-center gap-2 text-amber-600'>
                                    <span>⏳</span>
                                    <span>Pendiente de enviar por WhatsApp</span>
                                </div>
                            ");
                        }),
                ])
                ->visible(fn($record) => $record !== null)
                ->collapsible(),
        ]);
    }

    // =========================================================
    // TABLA
    // =========================================================

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tipo_gasto')
                    ->label('Categoría')
                    ->formatStateUsing(fn($state) => BodegaGasto::TIPOS_GASTO[$state] ?? $state)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('detalle')
                    ->label('Detalle')
                    ->limit(40)
                    ->tooltip(fn($record) => $record->detalle)
                    ->searchable(),

                Tables\Columns\TextColumn::make('monto')
                    ->label('Monto')
                    ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2))
                    ->sortable()
                    ->summarize([
                        Tables\Columns\Summarizers\Sum::make()
                            ->formatStateUsing(fn($state) => 'L ' . number_format($state, 2)),
                    ]),

                Tables\Columns\IconColumn::make('tiene_factura')
                    ->label('Factura')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-check')
                    ->falseIcon('heroicon-o-document-minus')
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
                    ->formatStateUsing(fn($state) => $state === 'aprobado' ? 'Aprobado' : 'Pendiente')
                    ->color(fn($state) => $state === 'aprobado' ? 'success' : 'warning')
                    ->sortable(),

                Tables\Columns\TextColumn::make('registrador.name')
                    ->label('Registrado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('tipo_gasto')
                    ->label('Categoría')
                    ->options(BodegaGasto::TIPOS_GASTO),

                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'aprobado' => 'Aprobado',
                    ]),

                Tables\Filters\TernaryFilter::make('tiene_factura')
                    ->label('¿Tiene Factura?'),

                Tables\Filters\TernaryFilter::make('enviado_whatsapp')
                    ->label('¿Enviado por WhatsApp?'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                // Botón Enviar WhatsApp
                Tables\Actions\Action::make('enviar_whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('success')
                    ->url(fn($record) => $record->generarUrlWhatsapp())
                    ->openUrlInNewTab()
                    ->after(function ($record) {
                        $record->marcarEnviadoWhatsapp();

                        Notification::make()
                            ->success()
                            ->title('Enviado')
                            ->body('Se abrió WhatsApp. No olvides adjuntar la factura si aplica.')
                            ->send();
                    })
                    ->visible(fn($record) => !$record->enviado_whatsapp)
                    ->requiresConfirmation()
                    ->modalHeading('Enviar por WhatsApp')
                    ->modalDescription(fn($record) => $record->tiene_factura
                        ? 'Se abrirá WhatsApp con los datos del gasto. Recuerda adjuntar la foto de la factura.'
                        : 'Se abrirá WhatsApp con los datos del gasto.')
                    ->modalSubmitActionLabel('Abrir WhatsApp'),

                // Botón Aprobar (solo para Jefe/Admin)
                Tables\Actions\Action::make('aprobar')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->action(function ($record) {
                        $record->aprobar(Auth::id());

                        Notification::make()
                            ->success()
                            ->title('Gasto Aprobado')
                            ->body('El gasto ha sido aprobado correctamente.')
                            ->send();
                    })
                    ->visible(function ($record) {
                        if ($record->estado !== 'pendiente') return false;

                        $currentUser = Auth::user();
                        return DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                            ->exists();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Gasto')
                    ->modalDescription('¿Estás seguro de aprobar este gasto?'),

                // Botón Rechazar (eliminar - solo para Jefe/Admin)
                Tables\Actions\Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(function ($record) {
                        $record->delete(); // Soft delete

                        Notification::make()
                            ->warning()
                            ->title('Gasto Rechazado')
                            ->body('El gasto ha sido rechazado y eliminado.')
                            ->send();
                    })
                    ->visible(function ($record) {
                        if ($record->estado !== 'pendiente') return false;

                        $currentUser = Auth::user();
                        return DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_type', '=', get_class($currentUser))
                            ->where('model_has_roles.model_id', '=', $currentUser->id)
                            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                            ->exists();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar Gasto')
                    ->modalDescription('¿Estás seguro de rechazar este gasto? Se eliminará del sistema.'),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn($record) => $record->estado === 'pendiente'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Aprobar masivo
                    Tables\Actions\BulkAction::make('aprobar_masivo')
                        ->label('Aprobar Seleccionados')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->estado === 'pendiente') {
                                    $record->aprobar(Auth::id());
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Gastos Aprobados')
                                ->body("{$count} gastos han sido aprobados.")
                                ->send();
                        })
                        ->visible(function () {
                            $currentUser = Auth::user();
                            return DB::table('model_has_roles')
                                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                ->where('model_has_roles.model_id', '=', $currentUser->id)
                                ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                ->exists();
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(function () {
                            $currentUser = Auth::user();
                            return DB::table('model_has_roles')
                                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                                ->where('model_has_roles.model_type', '=', get_class($currentUser))
                                ->where('model_has_roles.model_id', '=', $currentUser->id)
                                ->whereIn('roles.name', ['Super Admin', 'Jefe'])
                                ->exists();
                        }),

                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('fecha', 'desc');
    }

    // =========================================================
    // PÁGINAS
    // =========================================================

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBodegaGastos::route('/'),
            'create' => Pages\CreateBodegaGasto::route('/create'),
            'view' => Pages\ViewBodegaGasto::route('/{record}'),
            'edit' => Pages\EditBodegaGasto::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQueryWithoutScopes(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    // =========================================================
    // BADGE DE NAVEGACIÓN
    // =========================================================

    public static function getNavigationBadge(): ?string
    {
        $currentUser = Auth::user();
        if (!$currentUser) return null;

        $esSuperAdminOJefe = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', '=', get_class($currentUser))
            ->where('model_has_roles.model_id', '=', $currentUser->id)
            ->whereIn('roles.name', ['Super Admin', 'Jefe'])
            ->exists();

        $query = static::getModel()::where('estado', 'pendiente');

        if (!$esSuperAdminOJefe) {
            $bodegasUsuario = DB::table('bodega_user')
                ->where('user_id', $currentUser->id)
                ->where('activo', true)
                ->pluck('bodega_id')
                ->toArray();

            $query->whereIn('bodega_id', $bodegasUsuario);
        }

        $count = $query->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
