<?php

namespace App\Filament\Resources\EmpleadoResource\RelationManagers;

use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;

class OrdenesTrabajoRelationManager extends RelationManager
{
    protected static string $relationship = 'ordenesTrabajoAsignadas';

    protected static ?string $title = 'Órdenes de Trabajo Asignadas';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('vehiculo_id')
                    ->relationship('vehiculo', 'placa')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Vehículo'),
                Forms\Components\DateTimePicker::make('fecha_inicio')
                    ->required()
                    ->default(fn () => now())
                    ->displayFormat('d/m/Y h:i A')
                    ->seconds(false)
                    ->native(false)
                    ->label('Fecha Inicio'),
                Forms\Components\DateTimePicker::make('fecha_fin')
                    ->displayFormat('d/m/Y h:i A')
                    ->seconds(false)
                    ->native(false)
                    ->label('Fecha Fin'),
                Forms\Components\Select::make('estado')
                    ->options([
                        'Abierta' => 'Abierta',
                        'En Proceso' => 'En Proceso',
                        'Finalizada' => 'Finalizada',
                        'Cancelada' => 'Cancelada',
                    ])
                    ->default('Abierta')
                    ->required()
                    ->label('Estado'),
                Forms\Components\Select::make('prioridad')
                    ->options([
                        'Alta' => 'Alta',
                        'Media' => 'Media',
                        'Baja' => 'Baja',
                    ])
                    ->default('Media')
                    ->required()
                    ->label('Prioridad'),
                Forms\Components\Textarea::make('descripcion')
                    ->required()
                    ->columnSpanFull()
                    ->label('Descripción / Diagnóstico'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('orden_trabajo_id')
            ->columns([
                Tables\Columns\TextColumn::make('orden_trabajo_id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehiculo.placa')
                    ->label('Vehículo')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_inicio')
                    ->label('Inicio')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable()
                    ->timezone('America/Bogota'),
                Tables\Columns\TextColumn::make('fecha_fin')
                    ->label('Fin')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable()
                    ->timezone('America/Bogota')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
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
                    ->label('Prioridad')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Alta' => 'danger',
                        'Media' => 'warning',
                        'Baja' => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
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
