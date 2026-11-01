<?php

namespace Src\Fleet\Route\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Fleet\Route\UseCases\SyncFleetEventsUseCase;

/**
 * Forma del lote. El detalle de cada evento se valida uno a uno, para que un evento
 * inválido se rechace solo y no bloquee al resto.
 */
class SyncFleetEventsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.event_id' => ['required', 'uuid'],
            'items.*.type' => ['required', 'string', Rule::in(SyncFleetEventsUseCase::TYPES)],
        ];
    }

    /**
     * El resto de campos de cada evento no los declara `rules()`.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return array_values((array) $this->input('items'));
    }
}
