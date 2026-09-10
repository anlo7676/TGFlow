<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_create_plan_and_denial_is_audited()
    {
        $admin = Admin::create(['username' => 'viewer', 'email' => 'viewer@example.com', 'password_hash' => Hash::make('password-long'), 'role' => 'viewer', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/plans', [])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['admin_id' => $admin->id, 'object_type' => 'admin_api']);
    }
}
