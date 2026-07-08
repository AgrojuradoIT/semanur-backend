<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransaccionInventarioResource\Pages;
use App\Models\TransaccionInventario;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use Filament\Schemas\Schema;
use BackedEnum;

class TransaccionInventarioResource extends Resource
{
    protected static ?string $model = TransaccionInventario::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-trending-up';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('producto_id')
                    ->relationship('producto', 'producto_nombre')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('usuario_id')
                    ->relationship('usuario', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('transaccion_tipo')
                    ->options([
                        'entrada' => 'Entrada',
                        'salida' => 'Salida',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('transaccion_cantidad')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('transaccion_motivo')
                    ->maxLength(255),
                Forms\Components\Textarea::make('transaccion_notas'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('producto.producto_nombre')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('transaccion_tipo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('transaccion_cantidad')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('usuario.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
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
            'index' => Pages\ListTransaccionInventarios::route('/'),
            'create' => Pages\CreateTransaccionInventario::route('/create'),
            'edit' => Pages\EditTransaccionInventario::route('/{record}/edit'),
        ];
    }
}
