<?php

use Src\Shared\Pipelines\RulesPipeline;
use Src\Shared\Services\TicketCodeGenerator;
use Src\Shared\Services\TicketNumberGenerator;

it('generates sequential ticket numbers', function () {
    $generator = new TicketNumberGenerator;

    $code1 = $generator->generate('ABC');
    $code2 = $generator->generate('ABC');

    expect($code1)->toBe('ABC-0000001');
    expect($code2)->toBe('ABC-0000002');
});

it('generates 8 char alphanumeric ticket codes', function () {
    $generator = new TicketCodeGenerator;

    $code = $generator->generate();

    expect(strlen($code))->toBe(8);
    expect($code)->toMatch('/^[A-Z0-9]+$/');
});

it('runs data through a rules pipeline', function () {
    class AddOneRule
    {
        public function handle(array $data, Closure $next)
        {
            $data['count'] += 1;

            return $next($data);
        }
    }

    class MultiplyTwoRule
    {
        public function handle(array $data, Closure $next)
        {
            $data['count'] *= 2;

            return $next($data);
        }
    }

    $result = RulesPipeline::run(['count' => 10], [
        AddOneRule::class,
        MultiplyTwoRule::class,
    ]);

    // (10 + 1) * 2 = 22
    expect($result['count'])->toBe(22);
});
