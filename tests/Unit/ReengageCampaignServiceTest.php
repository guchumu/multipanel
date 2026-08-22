<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MediaUser;
use App\Services\ReengageCampaignService;
use Tests\TestCase;

final class ReengageCampaignServiceTest extends TestCase
{
    public function testRenderReplacesOfferPlaceholders(): void
    {
        $svc = new ReengageCampaignService();
        $user = new MediaUser([
            'username' => 'ana',
            'display_name' => 'Ana López',
            'email' => 'ana@ejemplo.com',
            'expires_at' => '2026-08-01',
        ]);
        $cfg = ['trial_days' => 3, 'discount_percent' => 15, 'link_ttl_days' => 365];
        $offer = [
            'year_price' => '70',
            'discounted_price' => '59,50',
            'renew_label' => '1 año',
            'payment_url' => 'https://ejemplo.test/p/abc123',
            'portal_url' => 'https://ejemplo.test/u/xyz',
        ];
        $out = $svc->render(
            'Hola {display_name}, {renew_label} {discounted_price}€ (antes {year_price}€). Paga: {payment_url}',
            $user,
            $cfg,
            'NucBox',
            $offer
        );

        $this->assertStringContainsString('Ana López', $out);
        $this->assertStringContainsString('1 año', $out);
        $this->assertStringContainsString('59,50', $out);
        $this->assertStringContainsString('/p/abc123', $out);
        $this->assertStringNotContainsString('{payment_url}', $out);
    }

    public function testApplyDiscountAmount(): void
    {
        $svc = new ReengageCampaignService();
        $this->assertSame(59.5, $svc->applyDiscountAmount(70.0, 15));
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
