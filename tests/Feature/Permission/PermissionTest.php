<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Src\Permission\Models\Permission;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    /**
     * Test listing permissions
     */
    public function test_it_can_list_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->getJson('/api/v1/permission/permissions');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'permissions' => [
                    '*' => ['id', 'name', 'guard_name'],
                ],
            ],
        ]);
    }

    /**
     * Test creating a permission
     */
    public function test_it_can_create_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->postJson(
            '/api/v1/permission/permissions',
            ['name' => 'test.permission']
        );

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Permission created successfully.',
            'data' => [
                'name' => 'test.permission',
            ],
        ]);
    }

    /**
     * Test retrieving a permission
     */
    public function test_it_can_retrieve_permission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'test.permission']);
        $this->actingAs($user, 'api');

        $response = $this->getJson("/api/v1/permission/permissions/{$permission->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'name' => 'test.permission',
            ],
        ]);
    }

    /**
     * Test updating a permission
     */
    public function test_it_can_update_permission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'test.permission']);
        $this->actingAs($user, 'api');

        $response = $this->putJson(
            "/api/v1/permission/permissions/{$permission->id}",
            ['name' => 'updated.permission']
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Permission updated successfully.',
            'data' => [
                'name' => 'updated.permission',
            ],
        ]);
    }

    /**
     * Test deleting a permission
     */
    public function test_it_can_delete_permission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'test.permission']);
        $this->actingAs($user, 'api');

        $response = $this->deleteJson("/api/v1/permission/permissions/{$permission->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Permission deleted successfully.',
        ]);
    }

    /**
     * Test permission not found
     */
    public function test_it_returns_404_for_nonexistent_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->getJson('/api/v1/permission/permissions/99999');

        $response->assertStatus(404);
    }

    /**
     * Test validation for permission creation
     */
    public function test_it_validates_permission_creation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->postJson(
            '/api/v1/permission/permissions',
            ['name' => '']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }
}
