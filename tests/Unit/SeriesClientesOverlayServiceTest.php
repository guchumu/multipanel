<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SeriesClientesOverlayService;
use PHPUnit\Framework\TestCase;

final class SeriesClientesOverlayServiceTest extends TestCase
{
    public function test_aggregate_profiles_takes_max_fechafinal_among_plex_servicios(): void
    {
        $service = new SeriesClientesOverlayService();
        $profiles = $service->aggregateProfiles([
            [
                'id' => 1,
                'idusuariotelegram' => '6461429423',
                'email1' => 'parraandy18@gmail.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2024-01-13',
                'fechafinal' => '2024-01-20',
                'notas' => '',
                'notas2' => '',
                'servicio' => 1,
            ],
            [
                'id' => 2,
                'idusuariotelegram' => '6461429423',
                'email1' => 'parraandy18@gmail.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2025-05-11',
                'fechafinal' => '2025-05-18',
                'notas' => '',
                'notas2' => '',
                'servicio' => 1,
            ],
            // IPTV: fecha más reciente pero NO debe ganar como expires Plex
            [
                'id' => 3,
                'idusuariotelegram' => '6461429423',
                'email1' => 'parraandy18@gmail.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2025-09-21',
                'fechafinal' => '2026-12-31',
                'notas' => "I-P-T-V m-3-u Access:\nhttps://example.com/get.php?username=U&password=P&type=m3u_plus",
                'notas2' => '',
                'servicio' => 4,
            ],
        ]);

        $this->assertCount(1, $profiles);
        $p = $profiles[0];
        $this->assertTrue($p['has_plex']);
        $this->assertSame('6461429423', $p['telegram']);
        $this->assertSame('2025-05-18 23:59:59', $p['expires_at']);
        $this->assertSame('parraandy18@gmail.com', $p['email_primary']);
        $this->assertNull($p['notes']); // credenciales IPTV no se copian desde fila plex vacía
        $this->assertSame(2, $p['plex_row_count']);
        $this->assertSame(1, $p['iptv_row_count']);
    }

    public function test_iptv_only_profile_keeps_telegram_without_expires(): void
    {
        $service = new SeriesClientesOverlayService();
        $profiles = $service->aggregateProfiles([
            [
                'id' => 10,
                'idusuariotelegram' => '7074195553',
                'email1' => 'solo-iptv@example.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2025-01-01',
                'fechafinal' => '2026-01-01',
                'notas' => '',
                'notas2' => '',
                'servicio' => 2,
            ],
        ]);

        $this->assertCount(1, $profiles);
        $this->assertFalse($profiles[0]['has_plex']);
        $this->assertSame('7074195553', $profiles[0]['telegram']);
        $this->assertNull($profiles[0]['expires_at']);
    }

    public function test_servicio_5_counts_as_plex(): void
    {
        $service = new SeriesClientesOverlayService();
        $profiles = $service->aggregateProfiles([
            [
                'id' => 5,
                'idusuariotelegram' => '1111122222',
                'email1' => 'nuc@example.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2026-01-01',
                'fechafinal' => '2026-06-01',
                'notas' => 'cliente ok',
                'notas2' => '',
                'servicio' => 5,
            ],
        ]);

        $this->assertTrue($profiles[0]['has_plex']);
        $this->assertSame('2026-06-01 23:59:59', $profiles[0]['expires_at']);
        $this->assertSame('cliente ok', $profiles[0]['notes']);
    }

    public function test_sanitize_notes_strips_iptv_credentials(): void
    {
        $service = new SeriesClientesOverlayService();
        $dirty = "Nota admin\nI-P-T-V m-3-u Access:\nhttps://host/get.php?username=U&password=SECRET&type=m3u_plus\nUsername: U\nPassword: SECRET";
        $clean = $service->sanitizeNotes($dirty, '');
        $this->assertSame('Nota admin', $clean);
        $this->assertStringNotContainsString('SECRET', (string) $clean);
        $this->assertStringNotContainsString('get.php', (string) $clean);
    }

    public function test_sanitize_notes_keeps_plain_text(): void
    {
        $service = new SeriesClientesOverlayService();
        $this->assertSame('Pagar bizum', $service->sanitizeNotes('Pagar bizum', null));
        $this->assertNull($service->sanitizeNotes('   ', ''));
    }

    public function test_merge_profiles_by_shared_email(): void
    {
        $service = new SeriesClientesOverlayService();
        $profiles = $service->aggregateProfiles([
            [
                'id' => 1,
                'idusuariotelegram' => '1000000001',
                'email1' => 'a@example.com',
                'email2' => 'shared@example.com',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2025-01-01',
                'fechafinal' => '2025-02-01',
                'notas' => '',
                'notas2' => '',
                'servicio' => 1,
            ],
            [
                'id' => 2,
                'idusuariotelegram' => '1000000002',
                'email1' => 'shared@example.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2025-03-01',
                'fechafinal' => '2025-04-01',
                'notas' => '',
                'notas2' => '',
                'servicio' => 1,
            ],
        ]);

        $this->assertCount(1, $profiles);
        $this->assertSame('2025-04-01 23:59:59', $profiles[0]['expires_at']);
        $this->assertContains('a@example.com', $profiles[0]['emails']);
        $this->assertContains('shared@example.com', $profiles[0]['emails']);
    }

    public function test_ignores_chatid_style_zero_telegram(): void
    {
        $service = new SeriesClientesOverlayService();
        $profiles = $service->aggregateProfiles([
            [
                'id' => 1,
                'idusuariotelegram' => '0',
                'email1' => 'x@example.com',
                'email2' => '',
                'email3' => '',
                'email4' => '',
                'fechapago' => '2025-01-01',
                'fechafinal' => '2025-02-01',
                'notas' => '',
                'notas2' => '',
                'servicio' => 1,
            ],
        ]);

        $this->assertNull($profiles[0]['telegram']);
        $this->assertSame('2025-02-01 23:59:59', $profiles[0]['expires_at']);
    }
}
