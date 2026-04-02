<?php

namespace App\Domain\Shared\ValueObjects;

use InvalidArgumentException;

/**
 * Value Object para representar dinero con precisión
 *
 * Garantiza:
 * - Precisión decimal correcta
 * - Operaciones aritméticas seguras
 * - Validaciones strictas
 */
final class Money
{
    private float $amount;
    private string $currency;

    /**
     * Constructor privado - usar factory methods
     */
    private function __construct(float $amount, string $currency = 'HNL')
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("Monto no puede ser negativo: {$amount}");
        }

        if (!$this->isCurrencyValid($currency)) {
            throw new InvalidArgumentException("Moneda no válida: {$currency}");
        }

        $this->amount = round($amount, 2);
        $this->currency = $currency;
    }

    /**
     * Factory method para crear Money
     */
    public static function make(float $amount, string $currency = 'HNL'): self
    {
        return new self($amount, $currency);
    }

    /**
     * Factory method desde string
     */
    public static function fromString(string $amount, string $currency = 'HNL'): self
    {
        return self::make((float) $amount, $currency);
    }

    /**
     * Factory method para cero
     */
    public static function zero(string $currency = 'HNL'): self
    {
        return new self(0, $currency);
    }

    /**
     * Obtener el monto
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Obtener la moneda
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Sumar otro Money
     */
    public function add(Money $other): self
    {
        $this->validateSameCurrency($other);
        return self::make($this->amount + $other->amount, $this->currency);
    }

    /**
     * Restar otro Money
     */
    public function subtract(Money $other): self
    {
        $this->validateSameCurrency($other);
        return self::make($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiplicar por un factor
     */
    public function multiply(float $factor): self
    {
        return self::make($this->amount * $factor, $this->currency);
    }

    /**
     * Dividir por un divisor
     */
    public function divide(float $divisor): self
    {
        if ($divisor == 0) {
            throw new InvalidArgumentException("No se puede dividir por cero");
        }

        return self::make($this->amount / $divisor, $this->currency);
    }

    /**
     * Comparar igualdad
     */
    public function equals(Money $other): bool
    {
        $this->validateSameCurrency($other);
        return abs($this->amount - $other->amount) < 0.01;
    }

    /**
     * Verificar si es mayor que
     */
    public function greaterThan(Money $other): bool
    {
        $this->validateSameCurrency($other);
        return $this->amount > $other->amount;
    }

    /**
     * Verificar si es menor que
     */
    public function lessThan(Money $other): bool
    {
        $this->validateSameCurrency($other);
        return $this->amount < $other->amount;
    }

    /**
     * Verificar si es mayor o igual que
     */
    public function greaterThanOrEqual(Money $other): bool
    {
        return $this->greaterThan($other) || $this->equals($other);
    }

    /**
     * Verificar si es menor o igual que
     */
    public function lessThanOrEqual(Money $other): bool
    {
        return $this->lessThan($other) || $this->equals($other);
    }

    /**
     * Verificar si es cero
     */
    public function isZero(): bool
    {
        return abs($this->amount) < 0.01;
    }

    /**
     * Verificar si es positivo
     */
    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Obtener valor absoluto
     */
    public function absolute(): self
    {
        return self::make(abs($this->amount), $this->currency);
    }

    /**
     * Convertir a string formateado
     */
    public function formatted(): string
    {
        return number_format($this->amount, 2, '.', ',');
    }

    /**
     * Convertir a string
     */
    public function __toString(): string
    {
        return $this->currency . ' ' . $this->formatted();
    }

    /**
     * Serializar para JSON
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->formatted(),
        ];
    }

    /**
     * Validar que la moneda sea soportada
     */
    private function isCurrencyValid(string $currency): bool
    {
        return in_array($currency, ['HNL', 'USD', 'EUR']);
    }

    /**
     * Validar que tenga la misma moneda
     */
    private function validateSameCurrency(Money $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "Monedas no coinciden: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
