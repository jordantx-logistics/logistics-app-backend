<?php

namespace Src\Logistics\Order\Rules;

use Closure;
use Src\Shared\Contracts\CustomerDirectory;
use Src\Shared\Exceptions\BusinessRuleException;

class ValidateCustomerExists
{
    public function __construct(
        private readonly CustomerDirectory $customers,
    ) {}

    public function handle(array $payload, Closure $next): mixed
    {
        $senderId = (int) $payload['sender_id'];

        if (! $this->customers->customerExists($senderId)) {
            throw new BusinessRuleException("Sender with ID {$senderId} not found.", 'SENDER_NOT_FOUND');
        }

        $receiverId = (int) $payload['receiver_id'];

        if (! $this->customers->customerExists($receiverId)) {
            throw new BusinessRuleException("Receiver with ID {$receiverId} not found.", 'RECEIVER_NOT_FOUND');
        }

        return $next($payload);
    }
}
