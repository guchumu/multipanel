<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Request;
use ReflectionClass;
use Tests\TestCase;

final class RequestTest extends TestCase
{
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = $this->makeRequestWithHeaders([
            'X-Csrf-Token' => 'abc',
        ]);

        $this->assertSame('abc', $request->header('X-CSRF-TOKEN'));
        $this->assertSame('abc', $request->header('X-Csrf-Token'));
        $this->assertSame('abc', $request->header('x-csrf-token'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequestWithHeaders(array $headers): Request
    {
        $ref = new ReflectionClass(Request::class);
        /** @var Request $request */
        $request = $ref->newInstanceWithoutConstructor();

        $props = [
            'method' => 'GET',
            'uri' => '/',
            'query' => [],
            'post' => [],
            'server' => [],
            'headers' => $headers,
            'body' => '',
            'files' => [],
            'cookies' => [],
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($request, $value);
        }

        return $request;
    }
}
