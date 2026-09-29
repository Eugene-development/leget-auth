<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleMigrationTest extends TestCase
{
    public function test_migration_preserves_student_identity_and_adds_site_admin_role(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('university_enrolled_at'));
        Schema::create('licenses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
        });
        foreach (['student', 'client', 'superadmin'] as $i => $role) {
            DB::table('users')->insert(['id' => $i + 1, 'name' => $role, 'email' => $role.'@test.local', 'password' => 'unused', 'role' => $role]);
        }
        DB::table('licenses')->insert([['user_id' => 2], ['user_id' => 3]]);
        $migration = require base_path('../leget-db/database/migrations/2026_09_28_210000_separate_student_access_and_site_admin.php');
        $migration->up();
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'client']);
        $this->assertNotNull(DB::table('users')->where('id', 1)->value('university_enrolled_at'));
        $this->assertDatabaseHas('users', ['id' => 2, 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['id' => 3, 'role' => 'superadmin']);
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'student']);
        $this->assertDatabaseHas('users', ['id' => 2, 'role' => 'client']);
        $this->assertFalse(Schema::hasColumn('users', 'university_enrolled_at'));
    }
}
