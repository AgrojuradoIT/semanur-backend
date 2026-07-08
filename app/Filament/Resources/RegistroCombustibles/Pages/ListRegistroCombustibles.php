<?php

namespace App\Filament\Resources\RegistroCombustibles\Pages;

use App\Filament\Resources\RegistroCombustibles\RegistroCombustibleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegistroCombustibles extends ListRecords
{
    protected static string $resource = RegistroCombustibleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
