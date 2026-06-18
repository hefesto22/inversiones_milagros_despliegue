<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Municipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra el catálogo geográfico de Honduras: 18 departamentos / 298 municipios.
 *
 * Fuente: database/data/honduras_geo.php (códigos oficiales, orden oficial).
 * Idempotente: usa updateOrCreate por código, así que re-ejecutarlo no duplica.
 *
 *   php artisan db:seed --class=Database\\Seeders\\DepartamentoMunicipioSeeder
 */
class DepartamentoMunicipioSeeder extends Seeder
{
    /**
     * Nombres oficiales con acentuación correcta por código de departamento.
     * La fuente JSON viene en MAYÚSCULAS sin tildes; aquí se normaliza.
     */
    private const NOMBRES_DEPARTAMENTO = [
        '01' => 'Atlántida',
        '02' => 'Colón',
        '03' => 'Comayagua',
        '04' => 'Copán',
        '05' => 'Cortés',
        '06' => 'Choluteca',
        '07' => 'El Paraíso',
        '08' => 'Francisco Morazán',
        '09' => 'Gracias a Dios',
        '10' => 'Intibucá',
        '11' => 'Islas de la Bahía',
        '12' => 'La Paz',
        '13' => 'Lempira',
        '14' => 'Ocotepeque',
        '15' => 'Olancho',
        '16' => 'Santa Bárbara',
        '17' => 'Valle',
        '18' => 'Yoro',
    ];

    public function run(): void
    {
        $catalogo = require database_path('data/honduras_geo.php');

        DB::transaction(function () use ($catalogo): void {
            foreach ($catalogo as $dep) {
                $codigoDep = $dep['codigo'];

                $departamento = Departamento::updateOrCreate(
                    ['codigo' => $codigoDep],
                    ['nombre' => self::NOMBRES_DEPARTAMENTO[$codigoDep] ?? $this->aTitulo($dep['nombre'])]
                );

                foreach ($dep['municipios'] as $indice => $nombreMunicipio) {
                    // Código oficial DDMM: departamento (2) + secuencia 1-based (2).
                    $codigoMun = $codigoDep . str_pad((string) ($indice + 1), 2, '0', STR_PAD_LEFT);

                    Municipio::updateOrCreate(
                        ['codigo' => $codigoMun],
                        [
                            'departamento_id' => $departamento->id,
                            'nombre'          => $this->aTitulo($nombreMunicipio),
                        ]
                    );
                }
            }
        });
    }

    /**
     * Convierte "SAN PEDRO SULA" → "San Pedro Sula", manteniendo conectores
     * en minúscula ("La Unión", "Gracias a Dios") salvo si abren el nombre.
     */
    private function aTitulo(string $texto): string
    {
        $conectores = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'a'];
        $palabras = explode(' ', mb_strtolower(trim($texto), 'UTF-8'));

        $resultado = array_map(function (string $palabra, int $i) use ($conectores): string {
            if ($i > 0 && in_array($palabra, $conectores, true)) {
                return $palabra;
            }

            return mb_convert_case($palabra, MB_CASE_TITLE, 'UTF-8');
        }, $palabras, array_keys($palabras));

        return implode(' ', $resultado);
    }
}
