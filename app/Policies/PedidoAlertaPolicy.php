<?php

namespace App\Policies;

use App\Models\PedidoAlerta;
use App\Models\User;

/**
 * Bandeja Web de alertas de pedidos (U5).
 *
 * Deny by default: exige la capacidad efectiva `pedidos.read` revalidada en el
 * momento de la lectura (un usuario retirado o sin capacidad no lee aunque la
 * alerta exista) y que la alerta sea del propio usuario.
 */
class PedidoAlertaPolicy
{
    public function viewAny(User $user): bool
    {
        return PedidoInventarioPolicy::usuarioTieneCapacidad($user, PedidoInventarioPolicy::CAP_READ);
    }

    public function read(User $user, PedidoAlerta $alerta): bool
    {
        return (int) $alerta->destinatario_user_id === (int) $user->id
            && PedidoInventarioPolicy::usuarioTieneCapacidad($user, PedidoInventarioPolicy::CAP_READ);
    }

    public function delete(User $user, PedidoAlerta $alerta): bool
    {
        return false;
    }
}
