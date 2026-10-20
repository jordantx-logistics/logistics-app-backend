<?php

namespace Src\Shared\Services;

use Illuminate\Support\Facades\DB;

class TicketNumberGenerator
{
    /**
     * Generates a sequential ticket number safely using DB locks or sequence tables.
     * For this implementation, we use an atomic increment in a sequence table.
     */
    public function generate(string $prefix = 'ORD'): string
    {
        // For simplicity in SQLite/testing, and MySQL we can use a dedicated table or atomic operations.
        // E.g., INSERT INTO sequences (type) VALUES ('ticket') ON DUPLICATE KEY UPDATE check_val = check_val + 1

        // Here we build a robust numeric sequence generator.
        $nextValue = DB::table('sequences')
            ->where('type', 'ticket_number')
            ->lockForUpdate()
            ->value('current_value');

        if (! $nextValue) {
            DB::table('sequences')->insert([
                'type' => 'ticket_number',
                'current_value' => 1,
            ]);
            $nextValue = 1;
        } else {
            $nextValue++;
            DB::table('sequences')
                ->where('type', 'ticket_number')
                ->update(['current_value' => $nextValue]);
        }

        // Format: ORD-0000001
        return sprintf('%s-%07d', $prefix, $nextValue);
    }
}
