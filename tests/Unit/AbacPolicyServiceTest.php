<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AbacPolicyService;
use App\Models\User;
use Tests\TestCase;

final class AbacPolicyServiceTest extends TestCase
{
    public function testAdminCanManageBilling(): void
    {
        $service = new AbacPolicyService();
        $admin = new User(['id' => 1, 'role_id' => 2, 'tenant_id' => 1]);

        $this->assertTrue($service->evaluate($admin, 'billing.manage'));
    }

    public function testSupportCannotDeleteServers(): void
    {
        $service = new AbacPolicyService();
        $support = new User(['id' => 5, 'role_id' => 4, 'tenant_id' => 1]);

        $this->assertFalse($service->evaluate($support, 'servers.delete'));
    }
}
