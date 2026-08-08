<?php

declare(strict_types=1);

namespace Tests\Feature\Viajes;

use App\Models\Bodega;
use App\Models\Camion;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\User;
use App\Models\Viaje;
use Database\Seeders\DepartamentoMunicipioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zona de ruta del viaje: qué clientes puede atender el vendedor.
 *
 * La regla vive en Cliente::scopeEnZonaDe() y se resume así:
 *
 *   - Viaje sin zona                → ve todos los clientes (sin regresión).
 *   - Cliente sin depto O sin muni  → COMODÍN, visible en todos los viajes.
 *   - Departamento sin municipios marcados → entra completo.
 *   - Departamento con municipios marcados → entra SOLO con esos municipios.
 *
 * Cubre además que la validación del servidor (perteneceAZonaDe) use
 * exactamente el mismo criterio que la lista, que es lo que hace que el
 * filtro sea duro y no solo cosmético.
 */
class ZonaViajeTest extends TestCase
{
    use RefreshDatabase;

    private static int $contadorCamion = 700;

    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DepartamentoMunicipioSeeder::class);

        $this->bodega = Bodega::factory()->create();
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * @param  array<int, Departamento>  $departamentos
     * @param  array<int, Municipio>  $municipios
     */
    private function crearViaje(array $departamentos = [], array $municipios = []): Viaje
    {
        $chofer = User::factory()->create();
        $admin = User::factory()->create();

        $n = str_pad((string) ++self::$contadorCamion, 6, '0', STR_PAD_LEFT);

        $camion = Camion::create([
            'codigo' => 'CAM-Z-'.$n,
            'placa' => 'TSZ-'.$n,
            'bodega_id' => $this->bodega->id,
            'activo' => true,
            'created_by' => $admin->id,
        ]);

        $viaje = Viaje::create([
            'camion_id' => $camion->id,
            'chofer_id' => $chofer->id,
            'bodega_origen_id' => $this->bodega->id,
            'fecha_salida' => now(),
            'estado' => Viaje::ESTADO_EN_RUTA,
        ]);

        if ($departamentos !== []) {
            $viaje->departamentosZona()->sync(collect($departamentos)->pluck('id')->all());
        }

        if ($municipios !== []) {
            $viaje->municipiosZona()->sync(collect($municipios)->pluck('id')->all());
        }

        return $viaje->refresh();
    }

    private function departamento(string $codigo): Departamento
    {
        return Departamento::where('codigo', $codigo)->firstOrFail();
    }

    /**
     * Cliente ubicado en un municipio concreto (departamento + municipio).
     */
    private function clienteEn(Municipio $municipio): Cliente
    {
        return Cliente::factory()->create([
            'departamento_id' => $municipio->departamento_id,
            'municipio_id' => $municipio->id,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function idsVisiblesEn(Viaje $viaje): array
    {
        return Cliente::enZonaDe($viaje)->pluck('id')->all();
    }

    // =========================================================
    // Viaje sin zona
    // =========================================================

    #[Test]
    public function viaje_sin_zona_ve_todos_los_clientes(): void
    {
        $cortes = $this->departamento('05');
        $atlantida = $this->departamento('01');

        $unoDeCortes = $this->clienteEn($cortes->municipios()->firstOrFail());
        $unoDeAtlantida = $this->clienteEn($atlantida->municipios()->firstOrFail());
        $sinZona = Cliente::factory()->create(['departamento_id' => null, 'municipio_id' => null]);

        $viaje = $this->crearViaje();

        $visibles = $this->idsVisiblesEn($viaje);

        $this->assertContains($unoDeCortes->id, $visibles);
        $this->assertContains($unoDeAtlantida->id, $visibles);
        $this->assertContains($sinZona->id, $visibles);
    }

    #[Test]
    public function sin_viaje_no_se_filtra_nada(): void
    {
        $cortes = $this->departamento('05');
        $cliente = $this->clienteEn($cortes->municipios()->firstOrFail());

        $this->assertContains($cliente->id, Cliente::enZonaDe(null)->pluck('id')->all());
    }

    // =========================================================
    // Comodines
    // =========================================================

    #[Test]
    public function cliente_sin_departamento_ni_municipio_aparece_en_todos_los_viajes(): void
    {
        $comodin = Cliente::factory()->create(['departamento_id' => null, 'municipio_id' => null]);

        $viaje = $this->crearViaje([$this->departamento('05')]);

        $this->assertContains($comodin->id, $this->idsVisiblesEn($viaje));
        $this->assertTrue($comodin->perteneceAZonaDe($viaje));
    }

    #[Test]
    public function cliente_con_departamento_pero_sin_municipio_tambien_es_comodin(): void
    {
        // Ubicación incompleta = comodín: solo cuenta como "ubicado" quien
        // tiene departamento Y municipio.
        $atlantida = $this->departamento('01');

        $incompleto = Cliente::factory()->create([
            'departamento_id' => $atlantida->id,
            'municipio_id' => null,
        ]);

        // Zona que NO incluye Atlántida.
        $viaje = $this->crearViaje([$this->departamento('05')]);

        $this->assertContains($incompleto->id, $this->idsVisiblesEn($viaje));
        $this->assertTrue($incompleto->perteneceAZonaDe($viaje));
    }

    #[Test]
    public function consumidor_final_siempre_esta_disponible(): void
    {
        $consumidorFinal = Cliente::consumidorFinal();

        $viaje = $this->crearViaje([$this->departamento('05')]);

        $this->assertTrue($consumidorFinal->perteneceAZonaDe($viaje));
        $this->assertContains($consumidorFinal->id, $this->idsVisiblesEn($viaje));
    }

    // =========================================================
    // Departamento completo
    // =========================================================

    #[Test]
    public function departamento_sin_municipios_marcados_entra_completo(): void
    {
        $cortes = $this->departamento('05');
        $municipiosDeCortes = $cortes->municipios()->orderBy('nombre')->take(2)->get();

        $dentroA = $this->clienteEn($municipiosDeCortes[0]);
        $dentroB = $this->clienteEn($municipiosDeCortes[1]);
        $fuera = $this->clienteEn($this->departamento('01')->municipios()->firstOrFail());

        $viaje = $this->crearViaje([$cortes]);

        $visibles = $this->idsVisiblesEn($viaje);

        $this->assertContains($dentroA->id, $visibles);
        $this->assertContains($dentroB->id, $visibles);
        $this->assertNotContains($fuera->id, $visibles);
    }

    // =========================================================
    // Recorte por municipio
    // =========================================================

    #[Test]
    public function marcar_municipios_recorta_ese_departamento(): void
    {
        $cortes = $this->departamento('05');
        $municipios = $cortes->municipios()->orderBy('nombre')->take(2)->get();

        $elegido = $this->clienteEn($municipios[0]);
        $mismoDeptoOtroMunicipio = $this->clienteEn($municipios[1]);

        // Cortés en la zona, pero recortado al primer municipio.
        $viaje = $this->crearViaje([$cortes], [$municipios[0]]);

        $visibles = $this->idsVisiblesEn($viaje);

        $this->assertContains($elegido->id, $visibles);
        $this->assertNotContains($mismoDeptoOtroMunicipio->id, $visibles);

        // Y la validación del servidor coincide con la lista.
        $this->assertTrue($elegido->perteneceAZonaDe($viaje));
        $this->assertFalse($mismoDeptoOtroMunicipio->perteneceAZonaDe($viaje));
    }

    #[Test]
    public function recortar_un_departamento_no_recorta_a_los_demas(): void
    {
        // Escenario del pedido: "voy a Cortés y a Yoro, pero de Cortés solo a
        // Choloma". Yoro debe entrar completo.
        $cortes = $this->departamento('05');
        $atlantida = $this->departamento('01');

        $municipiosCortes = $cortes->municipios()->orderBy('nombre')->take(2)->get();
        $municipiosAtlantida = $atlantida->municipios()->orderBy('nombre')->take(2)->get();

        $cortesDentro = $this->clienteEn($municipiosCortes[0]);
        $cortesFuera = $this->clienteEn($municipiosCortes[1]);
        $atlantidaUno = $this->clienteEn($municipiosAtlantida[0]);
        $atlantidaDos = $this->clienteEn($municipiosAtlantida[1]);

        $viaje = $this->crearViaje([$cortes, $atlantida], [$municipiosCortes[0]]);

        $visibles = $this->idsVisiblesEn($viaje);

        $this->assertContains($cortesDentro->id, $visibles);
        $this->assertNotContains($cortesFuera->id, $visibles);

        // Atlántida sin municipios marcados → completo.
        $this->assertContains($atlantidaUno->id, $visibles);
        $this->assertContains($atlantidaDos->id, $visibles);
    }

    #[Test]
    public function municipio_de_un_departamento_no_seleccionado_igual_entra(): void
    {
        // Defensa: el formulario no permite llegar a este estado (los
        // municipios salen en cascada del departamento), pero si el dato
        // existiera, el municipio marcado manda y el cliente se ve.
        $cortes = $this->departamento('05');
        $atlantida = $this->departamento('01');

        $municipioSuelto = $atlantida->municipios()->orderBy('nombre')->firstOrFail();
        $cliente = $this->clienteEn($municipioSuelto);

        $viaje = $this->crearViaje([$cortes], [$municipioSuelto]);

        $this->assertContains($cliente->id, $this->idsVisiblesEn($viaje));
    }

    // =========================================================
    // Filtro duro: la validación del servidor
    // =========================================================

    #[Test]
    public function cliente_fuera_de_zona_es_rechazado_por_el_servidor(): void
    {
        $cortes = $this->departamento('05');
        $atlantida = $this->departamento('01');

        $deOtraZona = $this->clienteEn($atlantida->municipios()->firstOrFail());

        $viaje = $this->crearViaje([$cortes]);

        // No aparece en la lista...
        $this->assertNotContains($deOtraZona->id, $this->idsVisiblesEn($viaje));

        // ...y tampoco se puede seleccionar a mano por ID (defensa en el
        // servidor: es lo que impide saltarse el filtro desde el navegador).
        $this->assertFalse($deOtraZona->perteneceAZonaDe($viaje));
    }

    #[Test]
    public function asignarle_zona_a_un_comodin_deja_de_mostrarlo_en_otras_zonas(): void
    {
        // Efecto de la acción masiva "Asignar zona": mientras el cliente no
        // tiene ubicación se ve en todos los viajes; en cuanto se le asigna,
        // solo en los de su zona.
        $cortes = $this->departamento('05');
        $atlantida = $this->departamento('01');

        $cliente = Cliente::factory()->create(['departamento_id' => null, 'municipio_id' => null]);
        $viaje = $this->crearViaje([$cortes]);

        $this->assertTrue($cliente->perteneceAZonaDe($viaje));

        $municipioAtlantida = $atlantida->municipios()->firstOrFail();
        $cliente->update([
            'departamento_id' => $municipioAtlantida->departamento_id,
            'municipio_id' => $municipioAtlantida->id,
        ]);

        $this->assertFalse($cliente->fresh()->perteneceAZonaDe($viaje));
    }

    // =========================================================
    // Presentación
    // =========================================================

    #[Test]
    public function la_zona_se_describe_en_texto_legible(): void
    {
        $cortes = $this->departamento('05');
        $atlantida = $this->departamento('01');
        $municipioCortes = $cortes->municipios()->orderBy('nombre')->firstOrFail();

        $viaje = $this->crearViaje([$cortes, $atlantida], [$municipioCortes]);

        $descripcion = $viaje->descripcionZona();

        // Atlántida completo, Cortés recortado a un municipio.
        $this->assertStringContainsString('Atlántida', $descripcion);
        $this->assertStringContainsString('Cortés ('.$municipioCortes->nombre.')', $descripcion);
    }

    #[Test]
    public function un_viaje_sin_zona_no_tiene_descripcion(): void
    {
        $viaje = $this->crearViaje();

        $this->assertNull($viaje->descripcionZona());
        $this->assertFalse($viaje->tieneZonaDefinida());
    }

    #[Test]
    public function un_viaje_con_zona_reporta_que_la_tiene(): void
    {
        $viaje = $this->crearViaje([$this->departamento('05')]);

        $this->assertTrue($viaje->tieneZonaDefinida());
    }
}
