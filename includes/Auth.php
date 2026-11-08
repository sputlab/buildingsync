<?php
// includes/Auth.php — Autenticazione e controllo accessi
class Auth {

    /** Campi utente conservati in sessione (mai l'hash della password) */
    private const SESSION_FIELDS = 'id, username, email, email_notifiche, telefono, nome_completo, ruolo, admin_id, attivo, last_login, password_da_cambiare';

    // --- LOGIN ---
    public static function login(string $username, string $password): array {
        $username = trim(strtolower($username));
        $user = DB::one("SELECT * FROM buildingsync_utenti WHERE username=? LIMIT 1", [$username]);

        if (!$user || !$user['attivo']) {
            // Esegue comunque un password_verify per non rivelare (via tempi di risposta) se l'utente esiste
            password_verify($password, '$2y$12$DOMwae2pSvzaAnfQuejZGeyfsGE7fFnFIbDvOoXfid.9EljOZ9dq2');
            self::logAccess(null, $username, 'login_fail');
            return ['ok' => false, 'msg' => 'Credenziali non valide.'];
        }

        // Blocco temporaneo
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $min = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
            self::logAccess((int)$user['id'], $username, 'login_locked');
            return ['ok' => false, 'msg' => "Account temporaneamente bloccato. Riprova tra $min minuti."];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $attempts = (int)$user['login_attempts'] + 1;
            $locked   = null;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $locked   = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
                $attempts = 0;
            }
            DB::update('buildingsync_utenti', ['login_attempts'=>$attempts,'locked_until'=>$locked], 'id=?', [$user['id']]);
            self::logAccess((int)$user['id'], $username, 'login_fail');
            return ['ok' => false, 'msg' => 'Credenziali non valide.'];
        }

        $upd = ['login_attempts'=>0,'locked_until'=>null,'last_login'=>date('Y-m-d H:i:s')];
        // Aggiorna l'hash se il costo bcrypt configurato è cambiato
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST])) {
            $upd['password_hash'] = Security::hashPassword($password);
        }
        DB::update('buildingsync_utenti', $upd, 'id=?', [$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user'] = self::loadUser((int)$user['id']);
        $_SESSION['_sid_time'] = time();
        self::logAccess((int)$user['id'], $username, 'login_ok');
        return ['ok' => true];
    }

    public static function logout(): void {
        $id = self::id();
        if ($id) self::logAccess($id, self::user()['username'] ?? '', 'logout');
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
            session_destroy();
        }
    }

    private static function loadUser(int $id): ?array {
        $row = DB::one("SELECT ".self::SESSION_FIELDS." FROM buildingsync_utenti WHERE id=? LIMIT 1", [$id]);
        if ($row) $row['id'] = (int)$row['id'];
        return $row;
    }

    /**
     * Ricarica i dati dell'utente a ogni richiesta: se un amministratore disattiva l'account
     * o ne cambia il ruolo, l'effetto è immediato anche sulle sessioni già aperte.
     */
    public static function refresh(): void {
        if (empty($_SESSION['user']['id'])) return;
        try {
            $fresh = self::loadUser((int)$_SESSION['user']['id']);
        } catch (Exception $e) {
            return; // in caso di errore DB non si disconnette l'utente
        }
        if (!$fresh || !$fresh['attivo']) {
            $_SESSION = [];
            session_regenerate_id(true);
            return;
        }
        $_SESSION['user'] = $fresh;
    }

    // --- CHECKS ---
    public static function check(): bool { return !empty($_SESSION['user']['id']); }
    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function id(): ?int { return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null; }
    public static function role(): string { return $_SESSION['user']['ruolo'] ?? ''; }

    public static function requireLogin(): void {
        if (!self::check()) {
            $msg = !empty($_SESSION['_expired']) ? 'session_expired' : 'login_required';
            unset($_SESSION['_expired']);
            // Memorizza la pagina richiesta per tornarci dopo il login
            $req = $_SERVER['REQUEST_URI'] ?? '';
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && $req) $_SESSION['_after_login'] = $req;
            header('Location: '.APP_URL.'/login.php?msg='.$msg);
            exit;
        }
        // Cambio password obbligatorio (primo accesso o password impostata da un amministratore)
        if (self::mustChangePassword() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'cambio_password.php') {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                http_response_code(403);
                header('Content-Type: application/json');
                exit(json_encode(['ok'=>false,'error'=>'password_change_required']));
            }
            header('Location: '.APP_URL.'/cambio_password.php');
            exit;
        }
    }

    public static function mustChangePassword(): bool {
        return !empty($_SESSION['user']['password_da_cambiare']);
    }

    /**
     * Imposta una nuova password scelta dall'utente stesso (rimuove l'obbligo di cambio).
     * Restituisce un messaggio d'errore oppure null se tutto è andato a buon fine.
     */
    public static function changeOwnPassword(string $new, string $new2): ?string {
        $err = Security::validatePassword($new);
        if ($err) return $err;
        if ($new !== $new2) return 'Le nuove password non coincidono.';
        $row = DB::one("SELECT password_hash FROM buildingsync_utenti WHERE id=?", [self::id()]);
        if ($row && password_verify($new, $row['password_hash'])) return 'La nuova password deve essere diversa da quella attuale.';
        DB::update('buildingsync_utenti', [
            'password_hash'        => Security::hashPassword($new),
            'password_da_cambiare' => 0,
        ], 'id=?', [self::id()]);
        $_SESSION['user']['password_da_cambiare'] = 0;
        session_regenerate_id(true);
        self::logAction('password_change');
        return null;
    }

    public static function requireRole(array $roles): void {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            self::deny('Accesso negato');
        }
    }

    public static function deny(string $title = 'Accesso negato'): void {
        http_response_code(403);
        die('<h2>'.h($title).'</h2><a href="'.APP_URL.'/dashboard.php">Torna alla dashboard</a>');
    }

    public static function isAdmin(): bool {
        return in_array(self::role(), ['admin','superadmin'], true);
    }

    public static function isSuperAdmin(): bool { return self::role() === 'superadmin'; }

    /** L'utente corrente amministra questo stabile? (superadmin: sempre) */
    public static function managesBuilding(int $bid): bool {
        if (self::isSuperAdmin()) return true;
        if (self::role() !== 'admin') return false;
        return (bool) DB::one("SELECT id FROM buildingsync_stabili WHERE id=? AND admin_id=?", [$bid, self::id()]);
    }

    /** Verifica accesso a uno stabile */
    public static function canAccessBuilding(int $bid): bool {
        $u = self::user();
        if (!$u) return false;
        if ($u['ruolo'] === 'superadmin' || $u['ruolo'] === 'admin') return self::managesBuilding($bid);
        return (bool) DB::one(
            "SELECT id FROM buildingsync_utenti_stabili WHERE stabile_id=? AND utente_id=? AND attivo=1",
            [$bid, $u['id']]
        );
    }

    public static function assertBuilding(int $bid): void {
        if (!self::canAccessBuilding($bid)) self::deny('Accesso negato a questo stabile');
    }

    /**
     * Ruolo contestuale nello stabile: 'admin' | 'capocasa' | 'user' | null.
     * Il capocasa può essere assegnato per singolo stabile (utenti_stabili.ruolo_stabile)
     * oppure globalmente (utenti.ruolo = capocasa).
     */
    public static function buildingRole(int $bid): ?string {
        if (self::managesBuilding($bid)) return 'admin';
        $row = DB::one(
            "SELECT ruolo_stabile FROM buildingsync_utenti_stabili WHERE stabile_id=? AND utente_id=? AND attivo=1",
            [$bid, self::id()]
        );
        if (!$row) return null;
        if ($row['ruolo_stabile'] === 'capocasa' || self::role() === 'capocasa') return 'capocasa';
        return 'user';
    }

    /** Stabili visibili all'utente corrente */
    public static function myBuildings(): array {
        $u = self::user();
        if (!$u) return [];
        if ($u['ruolo'] === 'superadmin') {
            return DB::all("SELECT * FROM buildingsync_stabili ORDER BY nome");
        }
        if ($u['ruolo'] === 'admin') {
            return DB::all("SELECT * FROM buildingsync_stabili WHERE admin_id=? ORDER BY nome", [$u['id']]);
        }
        return DB::all(
            "SELECT s.* FROM buildingsync_stabili s JOIN buildingsync_utenti_stabili us ON us.stabile_id=s.id
             WHERE us.utente_id=? AND us.attivo=1 ORDER BY s.nome",
            [$u['id']]
        );
    }

    /** Utenti di uno stabile */
    public static function buildingUsers(int $bid): array {
        return DB::all(
            "SELECT u.id, u.username, u.nome_completo, u.email, u.ruolo,
                    us.numero_unita, us.attivo, us.ruolo_stabile
             FROM buildingsync_utenti u JOIN buildingsync_utenti_stabili us ON us.utente_id=u.id
             WHERE us.stabile_id=? ORDER BY u.nome_completo",
            [$bid]
        );
    }

    public static function logAction(string $action, string $details = ''): void {
        self::logAccess(self::id(), $details, $action, false);
    }

    private static function logAccess(?int $uid, string $username, string $action, bool $isLogin = true): void {
        try {
            DB::insert('buildingsync_audit_log', [
                'utente_id'  => $uid,
                'azione'     => $action,
                'dettagli'   => $isLogin ? "username=$username" : $username,
                'ip'         => Security::clientIp(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                'creato_il'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}
    }
}
