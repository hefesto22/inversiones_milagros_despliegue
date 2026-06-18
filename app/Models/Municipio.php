<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Municipio de Honduras (catálogo geográfico, 298 registros).
 *
 * Pertenece a un departamento. Datos de referencia inmutables sembrados por
 * DepartamentoMunicipioSeeder.
 */
class Municipio extends Model
{
    use HasFactory;

    protected $table = 'municipios';

    protected $fillable = [
        'departamento_id',
        'codigo',
        'nombre',
    ];

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }
}
