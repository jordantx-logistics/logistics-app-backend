<?php

use Illuminate\Support\Str;
use Src\Customer\Models\Customer;
use Src\Logistics\Order\Models\Order;

/*
|--------------------------------------------------------------------------
| Agregado de clientes (Fase 3)
|--------------------------------------------------------------------------
|
| `customers` es el remitente/destinatario de una orden. Se respalda en una
| persona o una empresa, y las altas del MVP (`/customer/persons|companies`)
| crean el agregado en el mismo movimiento para que el registro se pueda usar
| como remitente.
|
*/

beforeEach(function () {
    $this->user = adminUser();
    $this->headers = tokenHeadersFor($this->user);
});

it('can create a person', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/customer/persons', [
            'document_type' => 'DNI',
            'document_number' => '12345678',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.document_number', '12345678');

    // El alta del MVP crea también el cliente, o la persona no serviría como
    // remitente de una orden.
    $this->assertDatabaseHas('customers', [
        'customer_type' => Customer::TYPE_PERSON,
        'document_number' => '12345678',
        'display_name' => 'John Doe',
        'is_active' => true,
    ]);
});

it('can create a company', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/customer/companies', [
            'tax_id' => '20123456786', // RUC válido (dígito verificador módulo 11)
            'business_name' => 'Tech Logistics SAC',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.tax_id', '20123456786');

    $this->assertDatabaseHas('customers', [
        'customer_type' => Customer::TYPE_COMPANY,
        'document_type' => 'RUC',
        'document_number' => '20123456786',
        'display_name' => 'Tech Logistics SAC',
    ]);
});

it('genera el código de cliente con el año y una secuencia de la propia tabla', function () {
    $first = $this->withHeaders($this->headers)->postJson('/api/v1/customers', [
        'customer_type' => Customer::TYPE_PERSON,
        'document_type' => 'DNI',
        'document_number' => '70000001',
        'first_name' => 'Ana',
        'last_name' => 'Quispe',
    ]);

    $first->assertStatus(201);
    expect($first->json('data.code'))->toBe(sprintf('CLI-%s-000001', now()->format('Y')));

    $second = $this->withHeaders($this->headers)->postJson('/api/v1/customers', [
        'customer_type' => Customer::TYPE_PERSON,
        'document_type' => 'DNI',
        'document_number' => '70000002',
        'first_name' => 'Luis',
        'last_name' => 'Rojas',
    ]);

    expect($second->json('data.code'))->toBe(sprintf('CLI-%s-000002', now()->format('Y')));
});

it('rechaza un documento con formato inválido', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/customers', [
            'customer_type' => Customer::TYPE_PERSON,
            'document_type' => 'DNI',
            'document_number' => '123',
            'first_name' => 'Ana',
            'last_name' => 'Quispe',
        ])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'CUSTOMER_DOCUMENT_INVALID');
});

it('rechaza un RUC con dígito verificador equivocado', function () {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/customer/companies', [
            'tax_id' => '20123456789', // mismo prefijo, verificador incorrecto
            'business_name' => 'RUC Falso SAC',
        ])
        ->assertStatus(422)
        ->assertJsonPath('data.error_code', 'CUSTOMER_DOCUMENT_INVALID');
});

it('no registra dos clientes con el mismo documento', function () {
    $payload = [
        'customer_type' => Customer::TYPE_PERSON,
        'document_type' => 'DNI',
        'document_number' => '70123456',
        'first_name' => 'Ana',
        'last_name' => 'Quispe',
    ];

    $this->withHeaders($this->headers)->postJson('/api/v1/customers', $payload)->assertStatus(201);

    // La segunda vez el documento ya existe en `customers` **y** en `persons`.
    $this->withHeaders($this->headers)->postJson('/api/v1/customers', $payload)
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'CUSTOMER_DOCUMENT_TAKEN');
});

it('edita el cliente y su persona de respaldo', function () {
    $customer = Customer::factory()->create(['display_name' => 'Nombre viejo']);

    $response = $this->withHeaders($this->headers)
        ->putJson("/api/v1/customers/{$customer->id}", [
            'first_name' => 'Marta',
            'last_name' => 'Pérez',
            'email' => 'marta@ejemplo.test',
            'credit_limit' => 1500.50,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.display_name', 'Marta Pérez')
        ->assertJsonPath('data.email', 'marta@ejemplo.test')
        ->assertJsonPath('data.credit_limit', '1500.50');

    $this->assertDatabaseHas('persons', [
        'id' => $customer->person_id,
        'first_name' => 'Marta',
        'last_name' => 'Pérez',
        'email' => 'marta@ejemplo.test',
    ]);
});

it('desactiva el cliente en vez de borrarlo', function () {
    $customer = Customer::factory()->create();

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => false]);
    $this->assertDatabaseCount('customers', 1);
});

it('no desactiva un cliente con órdenes abiertas', function () {
    $customer = Customer::factory()->create();

    Order::create([
        'idempotency_key' => Str::uuid()->toString(),
        'sender_id' => $customer->id,
        'receiver_id' => $customer->id,
        'origin_agency_id' => 1,
        'destination_agency_id' => 1,
        'weight_kg' => 1,
        'total_amount' => 10,
        'status' => 'pending',
        'created_by' => 1,
    ]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/customers/{$customer->id}")
        ->assertStatus(409)
        ->assertJsonPath('data.error_code', 'CUSTOMER_HAS_OPEN_ORDERS');

    // Y sí lo desactiva cuando la orden ya se cerró.
    Order::query()->update(['status' => 'delivered']);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/customers/{$customer->id}")
        ->assertOk();
});

it('lista y filtra clientes', function () {
    Customer::factory()->create(['display_name' => 'Ana Quispe']);
    Customer::factory()->forCompany()->create(['display_name' => 'Tech Logistics SAC']);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/customers?q=Tech')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.display_name', 'Tech Logistics SAC');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/customers?customer_type=company')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/customers?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('deja ver los clientes a quien solo tiene customers.view, pero no crearlos', function () {
    $user = adminUser();
    $user->removeRole('admin')->assignRole('customer-service');
    $headers = tokenHeadersFor($user);

    $this->withHeaders($headers)->getJson('/api/v1/customers')->assertOk();

    $this->withHeaders($headers)
        ->postJson('/api/v1/customers', [
            'customer_type' => Customer::TYPE_PERSON,
            'document_type' => 'DNI',
            'document_number' => '70999999',
            'first_name' => 'Sin',
            'last_name' => 'Permiso',
        ])
        ->assertStatus(403);
});

it('muestra un cliente', function () {
    $customer = Customer::factory()->create();

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id)
        ->assertJsonPath('data.code', $customer->code);
});
