<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PermissionService;
use Tests\TestCase;

final class PermissionServiceTest extends TestCase
{
    public function testSuperAdminRoleHasImplicitAccess(): void
    {
        $service = new PermissionService();
        $user = new \App\Models\User(['id' => 1, 'role_id' => 1, 'tenant_id' => 1]);

        $this->assertTrue($service->can($user, 'billing.manage'));
        $this->assertTrue($service->can($user, 'any.permission'));
    }
}
