<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ClientStopGuidanceService;
use App\Services\PlaybackStopMessageService;
use App\Services\StreamLimitSettingsService;
use App\Services\TelegramSandboxSender;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * CRUD for predefined En directo stop/pause messages.
 */
class PlaybackStopMessageController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private PlaybackStopMessageService $messages = new PlaybackStopMessageService(),
        private StreamLimitSettingsService $streamSettings = new StreamLimitSettingsService(),
        private TelegramSandboxSender $sandboxSender = new TelegramSandboxSender(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('settings.stop_messages', [
            'title' => 'Mensajes al detener',
            'messages' => $this->messages->listForTenant($tenantId),
            'videoTranscodeMessage' => $this->streamSettings->getStoredKillMessageVideoTranscode($tenantId),
            'videoTranscodeDefault' => ClientStopGuidanceService::forSalvableTranscode(),
            'effectiveVideoTranscodeMessage' => $this->streamSettings->getKillMessageVideoTranscode($tenantId),
        ]);
    }

    public function updateVideoTranscodeMessage(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $body = trim((string) $request->input('kill_message_video_transcode', ''));
        if (mb_strlen($body) > 500) {
            Session::getInstance()->flash('error', 'El mensaje de Transcode no puede superar 500 caracteres.');

            return $this->redirect('/settings/stop-messages');
        }

        $this->streamSettings->setKillMessageVideoTranscode($tenantId, $body !== '' ? $body : null);
        Session::getInstance()->flash(
            'success',
            $body !== ''
                ? 'Mensaje de auto-corte Transcode guardado.'
                : 'Mensaje de Transcode restaurado al texto por defecto.'
        );

        return $this->redirect('/settings/stop-messages');
    }

    public function testVideoTranscodeMessage(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $body = $this->streamSettings->getKillMessageVideoTranscode($tenantId);
        $text = "MultiPanel — prueba mensaje Transcode\n\n"
            . $body
            . "\n\n[PRUEBA · no se envía al reproductor]";

        $result = $this->sandboxSender->sendToSandbox($tenantId, $text);
        Session::getInstance()->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/settings/stop-messages');
    }

    public function store(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        try {
            $this->messages->create(
                $tenantId,
                (string) $request->input('title', ''),
                (string) $request->input('body', ''),
                (bool) $request->input('is_default'),
            );
            Session::getInstance()->flash('success', 'Mensaje predefinido creado.');
        } catch (\InvalidArgumentException $e) {
            Session::getInstance()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            Session::getInstance()->flash('error', 'No se pudo crear el mensaje.');
        }

        return $this->redirect('/settings/stop-messages');
    }

    public function update(Request $request, int|string $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $id = (int) $id;
        $setDefault = $request->input('is_default') !== null;

        try {
            $ok = $this->messages->update(
                $tenantId,
                $id,
                (string) $request->input('title', ''),
                (string) $request->input('body', ''),
                $setDefault ? true : null,
            );
            Session::getInstance()->flash(
                $ok ? 'success' : 'error',
                $ok ? 'Mensaje actualizado.' : 'Mensaje no encontrado.'
            );
        } catch (\InvalidArgumentException $e) {
            Session::getInstance()->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            Session::getInstance()->flash('error', 'No se pudo actualizar el mensaje.');
        }

        return $this->redirect('/settings/stop-messages');
    }

    public function setDefault(Request $request, int|string $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $id = (int) $id;
        $ok = $this->messages->setDefault($tenantId, $id);

        Session::getInstance()->flash(
            $ok ? 'success' : 'error',
            $ok ? 'Mensaje marcado como predeterminado.' : 'Mensaje no encontrado.'
        );

        return $this->redirect('/settings/stop-messages');
    }

    public function destroy(Request $request, int|string $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $id = (int) $id;
        $ok = $this->messages->delete($tenantId, $id);

        Session::getInstance()->flash(
            $ok ? 'success' : 'error',
            $ok ? 'Mensaje eliminado.' : 'Mensaje no encontrado.'
        );

        return $this->redirect('/settings/stop-messages');
    }

    /**
     * Envía el mensaje al detener al Sandbox Chat ID (siempre sandbox) para previsualizar el texto.
     */
    public function test(Request $request, int|string $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $id = (int) $id;
        $msg = $this->messages->findForTenant($tenantId, $id);

        if ($msg === null) {
            Session::getInstance()->flash('error', 'Mensaje no encontrado.');
            return $this->redirect('/settings/stop-messages');
        }

        $title = trim((string) ($msg['title'] ?? ''));
        $body = trim((string) ($msg['body'] ?? ''));
        $text = "MultiPanel — prueba mensaje al detener\n\n"
            . ($title !== '' ? "Título: {$title}\n\n" : '')
            . $body
            . "\n\n[PRUEBA · no se envía al reproductor]";

        $result = $this->sandboxSender->sendToSandbox($tenantId, $text);
        Session::getInstance()->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/settings/stop-messages');
    }
}
