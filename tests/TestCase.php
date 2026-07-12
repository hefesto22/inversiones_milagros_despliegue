<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Setup base de tests.
     *
     * Llama withoutVite() para que los tests no requieran que los assets
     * Vite estén compilados (public/build/manifest.json). Esto desacopla
     * la suite de tests del paso de `npm run build`, lo que permite correr
     * tests en CI sin tener Node instalado y en local sin recompilar.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
