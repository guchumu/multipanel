<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TranscodeActionLinkService;
use App\Services\VideoTranscodeAutoKillToggleService;
use App\Services\VideoTranscodePauseService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Enlace público corto: GET /t/{code} (pausa o auto-corte Transcode).
 */
final class TranscodeActionLinkController extends Controller
{
    public function __construct(
        private TranscodeActionLinkService $links = new TranscodeActionLinkService(),
        private VideoTranscodePauseService $pauses = new VideoTranscodePauseService(),
        private VideoTranscodeAutoKillToggleService $toggles = new VideoTranscodeAutoKillToggleService(),
    ) {
    }

    public function run(Request $request, string $code): Response
    {
        $result = $this->links->consume($code);
        if (empty($result['ok'])) {
            $error = trim((string) ($result['error'] ?? ''));

            return Response::html($this->page(
                'Enlace no disponible',
                $error !== '' ? $error : 'Este enlace no es válido, ya se usó o ha caducado.',
                false
            ), 404);
        }

        $kind = (string) ($result['kind'] ?? '');
        if ($kind === TranscodeActionLinkService::KIND_PAUSE) {
            $duration = (string) ($result['duration'] ?? '');
            $untilLabel = (string) ($result['until_label'] ?? '');
            $username = trim((string) ($result['username'] ?? ''));
            $who = $username !== '' ? ' para «' . $username . '»' : '';

            return Response::html($this->page(
                'Pausa aplicada',
                'Auto-corte de vídeo Transcode pausado' . $who . ' ('
                . $this->pauses->durationLabel($duration)
                . '). Vigente hasta ' . $untilLabel . '.',
                true
            ), 200);
        }

        if ($kind === TranscodeActionLinkService::KIND_TOGGLE) {
            $enabled = !empty($result['enabled']);
            $state = $this->toggles->stateLabel($enabled);

            return Response::html($this->page(
                'Auto-corte actualizado',
                'Auto-corte de vídeo Transcode: ' . $state . ' ('
                . ($enabled ? 'activado' : 'desactivado') . ').',
                true
            ), 200);
        }

        return Response::html($this->page(
            'Enlace no disponible',
            'Este enlace no es válido.',
            false
        ), 404);
    }

    private function page(string $title, string $body, bool $success): string
    {
        $color = $success ? '#198754' : '#6c757d';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#16324f;">'
            . '<h1 style="font-size:1.35rem;color:' . $color . ';">'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p style="color:#6c757d;font-size:0.9rem;">Solo afecta al auto-corte de vídeo Transcode; no a límites de streams.</p>'
            . '</body></html>';
    }
}
