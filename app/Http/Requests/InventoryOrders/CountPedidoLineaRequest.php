<?php

namespace App\Http\Requests\InventoryOrders;

use App\Domain\InventoryOrders\CondicionLinea;
use App\DTO\InventoryOrders\DecimalQuantity;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Conteo de una línea de recepción (U6, R04).
 *
 * `conteo_total: null` significa NO CONTADO; `0.00` es una ausencia explícita.
 * La cantidad apta nunca supera la contada. Los campos de actor se rechazan:
 * el actor y el receptor salen del usuario autenticado (R12).
 */
class CountPedidoLineaRequest extends FormRequest
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
            'observacion_version' => ['required', 'integer', 'min:1'],
            'conteo_total' => ['present', 'nullable', 'string', $this->cantidadRule('conteo_total')],
            'conteo_aptos' => ['present', 'nullable', 'string', $this->cantidadRule('conteo_aptos')],
            'estado_condicion' => ['present', 'nullable', 'string', Rule::in(CondicionLinea::todos())],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'actor_user_id' => ['prohibited'],
            'receptor_empleado_id' => ['prohibited'],
            'count_confirmed' => ['prohibited'],
            'recepcion_id' => ['prohibited'],
            'pedido_linea_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $total = $this->input('conteo_total');
            $aptos = $this->input('conteo_aptos');
            $condicion = $this->input('estado_condicion');

            if ($total === null) {
                if ($aptos !== null) {
                    $validator->errors()->add(
                        'conteo_aptos',
                        'Si la línea no está contada (conteo_total null), la cantidad apta también debe ser null.',
                    );
                }

                if ($condicion !== null) {
                    $validator->errors()->add(
                        'estado_condicion',
                        'Si la línea no está contada (conteo_total null), la condición también debe ser null.',
                    );
                }

                return;
            }

            if ($aptos === null) {
                $validator->errors()->add(
                    'conteo_aptos',
                    'Un conteo explícito exige la cantidad apta (puede ser 0.00).',
                );
            }

            if ($condicion === null) {
                $validator->errors()->add(
                    'estado_condicion',
                    'Un conteo explícito exige declarar la condición física de la mercancía.',
                );
            }

            if ($condicion !== null && $condicion !== CondicionLinea::CONFORME
                && trim((string) $this->input('motivo')) === '') {
                $validator->errors()->add(
                    'motivo',
                    'Una condición distinta de conforme exige motivo.',
                );
            }

            if ($aptos === null) {
                return;
            }

            $totalCanonico = DecimalQuantity::tryNormalize($total, true);
            $aptosCanonico = DecimalQuantity::tryNormalize($aptos, true);

            if ($totalCanonico !== null && $aptosCanonico !== null
                && DecimalQuantity::compare($aptosCanonico, $totalCanonico) > 0) {
                $validator->errors()->add(
                    'conteo_aptos',
                    'La cantidad apta no puede superar la cantidad total contada.',
                );
            }
        });
    }

    private function cantidadRule(string $campo): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($campo): void {
            if (DecimalQuantity::tryNormalize($value, allowZero: true) === null) {
                $fail("El campo {$campo} debe ser un decimal exacto como string, mayor o igual a cero y con la escala del ledger.");
            }
        };
    }
}
