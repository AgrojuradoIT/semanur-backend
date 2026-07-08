<?php

namespace App\Filament\Resources\RegistroCombustibles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class RegistroCombustiblesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('tipo_combustible')
                    ->label('Combustible')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'gasolina' => 'info',
                        'acpm' => 'warning',
                        default => 'gray',
                    }),
                \Filament\Tables\Columns\TextColumn::make('tipo_destino')
                    ->label('Destino')
                    ->badge(),
                \Filament\Tables\Columns\TextColumn::make('vehiculo.placa')
                    ->label('Vehículo')
                    ->searchable()
                    ->toggleable(),
                \Filament\Tables\Columns\TextColumn::make('empleado.nombres')
                    ->label('Responsable')
                    ->searchable()
                    ->toggleable(),
                \Filament\Tables\Columns\TextColumn::make('tercero_nombre')
                    ->label('Tercero')
                    ->searchable()
                    ->toggleable(),
                \Filament\Tables\Columns\TextColumn::make('cantidad_galones')
                    ->label('Galones')
                    ->numeric(2)
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('usuario.name')
                    ->label('Registrado por')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('fecha', 'desc');
    }
}
