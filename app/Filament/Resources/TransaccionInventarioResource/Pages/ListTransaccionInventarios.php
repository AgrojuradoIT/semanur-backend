<?php

namespace App\Filament\Resources\TransaccionInventarioResource\Pages;

use App\Filament\Resources\TransaccionInventarioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTransaccionInventarios extends ListRecords
{
    protected static string $resource = TransaccionInventarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
