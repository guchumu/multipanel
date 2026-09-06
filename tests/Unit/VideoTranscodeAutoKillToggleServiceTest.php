<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\VideoTranscodeAutoKillToggleService;
use PHPUnit\Framework\TestCase;

final class VideoTranscodeAutoKillToggleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_KEY=test-secret-key-for-unit-tests');
        $_ENV['APP_KEY'] = 'test-secret-key-for-unit-tests';
    }

    public function testCreateTokenRejectsInvalidPayload(): void
    {
        $svc = new VideoTranscodeAutoKillToggleService();
        $this->assertNull($svc->createToken(['tenant_id' => 0, 'action' => 'enable']));
        $this->assertNull($svc->createToken(['tenant_id' => 1, 'action' => 'pause']));
    }

    public function testCreateTokenShapeAndRejectsTamperedSignature(): void
    {
        $svc = new VideoTranscodeAutoKillToggleService();
        $token = $svc->createToken([
            'tenant_id' => 3,
            'action' => VideoTranscodeAutoKillToggleService::ACTION_DISABLE,
        ]);
        $this->assertNotNull($token);
        $this->assertStringContainsString('.', (string) $token);

        $tampered = preg_replace('/.$/', 'x', (string) $token) ?? (string) $token;
        $result = $svc->consumeToken($tampered);
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', (string) ($result['error'] ?? ''));
    }

    public function testConsumeTokenRejectsGarbage(): void
    {
        $svc = new VideoTranscodeAutoKillToggleService();
        $this->assertFalse($svc->consumeToken('')['ok']);
        $this->assertFalse($svc->consumeToken('sin-punto')['ok']);
        $this->assertFalse($svc->consumeToken('abc.def')['ok']);
    }

    public function testLabels(): void
    {
        $svc = new VideoTranscodeAutoKillToggleService();
        $this->assertSame('ON', $svc->stateLabel(true));
        $this->assertSame('OFF', $svc->stateLabel(false));
        $this->assertSame('Activar auto-corte', $svc->ntfyActionLabel(VideoTranscodeAutoKillToggleService::ACTION_ENABLE));
        $this->assertSame('Apagar auto-corte', $svc->ntfyActionLabel(VideoTranscodeAutoKillToggleService::ACTION_DISABLE));
    }

    public function testBuildToggleUrlsNeedsPublicBase(): void
    {
        $prev = $_ENV['APP_URL'] ?? null;
        putenv('APP_URL=');
        $_ENV['APP_URL'] = '';
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST']);

        $svc = new VideoTranscodeAutoKillToggleService();
        // Sin APP_URL ni host: vacío (no inventar localhost en ntfy).
        $urls = $svc->buildToggleUrls(1);
        $this->assertSame([], $urls);

        if ($prev === null) {
            putenv('APP_URL');
            unset($_ENV['APP_URL']);
        } else {
            putenv('APP_URL=' . $prev);
            $_ENV['APP_URL'] = $prev;
        }
    }
}
