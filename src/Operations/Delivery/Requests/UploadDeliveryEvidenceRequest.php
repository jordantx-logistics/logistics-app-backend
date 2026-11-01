<?php

namespace Src\Operations\Delivery\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Src\Operations\Delivery\UseCases\DeliveryEvidenceStore;

class UploadDeliveryEvidenceRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:'.implode(',', DeliveryEvidenceStore::TYPES)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'type' => ['description' => '`signature` or `photo`.', 'example' => 'photo'],
            'file' => ['description' => 'Image (jpg or png, up to 5 MB).'],
        ];
    }
}
