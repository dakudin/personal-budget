<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = ['categories.view', 'transactions.view', 'transactions.create', 'transactions.update', 'transactions.delete'];

        foreach ([...$permissions, 'categories.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['user', 'admin'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo($permissions);
        }

        Role::findByName('admin', 'web')->givePermissionTo('categories.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
