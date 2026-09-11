<?php

use App\Policies\PedidoInventarioPolicy;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// U11 realtime opt-in (aditivo; no toca `user.{id}`): canal privado por
// destinatario de `pedido_alertas`. Exige AMBAS condiciones: el id del canal
// coincide con el usuario autenticado Y el usuario conserva la capacidad
// explícita `pedidos.read` (cuenta activa + matriz/permiso personal, sin
// bypass de admin para capacidades `pedidos.*`). Un usuario retirado o sin
// acceso deja de autorizar aunque conozca el nombre del canal.
Broadcast::channel('pedidos.alertas.{userId}', function ($user, $userId) {
    if ((int) $user->id !== (int) $userId) {
        return false;
    }

    return $user->hasPermission(PedidoInventarioPolicy::CAP_READ)
        && PedidoInventarioPolicy::usuarioTieneCapacidad($user, PedidoInventarioPolicy::CAP_READ);
});
