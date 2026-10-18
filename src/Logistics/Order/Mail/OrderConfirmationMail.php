<?php

namespace Src\Logistics\Order\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmación de orden al remitente.
 *
 * Recibe el payload ya resuelto en un array: no conoce modelos, así que
 * `Mail::fake()` y los tests del job no necesitan tocar la BD para armarlo.
 */
final class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{order_id?: int|null, ticket_code?: string|null, ticket_number?: string|null, total_amount?: string|null}  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmación de envío '.($this->payload['ticket_number'] ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'orders.confirmation',
            with: ['payload' => $this->payload],
        );
    }
}
