<?php

namespace App\Filament\Resources\TransaccionInventarioResource\Pages;

use App\Filament\Resources\TransaccionInventarioResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTransaccionInventario extends EditRecord
{
    protected static string $resource = TransaccionInventarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
