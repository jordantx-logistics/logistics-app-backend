<?php

use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Src\System\Logging\FlatJsonFormatter;
use Src\System\Middleware\AssignRequestId;

it('genera un X-Request-Id cuando el cliente no lo envía', function () {
    $response = $this->get('/up');

    expect($response->headers->get(AssignRequestId::HEADER))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('propaga el X-Request-Id que envía el cliente', function () {
    $response = $this->withHeaders([AssignRequestId::HEADER => 'proxy-abc.123'])->get('/up');

    $response->assertHeader(AssignRequestId::HEADER, 'proxy-abc.123');
});

it('descarta un X-Request-Id con formato inesperado', function () {
    $malicious = str_repeat('a', 80);

    $response = $this->withHeaders([AssignRequestId::HEADER => $malicious])->get('/up');

    expect($response->headers->get(AssignRequestId::HEADER))->not->toBe($malicious);
});

it('guarda el request_id y el usuario en el contexto de la petición', function () {
    $user = adminUser();

    $this->withHeaders([
        AssignRequestId::HEADER => 'req-42',
        ...tokenHeadersFor($user),
    ])->getJson('/api/v1/permission/roles')->assertOk();

    expect(Context::get('request_id'))->toBe('req-42')
        ->and(Context::get('user_id'))->toBe($user->id);
});

it('no rompe el registro de peticiones sin autenticar', function () {
    // Sin token, Sanctum no encuentra usuario; el middleware debe seguir
    // registrando la línea y no convertir el 401 en 500.
    $this->getJson('/api/v1/permission/roles')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('responde 401 JSON aunque el cliente no pida JSON', function () {
    // Sin esto, `Authenticate` intentaba redirigir a la ruta `login` (inexistente)
    // y la petición acababa en un 500 en vez de un 401.
    $this->get('/api/v1/permission/roles')
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('registra una línea por petición con ruta, estado y duración', function () {
    Log::spy();

    // `/up` es liveness puro: la línea se comprueba sin MySQL ni Redis, que es
    // como corre la suite (en CI el contenedor no alcanza los servicios).
    $this->get('/up');

    Log::shouldHaveReceived('info')->with('http_request', Mockery::on(
        fn (array $context) => $context['method'] === 'GET'
            && $context['route'] === 'up'
            && $context['status'] === 200
            && is_float($context['duration_ms'])
    ))->once();
});

it('añade el contexto de la petición a cada registro de log', function () {
    Context::add(['request_id' => 'req-7', 'user_id' => 13]);

    $record = new LogRecord(
        new DateTimeImmutable,
        'local',
        Level::Info,
        'algo pasó',
        ['route' => 'api/v1/health'],
        ['ya' => 'existente'],
    );

    // Se ejecutan los processors del canal tal y como los ejecuta Monolog: se
    // comprueba que el ContextLogProcessor que Laravel empuja al construirlo
    // vuelca el contexto en cada registro (bajo Octane, además, resuelve la app
    // en curso en cada llamada, que es el contenedor de la petición).
    foreach (Log::channel('json')->getLogger()->getProcessors() as $processor) {
        $record = $processor($record);
    }

    expect($record->extra)->toMatchArray([
        'ya' => 'existente',
        'request_id' => 'req-7',
        'user_id' => 13,
    ]);
});

it('aplana contexto y extra en la línea JSON', function () {
    $record = new LogRecord(
        new DateTimeImmutable,
        'local',
        Level::Info,
        'http_request',
        ['route' => 'api/v1/health', 'duration_ms' => 1.5],
        ['request_id' => 'req-9'],
    );

    $line = json_decode(trim((new FlatJsonFormatter)->format($record)), true);

    expect($line)->toMatchArray([
        'message' => 'http_request',
        'channel' => 'local',
        'level_name' => 'INFO',
        'route' => 'api/v1/health',
        'duration_ms' => 1.5,
        'request_id' => 'req-9',
    ])->and($line)->toHaveKeys(['datetime', 'level'])
        ->and($line)->not->toHaveKeys(['context', 'extra']);
});

it('construye el canal json con el formatter plano y el contexto de Laravel', function () {
    $channel = config('logging.channels.json');

    expect($channel['formatter'])->toBe(FlatJsonFormatter::class)
        ->and($channel['processors'])->toContain(PsrLogMessageProcessor::class)
        ->and($channel['formatter_with']['ignoreEmptyContextAndExtra'])->toBeTrue();

    $logger = Log::channel('json')->getLogger();
    $handlers = $logger->getHandlers();

    // Si algún processor no implementa ProcessorInterface, Laravel no construye
    // el canal y cae al *emergency logger* (escribiría en storage/logs/laravel.log
    // con LineFormatter): por eso se comprueba el handler real, no solo el config.
    expect($handlers)->not->toBeEmpty()
        ->and($handlers[0]->getFormatter())->toBeInstanceOf(FlatJsonFormatter::class)
        ->and(collect($logger->getProcessors())->contains(
            fn ($processor) => $processor instanceof ContextLogProcessor
        ))->toBeTrue();
});

it('activa el canal json en el .env de ejemplo', function () {
    // Los tests fijan LOG_CHANNEL=null (phpunit.xml) para no ensuciar la salida;
    // esto garantiza que quien copia .env.example sí usa los logs JSON.
    expect(file_get_contents(base_path('.env.example')))->toContain('LOG_CHANNEL=json');
});
