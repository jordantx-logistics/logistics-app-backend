<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Ejemplos de Scribe
|--------------------------------------------------------------------------
|
| `@apiResourceModel` construye el modelo de ejemplo con su factory. Si el
| modelo no tiene factory, Scribe cae a una instancia vacía: el ejemplo queda
| en `null` y el esquema del contrato cambia (`string|null` en cada campo).
| Eso pasó con `Order` —funcionaba en una BD con órdenes y derivaba contra una
| BD recién sembrada— y rompió el gate de deriva del CI. La factory es lo que
| hace que el contrato no dependa del estado de la base.
|
*/

it('cada modelo de ejemplo de Scribe tiene factory', function () {
    $models = collect(File::allFiles(base_path('src')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/@apiResourceModel\s+(\S+)/', $file->getContents(), $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($models)->not->toBeEmpty();

    foreach ($models as $model) {
        expect(class_exists($model))->toBeTrue();

        expect(class_exists(Factory::resolveFactoryName($model)))
            ->toBeTrue("@apiResourceModel $model no tiene factory: el ejemplo de Scribe dependería de la BD");
    }
});
