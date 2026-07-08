<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrdenTrabajoResource\Pages;
use App\Models\OrdenTrabajo;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use Filament\Schemas\Schema;
use BackedEnum;

class OrdenTrabajoResource extends Resource
{
    protected static ?string $model = OrdenTrabajo::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('vehiculo_id')
                    ->relationship('vehiculo', 'placa')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('mecanico_asignado_id')
                    ->relationship('mecanico', 'nombres')
                    ->searchable()
                    ->preload(),
                Forms\Components\DateTimePicker::make('fecha_inicio')
                    ->required()
                    ->default(fn () => now())
                    ->displayFormat('d/m/Y h:i A')
                    ->seconds(false)
                    ->native(false),
                Forms\Components\DateTimePicker::make('fecha_fin')
                    ->displayFormat('d/m/Y h:i A')
                    ->seconds(false)
                    ->native(false),
                Forms\Components\TextInput::make('estado')
                    ->required()
                    ->maxLength(255)
                    ->default('Abierta'),
                Forms\Components\TextInput::make('prioridad')
                    ->required()
                    ->maxLength(255)
                    ->default('Media'),
                Forms\Components\Textarea::make('descripcion')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('vehiculo.placa')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('mecanico.nombres')
                    ->label('Mecánico')
                    ->formatStateUsing(fn ($record) => $record->mecanico ? trim("{$record->mecanico->nombres} {$record->mecanico->apellidos}") : '-')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_inicio')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable()
                    ->timezone('America/Bogota'),
                Tables\Columns\TextColumn::make('fecha_fin')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable()
                    ->timezone('America/Bogota')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Abierta' => 'warning',
                        'En Proceso' => 'info',
                        'Finalizada' => 'success',
                        'Cancelada' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('prioridad')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Alta' => 'danger',
                        'Media' => 'warning',
                        'Baja' => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable()
                    ->timezone('America/Bogota')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrdenTrabajos::route('/'),
            'create' => Pages\CreateOrdenTrabajo::route('/create'),
            'edit' => Pages\EditOrdenTrabajo::route('/{record}/edit'),
        ];
    }
}
