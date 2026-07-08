<?php

namespace App\Filament\Pages;

use App\Models\RolePermiso;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use BackedEnum;
use UnitEnum;

class RolePermissions extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Permisos por Rol';

    protected static string | UnitEnum | null $navigationGroup = 'Administración';

    protected static ?string $title = 'Permisos por Rol';

    protected string $view = 'filament.pages.role-permissions';

    public ?array $data = [];

    public function mount(): void
    {
        $roles = RolePermiso::all()->keyBy('role');
        $modulos = array_keys(User::modulos());

        $defaults = [];
        foreach (['jefe_taller', 'auxiliar_bodega', 'operativo', 'visualizador'] as $role) {
            $dbPermisos = $roles->get($role)?->permisos ?? [];
            
            $mappedPermisos = [];
            foreach ($dbPermisos as $p) {
                if (is_string($p) && !str_contains($p, '.')) {
                    $mappedPermisos[] = "{$p}.read";
                    $mappedPermisos[] = "{$p}.write";
                } else {
                    $mappedPermisos[] = $p;
                }
            }
            $defaults[$role] = array_values(array_unique($mappedPermisos));
        }

        $this->form->fill([
            'roles' => $defaults,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $modulos = User::modulos();
        $granularOptions = [];
        foreach ($modulos as $key => $label) {
            $granularOptions["{$key}.read"] = "{$label} (Lectura)";
            $granularOptions["{$key}.write"] = "{$label} (Escritura)";
        }

        return $schema
            ->schema([
                Placeholder::make('info')
                    ->content('Configurá los permisos por defecto para cada rol. Los usuarios heredan estos módulos automáticamente. Los permisos individuales por usuario se agregan por encima de estos. "Lectura" permite ver datos y desplegables. "Escritura" permite crear, editar y eliminar.')
                    ->hiddenLabel(),

                Section::make('Administrador')
                    ->description('El rol admin siempre tiene acceso total a todos los módulos. No es configurable.')
                    ->schema([
                        Placeholder::make('admin_info')
                            ->content('✅ Todos los módulos — acceso completo')
                            ->hiddenLabel(),
                    ]),

                Section::make('Jefe de Taller')
                    ->schema([
                        CheckboxList::make('roles.jefe_taller')
                            ->options($granularOptions)
                            ->columns(2)
                            ->gridDirection('row')
                            ->hiddenLabel(),
                    ])->collapsed(),

                Section::make('Auxiliar de Bodega')
                    ->schema([
                        CheckboxList::make('roles.auxiliar_bodega')
                            ->options($granularOptions)
                            ->columns(2)
                            ->gridDirection('row')
                            ->hiddenLabel(),
                    ])->collapsed(),

                Section::make('Operativo')
                    ->schema([
                        CheckboxList::make('roles.operativo')
                            ->options($granularOptions)
                            ->columns(2)
                            ->gridDirection('row')
                            ->hiddenLabel(),
                    ])->collapsed(),

                Section::make('Visualizador')
                    ->schema([
                        CheckboxList::make('roles.visualizador')
                            ->options($granularOptions)
                            ->columns(2)
                            ->gridDirection('row')
                            ->hiddenLabel(),
                    ])->collapsed(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data['roles'] as $role => $permisos) {
            RolePermiso::updateOrCreate(
                ['role' => $role],
                ['permisos' => array_values($permisos ?? [])],
            );

            Cache::forget("role_permisos_{$role}");
        }

        Notification::make()
            ->title('¡Permisos guardados!')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar Cambios')
                ->submit('save'),
        ];
    }
}
