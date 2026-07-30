<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Core\Logger;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP email notification channel.
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private ?string $to = null,
    ) {
        $this->to ??= config('mail.from.address');
    }

    public function send(string $title, string $message, array $data = []): bool
    {
        $recipient = $data['to'] ?? $this->to;
        if (!$recipient) {
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = config('mail.host');
            $mail->Port = config('mail.port');
            $mail->SMTPAuth = (bool) config('mail.username');
            $mail->Username = config('mail.username');
            $mail->Password = config('mail.password');
            $mail->SMTPSecure = config('mail.encryption') ?: PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(config('mail.from.address'), config('mail.from.name'));
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
