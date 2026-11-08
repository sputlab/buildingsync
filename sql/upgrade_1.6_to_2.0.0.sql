-- ================================================================
-- BuildingSync — Aggiornamento database EasyCondo 1.6 → BuildingSync 2.0.0
--
-- COSA FA:
--   1. aggiunge le colonne delle patch 1.1–1.5 eventualmente mancanti
--      (lo script è sicuro anche se le patch sono già state applicate)
--   2. rinomina tutte le tabelle con il prefisso "buildingsync_"
--      (RENAME TABLE mantiene dati, indici e foreign key)
--   3. crea la tabella buildingsync_meta con la versione dello schema
--
-- PRIMA DI ESEGUIRLO: FARE UN BACKUP COMPLETO DEL DATABASE
--   (phpMyAdmin → Esporta, oppure: mysqldump -u UTENTE -p NOME_DB > backup_1.6.sql)
--
-- Esecuzione: phpMyAdmin → scheda "SQL" o "Importa" (il file usa DELIMITER),
--             oppure: mysql -u UTENTE -p NOME_DB < sql/upgrade_1.6_to_2.0.0.sql
-- Le tabelle di altre applicazioni presenti nello stesso database non vengono toccate.
-- ================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS bs_add_column;
DROP PROCEDURE IF EXISTS bs_add_index;

DELIMITER $$
CREATE PROCEDURE bs_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_ddl TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
     AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column) THEN
    SET @bs_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
    PREPARE bs_stmt FROM @bs_sql; EXECUTE bs_stmt; DEALLOCATE PREPARE bs_stmt;
  END IF;
END$$

CREATE PROCEDURE bs_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols TEXT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table)
     AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index) THEN
    SET @bs_sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
    PREPARE bs_stmt FROM @bs_sql; EXECUTE bs_stmt; DEALLOCATE PREPARE bs_stmt;
  END IF;
END$$
DELIMITER ;

-- ----------------------------------------------------------------
-- 1. Colonne delle patch 1.1 → 1.5 (solo se mancanti)
-- ----------------------------------------------------------------
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

CREATE TABLE IF NOT EXISTS `segnalazioni_allegati` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `segnalazione_id` INT UNSIGNED NOT NULL,
  `utente_id`       INT UNSIGNED NOT NULL,
  `filename`        VARCHAR(255) NOT NULL,
  `original_name`   VARCHAR(255) NOT NULL,
  `mime_type`       VARCHAR(80)  NOT NULL,
  `dimensione`      INT UNSIGNED NOT NULL DEFAULT 0,
  `creato_il`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_segnalazione_id` (`segnalazione_id`),
  CONSTRAINT `fk_all_segn`   FOREIGN KEY (`segnalazione_id`) REFERENCES `segnalazioni`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_all_utente` FOREIGN KEY (`utente_id`)       REFERENCES `utenti`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utente_id`   INT UNSIGNED NOT NULL,
  `token_hash`  VARCHAR(64)  NOT NULL,
  `scade_il`    DATETIME     NOT NULL,
  `usato`       TINYINT(1)   NOT NULL DEFAULT 0,
  `creato_il`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_utente_id` (`utente_id`),
  KEY `idx_scade_il`  (`scade_il`),
  CONSTRAINT `fk_prt_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL bs_add_column('utenti',         'email_notifiche',    "`email_notifiche` TINYINT(1) NOT NULL DEFAULT 1 AFTER `email`");
CALL bs_add_column('utenti',         'telefono',           "`telefono` VARCHAR(30) NULL AFTER `email`");
CALL bs_add_column('utenti',         'note',               "`note` TEXT NULL AFTER `telefono`");
CALL bs_add_column('segnalazioni',   'privata',            "`privata` TINYINT(1) NOT NULL DEFAULT 0 AFTER `stato`");
CALL bs_add_column('segnalazioni',   'privata_con_utente', "`privata_con_utente` INT UNSIGNED NULL AFTER `privata`");
CALL bs_add_column('segnalazioni',   'archiviata',         "`archiviata` TINYINT(1) NOT NULL DEFAULT 0 AFTER `stato`");
CALL bs_add_column('segnalazioni',   'modificato_da',      "`modificato_da` INT UNSIGNED NULL AFTER `aggiornato_il`");
CALL bs_add_column('segnalazioni',   'modificato_il',      "`modificato_il` DATETIME NULL AFTER `modificato_da`");
CALL bs_add_column('attivita',       'archiviata',         "`archiviata` TINYINT(1) NOT NULL DEFAULT 0 AFTER `stato`");
CALL bs_add_column('utenti_stabili', 'ruolo_stabile',      "`ruolo_stabile` ENUM('user','capocasa') NOT NULL DEFAULT 'user' AFTER `numero_unita`");

-- Nuovi indici 2.0.0 (prestazioni archivio e limite richieste reset)
CALL bs_add_index('segnalazioni', 'idx_stabile_arch', '`stabile_id`,`archiviata`');
CALL bs_add_index('attivita',     'idx_stabile_arch', '`stabile_id`,`archiviata`');
CALL bs_add_index('audit_log',    'idx_azione_ip',    '`azione`,`ip`,`creato_il`');

DROP PROCEDURE IF EXISTS bs_add_column;
DROP PROCEDURE IF EXISTS bs_add_index;

-- ----------------------------------------------------------------
-- 2. Rinomina tabelle con prefisso buildingsync_ (operazione atomica)
-- ----------------------------------------------------------------
RENAME TABLE
  `utenti`                TO `buildingsync_utenti`,
  `stabili`               TO `buildingsync_stabili`,
  `utenti_stabili`        TO `buildingsync_utenti_stabili`,
  `bacheca_post`          TO `buildingsync_bacheca_post`,
  `segnalazioni`          TO `buildingsync_segnalazioni`,
  `segnalazioni_allegati` TO `buildingsync_segnalazioni_allegati`,
  `attivita`              TO `buildingsync_attivita`,
  `password_reset_tokens` TO `buildingsync_password_reset_tokens`,
  `mail_log`              TO `buildingsync_mail_log`,
  `audit_log`             TO `buildingsync_audit_log`;

-- ----------------------------------------------------------------
-- 3. Versione schema
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_meta` (
  `chiave`         VARCHAR(50)  NOT NULL,
  `valore`         VARCHAR(255) NOT NULL,
  `aggiornato_il`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chiave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `buildingsync_meta` (`chiave`,`valore`) VALUES ('db_version','2.0.0')
  ON DUPLICATE KEY UPDATE `valore`=VALUES(`valore`);

-- I token di reset emessi dalla 1.6 contengono il vecchio URL: si invalidano
UPDATE `buildingsync_password_reset_tokens` SET `usato`=1 WHERE `usato`=0;

-- Fine aggiornamento. Verifica:  SELECT * FROM buildingsync_meta;
