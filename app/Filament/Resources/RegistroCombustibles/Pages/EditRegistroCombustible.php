<?php

namespace App\Filament\Resources\RegistroCombustibles\Pages;

use App\Filament\Resources\RegistroCombustibles\RegistroCombustibleResource;
use App\Models\RegistroCombustible;
use App\Services\CombustibleService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRegistroCombustible extends EditRecord
{
    protected static string $resource = RegistroCombustibleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (RegistroCombustible $record): bool => app(CombustibleService::class)
                    ->destroyRegistro($record, auth()->id())),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $result = app(CombustibleService::class)->updateRegistro(
            $record,
            $data,
            auth()->id(),
        );

        return $result['registro'];
    }
}
