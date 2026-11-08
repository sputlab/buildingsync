-- ================================================================
-- patch_v1_1.sql — Aggiornamento database per notifiche mail,
-- allegati segnalazioni, privacy segnalazioni
-- Eseguire dopo install.sql
-- ================================================================

-- Log delle email inviate
CREATE TABLE IF NOT EXISTS `mail_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `destinatario`  VARCHAR(200)    NOT NULL,
  `oggetto`       VARCHAR(300)    NOT NULL,
  `inviata`       TINYINT(1)      NOT NULL DEFAULT 0,
  `errore`        VARCHAR(500)    NULL,
  `creato_il`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_creato_il` (`creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campi privacy nelle segnalazioni
ALTER TABLE `segnalazioni`
  ADD COLUMN `privata`           TINYINT(1)   NOT NULL DEFAULT 0
    COMMENT 'Se 1: visibile solo al creatore e all admin (o utente specifico)'
    AFTER `stato`,
  ADD COLUMN `privata_con_utente` INT UNSIGNED NULL
    COMMENT 'Se admin crea segnalazione privata per un utente specifico'
    AFTER `privata`;

-- Allegati alle segnalazioni
CREATE TABLE IF NOT EXISTS `segnalazioni_allegati` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `segnalazione_id` INT UNSIGNED NOT NULL,
  `utente_id`      INT UNSIGNED NOT NULL,
  `filename`       VARCHAR(255) NOT NULL COMMENT 'Nome file sul disco (UUID)',
  `original_name`  VARCHAR(255) NOT NULL COMMENT 'Nome file originale',
  `mime_type`      VARCHAR(80)  NOT NULL,
  `dimensione`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Byte',
  `creato_il`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_segnalazione_id` (`segnalazione_id`),
  CONSTRAINT `fk_all_segn` FOREIGN KEY (`segnalazione_id`) REFERENCES `segnalazioni`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_all_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggiunge email_notifiche agli utenti (opt-out)
ALTER TABLE `utenti`
  ADD COLUMN `email_notifiche` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=riceve notifiche email, 0=disattivate'
    AFTER `email`;
