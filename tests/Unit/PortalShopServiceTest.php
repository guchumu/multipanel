<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalShopService;
use Tests\TestCase;

final class PortalShopServiceTest extends TestCase
{
    public function testPackOnlyIsThePresetPrice(): void
    {
        $priced = PortalShopService::priceExtras(40.0, 3, 1, 0, 50.0, 4.0);

        $this->assertSame(0, $priced['extra_users']);
        $this->assertSame(0.0, $priced['extra_users_price']);
        $this->assertSame(0.0, $priced['extra_streams_price']);
        $this->assertSame(40.0, $priced['total']);
    }

    public function testExtraAccountIsFlatForTheChosenPeriod(): void
    {
        $priced = PortalShopService::priceExtras(70.0, 6, 3, 0, 50.0, 4.0);

        $this->assertSame(2, $priced['extra_users']);
        $this->assertSame(100.0, $priced['extra_users_price']);
        $this->assertSame(170.0, $priced['total']);
    }

    public function testExtraStreamsMultiplyByMonthlyPriceAndPresetMonths(): void
    {
        // 1 cuenta con 4 visionados = 2 extra; 3 meses × 4 € = 24 €
        $priced = PortalShopService::priceExtras(40.0, 3, 1, 2, 50.0, 4.0);

        $this->assertSame(0, $priced['extra_users']);
        $this->assertSame(24.0, $priced['extra_streams_price']);
        $this->assertSame(64.0, $priced['total']);
    }

    public function testCombinedExtraAccountsAndStreams(): void
    {
        // pack 15 + 1 cuenta extra 50 + 1 visionado extra × 4 € × 1 mes
        $priced = PortalShopService::priceExtras(15.0, 1, 2, 1, 50.0, 4.0);

        $this->assertSame(1, $priced['extra_users']);
        $this->assertSame(50.0, $priced['extra_users_price']);
        $this->assertSame(4.0, $priced['extra_streams_price']);
        $this->assertSame(69.0, $priced['total']);
    }

    public function testResolveShopServerPrefersRequestedThenBuyer(): void
    {
        $servers = [
            ['id' => 10, 'type' => 'plex', 'name' => 'Plex', 'label' => 'Plex'],
            ['id' => 20, 'type' => 'jellyfin', 'name' => 'JF', 'label' => 'Jellyfin'],
        ];
        $shop = new PortalShopService();

        $this->assertSame(10, $shop->resolveShopServerId($servers, 20, 10));
        $this->assertSame(10, $shop->resolveShopServerId($servers, 0, 10));
        $this->assertSame(10, $shop->resolveShopServerId($servers, 99, 0));
        $this->assertNull($shop->resolveShopServerId([], 1, 1));
    }
}
