<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Import\ServicioServerMapper;
use PHPUnit\Framework\TestCase;

final class ServicioServerMapperTest extends TestCase
{
    public function test_allowed_codes_include_server10_and_nucbox(): void
    {
        $this->assertTrue(ServicioServerMapper::isAllowed(1));
        $this->assertTrue(ServicioServerMapper::isAllowed(5));
        $this->assertFalse(ServicioServerMapper::isAllowed(2));
        $this->assertFalse(ServicioServerMapper::isAllowed(null));
    }

    public function test_servicio_from_server_name(): void
    {
        $this->assertSame(1, ServicioServerMapper::servicioFromServerName('Server10'));
        $this->assertSame(1, ServicioServerMapper::servicioFromServerName('server10'));
        $this->assertSame(1, ServicioServerMapper::servicioFromServerName('Plex Server 10 Principal'));
        $this->assertSame(5, ServicioServerMapper::servicioFromServerName('Nucbox'));
        $this->assertSame(5, ServicioServerMapper::servicioFromServerName('NucBox HD'));
        $this->assertNull(ServicioServerMapper::servicioFromServerName('IPTV Mix'));
        $this->assertNull(ServicioServerMapper::servicioFromServerName('Servitron'));
    }

    public function test_resolve_row_prefers_servicio_column(): void
    {
        $code = ServicioServerMapper::resolveRowServicio(
            ['email' => 'a@test.com', 'servicio' => '5', 'server_id' => 1],
            ['a@test.com' => 1],
            [1 => 'Server10']
        );
        $this->assertSame(5, $code);
    }

    public function test_resolve_row_falls_back_to_payments_then_server_name(): void
    {
        $fromPayment = ServicioServerMapper::resolveRowServicio(
            ['email' => 'a@test.com', 'server_id' => 9],
            ['a@test.com' => 1],
            [9 => 'Other']
        );
        $this->assertSame(1, $fromPayment);

        $fromName = ServicioServerMapper::resolveRowServicio(
            ['email' => 'b@test.com', 'server_id' => 2],
            [],
            [2 => 'Server10']
        );
        $this->assertSame(1, $fromName);
    }

    public function test_payment_servicio_by_email_from_sql(): void
    {
        $sql = <<<'SQL'
INSERT INTO `payments_history` (`id`, `client_id`, `telegram_id`, `email`, `amount`, `payment_type`, `months_added`, `service`, `date`) VALUES
(1, 1, 1, 'one@test.com', 10.00, 'stripe', 1, '1', '2025-01-01 00:00:00'),
(2, 1, 1, 'one@test.com', 10.00, 'stripe', 1, '5', '2025-02-01 00:00:00'),
(3, 2, 2, 'two@test.com', 5.00, 'cash', 1, '2', '2025-01-01 00:00:00');
SQL;
        $map = ServicioServerMapper::paymentServicioByEmail($sql);
        $this->assertSame(5, $map['one@test.com']);
        $this->assertSame(2, $map['two@test.com']);
    }
}
