<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Unidad;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // =============================
        // CREAR USUARIO ADMIN
        // =============================
        $user = User::updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('12345678'),
            ]
        );

        // =============================
        // CREAR ROL ADMIN Y PERMISOS
        // =============================
        $role = Role::firstOrCreate(['name' => 'admin']);

        // Generar permisos con Shield
        Artisan::call('shield:generate');
        Artisan::call('permission:cache-reset');

        // Vuelve a cargar los permisos después de generarlos
        $permissions = Permission::all();

        // Asignar permisos al rol
        $role->syncPermissions($permissions);

        // Asignar rol al usuario
        $user->assignRole($role);

        // =============================
        // CREAR UNIDADES DE MEDIDA
        // =============================
        $unidades = [
            [
                'nombre' => 'Carton',
                'simbolo' => '1',
                'es_decimal' => false,
                'activo' => true,
            ],
            [
                'nombre' => 'Libra',
                'simbolo' => '1',
                'es_decimal' => false,
                'activo' => true,
            ],
            [
                'nombre' => 'Media Libra',
                'simbolo' => '0.5',
                'es_decimal' => true,
                'activo' => true,
            ],
            [
                'nombre' => 'Medio Carton',
                'simbolo' => '0.5',
                'es_decimal' => true,
                'activo' => true,
            ],
            [
                'nombre' => 'Paquete',
                'simbolo' => '1',
                'es_decimal' => false,
                'activo' => true,
            ],
            [
                'nombre' => 'Unidad',
                'simbolo' => '1',
                'es_decimal' => false,
                'activo' => true,
            ],
        ];

        foreach ($unidades as $unidad) {
            Unidad::updateOrCreate(
                ['nombre' => $unidad['nombre']],
                array_merge($unidad, [
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ])
            );
        }
    }
}