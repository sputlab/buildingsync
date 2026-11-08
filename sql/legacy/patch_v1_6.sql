-- patch_v1_5.sql
-- Ruolo per stabile (capocasa contestuale)
ALTER TABLE utenti_stabili
  ADD COLUMN ruolo_stabile ENUM('user','capocasa') NOT NULL DEFAULT 'user'
    COMMENT 'Ruolo contestuale nello stabile' AFTER numero_unita;

-- Archiviazione segnalazioni e attività
ALTER TABLE segnalazioni
  ADD COLUMN archiviata TINYINT(1) NOT NULL DEFAULT 0 AFTER stato;

ALTER TABLE attivita
  ADD COLUMN archiviata TINYINT(1) NOT NULL DEFAULT 0 AFTER stato;
