<?php

namespace App\Filament\Resources\EmpleadoResource\RelationManagers;

use App\Models\Vehiculo;
use App\Models\OrdenTrabajo;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;

class ProgramacionesRelationManager extends RelationManager
{
    protected static string $relationship = 'programaciones';

    protected static ?string $title = 'Programación y Asignaciones';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\DatePicker::make('fecha')
                    ->label('Fecha')
                    ->required(),
                Forms\Components\Select::make('vehiculo_id')
                    ->label('Vehículo')
                    ->options(Vehiculo::all()->pluck('placa', 'vehiculo_id'))
                    ->searchable()
                    ->nullable(),
                Forms\Components\TextInput::make('labor')
                    ->label('Labor / Tarea')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('ubicacion')
                    ->label('Ubicación')
                    ->maxLength(255),
                Forms\Components\Select::make('estado')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'pausado' => 'Pausado',
                        'completado' => 'Completado',
                    ])
                    ->default('pendiente')
                    ->required(),
                Forms\Components\Select::make('orden_trabajo_id')
                    ->label('Orden de Trabajo Relacionada')
                    ->options(OrdenTrabajo::all()->pluck('descripcion', 'orden_trabajo_id'))
                    ->searchable()
                    ->nullable(),
                Forms\Components\Toggle::make('es_novedad')
                    ->label('Es Novedad')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('labor')
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehiculo.placa')
                    ->label('Vehículo')
                    ->default('N/A')
                    ->searchable(),
                Tables\Columns\TextColumn::make('labor')
                    ->label('Labor')
                    ->searchable(),
                Tables\Columns\TextColumn::make('ubicacion')
                    ->label('Ubicación')
                    ->default('N/A')
                    ->searchable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pendiente' => 'warning',
                        'pausado' => 'danger',
                        'completado' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                Tables\Columns\IconColumn::make('es_novedad')
                    ->label('Novedad')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
