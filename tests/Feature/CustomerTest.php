<?php

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
});

it('can create a company', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/customer/companies', [
            'tax_id' => '20123456789',
            'business_name' => 'Tech Logistics SAC',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.tax_id', '20123456789');
});
