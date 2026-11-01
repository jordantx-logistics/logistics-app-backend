<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Src\Identity\User\Models\AccessToken;
use Src\Shared\Services\ReadWriteRouting;

/*
 * SQLite no tiene réplica: aquí se comprueba la decisión, que es el interruptor del `sticky`.
 * El enrutamiento real lo comprueba `scripts/replica-routing-check.php`.
 */
beforeEach(function () {
    config(['database.connections.'.config('database.default').'.read' => ['host' => 'replica']]);

    Route::middleware('api')->match(['get', 'post'], 'api/v1/test-routing', fn () => response()->json([
        'primary' => DB::connection()->hasModifiedRecords(),
    ]));
});

it('deja leer de la réplica en un GET', function () {
    DB::connection()->recordsHaveBeenModified(true);

    $this->getJson('api/v1/test-routing')->assertOk()->assertJson(['primary' => false]);
});

it('lee del primary en cualquier otro método', function () {
    $this->postJson('api/v1/test-routing')->assertOk()->assertJson(['primary' => true]);
});

it('quien acaba de escribir sigue leyendo del primary', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    app(ReadWriteRouting::class)->rememberWrite($user->id);
    app(ReadWriteRouting::class)->pinIfWroteRecently($user->id);

    expect(DB::connection()->hasModifiedRecords())->toBeTrue()
        ->and(Cache::has('db-primary-pin:'.$user->id))->toBeTrue();
});

it('no recuerda nada sin réplica configurada', function () {
    config(['database.connections.'.config('database.default').'.read' => null]);

    app(ReadWriteRouting::class)->rememberWrite(7);

    expect(Cache::has('db-primary-pin:7'))->toBeFalse();
});

it('una petición de escritura marca al usuario', function () {
    Route::middleware(['api', 'auth:sanctum'])->post('api/v1/test-routing-write', fn () => response()->json());

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('api/v1/test-routing-write')->assertOk();

    expect(Cache::has('db-primary-pin:'.$user->id))->toBeTrue();
});

it('una escritura rechazada no marca al usuario', function () {
    Route::middleware(['api', 'auth:sanctum'])->post('api/v1/test-routing-rejected', fn () => response()->json([], 422));

    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('api/v1/test-routing-rejected')->assertStatus(422);

    expect(Cache::has('db-primary-pin:'.$user->id))->toBeFalse();
});

it('resuelve el token con su usuario y rechaza el secreto incorrecto', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('test')->plainTextToken;

    $found = AccessToken::findToken($plain);

    expect($found)->not->toBeNull()
        ->and($found->tokenable->is($user))->toBeTrue()
        ->and(AccessToken::findToken(explode('|', $plain)[0].'|wrong'))->toBeNull();
});

it('no escribe last_used_at en cada petición', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('test')->plainTextToken;

    $token = AccessToken::findToken($plain);
    $token->forceFill(['last_used_at' => now()->subSeconds(10)])->save();
    $recent = $token->fresh()->last_used_at;

    $token = AccessToken::findToken($plain);
    $token->forceFill(['last_used_at' => now()])->save();
    expect($token->fresh()->last_used_at->equalTo($recent))->toBeTrue();

    $old = $token;
    DB::table('personal_access_tokens')->where('id', $old->id)->update(['last_used_at' => now()->subMinutes(5)]);
    $old = AccessToken::findToken($plain);
    $old->forceFill(['last_used_at' => now()])->save();

    expect($old->fresh()->last_used_at->greaterThan(now()->subMinute()))->toBeTrue();
});
