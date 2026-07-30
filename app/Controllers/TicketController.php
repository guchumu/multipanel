<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\AuditService;
use App\Services\Notifications\NotificationService;
use Core\Controller;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Session;
use Ramsey\Uuid\Uuid;

/**
 * Support ticket system controller.
 */
class TicketController extends Controller
{
    public function __construct(
        private AuthService $auth = new AuthService(),
        private AuditService $audit = new AuditService(),
        private NotificationService $notifications = new NotificationService(),
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $status = $request->input('status');

        $params = [$tenantId];
        $where = 't.tenant_id = ?';
        if ($status) {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }

        $tickets = Database::getInstance()->fetchAll(
            "SELECT t.*, c.email as customer_email, u.username as assigned_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN users u ON u.id = t.assigned_to
             WHERE {$where} ORDER BY t.updated_at DESC LIMIT 50",
            $params
        );

        $counts = Database::getInstance()->fetchAll(
            "SELECT status, COUNT(*) as count FROM tickets WHERE tenant_id = ? GROUP BY status",
            [$tenantId]
        );

        return $this->view('tickets.index', [
            'title' => 'Soporte',
            'tickets' => $tickets,
            'counts' => $counts,
            'currentStatus' => $status,
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $ticket = Database::getInstance()->fetchOne('SELECT * FROM tickets WHERE uuid = ?', [$uuid]);
        if (!$ticket) {
            return $this->redirect('/tickets');
        }

        $messages = Database::getInstance()->fetchAll(
            "SELECT tm.*, u.username, u.first_name, c.email as customer_email
             FROM ticket_messages tm
             LEFT JOIN users u ON u.id = tm.user_id
             LEFT JOIN customers c ON c.id = tm.customer_id
             WHERE tm.ticket_id = ? ORDER BY tm.created_at",
            [$ticket['id']]
        );

        return $this->view('tickets.show', [
            'title' => $ticket['subject'],
            'ticket' => $ticket,
            'messages' => $messages,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'subject' => 'required|max:255',
            'message' => 'required',
        ]);

        $tenantId = (int) ($this->auth->user()->tenant_id ?? 1);
        $userId = (int) $this->auth->user()->id;

        $ticketId = Database::getInstance()->insert('tickets', [
            'tenant_id' => $tenantId,
            'uuid' => Uuid::uuid4()->toString(),
            'customer_id' => $request->input('customer_id') ?: null,
            'subject' => $data['subject'],
            'status' => 'open',
            'priority' => $request->input('priority') ?? 'medium',
            'category' => $request->input('category'),
        ]);

        Database::getInstance()->insert('ticket_messages', [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'message' => $data['message'],
        ]);

        $this->notifications->notify('ticket.created', 'Nuevo ticket', $data['subject'], ['telegram']);

        Session::getInstance()->flash('success', 'Ticket creado.');
        return $this->redirect('/tickets');
    }

    public function reply(Request $request, string $uuid): Response
    {
        $ticket = Database::getInstance()->fetchOne('SELECT * FROM tickets WHERE uuid = ?', [$uuid]);
        if (!$ticket) {
            return $this->redirect('/tickets');
        }

        $message = $request->input('message');
        if (!$message) {
            return $this->redirect('/tickets/' . $uuid);
        }

        Database::getInstance()->insert('ticket_messages', [
            'ticket_id' => $ticket['id'],
            'user_id' => (int) $this->auth->user()->id,
            'message' => $message,
            'is_internal' => $request->input('is_internal') ? 1 : 0,
        ]);

        Database::getInstance()->update('tickets', [
            'status' => $request->input('status') ?? 'in_progress',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$ticket['id']]);

        return $this->redirect('/tickets/' . $uuid);
    }

    public function close(Request $request, string $uuid): Response
    {
        Database::getInstance()->update('tickets', [
            'status' => 'closed',
            'closed_at' => date('Y-m-d H:i:s'),
        ], 'uuid = ?', [$uuid]);

        return $this->redirect('/tickets/' . $uuid);
    }
}
