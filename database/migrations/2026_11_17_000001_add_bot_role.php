<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Role::where('name', 'bot')->exists()) {
            Role::create(['name' => 'bot']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $role = Role::where('name', 'bot')->first();
        if ($role === null) {
            return;
        }

        DB::table('model_has_roles')->where('role_id', $role->id)->delete();
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
