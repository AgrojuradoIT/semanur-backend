<?php

namespace App\Http\Requests\InventoryOrders;

use App\Services\Inventory\Orders\PedidoDocumentoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación estructural del multipart de documento. El contenido real del
 * archivo (MIME, firma, dimensiones, decodificación, límites acumulados) se
 * valida en PedidoDocumentoService ANTES de escribir en disco.
 */
class StorePedidoDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rol' => ['required', 'string', Rule::in(PedidoDocumentoService::ROLES)],
            'file' => ['required', 'file'],
            'replaces_documento_id' => ['nullable', 'integer', 'min:1'],
            'pedido_linea_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
