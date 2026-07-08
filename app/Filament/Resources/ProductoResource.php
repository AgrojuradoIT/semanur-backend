<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductoResource\Pages;
use App\Models\Producto;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions;
use Filament\Schemas\Schema;
use BackedEnum;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('categoria_id')
                    ->relationship('categoria', 'categoria_nombre')
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('producto_sku')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('producto_nombre')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('producto_unidad_medida')
                    ->maxLength(255),
                Forms\Components\TextInput::make('producto_stock_actual')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('producto_precio_costo')
                    ->numeric()
                    ->prefix('$'),
                Forms\Components\TextInput::make('producto_ubicacion')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('producto_sku')
                    ->searchable(),
                Tables\Columns\TextColumn::make('producto_nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('categoria.categoria_nombre')
                    ->sortable(),
                Tables\Columns\TextColumn::make('producto_stock_actual')
                    ->numeric()
                    ->sortable(),
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
            'index' => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'edit' => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
