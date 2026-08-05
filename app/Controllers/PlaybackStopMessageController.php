<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\PlaybackStopMessageService;
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
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('settings.stop_messages', [
            'title' => 'Mensajes al detener',
            'messages' => $this->messages->listForTenant($tenantId),
        ]);
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

    public function update(Request $request, int $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
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

    public function setDefault(Request $request, int $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $ok = $this->messages->setDefault($tenantId, $id);

        Session::getInstance()->flash(
            $ok ? 'success' : 'error',
            $ok ? 'Mensaje marcado como predeterminado.' : 'Mensaje no encontrado.'
        );

        return $this->redirect('/settings/stop-messages');
    }

    public function destroy(Request $request, int $id): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $ok = $this->messages->delete($tenantId, $id);

        Session::getInstance()->flash(
            $ok ? 'success' : 'error',
            $ok ? 'Mensaje eliminado.' : 'Mensaje no encontrado.'
        );

        return $this->redirect('/settings/stop-messages');
    }
}
