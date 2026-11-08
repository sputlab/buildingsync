<?php
// cambio_password.php — Cambio password obbligatorio al primo accesso
require_once __DIR__.'/includes/bootstrap.php';
Auth::requireLogin();
if (!Auth::mustChangePassword()) redirect(APP_URL.'/dashboard.php');

$u     = Auth::user();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $new  = is_string($_POST['new_password'] ?? null)  ? $_POST['new_password']  : '';
    $new2 = is_string($_POST['new_password2'] ?? null) ? $_POST['new_password2'] : '';
    $error = Auth::changeOwnPassword($new, $new2) ?? '';
    if (!$error) {
        flash('Password impostata correttamente. Benvenuto in '.APP_NAME.'!');
        redirect(APP_URL.'/dashboard.php');
    }
}
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Imposta la tua password — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= h(APP_VERSION) ?>">
</head>
<body>
<div class="login-wrap">
  <div class="login-box" style="max-width:440px">
    <div class="login-logo">
      <span class="ico">&#x1F511;</span>
      <h1><?= h(APP_NAME) ?></h1>
      <p>Imposta la tua password personale</p>
    </div>

    <div class="alert alert-info" style="margin-bottom:16px">
      Ciao <?= h(explode(' ', $u['nome_completo'])[0]) ?>, la password attuale è temporanea.
      Per continuare scegli una nuova password che conoscerai solo tu.
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:16px"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="off" id="forcedPassForm">
      <?= Security::csrfField() ?>
      <input type="text" name="username" value="<?= h($u['username']) ?>" autocomplete="username" hidden>
      <div class="fg" style="margin-bottom:14px">
        <label for="fpNew">Nuova password <span class="req">*</span></label>
        <div class="pw-field">
          <input type="password" id="fpNew" name="new_password" maxlength="72" required autocomplete="new-password" autofocus>
          <button type="button" class="pw-btn" data-pw-toggle="fpNew" aria-label="Mostra/nascondi password" title="Mostra/nascondi">&#x1F441;</button>
        </div>
        <span class="field-note">Min <?= PASSWORD_MIN_LEN ?> caratteri, almeno 1 maiuscola e 1 numero.</span>
      </div>
      <div class="fg" style="margin-bottom:20px">
        <label for="fpNew2">Conferma nuova password <span class="req">*</span></label>
        <div class="pw-field">
          <input type="password" id="fpNew2" name="new_password2" maxlength="72" required autocomplete="new-password">
          <button type="button" class="pw-btn" data-pw-toggle="fpNew2" aria-label="Mostra/nascondi password" title="Mostra/nascondi">&#x1F441;</button>
        </div>
        <span class="pass-hint" id="fpHint"></span>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Salva e continua</button>
    </form>

    <p style="text-align:center;margin-top:18px;font-size:.82rem">
      <a href="<?= APP_URL ?>/logout.php">Esci</a> &middot; <a href="<?= APP_URL ?>/privacy.php">Privacy</a>
    </p>
  </div>
</div>
<script>
(function(){
  var p1=document.getElementById('fpNew'), p2=document.getElementById('fpNew2'), hint=document.getElementById('fpHint');
  function chk(){
    if(!p1.value&&!p2.value){hint.textContent='';hint.className='pass-hint';return;}
    var okP = p1.value.length>=<?= (int)PASSWORD_MIN_LEN ?> && /[A-Z]/.test(p1.value) && /[0-9]/.test(p1.value);
    var ok = okP && p1.value===p2.value;
    hint.textContent = ok ? '✓ Password valida' : (!okP ? '✗ Min <?= (int)PASSWORD_MIN_LEN ?> caratteri, 1 maiuscola e 1 numero' : (p2.value ? '✗ Le password non coincidono' : ''));
    hint.className = 'pass-hint '+(ok?'ok':'err');
  }
  p1.addEventListener('input',chk); p2.addEventListener('input',chk);
})();
</script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= h(APP_VERSION) ?>"></script>
</body>
</html>
