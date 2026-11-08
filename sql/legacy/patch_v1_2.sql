-- ================================================================
-- patch_v1_2.sql — Token reset password, supporto multi-superadmin
-- Eseguire dopo patch_v1_1.sql
-- ================================================================

-- Tabella token reset password
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
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
  CONSTRAINT `fk_prt_utente` FOREIGN KEY (`utente_id`) REFERENCES `utenti`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Il ruolo superadmin ora supporta più istanze (nessuna modifica strutturale necessaria,
-- era già ENUM — basta non limitare il conteggio nel codice)
-- Nessuna ALTER TABLE necessaria.

-- Pulizia automatica token scaduti (opzionale, via evento MySQL se disponibile)
-- CREATE EVENT IF NOT EXISTS `cleanup_reset_tokens`
--   ON SCHEDULE EVERY 1 DAY
--   DO DELETE FROM password_reset_tokens WHERE scade_il < NOW() - INTERVAL 1 DAY;
