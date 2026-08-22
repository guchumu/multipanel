-- Desactiva planes demo del seed (4.99 / 9.99 / 14.99 / 149.99).
-- Los precios reales se sincronizan desde settings.renewal_presets al abrir /billing
-- o al guardar Configuración → Facturación.
UPDATE `subscription_plans`
SET `is_active` = 0
WHERE `slug` IN ('basic', 'standard', 'premium', 'premium-yearly');
