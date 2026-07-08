<?php

namespace App\Filament\Resources\RegistroCombustibles\Pages;

use App\Filament\Resources\RegistroCombustibles\RegistroCombustibleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegistroCombustible extends EditRecord
{
    protected static string $resource = RegistroCombustibleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
