<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agregado `customers` (Fase 3).
     *
     * Un cliente es siempre **persona o empresa**: `customers` guarda el dato
     * comercial (documento, contacto, crédito) y `person_id`/`company_id`
     * apuntan a la entidad de respaldo, que es del mismo contexto (FK permitida
     * por ADR-0006).
     *
     * La migración **no es solo aditiva**: rellena el agregado desde `persons` y
     * `companies` y repunta `orders.sender_id`/`receiver_id`, que en el MVP
     * apuntaban a `persons.id`. Sin ese repunte, las órdenes históricas
     * quedarían apuntando a filas que ya no son clientes.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // `CLI-2026-000123`: generado por `CustomerCodeGenerator` (año reinicia
            // la secuencia). El `code` no es fillable: lo pone el modelo en `creating`.
            $table->string('code', 20)->unique();

            // Discriminador + entidad de respaldo (exactamente una, coherente con
            // `customer_type`; la invariante la valida `ValidateCustomerBacking`).
            $table->string('customer_type', 10); // person | company
            $table->foreignId('person_id')->nullable()->constrained('persons');
            $table->foreignId('company_id')->nullable()->constrained('companies');

            $table->string('display_name', 200);
            $table->string('document_type', 10);   // DNI | RUC | CE | PASSPORT
            $table->string('document_number', 20);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('credit_limit', 12, 2)->nullable();

            // Referencia a `users` (Identity): indexada, sin FK (ADR-0006).
            // Nullable a propósito: las filas del backfill no tuvieron actor.
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->timestamps();

            // El documento identifica al cliente **por tipo**: un DNI y un RUC con
            // el mismo número no son el mismo cliente.
            $table->unique(['document_type', 'document_number']);
            $table->index('document_number'); // búsqueda por documento (sin el tipo)
            $table->index(['is_active', 'display_name']); // búsqueda del alta
            $table->index('customer_type');
        });

        $this->backfill();
    }

    /**
     * Rellena `customers` desde `persons`/`companies` y repunta las órdenes.
     *
     * El código se numera en orden de id y, al terminar, la secuencia del año
     * arranca en el último valor: así un alta nueva no repite `code`.
     */
    private function backfill(): void
    {
        $year = (int) now()->format('Y');
        $sequence = 0;

        DB::table('persons')->orderBy('id')->chunkById(500, function ($persons) use (&$sequence, $year): void {
            $rows = [];

            foreach ($persons as $person) {
                $rows[] = [
                    'code' => sprintf('CLI-%d-%06d', $year, ++$sequence),
                    'customer_type' => 'person',
                    'person_id' => $person->id,
                    'company_id' => null,
                    'display_name' => trim($person->first_name.' '.$person->last_name),
                    'document_type' => $person->document_type,
                    'document_number' => $person->document_number,
                    'email' => $person->email,
                    'phone' => $person->phone,
                    'address' => null,
                    'is_active' => true,
                    'credit_limit' => null,
                    'created_by' => null,
                    'created_at' => $person->created_at ?? now(),
                    'updated_at' => $person->updated_at ?? now(),
                ];
            }

            if ($rows !== []) {
                DB::table('customers')->insert($rows);
            }
        });

        DB::table('companies')->orderBy('id')->chunkById(500, function ($companies) use (&$sequence, $year): void {
            $rows = [];

            foreach ($companies as $company) {
                $rows[] = [
                    'code' => sprintf('CLI-%d-%06d', $year, ++$sequence),
                    'customer_type' => 'company',
                    'person_id' => null,
                    'company_id' => $company->id,
                    'display_name' => $company->business_name,
                    'document_type' => 'RUC',
                    'document_number' => $company->tax_id,
                    'email' => $company->contact_email,
                    'phone' => $company->contact_phone,
                    'address' => $company->address,
                    'is_active' => true,
                    'credit_limit' => null,
                    'created_by' => null,
                    'created_at' => $company->created_at ?? now(),
                    'updated_at' => $company->updated_at ?? now(),
                ];
            }

            if ($rows !== []) {
                DB::table('customers')->insert($rows);
            }
        });

        // Órdenes del MVP: `sender_id`/`receiver_id` eran `persons.id`. Solo los
        // clientes de persona pueden estar referenciados (las empresas nunca se
        // usaron como remitente/destinatario en el MVP).
        DB::table('customers')
            ->where('customer_type', 'person')
            ->whereNotNull('person_id')
            ->select(['id', 'person_id'])
            ->orderBy('person_id')
            ->chunk(500, function ($customers): void {
                foreach ($customers as $customer) {
                    DB::table('orders')->where('sender_id', $customer->person_id)->update(['sender_id' => $customer->id]);
                    DB::table('orders')->where('receiver_id', $customer->person_id)->update(['receiver_id' => $customer->id]);
                }
            });

        if ($sequence > 0) {
            DB::table('sequences')->updateOrInsert(
                ['type' => "customer_code_{$year}"],
                ['current_value' => $sequence, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
