<?php
// ================================================================
// config.php — Configurazione BuildingSync
// ISTRUZIONI:
//   1. Copiare questo file nella root del progetto come "config.php"
//   2. Modificare tutti i valori contrassegnati con <<<
//   3. NON esporre né condividere questo file (vedi .htaccess)
// ================================================================

// --- DATABASE ---
// Tutte le tabelle usano il prefisso "buildingsync_" (vedi sql/install.sql)
define('DB_HOST',    'localhost');
define('DB_NAME',    'buildingsync_db');     // <<< nome database
define('DB_USER',    'db_utente');           // <<< utente MySQL
define('DB_PASS',    'db_password_sicura');  // <<< password MySQL
define('DB_CHARSET', 'utf8mb4');

// --- APPLICAZIONE ---
define('APP_NAME', 'BuildingSync');
define('APP_URL',  'https://tuodominio.it/buildingsync'); // <<< URL senza slash finale

// --- SICUREZZA ---
// Generare con: php -r "echo bin2hex(random_bytes(32));"
define('APP_KEY', 'SOSTITUIRE_CON_64_CARATTERI_HEX_CASUALI'); // <<<

define('SESSION_NAME',       'bs_'.substr(md5(DB_NAME.APP_KEY), 0, 8));
define('CSRF_TOKEN_NAME',    '_t');
define('SESSION_LIFETIME',   3600);     // secondi di inattività prima del logout automatico
define('MAX_LOGIN_ATTEMPTS', 5);        // tentativi prima del blocco
define('LOCKOUT_MINUTES',    15);       // minuti di blocco
define('RESET_MAX_PER_HOUR', 5);        // richieste di reset password per IP all'ora

define('PASSWORD_MIN_LEN',   8);        // lunghezza minima password
define('BCRYPT_COST',        12);       // costo bcrypt (10-14 consigliato)

// --- PERCORSI ---
define('BASE_PATH',     __DIR__);
define('INCLUDES_PATH', __DIR__.'/includes');

// --- TIMEZONE ---
define('TZ', 'Europe/Rome');
date_default_timezone_set(TZ);

// --- DEBUG (false in produzione!) ---
define('DEBUG_MODE', false); // <<< impostare false in produzione

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_PATH.'/logs/php_errors.log');
}

// --- EMAIL ---
define('MAIL_FROM',      'noreply@tuodominio.it'); // <<< mittente
define('MAIL_FROM_NAME', APP_NAME);
define('MAIL_REPLY_TO',  '');                      // opzionale

// --- UPLOAD ALLEGATI ---
// I file NON sono accessibili via URL diretto: li serve allegato.php dopo il controllo permessi
define('UPLOAD_PATH',   BASE_PATH.'/uploads/segnalazioni/');
define('UPLOAD_MAX_MB', 5);
define('UPLOAD_TYPES',  ['image/jpeg','image/png','image/gif','image/webp']);

// --- PRIVACY (mostrati nella pagina privacy.php) ---
define('PRIVACY_TITOLARE',   'Nome e cognome / ragione sociale del titolare'); // <<<
define('PRIVACY_INDIRIZZO',  'Indirizzo completo del titolare');               // <<<
define('PRIVACY_EMAIL',      'privacy@tuodominio.it');                         // <<< contatto per l'esercizio dei diritti
define('PRIVACY_DPO',        '');   // email del Responsabile Protezione Dati (RPD/DPO), se nominato
define('PRIVACY_GESTORE',    'Sputlab (sputlab.com)'); // chi gestisce la piattaforma tecnica
define('PRIVACY_TRASFERIMENTI', 'I dati sono conservati su server situati nell\'Unione Europea e non sono trasferiti verso Paesi terzi.'); // <<< verificare con il proprio hosting
define('LOG_RETENTION_DAYS', 180);  // giorni di conservazione di log accessi e log email
