<?php
// includes/bootstrap.php — Avvio app, require classi, helper globali (PHP 7.4+)
if (!defined('APP_NAME')) require_once dirname(__DIR__).'/config.php';
require_once __DIR__.'/version.php';

// Valori di default per costanti introdotte nelle versioni successive
// (evita errori se config.php non è ancora stato aggiornato)
defined('RESET_MAX_PER_HOUR') || define('RESET_MAX_PER_HOUR', 5);
defined('MAIL_FROM')          || define('MAIL_FROM', 'noreply@'.($_SERVER['HTTP_HOST'] ?? 'localhost'));
defined('MAIL_FROM_NAME')     || define('MAIL_FROM_NAME', APP_NAME);
defined('MAIL_REPLY_TO')      || define('MAIL_REPLY_TO', '');
defined('UPLOAD_PATH')        || define('UPLOAD_PATH', BASE_PATH.'/uploads/segnalazioni/');
defined('UPLOAD_MAX_MB')      || define('UPLOAD_MAX_MB', 5);
defined('UPLOAD_TYPES')       || define('UPLOAD_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);
defined('PRIVACY_TITOLARE')   || define('PRIVACY_TITOLARE', APP_NAME);
defined('PRIVACY_INDIRIZZO')  || define('PRIVACY_INDIRIZZO', '');
defined('PRIVACY_EMAIL')      || define('PRIVACY_EMAIL', MAIL_FROM);
defined('PRIVACY_DPO')        || define('PRIVACY_DPO', '');
defined('PRIVACY_GESTORE')    || define('PRIVACY_GESTORE', 'Sputlab (sputlab.com)');
defined('PRIVACY_TRASFERIMENTI') || define('PRIVACY_TRASFERIMENTI', 'I dati sono conservati su server situati nell\'Unione Europea e non sono trasferiti verso Paesi terzi.');
defined('LOG_RETENTION_DAYS') || define('LOG_RETENTION_DAYS', 180);

require_once __DIR__.'/DB.php';
require_once __DIR__.'/Security.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Mailer.php';

Security::setSecurityHeaders();
Security::startSecureSession();
Auth::refresh();

// Pulizia periodica dei dati con scadenza (circa 1 richiesta su 100): attuazione dei tempi
// di conservazione dichiarati nell'informativa privacy
if (random_int(1, 100) === 1) {
    try {
        $d = (int)LOG_RETENTION_DAYS;
        DB::q("DELETE FROM buildingsync_audit_log WHERE creato_il < NOW() - INTERVAL $d DAY");
        DB::q("DELETE FROM buildingsync_mail_log  WHERE creato_il < NOW() - INTERVAL $d DAY");
        DB::q("DELETE FROM buildingsync_password_reset_tokens WHERE scade_il < NOW() - INTERVAL 1 DAY");
    } catch (Exception $e) {}
}

// ---- Helper globali ----

function h($v) {
    return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES|ENT_HTML5, 'UTF-8');
}

/**
 * Valore JS sicuro da inserire in un attributo HTML delimitato da doppi apici,
 * es. onclick="fn(<?= jsAttr($row) ?>)" oppure onsubmit="return confirm(<?= jsAttr($msg) ?>)"
 */
function jsAttr($v) {
    return h(json_encode($v, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT));
}

/** Valore JS sicuro da inserire dentro un blocco <script> */
function jsVal($v) {
    return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
}

/** Restituisce solo le chiavi indicate (evita di esporre campi sensibili nel front-end) */
function pick(array $row, array $keys) {
    $out = [];
    foreach ($keys as $k) $out[$k] = $row[$k] ?? null;
    return $out;
}

function redirect($url) {
    header('Location: '.$url);
    exit;
}

function flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg'=>$msg,'type'=>$type];
}

function getFlash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/** Valida una data in formato Y-m-d; restituisce la data o null */
function validDate($d) {
    $d = trim((string)$d);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
    [$y, $m, $g] = array_map('intval', explode('-', $d));
    return checkdate($m, $g, $y) ? $d : null;
}

function formatDate($d, $fmt = 'd/m/Y') {
    if (!$d || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date($fmt, strtotime($d));
}

function timeAgo($dt) {
    $t = time() - strtotime($dt);
    if ($t <    60) return 'poco fa';
    if ($t <  3600) return round($t/60).' min fa';
    if ($t < 86400) return round($t/3600).' ore fa';
    return formatDate($dt);
}

function badge($val, $map) {
    $maps = [
        'severity' => ['bassa'=>'badge-low','media'=>'badge-med','alta'=>'badge-high','critica'=>'badge-crit'],
        'status'   => ['aperta'=>'badge-open','in_lavorazione'=>'badge-wip','risolta'=>'badge-done','chiusa'=>'badge-closed'],
        'activity' => ['da_fare'=>'badge-open','in_corso'=>'badge-wip','completata'=>'badge-done','annullata'=>'badge-closed'],
        'role'     => ['superadmin'=>'badge-superadmin','admin'=>'badge-admin','capocasa'=>'badge-capocasa','user'=>'badge-user'],
    ];
    $labels = [
        'bassa'=>'Bassa','media'=>'Media','alta'=>'Alta','critica'=>'Critica',
        'aperta'=>'Aperta','in_lavorazione'=>'In lavorazione','risolta'=>'Risolta','chiusa'=>'Chiusa',
        'da_fare'=>'Da fare','in_corso'=>'In corso','completata'=>'Completata','annullata'=>'Annullata',
        'superadmin'=>'Super Admin','admin'=>'Admin','capocasa'=>'Capocasa','user'=>'Condomino',
    ];
    $allMaps = isset($maps[$map]) ? $maps[$map] : [];
    $cls = isset($allMaps[$val]) ? $allMaps[$val] : 'badge-default';
    $lbl = isset($labels[$val]) ? $labels[$val] : $val;
    return '<span class="badge '.$cls.'">'.h($lbl).'</span>';
}

function roleLabel($r) {
    $map = [
        'superadmin' => 'Super Admin',
        'admin'      => 'Amministratore',
        'capocasa'   => 'Capocasa',
        'user'       => 'Condomino',
    ];
    return isset($map[$r]) ? $map[$r] : ucfirst((string)$r);
}

/** Etichette categorie attività (unica fonte di verità) */
function catLabels() {
    return [
        'pulizia_scale'         => 'Pulizia scale',
        'manutenzione_giardino' => 'Manutenzione giardino',
        'assemblea'             => 'Assemblea',
        'manutenzione_impianti' => 'Manutenzione impianti',
        'ispezione'             => 'Ispezione',
        'altro'                 => 'Altro',
    ];
}

function catIcon($c) {
    $map = [
        'pulizia_scale'           => '🧹',
        'manutenzione_giardino'   => '🌿',
        'assemblea'               => '📋',
        'manutenzione_impianti'   => '🔧',
        'ispezione'               => '🔍',
    ];
    return isset($map[$c]) ? $map[$c] : '📌';
}

/**
 * Regole di visibilità di una segnalazione.
 * - Admin dello stabile / superadmin: vede tutto
 * - Segnalazione pubblica: visibile a tutti gli utenti dello stabile
 * - Segnalazione privata: visibile all'autore e all'eventuale destinatario (privata_con_utente)
 */
function canSeeReport(array $rep, array $u, bool $isAdmin) {
    if ($isAdmin || ($u['ruolo'] ?? '') === 'superadmin') return true;
    if (empty($rep['privata'])) return true;
    if ((int)$rep['utente_id'] === (int)$u['id']) return true;
    return !empty($rep['privata_con_utente']) && (int)$rep['privata_con_utente'] === (int)$u['id'];
}
