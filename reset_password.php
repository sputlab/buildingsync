<?php
// reset_password.php — Reset password self-service via email
require_once __DIR__.'/includes/bootstrap.php';

// Se già loggato, vai al profilo
if (Auth::check()) redirect(APP_URL.'/profilo.php');

$step = 'request'; // request | sent | reset | done
$error = '';

// ── STEP 1: richiesta reset ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['token'])) {
    Security::checkCsrf();
    $username = strtolower(trim(Security::postStr('username', 80)));
    $email    = strtolower(trim(Security::postStr('email', 150)));

    // Limite richieste per IP (anti-abuso / anti-spam)
    $recent = (int)(DB::one(
        "SELECT COUNT(*) n FROM buildingsync_audit_log
         WHERE azione='reset_request' AND ip=? AND creato_il > NOW() - INTERVAL 1 HOUR",
        [Security::clientIp()]
    )['n'] ?? 0);

    if ($recent >= RESET_MAX_PER_HOUR) {
        $error = 'Troppe richieste di reset da questo indirizzo. Riprovare tra un\'ora.';
    } else {
        Auth::logAction('reset_request', 'username='.$username);

        // Cerca utente (non rivelare se esiste o no)
        $user = DB::one(
            "SELECT id, nome_completo, email, username, attivo FROM buildingsync_utenti
             WHERE username=? AND attivo=1 LIMIT 1",
            [$username]
        );
        $emailMatch = $user && $email !== '' && hash_equals(strtolower($user['email']), $email);

        if ($emailMatch) {
            $token  = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 ora

            // Un solo token valido per utente
            DB::q("DELETE FROM buildingsync_password_reset_tokens WHERE utente_id=?", [$user['id']]);
            DB::insert('buildingsync_password_reset_tokens', [
                'utente_id'  => $user['id'],
                'token_hash' => hash('sha256', $token),
                'scade_il'   => $expiry,
                'usato'      => 0,
                'creato_il'  => date('Y-m-d H:i:s'),
            ]);

            Mailer::sendPasswordReset($user, APP_URL.'/reset_password.php?token='.urlencode($token));
        }
        // Sempre la stessa risposta (non rivelare se l'utente esiste)
        $step = 'sent';
    }
}

// ── STEP 2: verifica token e form nuova password ─────────────────
if (!empty($_GET['token']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = Security::str('token', 'GET', 80);
    $row   = validateToken($token);
    if (!$row) {
        $error = 'Il link non è valido o è scaduto. Richiedine uno nuovo.';
        $step  = 'request';
    } else {
        $step = 'reset';
    }
}

// ── STEP 3: salvataggio nuova password ───────────────────────────
if (!empty($_GET['token']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $token = Security::str('token', 'GET', 80);
    $row   = validateToken($token);

    if (!$row) {
        $error = 'Il link non è valido o è scaduto.';
        $step  = 'request';
    } else {
        $pass  = $_POST['password']  ?? '';
        $pass2 = $_POST['password2'] ?? '';
        $err   = Security::validatePassword($pass);
        if ($err) {
            $error = $err;
            $step  = 'reset';
        } elseif ($pass !== $pass2) {
            $error = 'Le password non coincidono.';
            $step  = 'reset';
        } else {
            DB::update('buildingsync_utenti', [
                'password_hash'  => Security::hashPassword($pass),
                'password_da_cambiare' => 0,   // scelta dall'utente stesso
                'login_attempts' => 0,
                'locked_until'   => null,
            ], 'id=?', [$row['utente_id']]);
            // Invalida il token
            DB::q("UPDATE buildingsync_password_reset_tokens SET usato=1 WHERE utente_id=?", [$row['utente_id']]);
            Auth::logAction('reset_done', 'utente_id='.$row['utente_id']);
            $step = 'done';
        }
    }
}

// ── Helper: valida token ─────────────────────────────────────────
function validateToken(string $token): ?array {
    if (!$token) return null;
    $hash = hash('sha256', $token);
    return DB::one(
        "SELECT * FROM buildingsync_password_reset_tokens
         WHERE token_hash=? AND usato=0 AND scade_il > ? LIMIT 1",
        [$hash, date('Y-m-d H:i:s')]
    ) ?: null;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Reset password — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= h(APP_VERSION) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-box" style="max-width:420px">
    <div class="login-logo">
      <span class="ico">&#x1F512;</span>
      <h1><?= h(APP_NAME) ?></h1>
      <p>Reset password</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'request'): ?>
    <!-- Form richiesta reset -->
    <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:20px">
      Inserire username e indirizzo email associato all'account. Verrà inviato un link per reimpostare la password.
    </p>
    <form method="POST" action="">
      <?= Security::csrfField() ?>
      <div class="fg" style="margin-bottom:14px">
        <label for="rusername">Username <span class="req">*</span></label>
        <input type="text" id="rusername" name="username" maxlength="80" required
               autocapitalize="none" autocomplete="username">
      </div>
      <div class="fg" style="margin-bottom:20px">
        <label for="remail">Email associata all'account <span class="req">*</span></label>
        <input type="email" id="remail" name="email" maxlength="150" required
               autocomplete="email">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
        Invia link di reset
      </button>
    </form>

    <?php elseif ($step === 'sent'): ?>
    <!-- Email inviata -->
    <div class="alert alert-success">
      Se l'username e l'email inseriti corrispondono a un account attivo, riceverà a breve un'email con le istruzioni per reimpostare la password.
    </div>
    <p style="font-size:.82rem;color:var(--gray-400);margin-bottom:20px">
      Controllare anche la cartella spam. Il link è valido per 1 ora.
    </p>

    <?php elseif ($step === 'reset'): ?>
    <!-- Form nuova password -->
    <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:20px">
      Scegliere una nuova password per il proprio account.
    </p>
    <form method="POST" action="?token=<?= h(urlencode(Security::str('token','GET',80))) ?>">
      <?= Security::csrfField() ?>
      <div class="fg" style="margin-bottom:14px">
        <label for="npass">Nuova password <span class="req">*</span></label>
        <input type="password" id="npass" name="password" maxlength="200" required
               autocomplete="new-password">
        <span class="field-note">Min <?= PASSWORD_MIN_LEN ?> caratteri, almeno 1 maiuscola e 1 numero.</span>
      </div>
      <div class="fg" style="margin-bottom:20px">
        <label for="npass2">Conferma nuova password <span class="req">*</span></label>
        <input type="password" id="npass2" name="password2" maxlength="200" required
               autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
        Imposta nuova password
      </button>
    </form>

    <?php elseif ($step === 'done'): ?>
    <!-- Completato -->
    <div class="alert alert-success">
      Password reimpostata con successo.
    </div>
    <p style="font-size:.85rem;color:var(--gray-500);margin-bottom:20px">
      Ora può accedere con la nuova password.
    </p>

    <?php endif; ?>

    <p style="text-align:center;margin-top:24px;font-size:.82rem">
      <a href="<?= APP_URL ?>/login.php">&larr; Torna al login</a>
      &middot; <a href="<?= APP_URL ?>/privacy.php">Privacy</a>
    </p>
  </div>
</div>
</body>
</html>
