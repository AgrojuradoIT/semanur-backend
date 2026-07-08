<?php

namespace App\Filament\Resources\RegistroCombustibles;

use App\Filament\Resources\RegistroCombustibles\Pages\CreateRegistroCombustible;
use App\Filament\Resources\RegistroCombustibles\Pages\EditRegistroCombustible;
use App\Filament\Resources\RegistroCombustibles\Pages\ListRegistroCombustibles;
use App\Filament\Resources\RegistroCombustibles\Schemas\RegistroCombustibleForm;
use App\Filament\Resources\RegistroCombustibles\Tables\RegistroCombustiblesTable;
use App\Models\RegistroCombustible;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RegistroCombustibleResource extends Resource
{
    protected static ?string $model = RegistroCombustible::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return RegistroCombustibleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegistroCombustiblesTable::configure($table);
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
            'index' => ListRegistroCombustibles::route('/'),
            'create' => CreateRegistroCombustible::route('/create'),
            'edit' => EditRegistroCombustible::route('/{record}/edit'),
        ];
    }
}
