<?php

namespace App\Services\Inventory\Orders;

use App\Domain\InventoryOrders\AlertaTipo;
use App\Models\PedidoAlerta;
use App\Models\PedidoEvento;
use App\Models\PedidoInventario;
use App\Models\User;
use App\Policies\PedidoInventarioPolicy;
use Illuminate\Database\Eloquent\Collection;

/**
 * Alertas Web persistentes del flujo de pedidos (plan sección 12).
 *
 * Reglas duras:
 * - Solo persiste en `pedido_alertas`; NUNCA crea Notificacion ni dispara el
 *   observer genérico (cero FCM, cero contaminación del contador móvil).
 * - Deduplicadas por (event_uuid, destinatario_user_id); la unique de la tabla
 *   es la garantía final.
 * - Los destinatarios se resuelven por capacidad efectiva + cuenta activa y se
 *   revalidan al leer: un usuario retirado o sin capacidad no recibe ni lee.
 *
 * Estas funciones se invocan DENTRO de la transacción de la acción que las
 * origina, para que alerta y cambio de estado sean atómicos.
 */
class PedidoAlertaService
{
    /**
     * Receptores activos con capacidad de recepción (auxiliares de bodega y
     * opt-ins explícitos). Se excluye el bypass de admin.
     *
     * @return Collection<int, User>
     */
    public function receptoresActivos(): Collection
    {
        return User::query()
            ->with('empleado')
            ->get()
            ->filter(fn (User $usuario): bool => PedidoInventarioPolicy::usuarioTieneCapacidad(
                $usuario,
                PedidoInventarioPolicy::CAP_RECEIVE,
            ))
            ->values();
    }

    /**
     * Destinatarios operativos de la publicación previa que todavía pueden leer
     * pedidos (retiro/cancelación no se envían a cuentas retiradas o sin acceso).
     *
     * @return Collection<int, User>
     */
    public function destinatariosPublicacion(PedidoInventario $pedido): Collection
    {
        $ids = PedidoAlerta::query()
            ->where('pedido_id', $pedido->pedido_id)
            ->where('tipo', AlertaTipo::PEDIDO_PUBLICADO)
            ->distinct()
            ->pluck('destinatario_user_id');

        if ($ids->isEmpty()) {
            return new Collection();
        }

        return User::query()
            ->with('empleado')
            ->whereIn('id', $ids->all())
            ->get()
            ->filter(fn (User $usuario): bool => PedidoInventarioPolicy::usuarioTieneCapacidad(
                $usuario,
                PedidoInventarioPolicy::CAP_READ,
            ))
            ->values();
    }

    /**
     * Crea las alertas faltantes de un evento para cada destinatario.
     * Idempotente: repetir la llamada no duplica filas.
     *
     * @param  iterable<User>  $destinatarios
     */
    public function crearParaEvento(PedidoEvento $evento, string $tipo, iterable $destinatarios): int
    {
        $creadas = 0;

        foreach ($destinatarios as $destinatario) {
            $alerta = PedidoAlerta::query()->firstOrCreate(
                [
                    'event_uuid' => $evento->event_uuid,
                    'destinatario_user_id' => (int) $destinatario->id,
                ],
                [
                    'pedido_id' => $evento->pedido_id,
                    'tipo' => $tipo,
                ],
            );

            if ($alerta->wasRecentlyCreated) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * Destinatarios de una firma de desfase (U7; plan sección 12): contabilidad
     * activa con lectura más el receptor firmante si sigue habilitado para
     * recibir. El receptor recibe la constancia de su propia firma para la
     * recepción pendiente. La unique (event_uuid, destinatario) deduplica.
     *
     * @return Collection<int, User>
     */
    public function destinatariosDesfase(PedidoInventario $pedido, User $firmante): Collection
    {
        $destinatarios = $this->contablesLectores();

        if (PedidoInventarioPolicy::usuarioTieneCapacidad($firmante, PedidoInventarioPolicy::CAP_RECEIVE)
            && ! $destinatarios->contains('id', (int) $firmante->id)) {
            $destinatarios->push($firmante);
        }

        return $destinatarios->values();
    }

    /**
     * Usuarios de contabilidad activos con lectura de pedidos: destinatarios de
     * los resúmenes de recepción (U6, plan sección 12). Sin bypass de admin y
     * sin otros roles de supervisión.
     *
     * @return Collection<int, User>
     */
    public function contablesLectores(): Collection
    {
        return User::query()
            ->with('empleado')
            ->get()
            ->filter(fn (User $usuario): bool => strtolower(trim((string) $usuario->role))
                === PedidoInventarioPolicy::ROLE_CONTABILIDAD
                && PedidoInventarioPolicy::usuarioTieneCapacidad(
                    $usuario,
                    PedidoInventarioPolicy::CAP_READ,
                ))
            ->values();
    }

    /**
     * Destinatarios de una novedad abierta: autor contable del pedido (si sigue
     * activo y con lectura) más los usuarios de contabilidad activos. La
     * deduplicación final la garantiza la unique (event_uuid, destinatario).
     *
     * @return Collection<int, User>
     */
    public function destinatariosNovedad(PedidoInventario $pedido): Collection
    {
        $destinatarios = $this->contablesLectores();
        $autor = $this->autorLecturaHabilitado($pedido->created_by);

        if ($autor !== null && ! $destinatarios->contains('id', $autor->id)) {
            $destinatarios->push($autor);
        }

        return $destinatarios->values();
    }

    /**
     * Autor del pedido con capacidad de lectura vigente; null si no puede
     * recibir el aviso.
     */
    public function autorLecturaHabilitado(?int $createdBy): ?User
    {
        if ($createdBy === null) {
            return null;
        }

        $autor = User::query()->with('empleado')->find($createdBy);

        if ($autor === null) {
            return null;
        }

        return PedidoInventarioPolicy::usuarioTieneCapacidad($autor, PedidoInventarioPolicy::CAP_READ)
            ? $autor
            : null;
    }

    public function usuarioPuedeLeer(User $usuario): bool
    {
        return PedidoInventarioPolicy::usuarioTieneCapacidad($usuario, PedidoInventarioPolicy::CAP_READ);
    }

    public function usuarioPuedeRecibir(User $usuario): bool
    {
        return PedidoInventarioPolicy::usuarioTieneCapacidad($usuario, PedidoInventarioPolicy::CAP_RECEIVE);
    }

    /**
     * Autor del borrador habilitado para corregir/reprocesar (capability de
     * escritura documental y cuenta activa). Null si no puede recibir el aviso.
     */
    public function autorCorrectorHabilitado(?int $createdBy): ?User
    {
        if ($createdBy === null) {
            return null;
        }

        $autor = User::query()->with('empleado')->find($createdBy);

        if ($autor === null) {
            return null;
        }

        return PedidoInventarioPolicy::usuarioTieneCapacidad($autor, PedidoInventarioPolicy::CAP_DOCUMENT_WRITE)
            ? $autor
            : null;
    }
}
