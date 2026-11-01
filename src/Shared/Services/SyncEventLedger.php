<?php

namespace Src\Shared\Services;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Models\SyncEvent;

/**
 * Aplica los eventos de un lote móvil, cada uno de forma independiente.
 *
 * El efecto y su marca se confirman en la misma transacción: un evento rechazado no
 * queda marcado y se puede corregir y reenviar; uno aplicado responde `duplicated`
 * en cada reintento, aunque ya no sea válido en el estado actual de la entidad.
 */
final class SyncEventLedger
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Closure(array<string, mixed>): array<string, mixed>  $rulesFor  Reglas de cada evento
     * @param  Closure(array<string, mixed>): void  $apply  Efecto del evento ya validado
     * @return array{accepted: int, duplicated: int, rejected: int, items: list<array<string, mixed>>}
     */
    public function process(array $items, string $typeKey, Closure $rulesFor, Closure $apply): array
    {
        $summary = ['accepted' => 0, 'duplicated' => 0, 'rejected' => 0, 'items' => []];

        foreach ($items as $item) {
            $eventId = (string) $item['event_id'];
            $result = $this->processOne($eventId, $item, (string) $item[$typeKey], $rulesFor, $apply);

            $summary[$result['status']]++;
            $summary['items'][] = $result;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{event_id: string, status: string, error_code?: string, message?: string}
     */
    private function processOne(string $eventId, array $item, string $type, Closure $rulesFor, Closure $apply): array
    {
        if (SyncEvent::query()->where('event_id', $eventId)->exists()) {
            return ['event_id' => $eventId, 'status' => 'duplicated'];
        }

        $validator = Validator::make($item, $rulesFor($item));

        if ($validator->fails()) {
            return [
                'event_id' => $eventId,
                'status' => 'rejected',
                'error_code' => 'SYNC_ITEM_INVALID',
                'message' => $validator->errors()->first(),
            ];
        }

        try {
            DB::transaction(function () use ($eventId, $type, $item, $apply): void {
                $apply($item);

                SyncEvent::query()->create([
                    'event_id' => $eventId,
                    'event_type' => substr($type, 0, 40),
                    'user_id' => auth()->id(),
                    'created_at' => now(),
                ]);
            });
        } catch (BusinessRuleException $exception) {
            return [
                'event_id' => $eventId,
                'status' => 'rejected',
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ];
        } catch (UniqueConstraintViolationException) {
            // Dos reintentos a la vez: el índice único de `event_id` rechaza el segundo.
            return ['event_id' => $eventId, 'status' => 'duplicated'];
        }

        return ['event_id' => $eventId, 'status' => 'accepted'];
    }
}
