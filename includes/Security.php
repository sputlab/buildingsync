<?php
// includes/Security.php — Sessione, CSRF, header HTTP sicuri, input
class Security {

    /** Path del cookie di sessione limitato alla cartella dell'app (non condiviso con altre app del dominio) */
    private static function cookiePath(): string {
        $p = parse_url(APP_URL, PHP_URL_PATH);
        return $p ? rtrim($p, '/').'/' : '/';
    }

    public static function startSecureSession(): void {
        if (session_status() !== PHP_SESSION_NONE) return;
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,                      // cookie di sessione (scade alla chiusura del browser)
            'path'     => self::cookiePath(),
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',                  // Lax: i link nelle email (reset, notifiche) mantengono la sessione
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string)max(SESSION_LIFETIME, 1440));
        session_start();

        // Timeout di inattività lato server
        $now = time();
        if (!empty($_SESSION['_last']) && $now - $_SESSION['_last'] > SESSION_LIFETIME) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_expired'] = 1;
        }
        $_SESSION['_last'] = $now;

        // Rigenera ID ogni 5 minuti
        if (empty($_SESSION['_sid_time']) || $now - $_SESSION['_sid_time'] > 300) {
            session_regenerate_id(true);
            $_SESSION['_sid_time'] = $now;
        }
    }

    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    }

    /** IP del client. Non si usa X-Forwarded-For perché è falsificabile dal client. */
    public static function clientIp(): string {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    public static function setSecurityHeaders(): void {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
    }

    // --- CSRF ---
    public static function csrfToken(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.h(self::csrfToken()).'">';
    }

    public static function checkCsrf(): void {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                die(json_encode(['ok'=>false,'error'=>'csrf']));
            }
            die('Sessione scaduta o token di sicurezza non valido. <a href="javascript:history.back()">Torna indietro</a> e ricarica la pagina.');
        }
    }

    // --- PASSWORD ---
    public static function hashPassword(string $p): string {
        return password_hash($p, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    public static function validatePassword(string $p): ?string {
        if (mb_strlen($p) < PASSWORD_MIN_LEN) return 'La password deve essere di almeno '.PASSWORD_MIN_LEN.' caratteri.';
        if (strlen($p) > 72) return 'La password non può superare i 72 caratteri.';
        if (!preg_match('/[A-Z]/', $p)) return 'La password deve contenere almeno una lettera maiuscola.';
        if (!preg_match('/[0-9]/', $p)) return 'La password deve contenere almeno un numero.';
        return null;
    }

    /** Password casuale che rispetta i requisiti di validatePassword() */
    public static function randomPassword(int $len = 12): string {
        $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < $len - 2; $i++) $p .= $alpha[random_int(0, strlen($alpha) - 1)];
        $p .= 'ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 23)];
        $p .= (string)random_int(2, 9);
        return str_shuffle($p);
    }

    // --- INPUT ---
    public static function str(string $key, string $source = 'POST', int $maxlen = 500): string {
        $val = $source === 'GET' ? ($_GET[$key] ?? '') : ($_POST[$key] ?? '');
        if (!is_string($val)) return '';
        return mb_substr(trim(strip_tags($val)), 0, $maxlen);
    }

    public static function int(string $key, string $source = 'POST', int $default = 0): int {
        $val = $source === 'GET' ? ($_GET[$key] ?? $default) : ($_POST[$key] ?? $default);
        if (is_array($val)) return $default;
        return (int) filter_var($val, FILTER_VALIDATE_INT, ['options'=>['default'=>$default]]);
    }

    public static function postStr(string $key, int $maxlen = 500): string {
        return self::str($key, 'POST', $maxlen);
    }

    public static function getInt(string $key, int $default = 0): int {
        return self::int($key, 'GET', $default);
    }

    public static function postInt(string $key, int $default = 0): int {
        return self::int($key, 'POST', $default);
    }

    /** Valore di $_POST[$key] se presente in $allowed, altrimenti $default */
    public static function postEnum(string $key, array $allowed, string $default): string {
        $v = $_POST[$key] ?? '';
        return (is_string($v) && in_array($v, $allowed, true)) ? $v : $default;
    }
}
