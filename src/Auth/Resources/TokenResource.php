<?php

namespace Src\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Token opaco recién emitido. El valor en claro solo viaja aquí; después solo
 * se puede revocar, no recuperar.
 */
class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource['token'],
            'token_type' => 'Bearer',
            'expires_in' => $this->resource['expires_in'],
        ];
    }
}
