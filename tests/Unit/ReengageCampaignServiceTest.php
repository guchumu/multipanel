<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MediaUser;
use App\Services\ReengageCampaignService;
use Tests\TestCase;

final class ReengageCampaignServiceTest extends TestCase
{
    public function testRenderReplacesHookPlaceholders(): void
    {
        $svc = new ReengageCampaignService();
        $user = new MediaUser([
            'username' => 'ana',
            'display_name' => 'Ana López',
            'email' => 'ana@ejemplo.com',
            'expires_at' => '2026-08-01',
        ]);
        $cfg = ['trial_days' => 3];
        $out = $svc->render(
            'Hola {display_name}, prueba {trial_days}d en {server_name}. Portal {portal_url}',
            $user,
            $cfg,
            'NucBox'
        );

        $this->assertStringContainsString('Ana López', $out);
        $this->assertStringContainsString('3d', $out);
        $this->assertStringContainsString('NucBox', $out);
        $this->assertStringContainsString('/portal/login', $out);
        $this->assertStringNotContainsString('{display_name}', $out);
    }
}
