<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departamento de Honduras (catálogo geográfico, 18 registros).
 *
 * Datos de referencia inmutables sembrados por DepartamentoMunicipioSeeder.
 */
class Departamento extends Model
{
    use HasFactory;

    protected $table = 'departamentos';

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    public function municipios(): HasMany
    {
        return $this->hasMany(Municipio::class, 'departamento_id');
    }
}
