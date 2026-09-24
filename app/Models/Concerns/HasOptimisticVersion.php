<?php

namespace App\Models\Concerns;

use App\Domain\InventoryOrders\VersionDesactualizadaException;

trait HasOptimisticVersion
{
    protected function versionAttributeName(): string
    {
        return 'version';
    }

    public function versionActual(): int
    {
        return (int) $this->getAttribute($this->versionAttributeName());
    }

    public function versionCoincide(?int $version): bool
    {
        return $version !== null && $this->versionActual() === (int) $version;
    }

    public function assertVersion(?int $version): void
    {
        if (! $this->versionCoincide($version)) {
            throw new VersionDesactualizadaException(sprintf(
                'Versión desactualizada para %s#%s: recibida %s, actual %d.',
                $this->getTable(),
                (string) $this->getKey(),
                $version === null ? 'null' : (string) $version,
                $this->versionActual(),
            ));
        }
    }

    public function incrementarVersion(): void
    {
        $this->setAttribute($this->versionAttributeName(), $this->versionActual() + 1);
    }
}
