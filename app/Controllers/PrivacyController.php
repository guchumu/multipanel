<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\GdprService;
use Core\Controller;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * GDPR privacy and data management controller.
 */
class PrivacyController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private GdprService $gdpr = new GdprService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);

        return $this->view('privacy.index', [
            'title' => 'Privacidad / GDPR',
            'requests' => $this->gdpr->listRequests($tenantId),
        ]);
    }

    public function export(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $userId = (int) $request->input('user_id', $this->auth->user()->id);
        $mediaUserId = $request->input('media_user_id') ? (int) $request->input('media_user_id') : null;

        $this->gdpr->requestExport($tenantId, $userId, $mediaUserId);
        $this->gdpr->processPending(1);

        Session::getInstance()->flash('success', 'Solicitud de exportación procesada.');
        return $this->redirect('/privacy');
    }

    public function delete(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $confirm = $request->input('confirm');

        if ($confirm !== 'DELETE') {
            Session::getInstance()->flash('error', 'Escribe DELETE para confirmar.');
            return $this->redirect('/privacy');
        }

        $mediaUserId = $request->input('media_user_id') ? (int) $request->input('media_user_id') : null;
        $userId = $mediaUserId ? null : (int) $request->input('user_id', $this->auth->user()->id);

        $this->gdpr->requestDeletion($tenantId, $userId, $mediaUserId);
        $this->gdpr->processPending(1);

        Session::getInstance()->flash('success', 'Solicitud de eliminación procesada.');
        return $this->redirect('/privacy');
    }

    public function download(Request $request, int $id): Response
    {
        $req = \Core\Database::getInstance()->fetchOne('SELECT * FROM gdpr_requests WHERE id = ? AND type = ?', [$id, 'export']);
        if (!$req || empty($req['file_path']) || !file_exists($req['file_path'])) {
            Session::getInstance()->flash('error', 'Exportación no disponible.');
            return $this->redirect('/privacy');
        }

        return \Core\Response::download($req['file_path'], basename($req['file_path']));
    }
}
