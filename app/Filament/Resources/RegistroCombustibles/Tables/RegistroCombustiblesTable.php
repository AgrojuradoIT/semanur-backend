<?php

namespace App\Filament\Resources\RegistroCombustibles\Tables;

use App\Services\CombustibleService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RegistroCombustiblesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('tipo_combustible')
                    ->label('Combustible')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'gasolina' => 'info',
                        'acpm' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('tipo_destino')
                    ->label('Destino')
                    ->badge(),
                TextColumn::make('vehiculo.placa')
                    ->label('Vehículo')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('empleado.nombres')
                    ->label('Responsable')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('tercero_nombre')
                    ->label('Tercero')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('cantidad_galones')
                    ->label('Galones')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('usuario.name')
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
                    DeleteBulkAction::make()
                        ->using(function (Collection $records): void {
                            DB::transaction(function () use ($records): void {
                                $service = app(CombustibleService::class);

                                foreach ($records as $record) {
                                    $service->destroyRegistro($record, auth()->id());
                                }
                            });
                        }),
                ]),
            ])
            ->defaultSort('fecha', 'desc');
    }
}
