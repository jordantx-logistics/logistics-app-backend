<?php

/*
|--------------------------------------------------------------------------
| CORS de la SPA
|--------------------------------------------------------------------------
|
| La SPA (otro origen) habla con la API con credenciales, así que la respuesta
| tiene que permitir el origen del frontend (`FRONTEND_URL`) y las credenciales:
| con un comodín el navegador descarta la cookie de sesión y el login no
| funciona. Fase 1, paso 1.5.
|
*/

it('permite las credenciales desde el origen del frontend', function () {
    $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])
        ->getJson('/sanctum/csrf-cookie');

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('no refleja un origen desconocido ni abre la API con comodín', function () {
    $response = $this->withHeaders(['Origin' => 'http://evil.example.com'])
        ->getJson('/sanctum/csrf-cookie');

    // Con un único origen permitido, `php-cors` escribe esa cabecera tal cual;
    // lo que no puede pasar es que refleje el origen del atacante ni que
    // devuelva `*` con credenciales (el navegador descarta la respuesta).
    expect($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('*')
        ->not->toBe('http://evil.example.com');
});

it('responde al preflight de la API con el método permitido', function () {
    $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ]);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});
