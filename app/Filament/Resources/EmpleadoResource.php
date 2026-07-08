<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmpleadoResource\Pages;
use App\Models\Cargo;
use App\Models\Empleado;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use App\Models\User;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use App\Filament\Resources\EmpleadoResource\RelationManagers\ProgramacionesRelationManager;
use App\Filament\Resources\EmpleadoResource\RelationManagers\OrdenesTrabajoRelationManager;
use BackedEnum;

class EmpleadoResource extends Resource
{
    protected static ?string $model = Empleado::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Empleados';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('tabs')
                    ->tabs([
                        Tab::make('Información Personal (CV)')
                            ->schema([
                                Forms\Components\TextInput::make('nombres')
                                    ->required()
                                    ->maxLength(255)
                                    ->label('Nombres'),
                                Forms\Components\TextInput::make('apellidos')
                                    ->required()
                                    ->maxLength(255)
                                    ->label('Apellidos'),
                                Forms\Components\TextInput::make('documento')
                                    ->maxLength(20)
                                    ->unique(ignorable: fn ($record) => $record)
                                    ->label('Número de Documento'),
                                Forms\Components\TextInput::make('telefono')
                                    ->tel()
                                    ->maxLength(20)
                                    ->label('Teléfono de Contacto'),
                                Forms\Components\TextInput::make('direccion')
                                    ->maxLength(255)
                                    ->label('Dirección de Residencia'),
                                Forms\Components\FileUpload::make('foto_url')
                                    ->image()
                                    ->disk('public')
                                    ->directory('empleados')
                                    ->label('Foto de Perfil'),
                                Forms\Components\RichEditor::make('resumen_profesional')
                                    ->label('Resumen Profesional / Historial Laboral')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Información Laboral')
                            ->schema([
                                Forms\Components\Select::make('cargo')
                                    ->options(Cargo::where('activo', true)->orderBy('orden')->pluck('nombre', 'nombre'))
                                    ->searchable()
                                    ->label('Cargo Asignado'),
                                Forms\Components\Select::make('user_id')
                                    ->options(User::all()->pluck('name', 'id'))
                                    ->searchable()
                                    ->label('Usuario del Sistema')
                                    ->nullable()
                                    ->helperText('Vincula este empleado a su cuenta de usuario para que pueda sincronizar en la App Móvil'),
                                Forms\Components\Select::make('estado')
                                    ->options([
                                        'activo' => 'Activo',
                                        'inactivo' => 'Inactivo',
                                        'retirado' => 'Retirado',
                                    ])
                                    ->default('activo')
                                    ->required()
                                    ->reactive()
                                    ->label('Estado Laboral'),
                                Forms\Components\DatePicker::make('fecha_retiro')
                                    ->label('Fecha de Retiro')
                                    ->visible(fn (callable $get) => $get('estado') === 'retirado')
                                    ->required(fn (callable $get) => $get('estado') === 'retirado'),
                                Forms\Components\Textarea::make('motivo_retiro')
                                    ->label('Motivo de Retiro')
                                    ->visible(fn (callable $get) => $get('estado') === 'retirado')
                                    ->required(fn (callable $get) => $get('estado') === 'retirado')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Tab::make('Licencia de Conducción')
                            ->schema([
                                Forms\Components\TextInput::make('licencia_conduccion')
                                    ->maxLength(50)
                                    ->label('Número de Licencia'),
                                Forms\Components\TextInput::make('categoria_licencia')
                                    ->maxLength(10)
                                    ->placeholder('ej: C2')
                                    ->label('Categoría de Licencia'),
                                Forms\Components\DatePicker::make('vencimiento_licencia')
                                    ->label('Vencimiento de Licencia'),
                            ])
                            ->columns(3),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombres')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('apellidos')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cargo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'activo' => 'success',
                        'inactivo' => 'warning',
                        'retirado' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ProgramacionesRelationManager::class,
            OrdenesTrabajoRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmpleados::route('/'),
            'create' => Pages\CreateEmpleado::route('/create'),
            'edit' => Pages\EditEmpleado::route('/{record}/edit'),
        ];
    }
}
