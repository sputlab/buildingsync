-- ================================================================
-- BuildingSync — Aggiornamento database 2.0.0 → 2.1.0
-- Aggiunge il flag per il cambio password obbligatorio al primo accesso.
-- Gli utenti già esistenti NON sono obbligati a cambiare password.
-- Per un'installazione pulita usare sql/install.sql (già aggiornato).
-- ================================================================
SET NAMES utf8mb4;

ALTER TABLE `buildingsync_utenti`
  ADD COLUMN `password_da_cambiare` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=cambio password obbligatorio al prossimo accesso' AFTER `password_hash`;

INSERT INTO `buildingsync_meta` (`chiave`,`valore`) VALUES ('db_version','2.1.0')
  ON DUPLICATE KEY UPDATE `valore`=VALUES(`valore`);
