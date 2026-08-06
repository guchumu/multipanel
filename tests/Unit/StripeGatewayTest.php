<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\StripeGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

final class StripeGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['APP_URL'] = 'https://panel.example.com';
        putenv('APP_URL=https://panel.example.com');
        // Reset config() static cache is not possible; APP_URL is read via env/config at runtime in gateway.
        $_SERVER['HTTP_HOST'] = 'panel.example.com';
        $_SERVER['HTTPS'] = 'on';
    }

    public function testRejectsPublishableKeyAsSecret(): void
    {
        $gateway = new StripeGateway('pk_test_fakePublishableKey123', '', $this->unusedClient());
        $result = $gateway->createCheckoutSession(10.0, 'EUR');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('clave pública', $result['error']);
    }

    public function testRejectsEmptySecret(): void
    {
        putenv('STRIPE_SECRET_KEY=');
        $_ENV['STRIPE_SECRET_KEY'] = '';

        $gateway = new StripeGateway('', '', $this->unusedClient());
        $result = $gateway->createCheckoutSession(10.0, 'EUR');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('secreta', strtolower($result['error']));
    }

    public function testRejectsLiveKeyWithHttpUrl(): void
    {
        $_ENV['APP_URL'] = 'http://panel.example.com';
        putenv('APP_URL=http://panel.example.com');
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);

        $gateway = new StripeGateway('sk_live_' . str_repeat('a', 24), '', $this->unusedClient());
        $result = $gateway->createCheckoutSession(10.0, 'EUR');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('HTTPS', $result['error']);
    }

    public function testSurfacesStripeApiErrorMessage(): void
    {
        $mock = new MockHandler([
            new Response(401, [], json_encode([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid API Key provided: sk_test_xxx',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => true]);

        $gateway = new StripeGateway('sk_test_' . str_repeat('b', 24), '', $client);
        $result = $gateway->createCheckoutSession(9.99, 'EUR', ['plan_name' => 'Digital services']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Clave secreta de Stripe inválida', $result['error']);
    }

    public function testCreatesCheckoutSessionUrl(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock), 'http_errors' => true]);

        $gateway = new StripeGateway('sk_test_' . str_repeat('c', 24), '', $client);
        $result = $gateway->createCheckoutSession(15.0, 'EUR', [
            'plan_name' => 'Digital services',
            'subscription_id' => 42,
        ]);

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $result['checkout_url']);
        $this->assertSame('cs_test_123', $result['session_id']);
    }

    private function unusedClient(): Client
    {
        $mock = new MockHandler([
            new Response(500, [], '{"error":{"message":"should not be called"}}'),
        ]);

        return new Client(['handler' => HandlerStack::create($mock), 'http_errors' => true]);
    }
}
