<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransaccionInventarioResource\Pages;
use App\Models\TransaccionInventario;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TransaccionInventarioResource extends Resource
{
    protected static ?string $model = TransaccionInventario::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-trending-up';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('producto_id')
                    ->relationship('producto', 'producto_nombre')
                    ->disabled(),
                Forms\Components\Select::make('bodega_id')
                    ->relationship('bodega', 'nombre')
                    ->disabled(),
                Forms\Components\Select::make('usuario_id')
                    ->relationship('usuario', 'name')
                    ->disabled(),
                Forms\Components\TextInput::make('transaccion_tipo')
                    ->disabled(),
                Forms\Components\TextInput::make('transaccion_cantidad')
                    ->numeric()
                    ->disabled(),
                Forms\Components\TextInput::make('transaccion_motivo')
                    ->disabled(),
                Forms\Components\TextInput::make('transaccion_referencia_type')
                    ->disabled(),
                Forms\Components\TextInput::make('transaccion_referencia_id')
                    ->disabled(),
                Forms\Components\Textarea::make('transaccion_notas')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('producto.producto_nombre')
                    ->label('Producto')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->placeholder('Global / Sin bodega')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('transaccion_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'ingreso', 'entrada' => 'success',
                        'salida' => 'danger',
                        'transferencia' => 'warning',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('transaccion_cantidad')
                    ->label('Cantidad')
                    ->numeric(2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('transaccion_motivo')
                    ->label('Motivo')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('usuario.name')
                    ->label('Registrado por')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('transaccion_notas')
                    ->label('Notas')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                // Protegido: sin eliminación masiva del libro mayor
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListTransaccionInventarios::route('/'),
        ];
    }
}
