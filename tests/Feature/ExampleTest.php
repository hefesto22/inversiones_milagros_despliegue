<?php

it('returns a redirect to admin login from the root URL', function () {
    // El producto no tiene landing pública. GET / redirige a /admin/login
    // por diseño (ver routes/web.php — ruta nombrada 'home').
    $response = $this->get('/');

    $response->assertStatus(302);
    $response->assertRedirect('/admin/login');
});
