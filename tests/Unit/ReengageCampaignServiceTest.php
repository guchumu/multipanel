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
        $cfg = ['trial_days' => 3, 'discount_percent' => 15, 'link_ttl_days' => 365];
        $out = $svc->render(
            'Hola {display_name}, Plex {trial_days}d, -{discount_percent}%, {link_years} año. {portal_url}',
            $user,
            $cfg,
            'NucBox',
            'https://ejemplo.test/u/abc123xyzABCDEFG'
        );

        $this->assertStringContainsString('Ana López', $out);
        $this->assertStringContainsString('3d', $out);
        $this->assertStringContainsString('-15%', $out);
        $this->assertStringContainsString('1 año', $out);
        $this->assertStringContainsString('/u/abc123xyzABCDEFG', $out);
        $this->assertStringNotContainsString('{display_name}', $out);
    }

    public function testTemplateForReturnsInviteByStep(): void
    {
        $svc = new ReengageCampaignService();
        $cfg = [
            'invites' => [
                ['title' => 'Uno', 'body' => 'cuerpo 1'],
                ['title' => 'Dos', 'body' => 'cuerpo 2'],
                ['title' => 'Tres', 'body' => 'cuerpo 3'],
                ['title' => 'Cuatro', 'body' => 'cuerpo 4'],
            ],
            'trial_title' => 'Prueba',
            'trial_body' => 'cuerpo prueba',
        ];

        $one = $svc->templateFor($cfg, 'invite', 1);
        $two = $svc->templateFor($cfg, 'invite', 2);
        $trial = $svc->templateFor($cfg, 'trial');

        $this->assertSame('Uno', $one['title'] ?? null);
        $this->assertSame(1, $one['step'] ?? null);
        $this->assertSame('Dos', $two['title'] ?? null);
        $this->assertSame('Prueba', $trial['title'] ?? null);
    }
}
