-- ================================================================
-- BuildingSync — Schema database 2.1.0 (installazione pulita)
-- MySQL 5.7+ / MariaDB 10.3+
-- Tutte le tabelle hanno il prefisso "buildingsync_"
-- Importare con: mysql -u UTENTE -p NOME_DB < sql/install.sql
-- (oppure dalla scheda "Importa" di phpMyAdmin)
--
-- Per aggiornare un'installazione esistente NON usare questo file:
--   EasyCondo 1.6      → sql/upgrade_1.6_to_2.0.0.sql poi sql/upgrade_2.0.0_to_2.1.0.sql
--   BuildingSync 2.0.0 → sql/upgrade_2.0.0_to_2.1.0.sql
-- ================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ----------------------------------------------------------------
-- META (versione schema)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_meta` (
  `chiave`         VARCHAR(50)  NOT NULL,
  `valore`         VARCHAR(255) NOT NULL,
  `aggiornato_il`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`chiave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- UTENTI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_utenti` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(50)      NOT NULL,
  `password_hash`   VARCHAR(255)     NOT NULL,
  `password_da_cambiare` TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1=cambio password obbligatorio al prossimo accesso',
  `email`           VARCHAR(150)     NOT NULL DEFAULT '',
  `email_notifiche` TINYINT(1)       NOT NULL DEFAULT 1 COMMENT '1=riceve notifiche email, 0=disattivate',
  `telefono`        VARCHAR(30)      NULL,
  `note`            TEXT             NULL,
  `nome_completo`   VARCHAR(120)     NOT NULL,
  `ruolo`           ENUM('superadmin','admin','capocasa','user') NOT NULL DEFAULT 'user',
  `admin_id`        INT UNSIGNED     NULL     COMMENT 'ID admin che ha creato utente/capocasa',
  `attivo`          TINYINT(1)       NOT NULL DEFAULT 1,
  `last_login`      DATETIME         NULL,
  `login_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`    DATETIME         NULL,
  `creato_il`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  KEY `idx_ruolo`    (`ruolo`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_attivo`   (`attivo`),
  CONSTRAINT `fk_bs_utenti_admin` FOREIGN KEY (`admin_id`) REFERENCES `buildingsync_utenti`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- STABILI / CONDOMINI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_stabili` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `admin_id`        INT UNSIGNED  NOT NULL COMMENT 'Amministratore responsabile',
  `nome`            VARCHAR(150)  NOT NULL,
  `indirizzo`       VARCHAR(255)  NOT NULL,
  `citta`           VARCHAR(100)  NOT NULL,
  `provincia`       VARCHAR(5)    NOT NULL DEFAULT '',
  `cap`             VARCHAR(10)   NOT NULL DEFAULT '',
  `unita_totali`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `unita_attive`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `codice_fiscale`  VARCHAR(20)   NULL,
  `note`            TEXT          NULL,
  `attivo`          TINYINT(1)    NOT NULL DEFAULT 1,
  `creato_il`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_attivo`   (`attivo`),
  CONSTRAINT `fk_bs_stabili_admin` FOREIGN KEY (`admin_id`) REFERENCES `buildingsync_utenti`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ASSEGNAZIONE UTENTI → STABILI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_utenti_stabili` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`    INT UNSIGNED NOT NULL,
  `utente_id`     INT UNSIGNED NOT NULL,
  `numero_unita`  VARCHAR(30)  NULL     COMMENT 'Numero interno/appartamento',
  `ruolo_stabile` ENUM('user','capocasa') NOT NULL DEFAULT 'user' COMMENT 'Ruolo contestuale nello stabile',
  `attivo`        TINYINT(1)   NOT NULL DEFAULT 1,
  `assegnato_il`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stabile_utente` (`stabile_id`,`utente_id`),
  KEY `idx_stabile_id` (`stabile_id`),
  KEY `idx_utente_id`  (`utente_id`),
  CONSTRAINT `fk_bs_us_stabile` FOREIGN KEY (`stabile_id`) REFERENCES `buildingsync_stabili`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_us_utente`  FOREIGN KEY (`utente_id`)  REFERENCES `buildingsync_utenti`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- BACHECA (post, avvisi, comunicazioni)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_bacheca_post` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`    INT UNSIGNED NOT NULL,
  `utente_id`     INT UNSIGNED NOT NULL,
  `titolo`        VARCHAR(200) NOT NULL,
  `contenuto`     TEXT         NOT NULL,
  `tipo`          ENUM('post','avviso','comunicazione') NOT NULL DEFAULT 'post',
  `in_evidenza`   TINYINT(1)   NOT NULL DEFAULT 0,
  `creato_il`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stabile_id` (`stabile_id`),
  KEY `idx_utente_id`  (`utente_id`),
  KEY `idx_creato_il`  (`creato_il`),
  CONSTRAINT `fk_bs_bp_stabile` FOREIGN KEY (`stabile_id`) REFERENCES `buildingsync_stabili`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_bp_utente`  FOREIGN KEY (`utente_id`)  REFERENCES `buildingsync_utenti`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- SEGNALAZIONI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_segnalazioni` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`         INT UNSIGNED NOT NULL,
  `utente_id`          INT UNSIGNED NOT NULL,
  `titolo`             VARCHAR(200) NOT NULL,
  `contenuto`          TEXT         NOT NULL,
  `gravita`            ENUM('bassa','media','alta','critica') NOT NULL DEFAULT 'media',
  `stato`              ENUM('aperta','in_lavorazione','risolta','chiusa') NOT NULL DEFAULT 'aperta',
  `archiviata`         TINYINT(1)   NOT NULL DEFAULT 0,
  `privata`            TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Se 1: visibile solo ad autore, destinatario e admin',
  `privata_con_utente` INT UNSIGNED NULL     COMMENT 'Destinatario di una segnalazione privata creata dall admin',
  `creato_il`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `modificato_da`      INT UNSIGNED NULL,
  `modificato_il`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stabile_id` (`stabile_id`),
  KEY `idx_utente_id`  (`utente_id`),
  KEY `idx_stato`      (`stato`),
  KEY `idx_stabile_arch` (`stabile_id`,`archiviata`),
  CONSTRAINT `fk_bs_seg_stabile`    FOREIGN KEY (`stabile_id`)    REFERENCES `buildingsync_stabili`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_seg_utente`     FOREIGN KEY (`utente_id`)     REFERENCES `buildingsync_utenti`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_seg_modificato` FOREIGN KEY (`modificato_da`) REFERENCES `buildingsync_utenti`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ALLEGATI SEGNALAZIONI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_segnalazioni_allegati` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `segnalazione_id` INT UNSIGNED NOT NULL,
  `utente_id`       INT UNSIGNED NOT NULL,
  `filename`        VARCHAR(255) NOT NULL COMMENT 'Nome file sul disco (casuale)',
  `original_name`   VARCHAR(255) NOT NULL COMMENT 'Nome file originale',
  `mime_type`       VARCHAR(80)  NOT NULL,
  `dimensione`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Byte',
  `creato_il`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_segnalazione_id` (`segnalazione_id`),
  CONSTRAINT `fk_bs_all_segn`   FOREIGN KEY (`segnalazione_id`) REFERENCES `buildingsync_segnalazioni`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bs_all_utente` FOREIGN KEY (`utente_id`)       REFERENCES `buildingsync_utenti`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ATTIVITÀ / CALENDARIO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_attivita` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`       INT UNSIGNED NOT NULL,
  `creato_da`        INT UNSIGNED NOT NULL,
  `assegnato_a`      INT UNSIGNED NULL,
  `titolo`           VARCHAR(200) NOT NULL,
  `descrizione`      TEXT         NULL,
  `categoria`        ENUM('pulizia_scale','manutenzione_giardino','assemblea','manutenzione_impianti','ispezione','altro') NOT NULL DEFAULT 'altro',
  `data_programmata` DATE         NOT NULL,
  `data_scadenza`    DATE         NULL,
  `stato`            ENUM('da_fare','in_corso','completata','annullata') NOT NULL DEFAULT 'da_fare',
  `archiviata`       TINYINT(1)   NOT NULL DEFAULT 0,
  `completato_da`    INT UNSIGNED NULL,
  `completato_il`    DATETIME     NULL,
  `note`             TEXT         NULL,
  `creato_il`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stabile_id`        (`stabile_id`),
  KEY `idx_assegnato_a`       (`assegnato_a`),
  KEY `idx_stato`             (`stato`),
  KEY `idx_data_programmata`  (`data_programmata`),
  KEY `idx_data_scadenza`     (`data_scadenza`),
  KEY `idx_stabile_arch`      (`stabile_id`,`archiviata`),
  CONSTRAINT `fk_bs_att_stabile`    FOREIGN KEY (`stabile_id`)    REFERENCES `buildingsync_stabili`(`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_att_creatore`   FOREIGN KEY (`creato_da`)     REFERENCES `buildingsync_utenti`(`id`)  ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_att_assegnato`  FOREIGN KEY (`assegnato_a`)   REFERENCES `buildingsync_utenti`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bs_att_completato` FOREIGN KEY (`completato_da`) REFERENCES `buildingsync_utenti`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- TOKEN RESET PASSWORD
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_password_reset_tokens` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utente_id`   INT UNSIGNED NOT NULL,
  `token_hash`  VARCHAR(64)  NOT NULL COMMENT 'SHA-256 del token',
  `scade_il`    DATETIME     NOT NULL,
  `usato`       TINYINT(1)   NOT NULL DEFAULT 0,
  `creato_il`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_utente_id` (`utente_id`),
  KEY `idx_scade_il`  (`scade_il`),
  CONSTRAINT `fk_bs_prt_utente` FOREIGN KEY (`utente_id`) REFERENCES `buildingsync_utenti`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- LOG EMAIL
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_mail_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `destinatario`  VARCHAR(200)    NOT NULL,
  `oggetto`       VARCHAR(300)    NOT NULL,
  `inviata`       TINYINT(1)      NOT NULL DEFAULT 0,
  `errore`        VARCHAR(500)    NULL,
  `creato_il`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_creato_il` (`creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- AUDIT LOG
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildingsync_audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utente_id`   INT UNSIGNED    NULL,
  `azione`      VARCHAR(100)    NOT NULL,
  `dettagli`    TEXT            NULL,
  `ip`          VARCHAR(45)     NULL,
  `user_agent`  VARCHAR(300)    NULL,
  `creato_il`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_utente_id` (`utente_id`),
  KEY `idx_azione`    (`azione`),
  KEY `idx_creato_il` (`creato_il`),
  KEY `idx_azione_ip` (`azione`,`ip`,`creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

-- ----------------------------------------------------------------
-- DATI INIZIALI
-- ----------------------------------------------------------------
INSERT INTO `buildingsync_meta` (`chiave`,`valore`) VALUES ('db_version','2.1.0')
  ON DUPLICATE KEY UPDATE `valore`=VALUES(`valore`);

-- Superadmin iniziale
--   Username: superadmin
--   Password: Admin2026!   (al primo accesso l'app obbliga a sceglierne una nuova)
-- Per generare un nuovo hash: php -r "echo password_hash('NuovaPassword', PASSWORD_BCRYPT, ['cost'=>12]);"
INSERT INTO `buildingsync_utenti`
  (`username`, `password_hash`, `password_da_cambiare`, `email`, `nome_completo`, `ruolo`, `attivo`)
VALUES (
  'superadmin',
  '$2y$12$rqwq.0GcrN4BvjFYBYBasOOkRe2d4lzyWtZ.rINrZvBzQb.0jJbVi',
  1,
  'superadmin@example.com',
  'Super Amministratore',
  'superadmin',
  1
);
