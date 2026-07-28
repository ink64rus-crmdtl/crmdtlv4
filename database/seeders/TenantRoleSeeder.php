<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class TenantRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Сброс кэша ролей и прав перед сидингом
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Создание базовых системных ролей тенанта
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'master', 'guard_name' => 'web']);
    }
}