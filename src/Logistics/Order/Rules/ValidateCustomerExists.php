<?php

namespace Src\Logistics\Order\Rules;

use Closure;
use Src\Customer\Models\Person;
use Src\Shared\Exceptions\BusinessRuleException;

class ValidateCustomerExists
{
    public function handle(array $payload, Closure $next): mixed
    {
        $senderExists = Person::where('id', $payload['sender_id'])->exists();
        if (! $senderExists) {
            throw new BusinessRuleException("Sender with ID {$payload['sender_id']} not found.", 'SENDER_NOT_FOUND');
        }

        $receiverExists = Person::where('id', $payload['receiver_id'])->exists();
        if (! $receiverExists) {
            throw new BusinessRuleException("Receiver with ID {$payload['receiver_id']} not found.", 'RECEIVER_NOT_FOUND');
        }

        return $next($payload);
    }
}
