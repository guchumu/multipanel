<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\GraphQL;
use Tests\TestCase;

final class GraphQLTest extends TestCase
{
    public function testHealthQuery(): void
    {
        $gql = new GraphQL();
        $gql->query('health', fn () => ['status' => 'ok', 'version' => '1.0.0']);

        $result = $gql->execute(['query' => 'query health { health }']);

        $this->assertArrayNotHasKey('errors', $result);
        $this->assertSame('ok', $result['data']['health']['status']);
    }

    public function testUnknownFieldReturnsError(): void
    {
        $gql = new GraphQL();
        $result = $gql->execute(['query' => 'query unknown { nonexistent }']);

        $this->assertArrayHasKey('errors', $result);
    }
}
