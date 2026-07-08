<x-filament-panels::page>
    {{ $this->form }}

    <div class="mt-6 flex justify-end gap-3">
        <x-filament::button wire:click="save" color="primary" icon="heroicon-o-check">
            Guardar Cambios
        </x-filament::button>
    </div>
</x-filament-panels::page>
