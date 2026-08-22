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

-- Planes = mismos precios que Configuración → Facturación (renewal_presets)
INSERT INTO `subscription_plans` (`tenant_id`, `name`, `slug`, `description`, `price`, `currency`, `interval`, `trial_days`, `max_streams`, `max_devices`, `features`, `sort_order`) VALUES
(1, '1 mes', 'renew-30', '30 días · precio de Configuración → Facturación', 15.00, 'EUR', 'monthly', 0, 2, 5, '{"days":30,"source":"renewal_presets"}', 0),
(1, '3 meses', 'renew-90', '90 días · precio de Configuración → Facturación', 40.00, 'EUR', 'quarterly', 0, 2, 5, '{"days":90,"source":"renewal_presets"}', 1),
(1, '6 meses', 'renew-180', '180 días · precio de Configuración → Facturación', 70.00, 'EUR', 'quarterly', 0, 2, 5, '{"days":180,"source":"renewal_presets"}', 2),
(1, '1 año', 'renew-365', '365 días · precio de Configuración → Facturación', 70.00, 'EUR', 'yearly', 0, 2, 5, '{"days":365,"source":"renewal_presets"}', 3);

-- Example plugin
INSERT IGNORE INTO `plugins` (`name`, `slug`, `version`, `description`, `author`, `is_active`) VALUES
('Telegram Bot Commands', 'telegram-bot', '1.0.0', 'Comandos Telegram para gestionar usuarios', 'MultiPanel', 0);
