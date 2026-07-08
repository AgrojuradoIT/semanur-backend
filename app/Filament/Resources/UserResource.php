<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use BackedEnum;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Usuarios';

    public static function form(Schema $schema): Schema
    {
        $modulos = User::modulos();
        $granularOptions = [];
        foreach ($modulos as $key => $label) {
            $granularOptions["{$key}.read"] = "{$label} (Lectura)";
            $granularOptions["{$key}.write"] = "{$label} (Escritura)";
        }

        return $schema
            ->schema([
                Section::make('Datos del Usuario')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->label('Nombre'),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn ($livewire) => $livewire instanceof Pages\CreateUser)
                            ->maxLength(255)
                            ->label('Contraseña'),
                        Forms\Components\Select::make('role')
                            ->options([
                                'admin' => 'Administrador',
                                'jefe_taller' => 'Jefe de Taller',
                                'auxiliar_bodega' => 'Auxiliar de Bodega',
                                'operativo' => 'Operativo',
                                'visualizador' => 'Visualizador',
                            ])
                            ->default('operativo')
                            ->required()
                            ->label('Rol'),
                        Forms\Components\TextInput::make('phone')
                            ->tel()
                            ->maxLength(20)
                            ->label('Teléfono'),
                    ])
                    ->columns(2),

                Section::make('Permisos por Módulo')
                    ->description(fn ($record) => $record && !$record->isAdmin()
                        ? 'Los permisos del rol ' . strtoupper($record->role) . ' se heredan automáticamente. Acá podés agregar módulos extras para este usuario.'
                        : 'Los administradores tienen acceso total automáticamente.'
                    )
                    ->schema([
                        Forms\Components\Placeholder::make('role_defaults_info')
                            ->content(function ($record) use ($granularOptions) {
                                if (!$record || $record->isAdmin()) {
                                    return 'Admin: acceso total';
                                }
                                $defaults = $record->roleDefaults();
                                $labels = collect($defaults)->map(fn ($m) => $granularOptions[$m] ?? $m)->join(', ');
                                return 'Por defecto (rol): ' . ($labels ?: 'Ninguno');
                            })
                            ->hidden(fn ($record) => !$record),
                        Forms\Components\CheckboxList::make('permisos')
                            ->options($granularOptions)
                            ->columns(2)
                            ->gridDirection('row')
                            ->label('Módulos adicionales')
                            ->helperText('Seleccioná los módulos extras además de los que ya tiene por su rol.'),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Nombre'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'jefe_taller' => 'warning',
                        'auxiliar_bodega' => 'info',
                        'operativo' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Admin',
                        'jefe_taller' => 'Jefe Taller',
                        'auxiliar_bodega' => 'Aux. Bodega',
                        'operativo' => 'Operativo',
                        default => ucfirst($state),
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('permisos')
                    ->label('Módulos')
                    ->formatStateUsing(fn ($record) => $record->isAdmin()
                        ? 'Todos'
                        : (collect($record->permisos_labels)->join(', ') ?: 'Ninguno')
                    )
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Creado'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'admin' => 'Administrador',
                        'jefe_taller' => 'Jefe de Taller',
                        'auxiliar_bodega' => 'Auxiliar de Bodega',
                        'operativo' => 'Operativo',
                        'visualizador' => 'Visualizador',
                    ])
                    ->label('Rol'),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
