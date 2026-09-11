<?php

namespace App\Policies;

use App\Domain\InventoryOrders\PedidoEstado;
use App\Models\PedidoInventario;
use App\Models\User;

/**
 * Capacidades del pedido de inventario (plan sección 7).
 *
 * Laravel descubre esta policy por convención para el modelo PedidoInventario;
 * no requiere registrar nada en AppServiceProvider.
 */
class PedidoInventarioPolicy
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_CONTABILIDAD = 'contabilidad';

    public const ROLE_AUXILIAR_BODEGA = 'auxiliar_bodega';

    public const ROLE_JEFE_TALLER = 'jefe_taller';

    public const CAP_READ = 'pedidos.read';

    public const CAP_CREATE = 'pedidos.create';

    public const CAP_DOCUMENT_WRITE = 'pedidos.document.write';

    public const CAP_PUBLISH = 'pedidos.publish';

    public const CAP_RECEIVE = 'pedidos.receive';

    public const CAP_CATALOG_COMPLETE = 'pedidos.catalog.complete';

    public const CAP_DISCREPANCY_RESPOND = 'pedidos.discrepancy.respond';

    public const CAP_DISCREPANCY_AUTHORIZE = 'pedidos.discrepancy.authorize';

    public const CAP_CANCEL = 'pedidos.cancel';

    /** @return list<string> */
    public static function capabilities(): array
    {
        return [
            self::CAP_READ,
            self::CAP_CREATE,
            self::CAP_DOCUMENT_WRITE,
            self::CAP_PUBLISH,
            self::CAP_RECEIVE,
            self::CAP_CATALOG_COMPLETE,
            self::CAP_DISCREPANCY_RESPOND,
            self::CAP_DISCREPANCY_AUTHORIZE,
            self::CAP_CANCEL,
        ];
    }

    public static function hasCapabilityForRole(string $role, string $capability): bool
    {
        $role = strtolower(trim($role));

        return match ($capability) {
            self::CAP_READ => in_array($role, [
                self::ROLE_ADMIN,
                self::ROLE_CONTABILIDAD,
                self::ROLE_AUXILIAR_BODEGA,
                self::ROLE_JEFE_TALLER,
            ], true),
            self::CAP_CREATE,
            self::CAP_DOCUMENT_WRITE,
            self::CAP_PUBLISH => $role === self::ROLE_ADMIN
                || $role === self::ROLE_CONTABILIDAD
                || ($role === self::ROLE_JEFE_TALLER && (bool) config('inventory_orders.supervisor_can_publish', false)),
            self::CAP_RECEIVE,
            self::CAP_CATALOG_COMPLETE => $role === self::ROLE_AUXILIAR_BODEGA
                || ($role === self::ROLE_ADMIN && (bool) config('inventory_orders.admin_can_receive', false)),
            self::CAP_DISCREPANCY_RESPOND => in_array($role, [
                self::ROLE_ADMIN,
                self::ROLE_CONTABILIDAD,
                self::ROLE_AUXILIAR_BODEGA,
            ], true)
                || ($role === self::ROLE_JEFE_TALLER && (bool) config('inventory_orders.supervisor_can_respond_discrepancy', false)),
            self::CAP_DISCREPANCY_AUTHORIZE => self::rolePuedeAutorizarDesfase($role),
            self::CAP_CANCEL => $role === self::ROLE_CONTABILIDAD
                || $role === self::ROLE_ADMIN
                || ($role === self::ROLE_JEFE_TALLER && (bool) config('inventory_orders.supervisor_can_cancel', false)),
            default => false,
        };
    }

    /**
     * Capacidad efectiva de un usuario para el flujo de pedidos.
     *
     * Combina la matriz por rol, los opt-ins explícitos de configuración y los
     * permisos personales (`permisos`) del usuario. Un admin NO recibe por esta
     * vía capacidades nuevas que no estén en la matriz: `effectivePermissions()`
     * de admin devuelve módulos legacy, no capacidades `pedidos.*`.
     *
     * Se usa para resolver destinatarios de alertas y revalidar lectura, donde
     * además del rol se exige que la cuenta esté operativamente activa
     * (misma semántica que EnsureActiveEmployee).
     */
    public static function usuarioTieneCapacidad(User $user, string $capability): bool
    {
        if (! $user->isOperationallyActive()) {
            return false;
        }

        $role = strtolower(trim((string) $user->role));

        if (self::hasCapabilityForRole($role, $capability)) {
            return true;
        }

        return ! $user->isAdmin() && in_array($capability, $user->effectivePermissions(), true);
    }

    /**
     * D1 permanece CERRADO por defecto, incluso para admin. Aunque el flag se
     * active, se exige que el rol esté en la lista explícita de autorizadores.
     */
    public static function rolePuedeAutorizarDesfase(string $role): bool
    {
        if (! (bool) config('inventory_orders.discrepancy_authorization_enabled', false)) {
            return false;
        }

        $roles = config('inventory_orders.discrepancy_authorizer_roles', []);

        if (! is_array($roles) || $roles === []) {
            return false;
        }

        $roles = array_map(
            fn (mixed $item): string => strtolower(trim((string) $item)),
            $roles,
        );

        return in_array(strtolower(trim($role)), $roles, true);
    }

    public function viewAny(User $user): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_READ);
    }

    public function view(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_READ);
    }

    public function create(User $user): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_CREATE);
    }

    public function update(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_CREATE)
            && PedidoEstado::esEditable((string) $pedido->estado);
    }

    public function publish(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_PUBLISH)
            && (string) $pedido->estado === PedidoEstado::BORRADOR;
    }

    /**
     * Capacidad de publicación sin mirar el estado: la usan los endpoints de
     * transición para autorizar primero y permitir el replay idempotente de una
     * operación ya completada. El estado y la versión se validan bajo lock en el
     * servicio con mensajes propios.
     */
    public function publishCapability(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_PUBLISH);
    }

    /**
     * Capacidad de cancelación sin mirar estado ni ledger: el servicio aplica
     * las guardas operativas (409) después de autorizar.
     */
    public function cancelCapability(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_CANCEL);
    }

    /**
     * Retiro documental antes de cualquier ingreso: revierte la publicación a
     * borrador para una nueva revisión. Exige la capacidad de publicación
     * (quien publica es quien puede retirar) y el estado pendiente_recepcion.
     */
    public function returnForCorrection(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_PUBLISH)
            && (string) $pedido->estado === PedidoEstado::PENDIENTE_RECEPCION;
    }

    public function receive(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_RECEIVE)
            && in_array((string) $pedido->estado, [
                PedidoEstado::PENDIENTE_RECEPCION,
                PedidoEstado::EN_VALIDACION,
                PedidoEstado::PARCIAL_CON_NOVEDAD,
            ], true);
    }

    /**
     * Capacidad de recepción sin mirar el estado: la usan las confirmaciones
     * para autorizar primero y permitir el replay idempotente de una operación
     * ya completada aunque el pedido haya quedado en un estado terminal. El
     * estado, la versión y la inmutabilidad se validan bajo lock en el servicio.
     */
    public function receiveCapability(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_RECEIVE);
    }

    /**
     * Carga de evidencia privada: exige pedidos.document.write y que el pedido
     * siga en borrador (la evidencia se congela con la publicación, R03). El
     * reemplazo de documentos publicados pertenece a U5, no a esta unidad.
     */
    public function uploadDocument(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_DOCUMENT_WRITE)
            && (string) $pedido->estado === PedidoEstado::BORRADOR;
    }

    /**
     * Lectura del derivado privado: misma capacidad de lectura del pedido. La
     * bodega debe poder ampliar la evidencia durante la recepción.
     */
    public function viewDocument(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_READ);
    }

    /**
     * Consulta del intento OCR: misma capacidad de lectura del pedido. Deny by
     * default; un intento ajeno responde 404 en el controlador.
     */
    public function viewOcrAttempt(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_READ);
    }

    /**
     * Reproceso explícito del OCR: exige pedidos.document.write y que el pedido
     * siga en borrador (la evidencia se congela con la publicación, R03).
     * Un intento activo lo bloquea el servicio con 409.
     */
    public function reprocessOcr(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_DOCUMENT_WRITE)
            && (string) $pedido->estado === PedidoEstado::BORRADOR;
    }

    public function completeCatalog(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_CATALOG_COMPLETE)
            && in_array((string) $pedido->estado, [
                PedidoEstado::PENDIENTE_RECEPCION,
                PedidoEstado::EN_VALIDACION,
                PedidoEstado::PARCIAL_CON_NOVEDAD,
            ], true);
    }

    public function respondToDiscrepancy(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_DISCREPANCY_RESPOND)
            && in_array((string) $pedido->estado, [
                PedidoEstado::EN_VALIDACION,
                PedidoEstado::PARCIAL_CON_NOVEDAD,
            ], true);
    }

    public function authorizeDiscrepancy(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_DISCREPANCY_AUTHORIZE)
            && in_array((string) $pedido->estado, [
                PedidoEstado::EN_VALIDACION,
                PedidoEstado::PARCIAL_CON_NOVEDAD,
            ], true);
    }

    /**
     * Capacidad de firma sin mirar el estado: el servicio valida estado,
     * novedad y contexto bajo lock, y permite el replay idempotente de una
     * firma ya completada aunque el pedido haya quedado terminal.
     */
    public function authorizeDiscrepancyCapability(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_DISCREPANCY_AUTHORIZE);
    }

    /**
     * Capacidad de respuesta sin mirar el estado (la novedad abierta y el
     * estado del pedido se validan en el servicio con mensajes propios).
     */
    public function respondToDiscrepancyCapability(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_DISCREPANCY_RESPOND);
    }

    public function cancel(User $user, PedidoInventario $pedido): bool
    {
        return self::hasCapabilityForRole($this->role($user), self::CAP_CANCEL)
            && in_array((string) $pedido->estado, [
                PedidoEstado::BORRADOR,
                PedidoEstado::PENDIENTE_RECEPCION,
                PedidoEstado::EN_VALIDACION,
            ], true)
            && ! $pedido->tieneIngresos();
    }

    public function delete(User $user, PedidoInventario $pedido): bool
    {
        return false;
    }

    public function restore(User $user, PedidoInventario $pedido): bool
    {
        return false;
    }

    public function forceDelete(User $user, PedidoInventario $pedido): bool
    {
        return false;
    }

    private function role(User $user): string
    {
        return strtolower(trim((string) $user->role));
    }
}
