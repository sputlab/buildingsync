<?php
require_once __DIR__.'/../includes/bootstrap.php';
Auth::requireRole(['admin','superadmin']);

$u   = Auth::user();
$isSA = Auth::isSuperAdmin();
$pageTitle = 'Gestione Utenti';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::checkCsrf();
    $action = Security::postStr('action',30);

    if ($action === 'save_user') {
        $id       = Security::postInt('id');
        $username = strtolower(trim(Security::postStr('username', 50)));
        $nomec    = Security::postStr('nome_completo', 120);
        $email    = filter_var(Security::postStr('email', 150), FILTER_VALIDATE_EMAIL) ?: '';
        $ruolo    = Security::postStr('ruolo', 20);
        $attivo   = !empty($_POST['attivo']) ? 1 : 0;
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $pass2    = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $sendMail = !empty($_POST['invia_credenziali']);
        $adminId  = $u['id'];
        if ($isSA && ($sel = Security::postInt('admin_id'))
            && DB::one("SELECT id FROM buildingsync_utenti WHERE id=? AND ruolo IN ('admin','superadmin')", [$sel])) {
            $adminId = $sel;
        }
        if (Security::postStr('email', 150) !== '' && $email === '') { flash('Indirizzo email non valido.','error'); redirect(APP_URL.'/admin/utenti.php'); }

        // Validazioni
        if (!$username || !$nomec) { flash('Username e nome completo sono obbligatori.','error'); redirect(APP_URL.'/admin/utenti.php'); }
        if (!preg_match('/^[a-z0-9_\.]{3,50}$/', $username)) { flash("Username non valido (solo lettere, numeri, punto, underscore, 3-50 caratteri).",'error'); redirect(APP_URL.'/admin/utenti.php'); }
        if (!in_array($ruolo, ['user','capocasa','admin'])) $ruolo = 'user';
        if (!$isSA && $ruolo === 'admin') $ruolo = 'user';

        if ($id) {
            // edit
            $existing = DB::one("SELECT * FROM buildingsync_utenti WHERE id=?".($isSA?'':' AND (admin_id=? OR id=?)'), $isSA?[$id]:[$id,$u['id'],$u['id']]);
            if (!$existing) { flash('Utente non trovato.','error'); redirect(APP_URL.'/admin/utenti.php'); }
            $isSelf = (int)$existing['id'] === (int)$u['id'];
            // Il ruolo di admin/superadmin non si modifica da questa pagina (evita l'auto-declassamento):
            // per gli amministratori usare "Amministratori".
            if ($isSelf || in_array($existing['ruolo'], ['admin','superadmin'], true) && !($isSA && $ruolo === 'admin' && $existing['ruolo'] === 'admin')) {
                if ($isSelf || $existing['ruolo'] === 'superadmin' || !$isSA) $ruolo = $existing['ruolo'];
            }
            if ($isSelf) $attivo = 1; // non ci si può disattivare da soli
            // check username unico
            $dup = DB::one("SELECT id FROM buildingsync_utenti WHERE username=? AND id!=?", [$username,$id]);
            if ($dup) { flash('Username già in uso.','error'); redirect(APP_URL.'/admin/utenti.php'); }

            $telefono = Security::postStr('telefono', 30);
            $nota     = Security::postStr('nota_utente', 1000);
            $data = ['username'=>$username,'nome_completo'=>$nomec,'email'=>$email,'telefono'=>$telefono,'note'=>$nota,'ruolo'=>$ruolo,'attivo'=>$attivo];
            if ($password) {
                $err = Security::validatePassword($password);
                if ($err) { flash($err,'error'); redirect(APP_URL.'/admin/utenti.php'); }
                if ($password !== $pass2) { flash('Le password non coincidono.','error'); redirect(APP_URL.'/admin/utenti.php'); }
                $data['password_hash'] = Security::hashPassword($password);
                // Password decisa dall'admin: l'utente dovrà cambiarla al prossimo accesso
                $data['password_da_cambiare'] = $isSelf ? 0 : 1;
            }
            if ($isSA && !in_array($existing['ruolo'], ['admin','superadmin'], true)) $data['admin_id'] = $adminId;
            DB::update('buildingsync_utenti', $data, 'id=?', [$id]);
            if (!empty($data['password_hash'])) {
                // Nuova password: sblocca l'account
                DB::update('buildingsync_utenti', ['login_attempts'=>0,'locked_until'=>null], 'id=?', [$id]);
            }
            flash('Utente aggiornato.');
        } else {
            // create
            if (!$password) { flash('La password è obbligatoria per i nuovi utenti.','error'); redirect(APP_URL.'/admin/utenti.php'); }
            $err = Security::validatePassword($password);
            if ($err) { flash($err,'error'); redirect(APP_URL.'/admin/utenti.php'); }
            if ($password !== $pass2) { flash('Le password non coincidono.','error'); redirect(APP_URL.'/admin/utenti.php'); }
            $dup = DB::one("SELECT id FROM buildingsync_utenti WHERE username=?", [$username]);
            if ($dup) { flash('Username già in uso.','error'); redirect(APP_URL.'/admin/utenti.php'); }

            $telefono = Security::postStr('telefono', 30);
            $nota     = Security::postStr('nota_utente', 1000);
            DB::insert('buildingsync_utenti', [
                'username'      => $username,
                'password_hash' => Security::hashPassword($password),
                'password_da_cambiare' => 1,
                'nome_completo' => $nomec,
                'email'         => $email,
                'telefono'      => $telefono,
                'note'          => $nota,
                'ruolo'         => $ruolo,
                'admin_id'      => $adminId,
                'attivo'        => $attivo,
                'creato_il'     => date('Y-m-d H:i:s'),
            ]);
            if ($sendMail && $email) {
                Mailer::notifyAccountCreated(['username'=>$username,'nome_completo'=>$nomec,'email'=>$email], $password);
                flash('Utente creato. Credenziali inviate a '.$email.'.');
            } else {
                flash('Utente creato con successo.');
            }
        }
        redirect(APP_URL.'/admin/utenti.php');
    }

    if ($action === 'delete_user') {
        $id = Security::postInt('id');
        if ($id === (int)$u['id']) { flash('Non puoi eliminare te stesso.','error'); redirect(APP_URL.'/admin/utenti.php'); }
        $existing = DB::one("SELECT id,ruolo FROM buildingsync_utenti WHERE id=?".($isSA?'':' AND admin_id=?'), $isSA?[$id]:[$id,$u['id']]);
        if ($existing && !in_array($existing['ruolo'], ['superadmin','admin'], true)) {
            $files = DB::all(
                "SELECT a.filename FROM buildingsync_segnalazioni_allegati a
                 LEFT JOIN buildingsync_segnalazioni s ON s.id=a.segnalazione_id
                 WHERE a.utente_id=? OR s.utente_id=?", [$id, $id]
            );
            try {
                DB::delete('buildingsync_utenti', 'id=?', [$id]);
                foreach ($files as $f) @unlink(UPLOAD_PATH.basename($f['filename']));
                Auth::logAction('delete_user', 'utente_id='.$id);
                flash('Utente eliminato.');
            } catch (PDOException $e) {
                error_log('delete_user: '.$e->getMessage());
                flash('Impossibile eliminare l\'utente: ci sono dati collegati. Disattivalo invece di eliminarlo.', 'error');
            }
        } elseif ($existing) {
            flash('Gli amministratori si gestiscono dalla pagina Amministratori.', 'error');
        }
        redirect(APP_URL.'/admin/utenti.php');
    }
    if ($action === 'toggle_active') {
        $id = Security::postInt('id');
        if ($id !== (int)$u['id']) {
            $existing = DB::one("SELECT id,attivo FROM buildingsync_utenti WHERE id=?".($isSA?'':' AND admin_id=?'), $isSA?[$id]:[$id,$u['id']]);
            if ($existing) DB::update('buildingsync_utenti', ['attivo'=>$existing['attivo']?0:1], 'id=?', [$id]);
        }
        redirect(APP_URL.'/admin/utenti.php');
    }
    redirect(APP_URL.'/admin/utenti.php');
}

// ---- GET ----
$editId   = Security::getInt('edit');
$editUser = null;
if ($editId) {
    $editUser = DB::one("SELECT * FROM buildingsync_utenti WHERE id=?".($isSA?'':' AND (admin_id=? OR id=?)'), $isSA?[$editId]:[$editId,$u['id'],$u['id']]);
}

$search = Security::str('q', 'GET', 100);
$page   = max(1, Security::getInt('page') ?: 1);
$limit  = 25;
$offset = ($page-1)*$limit;

if ($isSA) {
    $whereStr = $search ? "WHERE u.ruolo != 'superadmin' AND (u.username LIKE ? OR u.nome_completo LIKE ? OR u.email LIKE ?)" : "WHERE u.ruolo != 'superadmin'";
    $params   = $search ? ["%$search%","%$search%","%$search%"] : [];
} else {
    $whereStr = $search ? "WHERE u.admin_id=? AND u.ruolo != 'superadmin' AND (u.username LIKE ? OR u.nome_completo LIKE ?)" : "WHERE u.admin_id=? AND u.ruolo != 'superadmin'";
    $params   = $search ? [$u['id'],"%$search%","%$search%"] : [$u['id']];
}

$total   = DB::one("SELECT COUNT(*) n FROM buildingsync_utenti u $whereStr", $params)['n'] ?? 0;
$users   = DB::all("SELECT u.id, u.username, u.nome_completo, u.email, u.telefono, u.note, u.ruolo, u.admin_id, u.attivo, u.last_login, u.locked_until,
                           a.nome_completo admin_nome
                    FROM buildingsync_utenti u LEFT JOIN buildingsync_utenti a ON a.id=u.admin_id
                    $whereStr ORDER BY u.nome_completo LIMIT $limit OFFSET $offset", $params);
$userJsFields = ['id','username','nome_completo','email','telefono','note','ruolo','admin_id','attivo'];
$totalPages = (int)ceil($total/$limit);

$allAdmins = $isSA ? DB::all("SELECT id,nome_completo FROM buildingsync_utenti WHERE ruolo IN ('admin','superadmin') AND attivo=1 ORDER BY nome_completo") : [];

include INCLUDES_PATH.'/header.php';
?>

<div class="page-hd">
  <div>
    <h1>👥 Gestione Utenti</h1>
    <p class="sub">Totale: <?= $total ?> utenti</p>
  </div>
  <button class="btn btn-primary" onclick="resetUserModal()">+ Nuovo utente</button>
</div>

<!-- Ricerca -->
<form method="GET" class="flex mb">
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cerca per nome, username, email..." style="flex:1;max-width:400px">
  <button type="submit" class="btn btn-secondary">🔍 Cerca</button>
  <?php if ($search): ?><a href="?" class="btn btn-secondary">✕ Reset</a><?php endif; ?>
</form>

<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Nome</th>
        <th>Username</th>
        <th>Email</th>
        <th>Telefono</th>
        <th>Ruolo</th>
        <?php if ($isSA): ?><th>Admin</th><?php endif; ?>
        <th>Stato</th>
        <th>Ultimo accesso</th>
        <th>Azioni</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
      <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--gray-400)">Nessun utente trovato</td></tr>
      <?php else: foreach($users as $usr): ?>
      <tr>
        <td><strong><?= h($usr['nome_completo']) ?></strong></td>
        <td class="muted">@<?= h($usr['username']) ?></td>
        <td class="muted"><?= h($usr['email']) ?></td>
        <td class="muted"><?= h($usr['telefono'] ?: '—') ?></td>
        <td><?= badge($usr['ruolo'],'role') ?></td>
        <?php if ($isSA): ?><td class="muted"><?= h($usr['admin_nome']??'—') ?></td><?php endif; ?>
        <td><?= $usr['attivo']?'<span class="badge badge-done">Attivo</span>':'<span class="badge badge-closed">Inattivo</span>' ?>
          <?php if ($usr['locked_until'] && strtotime($usr['locked_until']) > time()): ?><span class="badge badge-high" title="Troppi tentativi di accesso errati">Bloccato</span><?php endif; ?></td>
        <td class="muted"><?= $usr['last_login'] ? formatDate($usr['last_login'],'d/m/Y H:i') : '—' ?></td>
        <td class="td-act">
          <button class="btn btn-sm btn-secondary btn-icon" onclick="openEditUser(<?= jsAttr(pick($usr,$userJsFields)) ?>)" title="Modifica">✏</button>
          <form method="POST" style="display:inline">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
            <button type="submit" class="btn btn-sm <?= $usr['attivo']?'btn-warn':'btn-success' ?>" title="<?= $usr['attivo']?'Disattiva':'Attiva' ?>">
              <?= $usr['attivo']?'⏸':'▶' ?>
            </button>
          </form>
          <?php if ((int)$usr['id'] !== (int)$u['id'] && !in_array($usr['ruolo'],['superadmin','admin'],true)): ?>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete(<?= jsAttr('Eliminare l\'utente '.$usr['nome_completo'].'? Verranno eliminati anche i suoi post e le sue segnalazioni.') ?>)">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Elimina">🗑</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Paginazione -->
<?php if ($totalPages > 1): ?>
<?php $page = (int)$page; ?>
<div class="pager">
  <?php if ($page>1): ?><a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>">← Prec.</a><?php endif; ?>
  <?php for($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
  <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="<?= $i===$page?'cur':'' ?>"><?= $i ?></a>
  <?php endfor; ?>
  <?php if ($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>">Succ. →</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- Modal create/edit utente -->
<div class="overlay" id="modalUser">
  <div class="modal" style="max-width:580px">
    <div class="modal-head">
      <span class="modal-title" id="modalUserTitle">Nuovo Utente</span>
      <button class="modal-close" onclick="closeModal('modalUser')">×</button>
    </div>
    <form method="POST" id="userForm">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_user">
      <input type="hidden" name="id" id="userId" value="0">
      <div class="form-grid">
        <div class="fg">
          <label>Username <span class="req">*</span></label>
          <input type="text" name="username" id="uUsername" maxlength="50" required pattern="[a-z0-9_\.]{3,50}" autocapitalize="none"
                 placeholder="Es. mario.rossi">
          <span class="hint">3-50 caratteri: lettere, numeri, punto, _</span>
        </div>
        <div class="fg">
          <label>Nome completo <span class="req">*</span></label>
          <input type="text" name="nome_completo" id="uNome" maxlength="120" required>
        </div>
        <div class="fg">
          <label>Email</label>
          <input type="email" name="email" id="uEmail" maxlength="150">
        </div>
        <div class="fg">
          <label>Telefono</label>
          <input type="text" name="telefono" id="uTel" maxlength="30" placeholder="+39 000 0000000">
        </div>
        <div class="fg">
          <label>Ruolo</label>
          <select name="ruolo" id="uRuolo">
            <option value="user">Condomino</option>
            <option value="capocasa">Capocasa</option>
            <?php if ($isSA): ?><option value="admin">Amministratore</option><?php endif; ?>
          </select>
        </div>
        <?php if ($isSA && !empty($allAdmins)): ?>
        <div class="fg">
          <label>Admin di riferimento</label>
          <select name="admin_id" id="uAdmin">
            <option value="">— Me stesso —</option>
            <?php foreach($allAdmins as $a): ?>
            <option value="<?= $a['id'] ?>"><?= h($a['nome_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="fg" style="justify-content:flex-end;padding-top:22px">
          <label><input type="checkbox" name="attivo" id="uAttivo" value="1" checked> Attivo</label>
        </div>
        <div class="fg full">
          <label>Note</label>
          <textarea name="nota_utente" id="uNota" rows="3" maxlength="1000" placeholder="Informazioni aggiuntive..."></textarea>
          <span class="field-note">Attenzione: questo campo è visibile e modificabile anche dall'utente nel proprio profilo.</span>
        </div>
        <div class="fg">
          <label>Password <span class="req" id="passReq">*</span></label>
<div class="pw-field">
            <input type="password" name="password" id="uPass" maxlength="72" autocomplete="new-password">
            <button type="button" class="pw-btn" data-pw-toggle="uPass" aria-label="Mostra/nascondi password" title="Mostra/nascondi">&#x1F441;</button>
            <button type="button" class="pw-btn" data-pw-copy="uPass" aria-label="Copia password negli appunti" title="Copia negli appunti">&#x1F4CB;</button>
          </div>
          <button type="button" class="btn btn-sm btn-outline pw-gen" data-pw-gen="uPass,uPass2">&#x1F3B2; Genera password sicura</button>
          <span class="field-note" id="passCriteria" style="display:none">Min <?= PASSWORD_MIN_LEN ?> car., almeno 1 maiuscola e 1 numero</span>
          <span class="pass-hint" id="passOkHint"></span>
        </div>
        <div class="fg">
          <label>Conferma password</label>
<div class="pw-field">
            <input type="password" name="password2" id="uPass2" maxlength="72" autocomplete="new-password">
            <button type="button" class="pw-btn" data-pw-toggle="uPass2" aria-label="Mostra/nascondi password" title="Mostra/nascondi">&#x1F441;</button>
          </div>
          <span class="pass-hint" id="passMatchHint"></span>
        </div>
        <div class="fg full">
          <span class="field-note">&#x1F512; Al primo accesso l'utente dovrà obbligatoriamente sostituire la password impostata qui.</span>
        </div>
        <div class="fg full" id="sendCredWrap">
          <label class="check-label">
            <input type="checkbox" name="invia_credenziali" id="uSendCred" value="1">
            Invia username e password all'utente via email (solo per i nuovi utenti con email)
          </label>
        </div>
      </div>
      <div class="form-actions mt">
        <button type="submit" class="btn btn-primary" id="userFormSubmit">Salva</button>
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalUser')">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditUser(user) {
  document.getElementById('modalUserTitle').textContent = 'Modifica Utente';
  document.getElementById('userId').value    = user.id;
  document.getElementById('uUsername').value = user.username;
  document.getElementById('uNome').value     = user.nome_completo;
  document.getElementById('uEmail').value    = user.email || '';
  document.getElementById('uRuolo').value    = user.ruolo;
  document.getElementById('uAttivo').checked = user.attivo == 1;
  document.getElementById('uTel').value      = user.telefono || '';
  document.getElementById('uNota').value     = user.note || '';
  document.getElementById('uPass').value     = '';
  document.getElementById('uPass2').value    = '';
  document.getElementById('passReq').textContent = '(opzionale in modifica)';
  document.getElementById('passReq').style.color = 'var(--gray-400)';
  var okH=document.getElementById('passOkHint'); if(okH){okH.textContent='';okH.className='pass-hint';}
  var mH=document.getElementById('passMatchHint'); if(mH){mH.textContent='';mH.className='pass-hint';}
  var cr=document.getElementById('passCriteria'); if(cr) cr.style.display='none';
  var p1=document.getElementById('uPass'); if(p1){p1.classList.remove('pass-match-ok','pass-match-err');}
  var p2=document.getElementById('uPass2'); if(p2){p2.classList.remove('pass-match-ok','pass-match-err');}
  isEditing = true;
  var sc=document.getElementById('sendCredWrap'); if(sc) sc.style.display='none';
  <?php if ($isSA): ?>
  const adminSel = document.getElementById('uAdmin');
  if (adminSel) adminSel.value = user.admin_id || '';
  <?php endif; ?>
  openModal('modalUser');
}

function resetUserModal(){
  isEditing=false;
  var sc=document.getElementById('sendCredWrap'); if(sc) sc.style.display='';
  var scb=document.getElementById('uSendCred'); if(scb) scb.checked=false;
  document.getElementById('modalUserTitle').textContent='Nuovo Utente';
  document.getElementById('userId').value='0';
  ['uUsername','uNome','uEmail','uTel','uNota','uPass','uPass2'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
  document.getElementById('uRuolo').value='user';
  document.getElementById('uAttivo').checked=true;
  var pr=document.getElementById('passReq');if(pr){pr.textContent='*';pr.style.color='var(--red)';}
  var okH=document.getElementById('passOkHint');if(okH){okH.textContent='';okH.className='pass-hint';}
  var mH=document.getElementById('passMatchHint');if(mH){mH.textContent='';mH.className='pass-hint';}
  var cr=document.getElementById('passCriteria');if(cr)cr.style.display='none';
  var p1=document.getElementById('uPass');if(p1)p1.classList.remove('pass-match-ok','pass-match-err');
  var p2=document.getElementById('uPass2');if(p2)p2.classList.remove('pass-match-ok','pass-match-err');
  var sub=document.getElementById('userFormSubmit');if(sub)sub.disabled=false;
  <?php if ($isSA): ?>var adminSel=document.getElementById('uAdmin');if(adminSel)adminSel.value='';<?php endif; ?>
  openModal('modalUser');
}

<?php if ($editUser): ?>
document.addEventListener('DOMContentLoaded', function(){ openEditUser(<?= jsVal(pick($editUser,$userJsFields ?? ['id','username','nome_completo','email','telefono','note','ruolo','admin_id','attivo'])) ?>); });
<?php endif; ?>

// ── Verifica password in tempo reale ────────────────────────────
var MIN_LEN = <?= PASSWORD_MIN_LEN ?>;
var isEditing = false; // true quando si modifica un utente esistente

function checkPass() {
  var p1       = document.getElementById('uPass').value;
  var p2       = document.getElementById('uPass2').value;
  var p1El     = document.getElementById('uPass');
  var p2El     = document.getElementById('uPass2');
  var okHint   = document.getElementById('passOkHint');
  var matchHint= document.getElementById('passMatchHint');
  var criteria = document.getElementById('passCriteria');
  var submit   = document.getElementById('userFormSubmit');

  // Se in modifica e password vuota: nessun controllo
  if (isEditing && !p1 && !p2) {
    [p1El,p2El].forEach(function(el){ el.classList.remove('pass-match-ok','pass-match-err'); });
    if(okHint)    { okHint.textContent=''; okHint.className='pass-hint'; }
    if(matchHint) { matchHint.textContent=''; matchHint.className='pass-hint'; }
    if(criteria)  criteria.style.display='none';
    if(submit)    submit.disabled=false;
    return;
  }

  var minOk  = p1.length >= MIN_LEN;
  var hasUC  = /[A-Z]/.test(p1);
  var hasNum = /[0-9]/.test(p1);
  var passOk = minOk && hasUC && hasNum;
  var match  = p1===p2 && p1.length>0;

  // Mostra criteri appena si digita nella password
  if (p1.length > 0 && criteria) criteria.style.display='block';
  else if (!p1.length && criteria) criteria.style.display='none';

  // Feedback sotto il campo Password
  p1El.classList.remove('pass-match-ok','pass-match-err');
  if (p1.length > 0) {
    if (passOk) {
      p1El.classList.add('pass-match-ok');
      if(okHint){ okHint.textContent='✓ Password ok'; okHint.className='pass-hint ok'; }
    } else {
      p1El.classList.add('pass-match-err');
      var errs=[];
      if(!minOk)  errs.push('min '+MIN_LEN+' caratteri');
      if(!hasUC)  errs.push('1 maiuscola');
      if(!hasNum) errs.push('1 numero');
      if(okHint){ okHint.textContent='✗ Richiesto: '+errs.join(', '); okHint.className='pass-hint err'; }
    }
  } else {
    if(okHint){ okHint.textContent=''; okHint.className='pass-hint'; }
  }

  // Feedback sotto la Conferma Password
  p2El.classList.remove('pass-match-ok','pass-match-err');
  if (p2.length > 0) {
    if (match && passOk) {
      p2El.classList.add('pass-match-ok');
      if(matchHint){ matchHint.textContent='✓ Le password coincidono'; matchHint.className='pass-hint ok'; }
    } else {
      p2El.classList.add('pass-match-err');
      if(matchHint){ matchHint.textContent=(!match?'✗ Le password non coincidono':'✗ Correggi la password'); matchHint.className='pass-hint err'; }
    }
  } else {
    if(matchHint){ matchHint.textContent=''; matchHint.className='pass-hint'; }
  }

  // Abilita/disabilita submit
  if(submit){
    if(isEditing && !p1) { submit.disabled=false; }
    else { submit.disabled = !(passOk && match); }
  }
}

document.getElementById('uPass')?.addEventListener('input', checkPass);
document.getElementById('uPass2')?.addEventListener('input', checkPass);

// Form submit: blocca se password inserita ma invalida/non coincidente
document.getElementById('userForm')?.addEventListener('submit', function(e){
  var p1 = document.getElementById('uPass').value;
  var p2 = document.getElementById('uPass2').value;
  if (!p1) return; // in modifica, password vuota = non cambia
  var minOk=/[A-Z]/.test(p1)&&/[0-9]/.test(p1)&&p1.length>=MIN_LEN;
  if (!minOk || p1!==p2) {
    e.preventDefault();
    checkPass();
    document.getElementById('uPass').focus();
  }
});
</script>

<?php include INCLUDES_PATH.'/footer.php'; ?>
