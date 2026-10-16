<?php

namespace Src\Shared\Pipelines;

use Illuminate\Support\Facades\Pipeline;

class RulesPipeline
{
    /**
     * Run a payload through a series of Rule classes.
     *
     * @param  array<class-string>  $rules
     */
    public static function run(mixed $payload, array $rules): mixed
    {
        return Pipeline::send($payload)
            ->through($rules)
            ->thenReturn();
    }
}
