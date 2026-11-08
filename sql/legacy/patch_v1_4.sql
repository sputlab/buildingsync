-- ================================================================
-- patch_v1_4.sql — Telefono e note utenti, tracciamento modifiche segnalazioni
-- ================================================================

-- Aggiunge telefono e note agli utenti
ALTER TABLE `utenti`
  ADD COLUMN `telefono` VARCHAR(30) NULL AFTER `email`,
  ADD COLUMN `note`     TEXT        NULL AFTER `telefono`;

-- Tracciamento chi ha modificato la segnalazione e quando
ALTER TABLE `segnalazioni`
  ADD COLUMN `modificato_da`  INT UNSIGNED NULL AFTER `aggiornato_il`,
  ADD COLUMN `modificato_il`  DATETIME     NULL AFTER `modificato_da`;

ALTER TABLE `segnalazioni`
  ADD CONSTRAINT `fk_seg_modificato` FOREIGN KEY (`modificato_da`) REFERENCES `utenti`(`id`) ON DELETE SET NULL;
