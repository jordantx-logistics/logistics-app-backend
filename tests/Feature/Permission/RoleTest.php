<?php

namespace Tests\Feature\Permission;

use Src\Identity\Role\Models\Permission;
use Src\Identity\Role\Models\Role;
use Tests\TestCase;

class RoleTest extends TestCase
{
    /**
     * Test listing roles
     */
    public function test_it_can_list_roles(): void
    {
        $user = adminUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/permission/roles');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'roles' => [
                    '*' => ['id', 'name', 'guard_name'],
                ],
            ],
        ]);
    }

    /**
     * Test creating a role
     */
    public function test_it_can_create_role(): void
    {
        $user = adminUser();
        $this->actingAs($user);

        $response = $this->postJson(
            '/api/v1/permission/roles',
            ['name' => 'TestRole']
        );

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => [
                'name' => 'TestRole',
            ],
        ]);

        // Mismo motivo que en `PermissionTest`: el guard del rol es `web`, no el
        // guard por defecto de la petición (`sanctum` tras `auth:sanctum`).
        $this->assertDatabaseHas('roles', [
            'name' => 'TestRole',
            'guard_name' => 'web',
        ]);
    }

    /**
     * Test retrieving a role
     */
    public function test_it_can_retrieve_role(): void
    {
        $user = adminUser();
        $role = Role::create(['name' => 'TestRole']);
        $this->actingAs($user);

        $response = $this->getJson("/api/v1/permission/roles/{$role->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'name' => 'TestRole',
            ],
        ]);
    }

    /**
     * Test updating a role
     */
    public function test_it_can_update_role(): void
    {
        $user = adminUser();
        $role = Role::create(['name' => 'TestRole']);
        $this->actingAs($user);

        $response = $this->putJson(
            "/api/v1/permission/roles/{$role->id}",
            ['name' => 'UpdatedRole']
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => [
                'name' => 'UpdatedRole',
            ],
        ]);
    }

    /**
     * Test deleting a role
     */
    public function test_it_can_delete_role(): void
    {
        $user = adminUser();
        $role = Role::create(['name' => 'TestRole']);
        $this->actingAs($user);

        $response = $this->deleteJson("/api/v1/permission/roles/{$role->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }

    /**
     * Test assigning permissions to a role
     */
    public function test_it_can_assign_permissions_to_role(): void
    {
        $user = adminUser();
        $role = Role::create(['name' => 'TestRole']);
        $permission = Permission::create(['name' => 'test.permission']);
        $this->actingAs($user);

        $response = $this->postJson(
            "/api/v1/permission/roles/{$role->id}/permissions",
            ['permission_ids' => [$permission->id]]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Permissions assigned to role successfully.',
            'data' => [
                'permissions' => ['test.permission'],
            ],
        ]);
    }

    /**
     * Test assigning non-existent permission to role
     */
    public function test_it_validates_permission_assignment(): void
    {
        $user = adminUser();
        $role = Role::create(['name' => 'TestRole']);
        $this->actingAs($user);

        $response = $this->postJson(
            "/api/v1/permission/roles/{$role->id}/permissions",
            ['permission_ids' => [99999]]
        );

        $response->assertStatus(422);
    }

    /**
     * Test role not found
     */
    public function test_it_returns_404_for_nonexistent_role(): void
    {
        $user = adminUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/permission/roles/99999');

        $response->assertStatus(404);
    }

    /**
     * Test assigning role to user
     */
    public function test_it_can_assign_role_to_user(): void
    {
        $user = adminUser();
        $role = Role::create(['name' => 'TestRole']);
        $this->actingAs($user);

        $response = $this->postJson(
            "/api/v1/permission/users/{$user->id}/roles",
            ['role_ids' => [$role->id]]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Role assigned to user successfully.',
        ]);
    }

    /**
     * Test assigning non-existent role to user
     */
    public function test_it_validates_role_assignment(): void
    {
        $user = adminUser();
        $this->actingAs($user);

        $response = $this->postJson(
            "/api/v1/permission/users/{$user->id}/roles",
            ['role_ids' => [99999]]
        );

        $response->assertStatus(422);
    }

    /**
     * Un id no numérico no debe llegar al controlador (`int $id`): la restricción
     * de ruta lo deja en un 404 en vez de un TypeError (500).
     */
    public function test_it_returns_404_for_a_non_numeric_role_id(): void
    {
        $user = adminUser();
        $this->actingAs($user);

        $this->getJson('/api/v1/permission/roles/architecto')->assertStatus(404);
        $this->deleteJson('/api/v1/permission/roles/architecto')->assertStatus(404);
    }
}
