<?php

declare(strict_types=1);

namespace Tests\Feature\Clientes;

use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use Database\Seeders\DepartamentoMunicipioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo geográfico de Honduras y su vínculo con clientes.
 *
 * Cubre:
 *   1. El seeder siembra exactamente 18 departamentos / 298 municipios.
 *   2. Idempotencia: re-ejecutar el seeder no duplica.
 *   3. La cascada: cada departamento solo ve sus propios municipios.
 *   4. El cliente puede guardar y leer departamento + municipio.
 */
class CatalogoGeograficoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DepartamentoMunicipioSeeder::class);
    }

    /** @test */
    public function siembra_18_departamentos_y_298_municipios(): void
    {
        $this->assertSame(18, Departamento::count());
        $this->assertSame(298, Municipio::count());
    }

    /** @test */
    public function el_seeder_es_idempotente(): void
    {
        // Segunda corrida: no debe duplicar.
        $this->seed(DepartamentoMunicipioSeeder::class);

        $this->assertSame(18, Departamento::count());
        $this->assertSame(298, Municipio::count());
    }

    /** @test */
    public function cada_departamento_lista_solo_sus_municipios(): void
    {
        $atlantida = Departamento::where('codigo', '01')->firstOrFail();

        $this->assertSame('Atlántida', $atlantida->nombre);
        $this->assertCount(8, $atlantida->municipios);

        // Todos los municipios cargados pertenecen a Atlántida (cascada correcta).
        $this->assertTrue(
            $atlantida->municipios->every(fn (Municipio $m) => $m->departamento_id === $atlantida->id)
        );

        // Francisco Morazán tiene 28 municipios e incluye Tegucigalpa (Distrito Central).
        $fm = Departamento::where('codigo', '08')->firstOrFail();
        $this->assertCount(28, $fm->municipios);
    }

    /** @test */
    public function el_municipio_pertenece_a_su_departamento(): void
    {
        $municipio = Municipio::where('codigo', '0101')->firstOrFail(); // La Ceiba

        $this->assertSame('La Ceiba', $municipio->nombre);
        $this->assertSame('01', $municipio->departamento->codigo);
    }

    /** @test */
    public function el_cliente_guarda_departamento_y_municipio(): void
    {
        $departamento = Departamento::where('codigo', '05')->firstOrFail(); // Cortés
        $municipio = $departamento->municipios()->firstOrFail();

        $cliente = Cliente::factory()->create([
            'departamento_id' => $departamento->id,
            'municipio_id'    => $municipio->id,
        ]);

        $cliente->refresh()->load(['departamento', 'municipio']);

        $this->assertSame($departamento->id, $cliente->departamento->id);
        $this->assertSame($municipio->id, $cliente->municipio->id);
        $this->assertSame('Cortés', $cliente->departamento->nombre);
    }
}
