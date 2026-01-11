<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    use HasFactory;

    protected $table = 'empresa';

    protected $fillable = [
        'nombre',
        'logo',
        'rtn',
        'cai',
        'telefono',
        'correo_electronico',
        'direccion',
        'lema',
        'rango_desde',
        'rango_hasta',
        'ultimo_numero_emitido',
        'fecha_limite_emision',
    ];

    protected $casts = [
        'fecha_limite_emision' => 'date',
    ];

    /**
     * Obtener la instancia única de empresa
     */
    public static function getData(): ?self
    {
        return self::first();
    }

    /**
     * Obtener el siguiente número de factura
     */
    public function getSiguienteNumeroFactura(): ?string
    {
        if (!$this->ultimo_numero_emitido) {
            return $this->rango_desde;
        }

        // Extraer las partes del número
        $partes = explode('-', $this->ultimo_numero_emitido);
        if (count($partes) !== 4) {
            return null;
        }

        // Incrementar el último segmento
        $ultimoSegmento = (int) $partes[3];
        $ultimoSegmento++;
        $partes[3] = str_pad($ultimoSegmento, 8, '0', STR_PAD_LEFT);

        return implode('-', $partes);
    }

    /**
     * Verificar si el rango de facturación está por agotarse
     */
    public function rangoProximoAgotar(int $umbral = 50): bool
    {
        if (!$this->ultimo_numero_emitido || !$this->rango_hasta) {
            return false;
        }

        $partesActual = explode('-', $this->ultimo_numero_emitido);
        $partesHasta = explode('-', $this->rango_hasta);

        if (count($partesActual) !== 4 || count($partesHasta) !== 4) {
            return false;
        }

        $actual = (int) $partesActual[3];
        $hasta = (int) $partesHasta[3];

        return ($hasta - $actual) <= $umbral;
    }

    /**
     * Verificar si el CAI está por vencer
     */
    public function caiProximoVencer(int $diasUmbral = 30): bool
    {
        if (!$this->fecha_limite_emision) {
            return false;
        }

        return $this->fecha_limite_emision->diffInDays(now()) <= $diasUmbral;
    }

    /**
     * Verificar si el CAI ya venció
     */
    public function caiVencido(): bool
    {
        if (!$this->fecha_limite_emision) {
            return false;
        }

        return $this->fecha_limite_emision->isPast();
    }

    /**
     * Actualizar último número emitido
     */
    public function actualizarUltimoNumero(string $numero): void
    {
        $this->update(['ultimo_numero_emitido' => $numero]);
    }
}