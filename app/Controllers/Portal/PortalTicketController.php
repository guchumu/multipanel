<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Services\PortalAuthService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Ramsey\Uuid\Uuid;

/**
 * Portal ticket controller for clients.
 */
class PortalTicketController extends Controller
{
    public function __construct(
        private PortalAuthService $auth = new PortalAuthService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $tickets = Database::getInstance()->fetchAll(
            "SELECT t.* FROM tickets t
             JOIN customers c ON c.id = t.customer_id
             WHERE c.media_user_id = ? ORDER BY t.created_at DESC",
            [$user->id]
        );

        return $this->view('portal.tickets.index', [
            'title' => 'Mis tickets',
            'portalUser' => $user,
            'tickets' => $tickets,
            'navActive' => 'tickets',
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('portal.tickets.create', [
            'title' => 'Nuevo ticket',
            'portalUser' => $this->auth->user(),
            'navActive' => 'tickets',
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        $data = $this->validate($request, [
            'subject' => 'required|max:255',
            'message' => 'required',
        ]);

        $db = Database::getInstance();
        $customer = $db->fetchOne('SELECT id FROM customers WHERE media_user_id = ? LIMIT 1', [$user->id]);

        if (!$customer) {
            $customerId = $db->insert('customers', [
                'tenant_id' => $user->tenant_id ?? 1,
                'uuid' => Uuid::uuid4()->toString(),
                'media_user_id' => $user->id,
                'email' => $user->email ?? $user->username . '@portal.local',
                'status' => 'active',
            ]);
        } else {
            $customerId = (int) $customer['id'];
        }

        $ticketId = $db->insert('tickets', [
            'tenant_id' => $user->tenant_id ?? 1,
            'uuid' => Uuid::uuid4()->toString(),
            'customer_id' => $customerId,
            'subject' => $data['subject'],
            'status' => 'open',
            'priority' => $request->input('priority') ?? 'medium',
            'category' => $request->input('category') ?? 'general',
        ]);

        $db->insert('ticket_messages', [
            'ticket_id' => $ticketId,
            'customer_id' => $customerId,
            'message' => $data['message'],
        ]);

        Session::getInstance()->flash('success', 'Ticket enviado. Te responderemos pronto.');
        return $this->redirect('/portal/tickets');
    }

    public function show(Request $request, string $uuid): Response
    {
        $user = $this->auth->user();
        $ticket = Database::getInstance()->fetchOne(
            "SELECT t.* FROM tickets t
             JOIN customers c ON c.id = t.customer_id
             WHERE t.uuid = ? AND c.media_user_id = ?",
            [$uuid, $user->id]
        );

        if (!$ticket) {
            return $this->redirect('/portal/tickets');
        }

        $messages = Database::getInstance()->fetchAll(
            "SELECT tm.*, u.username FROM ticket_messages tm
             LEFT JOIN users u ON u.id = tm.user_id
             WHERE tm.ticket_id = ? AND tm.is_internal = 0 ORDER BY tm.created_at",
            [$ticket['id']]
        );

        return $this->view('portal.tickets.show', [
            'title' => $ticket['subject'],
            'portalUser' => $user,
            'ticket' => $ticket,
            'messages' => $messages,
            'navActive' => 'tickets',
        ]);
    }

    public function reply(Request $request, string $uuid): Response
    {
        $user = $this->auth->user();
        $db = Database::getInstance();

        $ticket = $db->fetchOne(
            "SELECT t.*, c.id AS customer_row_id FROM tickets t
             JOIN customers c ON c.id = t.customer_id
             WHERE t.uuid = ? AND c.media_user_id = ?",
            [$uuid, $user->id]
        );

        if (!$ticket) {
            return $this->redirect('/portal/tickets');
        }

        if (in_array((string) ($ticket['status'] ?? ''), ['closed', 'resolved'], true)) {
            Session::getInstance()->flash('error', 'Este ticket está cerrado. Abre uno nuevo si lo necesitas.');
            return $this->redirect('/portal/tickets/' . $uuid);
        }

        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            Session::getInstance()->flash('error', 'Escribe un mensaje antes de enviar.');
            return $this->redirect('/portal/tickets/' . $uuid);
        }

        $db->insert('ticket_messages', [
            'ticket_id' => (int) $ticket['id'],
            'customer_id' => (int) $ticket['customer_row_id'],
            'message' => $message,
        ]);

        $db->update('tickets', [
            'status' => 'open',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $ticket['id']]);

        Session::getInstance()->flash('success', 'Mensaje enviado.');
        return $this->redirect('/portal/tickets/' . $uuid);
    }
}
