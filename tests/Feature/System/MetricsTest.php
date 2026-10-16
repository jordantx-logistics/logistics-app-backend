<?php

use Src\System\Services\Metrics\DatabaseConnectionsGauge;

it('expone el contador y el histograma de las peticiones atendidas', function () {
    $this->getJson('/api/v1/health')->assertOk();

    $body = $this->get('/metrics')->assertOk()->getContent();

    expect($body)
        ->toContain('http_requests_total{')
        ->toContain('route="api/v1/health"')
        ->toContain('method="GET"')
        ->toContain('status="200"')
        ->toContain('env="testing"')
        ->toContain('http_request_duration_seconds_bucket{')
        ->toContain('http_request_duration_seconds_count{')
        ->toContain('octane_workers_busy{');
});

it('devuelve el formato de texto de Prometheus', function () {
    $contentType = $this->get('/metrics')->assertOk()->headers->get('Content-Type');

    expect($contentType)->toContain('text/plain')->toContain('version=0.0.4');
});

it('etiqueta por patrón de ruta y agrupa las peticiones sin ruta', function () {
    // La etiqueta `route` nunca es la URL: si no, cada id abriría una serie.
    $this->get('/no-existe')->assertNotFound();

    $body = $this->get('/metrics')->getContent();

    expect($body)->toContain('route="unmatched"')->toContain('status="404"');
});

it('no se mide a sí mismo', function () {
    $body = $this->get('/metrics')->assertOk()->getContent();

    expect($body)->not->toContain('route="metrics"');
});

it('exige el token de scrape cuando está configurado', function () {
    config()->set('metrics.token', 's3cr3t');

    $this->get('/metrics')->assertForbidden();
    $this->get('/metrics?token=otro')->assertForbidden();

    $this->get('/metrics?token=s3cr3t')->assertOk();
    $this->withHeaders(['Authorization' => 'Bearer s3cr3t'])->get('/metrics')->assertOk();
});

it('sin token solo atiende desde red privada', function () {
    // El cliente de los tests es 127.0.0.1, así que el caso permitido ya lo
    // cubren los tests anteriores; aquí se comprueba el rechazo desde fuera.
    $response = $this->call('GET', '/metrics', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

    $response->assertForbidden();
});

it('desaparece si el exporter está apagado', function () {
    config()->set('metrics.enabled', false);

    $this->get('/metrics')->assertNotFound();
});

it('omite las conexiones de BD cuando el motor no las expone', function () {
    // En tests la BD es SQLite: no hay `Threads_connected` que leer.
    expect(app(DatabaseConnectionsGauge::class)->value())->toBeNull()
        ->and($this->get('/metrics')->getContent())->not->toContain('db_connections_used');
});
