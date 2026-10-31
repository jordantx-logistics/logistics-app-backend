<?php

namespace Src\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * Importe monetario expresado en céntimos enteros.
 *
 * El dominio financiero nunca opera con `float`: un céntimo perdido en una
 * conversión binaria descuadra una caja. La base guarda `decimal(12,2)` y este
 * objeto es el único punto que convierte a/desde esa representación.
 */
final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'PEN',
    ) {
        if ($currency === '') {
            throw new InvalidArgumentException('Currency is required.');
        }
    }

    public static function zero(string $currency = 'PEN'): self
    {
        return new self(0, strtoupper($currency));
    }

    public static function fromCents(int $cents, string $currency = 'PEN'): self
    {
        return new self($cents, strtoupper($currency));
    }

    /**
     * Convierte `45.00`, `45` o `"45.5"` a céntimos sin pasar por float.
     */
    public static function fromDecimal(string|int|float $amount, string $currency = 'PEN'): self
    {
        $normalized = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        $normalized = trim($normalized);

        if ($normalized === '') {
            return self::zero($currency);
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException("Invalid monetary amount [{$amount}].");
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '0', 2, '0');
        $cents = ($whole * 100) + (int) $fraction;

        return new self($negative ? -$cents : $cents, strtoupper($currency));
    }

    public static function sum(self ...$amounts): self
    {
        $currency = $amounts[0]->currency ?? 'PEN';
        $cents = 0;

        foreach ($amounts as $amount) {
            if ($amount->currency !== $currency) {
                throw new InvalidArgumentException('Cannot sum money with different currencies.');
            }

            $cents += $amount->cents;
        }

        return new self($cents, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents >= $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->cents === $other->cents;
    }

    /**
     * Valor decimal con dos posiciones, apto para `decimal(12,2)`.
     */
    public function toDecimal(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $absolute = abs($this->cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function toFloat(): float
    {
        return (float) $this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money currencies must match.');
        }
    }
}
