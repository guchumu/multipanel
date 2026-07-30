-- Default automation rules seed
-- Run after schema.sql

INSERT INTO `automation_rules` (`tenant_id`, `name`, `description`, `trigger_event`, `conditions`, `actions`, `priority`, `is_active`) VALUES
(1, 'Suspender por impago 5 días', 'Suspende usuarios con suscripción vencida hace más de 5 días', 'payment.overdue',
 '[{"field":"trigger","operator":"equals","value":"payment.overdue"}]',
 '[{"type":"suspend_user","params":{"days_overdue":5}}]', 100, 1),

(1, 'Eliminar tras 15 días suspendido', 'Elimina usuarios suspendidos hace más de 15 días', 'payment.overdue',
 '[{"field":"trigger","operator":"equals","value":"payment.overdue"}]',
 '[{"type":"delete_user","params":{"days_overdue":15}}]', 90, 1),

(1, 'Reactivar al confirmar pago', 'Reactiva usuarios cuando la suscripción vuelve a activa', 'payment.received',
 '[{"field":"trigger","operator":"equals","value":"payment.received"}]',
 '[{"type":"activate_user","params":{}}]', 80, 1),

(1, 'Notificar servidor caído', 'Envía alerta cuando un servidor queda offline', 'server.offline',
 '[{"field":"trigger","operator":"equals","value":"server.offline"}]',
 '[{"type":"notify","params":{"event":"server.offline","title":"Servidor caído","message":"Un servidor ha dejado de responder.","channels":["telegram","discord"]}}]', 70, 1);

-- Default subscription plans
INSERT INTO `subscription_plans` (`tenant_id`, `name`, `slug`, `description`, `price`, `currency`, `interval`, `trial_days`, `max_streams`, `max_devices`) VALUES
(1, 'Básico', 'basic', '1 stream, 2 dispositivos', 4.99, 'EUR', 'monthly', 3, 1, 2),
(1, 'Estándar', 'standard', '2 streams, 5 dispositivos', 9.99, 'EUR', 'monthly', 3, 2, 5),
(1, 'Premium', 'premium', '4 streams, ilimitados dispositivos', 14.99, 'EUR', 'monthly', 7, 4, 10),
(1, 'Anual Premium', 'premium-yearly', 'Plan premium con descuento anual', 149.99, 'EUR', 'yearly', 7, 4, 10);

-- Example plugin
INSERT IGNORE INTO `plugins` (`name`, `slug`, `version`, `description`, `author`, `is_active`) VALUES
('Telegram Bot Commands', 'telegram-bot', '1.0.0', 'Comandos Telegram para gestionar usuarios', 'MultiPanel', 0);
