<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;
use Src\Customer\Models\Customer;
use Src\Customer\UseCases\CreateCustomerUseCase;
use Src\Finance\Invoice\Models\InvoiceSeries;
use Src\Logistics\Tariff\Models\Tariff;
use Src\Organization\Models\Agency;

/**
 * Datos de los E2E de Playwright: dos agencias, un usuario por rol web, dos clientes
 * y una tarifa nacional. Corre solo sobre el stack aislado (`docker-compose.e2e.yml`).
 * La contraseña es de prueba y no se usa en ningún otro entorno.
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'E2e-Password-2026!';

    public const ROLES = ['admin', 'agency-supervisor', 'counter', 'cashier', 'customer-service', 'analyst'];

    /** Roles nacionales: no se les asigna agencia. */
    private const NATIONAL = ['admin', 'analyst'];

    public function run(CreateCustomerUseCase $customers): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2eSeeder only runs on local or testing.');
        }

        $origin = $this->agency('E2E-A', 'E2E Origin');
        $this->agency('E2E-B', 'E2E Destination');

        foreach (self::ROLES as $role) {
            $user = User::query()->updateOrCreate(
                ['email' => "e2e.{$role}@logistics.test"],
                ['name' => 'E2E '.ucfirst($role), 'password' => self::PASSWORD],
            );

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role]);

            if (! in_array($role, self::NATIONAL, true)) {
                $user->agencies()->syncWithoutDetaching([$origin->id => ['is_primary' => true]]);
            }
        }

        foreach ([['Sender', '70000001'], ['Receiver', '70000002']] as [$lastName, $document]) {
            if (! Customer::query()->where('document_number', $document)->exists()) {
                $customers->execute([
                    'customer_type' => Customer::TYPE_PERSON,
                    'document_type' => 'DNI',
                    'document_number' => $document,
                    'first_name' => 'E2E',
                    'last_name' => $lastName,
                ]);
            }
        }

        Tariff::query()->updateOrCreate(['code' => 'E2E-NATIONAL'], [
            'name' => 'E2E national',
            'service_type' => 'standard',
            'scope_type' => Tariff::SCOPE_NATIONAL,
            'base_price' => '5.00',
            'price_per_kg' => '2.50',
            'min_charge' => '5.00',
            'max_weight_kg' => '1000.00',
            'priority' => 0,
            'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        foreach (['boleta' => 'B999', 'factura' => 'F999'] as $documentType => $series) {
            InvoiceSeries::query()->updateOrCreate(
                ['agency_id' => $origin->id, 'document_type' => $documentType, 'series' => $series],
                [
                    'issuer_ruc' => '20123456789',
                    'issuer_name' => 'Logistics E2E SAC',
                    'issuer_trade_name' => 'Logistics E2E',
                    'issuer_address' => 'Av. E2E 123, Lima',
                    'issuer_ubigeo' => '150101',
                    'establishment_code' => '0000',
                    'is_active' => true,
                    'is_default' => true,
                ],
            );
        }
    }

    private function agency(string $code, string $name): Agency
    {
        return Agency::query()->updateOrCreate(['code' => $code], [
            'name' => $name,
            'type' => 'branch',
            'ubigeo' => '150101',
            'timezone' => 'America/Lima',
            'is_active' => true,
        ]);
    }
}
