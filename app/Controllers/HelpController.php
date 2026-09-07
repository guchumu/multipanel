<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PlaybackStopMessageService;
use App\Services\StreamLimitSettingsService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Guías internas para el equipo (atención al cliente, etc.).
 */
final class HelpController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
    ) {
    }

    public function atencionCliente(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $stream = new StreamLimitSettingsService();
        $stop = new PlaybackStopMessageService();

        return $this->view('help.atencion_cliente', [
            'title' => 'Atención al cliente',
            'user' => $this->auth->user(),
            'msgConfigTitle' => PlaybackStopMessageService::DEFAULT_TITLE,
            'msgConfigBody' => $stop->defaultBody($tenantId),
            'msgKillGeneric' => $stream->getKillMessage($tenantId),
            'msgKillHome' => $stream->getKillMessageHome($tenantId),
            'msgKillAway' => $stream->getKillMessageAway($tenantId),
            'maxHome' => $stream->getDefaultMaxStreams($tenantId),
            'maxAway' => $stream->getDefaultMaxAwayStreams($tenantId),
        ]);
    }
}
