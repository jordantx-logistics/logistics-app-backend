<?php

namespace Src\Shared\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class BusinessRuleException extends Exception
{
    public function __construct(
        string $message,
        public string $errorCode = 'BUSINESS_RULE_VIOLATION',
        public int $statusCode = 422
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => ['error_code' => $this->errorCode],
        ], $this->statusCode);
    }
}
