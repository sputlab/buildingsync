<?php
require_once __DIR__.'/includes/bootstrap.php';
if (Auth::check()) redirect(APP_URL.'/dashboard.php');

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $username = Security::postStr('username', 80);
    $password = $_POST['password'] ?? '';
    if (!$username || !$password) {
        $error = 'Inserire username e password.';
    } else {
        $r = Auth::login($username, $password);
        if ($r['ok']) {
            // Torna alla pagina richiesta prima del login (solo percorsi interni all'app)
            $next  = $_SESSION['_after_login'] ?? '';
            unset($_SESSION['_after_login']);
            $base  = rtrim((string)parse_url(APP_URL, PHP_URL_PATH), '/').'/';
            $host  = parse_url(APP_URL, PHP_URL_SCHEME).'://'.parse_url(APP_URL, PHP_URL_HOST)
                   .(parse_url(APP_URL, PHP_URL_PORT) ? ':'.parse_url(APP_URL, PHP_URL_PORT) : '');
            if (is_string($next) && strpos($next, $base) === 0 && strpos($next, '//') === false
                && strpos($next, 'logout.php') === false) {
                redirect($host.$next);
            }
            redirect(APP_URL.'/dashboard.php');
        }
        else $error = $r['msg'];
    }
}
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Accesso — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= h(APP_VERSION) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <span class="ico">🏛</span>
      <h1><?= h(APP_NAME) ?></h1>
      <p>Gestione condominiale — Accesso sicuro</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:16px">
      <?= h($error) ?>
    </div>
    <?php endif; ?>

    <?php $gmsg = $_GET['msg'] ?? ''; if ($gmsg === 'login_required' && !$error): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
      Effettua l'accesso per continuare.
    </div>
    <?php elseif ($gmsg === 'session_expired' && !$error): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
      Sessione scaduta per inattività. Effettua di nuovo l'accesso.
    </div>
    <?php elseif ($gmsg === 'logout' && !$error): ?>
    <div class="alert alert-success" style="margin-bottom:16px">
      Disconnessione effettuata.
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <?= Security::csrfField() ?>
      <div class="fg" style="margin-bottom:14px">
        <label for="username">Username <span class="req">*</span></label>
        <input type="text" id="username" name="username"
               value="<?= h($username) ?>"
               autocomplete="username" autocapitalize="none"
               maxlength="80" required autofocus>
      </div>
      <div class="fg" style="margin-bottom:22px">
        <label for="password">Password <span class="req">*</span></label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" maxlength="200" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
        Accedi
      </button>
    </form>

    <p style="text-align:center;margin-top:16px;font-size:.82rem">
      <a href="<?= APP_URL ?>/reset_password.php">Password dimenticata?</a>
    </p>
    <p style="text-align:center;margin-top:8px;font-size:.78rem;color:var(--gray-400)">
      Per assistenza contattare l'amministratore di sistema.
    </p>
    <p class="login-links">
      <a href="<?= APP_URL ?>/privacy.php">&#x1F512; Informativa privacy e cookie</a>
    </p>
    <p style="text-align:center;margin-top:4px;font-size:.7rem;color:var(--gray-400)">
      <?= h(APP_NAME) ?> v<?= h(APP_VERSION) ?>
    </p>
  </div>
</div>
</body>
</html>
