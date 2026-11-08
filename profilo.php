<?php
require_once __DIR__.'/includes/bootstrap.php';
Auth::requireLogin();

$u = Auth::user();
$pageTitle = 'Il mio profilo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $action = Security::postStr('action', 30);

    if ($action === 'update_profile') {
        $nomec = Security::postStr('nome_completo', 120);
        $email = filter_var(Security::postStr('email', 150), FILTER_VALIDATE_EMAIL) ?: '';
        $telefono = Security::postStr('telefono', 30);
        $nota     = Security::postStr('nota_utente', 1000);
        $notif    = !empty($_POST['email_notifiche']) ? 1 : 0;
        if (!$nomec) { flash('Il nome completo è obbligatorio.', 'error'); redirect(APP_URL.'/profilo.php'); }
        if (Security::postStr('email', 150) !== '' && $email === '') { flash('Indirizzo email non valido.', 'error'); redirect(APP_URL.'/profilo.php'); }
        DB::update('buildingsync_utenti', ['nome_completo'=>$nomec,'email'=>$email,'telefono'=>$telefono,'note'=>$nota,'email_notifiche'=>$notif], 'id=?', [$u['id']]);
        flash('Profilo aggiornato.');
        redirect(APP_URL.'/profilo.php');
    }

    if ($action === 'change_password') {
        $old  = is_string($_POST['old_password'] ?? null)  ? $_POST['old_password']  : '';
        $new  = is_string($_POST['new_password'] ?? null)  ? $_POST['new_password']  : '';
        $new2 = is_string($_POST['new_password2'] ?? null) ? $_POST['new_password2'] : '';

        $dbUser = DB::one("SELECT password_hash FROM buildingsync_utenti WHERE id=?", [$u['id']]);
        if (!password_verify($old, $dbUser['password_hash'])) {
            flash('La password attuale non è corretta.', 'error');
            redirect(APP_URL.'/profilo.php');
        }
        $err = Auth::changeOwnPassword($new, $new2);
        if ($err) { flash($err, 'error'); redirect(APP_URL.'/profilo.php'); }
        flash('Password modificata con successo.');
        redirect(APP_URL.'/profilo.php');
    }
    redirect(APP_URL.'/profilo.php');
}

// Dati aggiornati dal DB
$profile  = DB::one("SELECT * FROM buildingsync_utenti WHERE id=?", [$u['id']]);
$myBuilds = Auth::myBuildings();
$nPost    = 0; $nSeg = 0;
foreach ($myBuilds as $b) {
    $nPost += DB::one("SELECT COUNT(*) n FROM buildingsync_bacheca_post WHERE stabile_id=? AND utente_id=?", [$b['id'],$u['id']])['n'] ?? 0;
    $nSeg  += DB::one("SELECT COUNT(*) n FROM buildingsync_segnalazioni WHERE stabile_id=? AND utente_id=?", [$b['id'],$u['id']])['n'] ?? 0;
}

include INCLUDES_PATH.'/header.php';
?>

<div class="page-hd">
  <div>
    <h1>Il mio profilo</h1>
    <p class="sub"><?= h(roleLabel($profile['ruolo'])) ?> — account creato il <?= formatDate($profile['creato_il']) ?></p>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">

<!-- Dati profilo -->
<div>
  <div class="card">
    <div class="card-head"><span class="card-title">Dati personali</span></div>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="update_profile">
      <div class="form-grid">
        <div class="fg">
          <label>Username</label>
          <input type="text" value="@<?= h($profile['username']) ?>" disabled>
          <span class="field-note">L'username non può essere modificato.</span>
        </div>
        <div class="fg">
          <label>Ruolo</label>
          <input type="text" value="<?= h(roleLabel($profile['ruolo'])) ?>" disabled>
        </div>
        <div class="fg">
          <label for="pNome">Nome completo <span class="req">*</span></label>
          <input type="text" id="pNome" name="nome_completo"
                 value="<?= h($profile['nome_completo']) ?>" maxlength="120" required>
        </div>
        <div class="fg">
          <label for="pEmail">Email</label>
          <input type="email" id="pEmail" name="email"
                 value="<?= h($profile['email']) ?>" maxlength="150">
        </div>
        <div class="fg">
          <label for="pTel">Telefono</label>
          <input type="text" id="pTel" name="telefono"
                 value="<?= h($profile['telefono'] ?? '') ?>" maxlength="30"
                 placeholder="+39 000 0000000">
        </div>
        <div class="fg full">
          <label class="check-label">
            <input type="checkbox" name="email_notifiche" value="1" <?= !empty($profile['email_notifiche']) ? 'checked' : '' ?>>
            Ricevi notifiche via email (nuovi messaggi, segnalazioni, attività)
          </label>
          <span class="field-note">Le email di reset password vengono inviate comunque.</span>
        </div>
        <div class="fg full">
          <label for="pNota">Nota personale</label>
          <textarea id="pNota" name="nota_utente" rows="3" maxlength="1000"
                    placeholder="Informazioni aggiuntive..."><?= h($profile['note'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva modifiche</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><span class="card-title">Modifica password</span></div>
    <form method="POST" autocomplete="off">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="form-grid">
        <div class="fg full">
          <label for="pOld">Password attuale <span class="req">*</span></label>
          <input type="password" id="pOld" name="old_password" maxlength="200" required autocomplete="current-password">
        </div>
        <div class="fg">
          <label for="pNew">Nuova password <span class="req">*</span></label>
          <input type="password" id="pNew" name="new_password" maxlength="200" required autocomplete="new-password">
          <span class="field-note">Min <?= PASSWORD_MIN_LEN ?> caratteri, almeno 1 maiuscola e 1 numero.</span>
        </div>
        <div class="fg">
          <label for="pNew2">Conferma nuova password <span class="req">*</span></label>
          <input type="password" id="pNew2" name="new_password2" maxlength="200" required autocomplete="new-password">
          <span class="pass-hint" id="profiloPassHint"></span>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary" id="profiloPassSubmit">Cambia password</button>
      </div>
    </form>
    <script>
    document.getElementById('pNew')?.addEventListener('input', checkProfPass);
    document.getElementById('pNew2')?.addEventListener('input', checkProfPass);
    function checkProfPass(){
      var p1=document.getElementById('pNew').value;
      var p2=document.getElementById('pNew2').value;
      var hint=document.getElementById('profiloPassHint');
      if(!p1&&!p2){hint.textContent='';return;}
      var ok=p1.length>=<?= PASSWORD_MIN_LEN ?> && /[A-Z]/.test(p1) && /[0-9]/.test(p1) && p1===p2;
      hint.textContent=ok?'✓ Password valida':(!p2.length?'':'✗ '+(p1!==p2?'Le password non coincidono':p1.length<<?= PASSWORD_MIN_LEN ?>?'Troppo corta':'Manca maiuscola o numero'));
      hint.className='pass-hint '+(ok?'ok':'err');
    }
    </script>
  </div>
</div>

<!-- Riepilogo -->
<div>
  <div class="card">
    <div class="card-head"><span class="card-title">Riepilogo attività</span></div>
    <div class="summary-list">
      <div class="summary-row">
        <span class="summary-label">Stabili associati</span>
        <span class="summary-val"><?= count($myBuilds) ?></span>
      </div>
      <div class="summary-row">
        <span class="summary-label">Post in bacheca</span>
        <span class="summary-val"><?= $nPost ?></span>
      </div>
      <div class="summary-row">
        <span class="summary-label">Segnalazioni inviate</span>
        <span class="summary-val"><?= $nSeg ?></span>
      </div>
      <div class="summary-row">
        <span class="summary-label">Ultimo accesso</span>
        <span class="summary-val"><?= $profile['last_login'] ? formatDate($profile['last_login'], 'd/m/Y H:i') : '—' ?></span>
      </div>
    </div>
  </div>

  <?php if (!empty($myBuilds)): ?>
  <div class="card">
    <div class="card-head"><span class="card-title">I miei stabili</span></div>
    <?php foreach ($myBuilds as $b): ?>
    <a href="<?= APP_URL ?>/stabile.php?id=<?= $b['id'] ?>"
       class="building-link">
      <span><?= h($b['nome']) ?></span>
      <span class="muted">&rsaquo;</span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</div>

<?php include INCLUDES_PATH.'/footer.php'; ?>
