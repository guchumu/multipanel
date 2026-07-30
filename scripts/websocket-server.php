#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lightweight WebSocket server for MultiPanel realtime events.
 *
 * Usage: php scripts/websocket-server.php [port]
 * Clients connect: ws://localhost:8081?channel=dashboard
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/core/helpers.php';

$port = (int) ($argv[1] ?? env('WEBSOCKET_PORT', 8081));
$address = "0.0.0.0:{$port}";

$server = stream_socket_server("tcp://{$address}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed: {$errstr}\n");
    exit(1);
}

echo "[MultiPanel WS] Listening on {$address}\n";

/** @var array<int, array{socket: resource, channel: string, last: float}> */
$clients = [];

function wsHandshake(string $headers): ?string
{
    if (!preg_match('/Sec-WebSocket-Key: (.+)/i', $headers, $m)) {
        return null;
    }
    $key = trim($m[1]);
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
}

function wsEncode(string $msg): string
{
    $len = strlen($msg);
    return chr(129) . ($len < 126 ? chr($len) : chr(126) . pack('n', $len)) . $msg;
}

while (true) {
    $read = array_merge([$server], array_column($clients, 'socket'));
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) === false) {
        continue;
    }

    if (in_array($server, $read, true)) {
        $conn = @stream_socket_accept($server, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $clients[(int) $conn] = ['socket' => $conn, 'channel' => 'dashboard', 'last' => microtime(true), 'handshake' => false, 'buffer' => ''];
        }
    }

    foreach ($clients as $id => &$client) {
        $data = @fread($client['socket'], 8192);
        if ($data === false || $data === '') {
            if (feof($client['socket'])) {
                fclose($client['socket']);
                unset($clients[$id]);
            }
            continue;
        }

        if (!$client['handshake']) {
            $client['buffer'] .= $data;
            if (str_contains($client['buffer'], "\r\n\r\n")) {
                $response = wsHandshake($client['buffer']);
                if ($response) {
                    fwrite($client['socket'], $response);
                    $client['handshake'] = true;
                    if (preg_match('/GET \/\?channel=([a-z0-9_-]+)/i', $client['buffer'], $cm)) {
                        $client['channel'] = $cm[1];
                    }
                } else {
                    fclose($client['socket']);
                    unset($clients[$id]);
                }
            }
            continue;
        }
    }
    unset($client);

    foreach ($clients as $id => $client) {
        if (!$client['handshake']) {
            continue;
        }
        $events = \Core\RealtimeBroker::consume($client['channel'], $client['last']);
        foreach ($events as $event) {
            @fwrite($client['socket'], wsEncode(json_encode($event)));
            $clients[$id]['last'] = (float) ($event['at'] ?? microtime(true));
        }
    }
}
