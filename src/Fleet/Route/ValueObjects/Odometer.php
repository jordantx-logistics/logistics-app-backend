<?php

namespace Src\Fleet\Route\ValueObjects;

/**
 * Lectura de odómetro en centésimas de kilómetro, para comparar y restar sin
 * errores de coma flotante (mismo criterio que `Money` con los céntimos).
 */
final class Odometer
{
    private function __construct(public readonly int $hundredths) {}

    public static function fromDecimal(string|int|float $km): self
    {
        return new self((int) round(((float) $km) * 100));
    }

    public function isBelow(self $other): bool
    {
        return $this->hundredths < $other->hundredths;
    }

    /** Kilómetros recorridos desde `$start`. */
    public function kmSince(self $start): float
    {
        return ($this->hundredths - $start->hundredths) / 100;
    }

    public function toDecimal(): string
    {
        return number_format($this->hundredths / 100, 2, '.', '');
    }
}
