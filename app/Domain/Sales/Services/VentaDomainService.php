<?php

namespace App\Domain\Sales\Services;

use App\Application\DTOs\VentaDTO;
use App\Domain\Sales\ValueObjects\VentaState;
use App\Domain\Shared\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Servicio de dominio para Venta
 *
 * Contiene lógica de validación y decisiones de negocio
 * que son independientes de la persistencia
 */
final class VentaDomainService
{
    /**
     * Validar que una venta pueda completarse
     *
     * @throws InvalidArgumentException
     */
    public function validarPuedeCompletarse(VentaDTO $venta): void
    {
        // Validar estado
        if (!$venta->estado->puedeCompletarse()) {
            throw new InvalidArgumentException(
                "Venta en estado '{$venta->estado->label()}' no puede completarse"
            );
        }

        // Validar que tenga detalles
        if ($venta->detalles->isEmpty()) {
            throw new InvalidArgumentException("Venta debe tener al menos un detalle");
        }

        // Validar que el total sea positivo
        if (!$venta->total->isPositive()) {
            throw new InvalidArgumentException("Total debe ser mayor a 0");
        }

        // Validar que cliente exista y esté activo
        if (!$venta->clienteId) {
            throw new InvalidArgumentException("Cliente es requerido");
        }

        // Validar que bodega exista
        if (!$venta->bodegaId) {
            throw new InvalidArgumentException("Bodega es requerida");
        }
    }

    /**
     * Validar que una venta pueda cancelarse
     *
     * @throws InvalidArgumentException
     */
    public function validarPuedeCancelarse(VentaDTO $venta): void
    {
        if (!$venta->estado->puedeCancelarse()) {
            throw new InvalidArgumentException(
                "Venta en estado '{$venta->estado->label()}' no puede cancelarse"
            );
        }
    }

    /**
     * Calcular ganancia de una venta
     */
    public function calcularGanancia(VentaDTO $venta): Money
    {
        $costoTotal = $venta->detalles->reduce(
            function ($suma, $detalle) {
                $costoLinea = $detalle->costoUnitario->multiply($detalle->cantidad);
                return $suma + $costoLinea->getAmount();
            },
            0
        );

        return $venta->subtotal->subtract(Money::make($costoTotal));
    }

    /**
     * Calcular margen de ganancia en porcentaje
     */
    public function calcularMargenPorcentaje(VentaDTO $venta): float
    {
        $ganancia = $this->calcularGanancia($venta);

        if ($venta->subtotal->getAmount() <= 0) {
            return 0;
        }

        return ($ganancia->getAmount() / $venta->subtotal->getAmount()) * 100;
    }

    /**
     * Determinar estado de pago basado en pagos registrados
     */
    public function determinarEstadoPago(Money $montoPagado, Money $total): string
    {
        return match(true) {
            $montoPagado->getAmount() >= $total->getAmount() => 'pagado',
            $montoPagado->isPositive() => 'parcial',
            default => 'pendiente',
        };
    }

    /**
     * Validar que el cliente puede recibir crédito
     *
     * @throws InvalidArgumentException
     */
    public function validarClientePuedeComprarCredito(object $cliente, Money $totalVenta): void
    {
        // Si no tiene días de crédito configurados
        if ($cliente->dias_credito <= 0) {
            throw new InvalidArgumentException(
                "Cliente {$cliente->nombre} no está configurado para comprar a crédito"
            );
        }

        // Si no tiene límite de crédito configurado
        if ($cliente->limite_credito <= 0) {
            throw new InvalidArgumentException(
                "Cliente {$cliente->nombre} no tiene límite de crédito configurado"
            );
        }

        // Validar que no exceda límite
        $disponible = $cliente->limite_credito - $cliente->saldo_pendiente;
        if ($totalVenta->getAmount() > $disponible) {
            throw new InvalidArgumentException(
                "Venta excede límite de crédito disponible. " .
                "Disponible: L " . number_format($disponible, 2) . ", " .
                "Requerido: L " . $totalVenta->formatted()
            );
        }
    }

    /**
     * Calcular fecha de vencimiento
     */
    public function calcularFechaVencimiento(object $cliente): ?\DateTimeImmutable
    {
        if ($cliente->dias_credito <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())
            ->add(new \DateInterval("P{$cliente->dias_credito}D"));
    }

    /**
     * ¿La venta está vencida?
     */
    public function estaVencida(VentaDTO $venta): bool
    {
        // No está vencida si ya está pagada
        if ($venta->estaPagada()) {
            return false;
        }

        // No está vencida sin fecha de vencimiento
        if (!$venta->fechaVencimiento) {
            return false;
        }

        // Está vencida si la fecha es pasada
        return $venta->fechaVencimiento < new \DateTimeImmutable();
    }

    /**
     * Obtener días hasta vencimiento (negativo = vencida, positivo = por vencer)
     */
    public function diasHastaVencimiento(VentaDTO $venta): ?int
    {
        if (!$venta->fechaVencimiento) {
            return null;
        }

        $hoy = new \DateTimeImmutable();
        $interval = $hoy->diff($venta->fechaVencimiento);

        if ($interval->invert) {
            return -$interval->days;
        }

        return $interval->days;
    }
}
