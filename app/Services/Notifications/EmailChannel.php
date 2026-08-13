<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\MailConfig;
use Core\Logger;
use Core\Session;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP email notification channel (settings UI smtp + .env fallback).
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private ?string $to = null,
    ) {
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $tenantId = isset($data['tenant_id'])
            ? (int) $data['tenant_id']
            : (int) (Session::getInstance()->get('tenant_id') ?? 1);
        $mailCfg = MailConfig::forTenant($tenantId);

        $recipient = trim((string) ($data['to'] ?? $this->to ?? ''));
        if ($recipient === '') {
            $recipient = trim((string) $mailCfg['from_address']);
        }
        if ($recipient === '' || $mailCfg['host'] === '') {
            Logger::debug('Email notification skipped: missing recipient or SMTP host');
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $mailCfg['host'];
            $mail->Port = $mailCfg['port'];
            $mail->SMTPAuth = $mailCfg['username'] !== '';
            $mail->Username = $mailCfg['username'];
            $mail->Password = $mailCfg['password'];
            $encryption = strtolower($mailCfg['encryption']);
            $mail->SMTPSecure = match ($encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none', '' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->CharSet = 'UTF-8';

            $from = $mailCfg['from_address'] !== '' ? $mailCfg['from_address'] : $recipient;
            $mail->setFrom($from, $mailCfg['from_name'] !== '' ? $mailCfg['from_name'] : 'MultiPanel');
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $title;
            $mail->Body = $this->renderTemplate($title, $message, $data);
            $mail->AltBody = strip_tags($message);

            $mail->send();
            return true;
        } catch (MailException $e) {
            Logger::error('Email notification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getName(): string
    {
        return 'email';
    }

    private function renderTemplate(string $title, string $message, array $data): string
    {
        $appName = config('app.name', 'MultiPanel');
        $body = nl2br(htmlspecialchars($message));

        return <<<HTML
<!DOCTYPE html>
<html><body style="font-family:Arial,sans-serif;background:#f4f6f9;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;padding:30px;">
    <h2 style="color:#0d6efd;margin-top:0;">{$appName}</h2>
    <h3 style="color:#333;">{$title}</h3>
    <div style="color:#555;line-height:1.6;">{$body}</div>
    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
    <p style="color:#999;font-size:12px;">Notificación automática de {$appName}</p>
</div>
</body></html>
HTML;
    }
}
