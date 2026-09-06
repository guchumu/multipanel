<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\VideoTranscodePauseService;
use Core\Controller;
use Core\Request;
use Core\Response;

/**
 * Enlace público firmado: pausa temporal del auto-corte de vídeo Transcode.
 */
final class VideoTranscodePauseController extends Controller
{
    public function __construct(
        private VideoTranscodePauseService $pauses = new VideoTranscodePauseService(),
    ) {
    }

    public function pause(Request $request, string $token): Response
    {
        $result = $this->pauses->consumeToken($token);
        if (empty($result['ok'])) {
            $error = trim((string) ($result['error'] ?? ''));

            return Response::html($this->page(
                'Enlace no disponible',
                $error !== '' ? $error : 'Este enlace no es válido o ha caducado.',
                false
            ), 404);
        }

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
