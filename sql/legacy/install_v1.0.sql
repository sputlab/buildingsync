-- ================================================================
-- GestioneCondomini — Schema database v1.0
-- MySQL 5.7+ / MariaDB 10.3+
-- Importare con: mysql -u root -p condominio_db < install.sql
-- ================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ----------------------------------------------------------------
-- UTENTI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `utenti` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(50)      NOT NULL,
  `password_hash`   VARCHAR(255)     NOT NULL,
  `email`           VARCHAR(150)     NOT NULL DEFAULT '',
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
  KEY `idx_attivo`   (`attivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- STABILI / CONDOMINI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stabili` (
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
  KEY `idx_attivo`   (`attivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ASSEGNAZIONE UTENTI → STABILI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `utenti_stabili` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`    INT UNSIGNED NOT NULL,
  `utente_id`     INT UNSIGNED NOT NULL,
  `numero_unita`  VARCHAR(30)  NULL     COMMENT 'Numero interno/appartamento',
  `attivo`        TINYINT(1)   NOT NULL DEFAULT 1,
  `assegnato_il`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stabile_utente` (`stabile_id`,`utente_id`),
  KEY `idx_stabile_id` (`stabile_id`),
  KEY `idx_utente_id`  (`utente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- BACHECA (post, avvisi, comunicazioni)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bacheca_post` (
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
  KEY `idx_creato_il`  (`creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- SEGNALAZIONI
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `segnalazioni` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`    INT UNSIGNED NOT NULL,
  `utente_id`     INT UNSIGNED NOT NULL,
  `titolo`        VARCHAR(200) NOT NULL,
  `contenuto`     TEXT         NOT NULL,
  `gravita`       ENUM('bassa','media','alta','critica') NOT NULL DEFAULT 'media',
  `stato`         ENUM('aperta','in_lavorazione','risolta','chiusa') NOT NULL DEFAULT 'aperta',
  `creato_il`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stabile_id` (`stabile_id`),
  KEY `idx_utente_id`  (`utente_id`),
  KEY `idx_stato`      (`stato`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ATTIVITÀ / CALENDARIO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attivita` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stabile_id`      INT UNSIGNED NOT NULL,
  `creato_da`       INT UNSIGNED NOT NULL,
  `assegnato_a`     INT UNSIGNED NULL,
  `titolo`          VARCHAR(200) NOT NULL,
  `descrizione`     TEXT         NULL,
  `categoria`       ENUM('pulizia_scale','manutenzione_giardino','assemblea','manutenzione_impianti','ispezione','altro') NOT NULL DEFAULT 'altro',
  `data_programmata` DATE        NOT NULL,
  `data_scadenza`   DATE         NULL,
  `stato`           ENUM('da_fare','in_corso','completata','annullata') NOT NULL DEFAULT 'da_fare',
  `completato_da`   INT UNSIGNED NULL,
  `completato_il`   DATETIME     NULL,
  `note`            TEXT         NULL,
  `creato_il`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `aggiornato_il`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stabile_id`      (`stabile_id`),
  KEY `idx_assegnato_a`     (`assegnato_a`),
  KEY `idx_stato`           (`stato`),
  KEY `idx_data_programmata` (`data_programmata`),
  KEY `idx_data_scadenza`   (`data_scadenza`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- AUDIT LOG
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
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
  KEY `idx_creato_il` (`creato_il`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- FOREIGN KEYS
-- ----------------------------------------------------------------
ALTER TABLE `utenti`
  ADD CONSTRAINT `fk_utenti_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `utenti`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `stabili`
  ADD CONSTRAINT `fk_stabili_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `utenti`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `utenti_stabili`
  ADD CONSTRAINT `fk_us_stabile` FOREIGN KEY (`stabile_id`) REFERENCES `stabili`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_us_utente`  FOREIGN KEY (`utente_id`)  REFERENCES `utenti`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `bacheca_post`
  ADD CONSTRAINT `fk_bp_stabile` FOREIGN KEY (`stabile_id`) REFERENCES `stabili`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bp_utente`  FOREIGN KEY (`utente_id`)  REFERENCES `utenti`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `segnalazioni`
  ADD CONSTRAINT `fk_seg_stabile` FOREIGN KEY (`stabile_id`) REFERENCES `stabili`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_seg_utente`  FOREIGN KEY (`utente_id`)  REFERENCES `utenti`(`id`)  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `attivita`
  ADD CONSTRAINT `fk_att_stabile`   FOREIGN KEY (`stabile_id`)  REFERENCES `stabili`(`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_att_creatore`  FOREIGN KEY (`creato_da`)   REFERENCES `utenti`(`id`)  ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_att_assegnato` FOREIGN KEY (`assegnato_a`) REFERENCES `utenti`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_att_completato`FOREIGN KEY (`completato_da`)REFERENCES `utenti`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

SET foreign_key_checks = 1;

-- ----------------------------------------------------------------
-- SUPERADMIN INIZIALE
-- Username: superadmin
-- Password: Admin2026! (CAMBIARE SUBITO dopo il primo accesso)
-- Hash bcrypt cost 12
-- ----------------------------------------------------------------
INSERT INTO `utenti`
  (`username`, `password_hash`, `email`, `nome_completo`, `ruolo`, `attivo`)
VALUES (
  'superadmin',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'superadmin@example.com',
  'Super Amministratore',
  'superadmin',
  1
);

-- NOTA: l'hash sopra corrisponde alla password "Admin2026!"
-- Per generare un nuovo hash: php -r "echo password_hash('NuovaPassword', PASSWORD_BCRYPT, ['cost'=>12]);"
