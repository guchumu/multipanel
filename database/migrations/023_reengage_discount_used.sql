-- Descuento de reenganche usado (15% único por cliente)
ALTER TABLE `media_user_reengage`
    ADD COLUMN `discount_used_at` DATETIME NULL AFTER `converted_at`;
