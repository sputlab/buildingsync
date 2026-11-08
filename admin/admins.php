<?php
require_once __DIR__.'/../includes/bootstrap.php';
Auth::requireRole(['superadmin']);

$u = Auth::user();
$pageTitle = 'Gestione Amministratori';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $action = Security::postStr('action', 30);

    if ($action === 'save_admin') {
        $id       = Security::postInt('id');
        $username = strtolower(trim(Security::postStr('username', 50)));
        $nomec    = Security::postStr('nome_completo', 120);
        $email    = filter_var(Security::postStr('email', 150), FILTER_VALIDATE_EMAIL) ?: '';
        $attivo   = !empty($_POST['attivo']) ? 1 : 0;
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $pass2    = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
        $sendMail = !empty($_POST['invia_credenziali']);
        if (Security::postStr('email', 150) !== '' && $email === '') {
            flash('Indirizzo email non valido.', 'error');
            redirect(APP_URL.'/admin/admins.php');
        }

        if (!$username || !$nomec) {
            flash('Username e nome completo sono obbligatori.', 'error');
            redirect(APP_URL.'/admin/admins.php');
        }
        if (!preg_match('/^[a-z0-9_\.]{3,50}$/', $username)) {
            flash('Username non valido (3-50 caratteri: lettere minuscole, numeri, punto, underscore).', 'error');
            redirect(APP_URL.'/admin/admins.php');
        }

        if ($id) {
            $existing = DB::one("SELECT * FROM buildingsync_utenti WHERE id=? AND ruolo IN ('admin','superadmin')", [$id]);
            if (!$existing) { flash('Amministratore non trovato.', 'error'); redirect(APP_URL.'/admin/admins.php'); }
            $dup = DB::one("SELECT id FROM buildingsync_utenti WHERE username=? AND id!=?", [$username, $id]);
            if ($dup) { flash('Username già in uso.', 'error'); redirect(APP_URL.'/admin/admins.php'); }

            $ruoloNuovo = Security::postEnum('ruolo_nuovo', ['admin','superadmin'], $existing['ruolo']);
            if ((int)$existing['id'] === (int)$u['id']) { $ruoloNuovo = 'superadmin'; $attivo = 1; } // niente auto-declassamento
            $data = ['username'=>$username,'nome_completo'=>$nomec,'email'=>$email,'attivo'=>$attivo,'ruolo'=>$ruoloNuovo];
            if ($password) {
                $err = Security::validatePassword($password);
                if ($err) { flash($err, 'error'); redirect(APP_URL.'/admin/admins.php'); }
                if ($password !== $pass2) { flash('Le password non coincidono.', 'error'); redirect(APP_URL.'/admin/admins.php'); }
                $data['password_hash']  = Security::hashPassword($password);
                $data['password_da_cambiare'] = ((int)$existing['id'] === (int)$u['id']) ? 0 : 1;
                $data['login_attempts'] = 0;
                $data['locked_until']   = null;
            }
            DB::update('buildingsync_utenti', $data, 'id=?', [$id]);
            flash('Amministratore aggiornato.');
        } else {
            if (!$password) { flash('La password è obbligatoria.', 'error'); redirect(APP_URL.'/admin/admins.php'); }
            $err = Security::validatePassword($password);
            if ($err) { flash($err, 'error'); redirect(APP_URL.'/admin/admins.php'); }
            if ($password !== $pass2) { flash('Le password non coincidono.', 'error'); redirect(APP_URL.'/admin/admins.php'); }
            $dup = DB::one("SELECT id FROM buildingsync_utenti WHERE username=?", [$username]);
            if ($dup) { flash('Username già in uso.', 'error'); redirect(APP_URL.'/admin/admins.php'); }

            $ruoloNuovo = Security::postEnum('ruolo_nuovo', ['admin','superadmin'], 'admin');
            DB::insert('buildingsync_utenti', [
                'username'      => $username,
                'password_hash' => Security::hashPassword($password),
                'password_da_cambiare' => 1,
                'email'         => $email,
                'nome_completo' => $nomec,
                'ruolo'         => $ruoloNuovo,
                'admin_id'      => null,
                'attivo'        => $attivo,
                'creato_il'     => date('Y-m-d H:i:s'),
            ]);
            if ($sendMail && $email) {
                Mailer::notifyAccountCreated(['username'=>$username,'nome_completo'=>$nomec,'email'=>$email], $password);
                flash('Amministratore creato. Credenziali inviate a '.$email.'.');
            } else {
                flash('Amministratore creato.');
            }
        }
        redirect(APP_URL.'/admin/admins.php');
    }

    if ($action === 'delete_admin') {
        $id = Security::postInt('id');
        if ($id === (int)$u['id']) { flash('Non puoi eliminare te stesso.', 'error'); redirect(APP_URL.'/admin/admins.php'); }
        $existing = DB::one("SELECT id FROM buildingsync_utenti WHERE id=? AND ruolo IN ('admin','superadmin')", [$id]);
        if ($existing) {
            // Conta stabili associati
            $nStabili = DB::one("SELECT COUNT(*) n FROM buildingsync_stabili WHERE admin_id=?", [$id])['n'] ?? 0;
            if ($nStabili > 0) {
                flash("Impossibile eliminare: questo amministratore gestisce $nStabili stabile/i. Riassegnarli prima.", 'error');
                redirect(APP_URL.'/admin/admins.php');
            }
            try {
                DB::delete('buildingsync_utenti', 'id=?', [$id]);
                Auth::logAction('delete_admin', 'utente_id='.$id);
                flash('Amministratore eliminato.');
            } catch (PDOException $e) {
                // es. attività create da questo admin (vincolo RESTRICT)
                error_log('delete_admin: '.$e->getMessage());
                flash('Impossibile eliminare: ci sono dati collegati (es. attività create). Disattivarlo invece di eliminarlo.', 'error');
            }
        }
        redirect(APP_URL.'/admin/admins.php');
    }

    if ($action === 'toggle_active') {
        $id = Security::postInt('id');
        if ($id !== (int)$u['id']) {
            $existing = DB::one("SELECT id,attivo FROM buildingsync_utenti WHERE id=? AND ruolo IN ('admin','superadmin')", [$id]);
            if ($existing) DB::update('buildingsync_utenti', ['attivo' => $existing['attivo'] ? 0 : 1], 'id=?', [$id]);
        }
        redirect(APP_URL.'/admin/admins.php');
    }
    redirect(APP_URL.'/admin/admins.php');
}

// --- GET ---
$editId    = Security::getInt('edit');
$adminJsFields = ['id','username','nome_completo','email','ruolo','attivo'];
$editAdmin = $editId ? DB::one("SELECT id, username, nome_completo, email, ruolo, attivo FROM buildingsync_utenti WHERE id=? AND ruolo IN ('admin','superadmin')", [$editId]) : null;

$admins = DB::all(
    "SELECT u.id, u.username, u.nome_completo, u.email, u.ruolo, u.attivo, u.last_login, u.locked_until,
        (SELECT COUNT(*) FROM buildingsync_stabili s WHERE s.admin_id=u.id) n_stabili,
        (SELECT COUNT(*) FROM buildingsync_utenti u2 WHERE u2.admin_id=u.id AND u2.ruolo != 'superadmin') n_utenti
     FROM buildingsync_utenti u
     WHERE u.ruolo IN ('admin','superadmin') AND u.id != ?
     ORDER BY u.ruolo DESC, u.nome_completo",
    [$u['id']]
);

include INCLUDES_PATH.'/header.php';
?>

<div class="page-hd">
  <div>
    <h1>Amministratori</h1>
    <p class="sub">Amministratori e Super Admin del sistema — <?= count($admins) ?> totali</p>
  </div>
  <button class="btn btn-primary" onclick="resetAdminModal()">+ Nuovo amministratore</button>
</div>

<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Nome completo</th>
        <th>Username</th>
        <th>Ruolo</th>
        <th>Email</th>
        <th>Stabili</th>
        <th>Utenti</th>
        <th>Stato</th>
        <th>Ultimo accesso</th>
        <th>Azioni</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($admins)): ?>
      <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted)">Nessun amministratore creato</td></tr>
      <?php else: foreach ($admins as $adm): ?>
      <tr>
        <td><strong><?= h($adm['nome_completo']) ?></strong></td>
        <td class="muted">@<?= h($adm['username']) ?></td>
        <td><?= badge($adm['ruolo'],'role') ?></td>
        <td class="muted"><?= h($adm['email'] ?: '—') ?></td>
        <td><?= (int)$adm['n_stabili'] ?></td>
        <td><?= (int)$adm['n_utenti'] ?></td>
        <td>
          <?php if ($adm['attivo']): ?>
            <span class="badge badge-ok">Attivo</span>
          <?php else: ?>
            <span class="badge badge-off">Inattivo</span>
          <?php endif; ?>
          <?php if ($adm['locked_until'] && strtotime($adm['locked_until']) > time()): ?><span class="badge badge-high">Bloccato</span><?php endif; ?>
        </td>
        <td class="muted"><?= $adm['last_login'] ? formatDate($adm['last_login'], 'd/m/Y H:i') : '—' ?></td>
        <td>
          <div class="row-actions">
            <button class="btn btn-sm btn-outline"
                    onclick="fillAdminModal(<?= jsAttr(pick($adm,$adminJsFields)) ?>)"
                    title="Modifica">Modifica</button>
            <form method="POST" style="display:inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= $adm['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline"><?= $adm['attivo'] ? 'Disattiva' : 'Attiva' ?></button>
            </form>
            <?php if ((int)$adm['id'] !== (int)$u['id']): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm(<?= jsAttr('Eliminare l\'amministratore '.$adm['nome_completo'].'? Questa operazione è irreversibile.') ?>)">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="delete_admin">
              <input type="hidden" name="id" value="<?= $adm['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal nuovo / modifica admin -->
<div class="overlay" id="modalAdmin">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="adminModalTitle">Nuovo Amministratore</span>
      <button class="modal-close" onclick="closeModal('modalAdmin')" aria-label="Chiudi">&#x2715;</button>
    </div>
    <form method="POST" autocomplete="off">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_admin">
      <input type="hidden" name="id" id="adminId" value="0">

      <div class="form-grid">
        <div class="fg">
          <label for="aNome">Nome completo <span class="req">*</span></label>
          <input type="text" id="aNome" name="nome_completo" maxlength="120" required>
        </div>
        <div class="fg">
          <label for="aUsername">Username <span class="req">*</span></label>
          <input type="text" id="aUsername" name="username" maxlength="50" required
                 pattern="[a-z0-9_\.]{3,50}" autocapitalize="none" spellcheck="false">
          <span class="field-note">3–50 caratteri: lettere minuscole, numeri, punto, _</span>
        </div>
        <div class="fg">
          <label for="aEmail">Email</label>
          <input type="email" id="aEmail" name="email" maxlength="150">
        </div>
        <div class="fg">
          <label for="aRuolo">Ruolo</label>
          <select id="aRuolo" name="ruolo_nuovo">
            <option value="admin">Amministratore</option>
            <option value="superadmin">Super Admin</option>
          </select>
        </div>
        <div class="fg" style="align-self:flex-end;padding-bottom:8px">
          <label class="check-label">
            <input type="checkbox" name="attivo" id="aAttivo" value="1" checked>
            Account attivo
          </label>
        </div>
        <div class="fg">
          <label for="aPass">Password <span class="req" id="aPassReq">*</span></label>
<div class="pw-field">
            <input type="password" name="password" id="aPass" maxlength="72" autocomplete="new-password">
            <button type="button" class="pw-btn" data-pw-toggle="aPass" aria-label="Mostra/nascondi password" title="Mostra/nascondi">&#x1F441;</button>
            <button type="button" class="pw-btn" data-pw-copy="aPass" aria-label="Copia password negli appunti" title="Copia negli appunti">&#x1F4CB;</button>
          </div>
          <button type="button" class="btn btn-sm btn-outline pw-gen" data-pw-gen="aPass,aPass2">&#x1F3B2; Genera password sicura</button>
          <span class="field-note">Min <?= PASSWORD_MIN_LEN ?> car., almeno 1 maiuscola e 1 numero. Lasciare vuoto per non modificare.</span>
        </div>
        <div class="fg">
          <label for="aPass2">Conferma password</label>
<div class="pw-field">
            <input type="password" id="aPass2" name="password2" maxlength="72" autocomplete="new-password">
            <button type="button" class="pw-btn" data-pw-toggle="aPass2" aria-label="Mostra/nascondi password" title="Mostra/nascondi">&#x1F441;</button>
          </div>
        </div>
        <div class="fg full">
          <span class="field-note">&#x1F512; Al primo accesso l'amministratore dovrà obbligatoriamente sostituire la password impostata qui.</span>
        </div>
        <div class="fg full" id="aSendCredWrap">
          <label class="check-label">
            <input type="checkbox" name="invia_credenziali" id="aSendCred" value="1">
            Invia username e password via email (solo nuovi account con email)
          </label>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalAdmin')">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function fillAdminModal(adm) {
  document.getElementById('adminModalTitle').textContent = 'Modifica Amministratore';
  document.getElementById('adminId').value    = adm.id;
  document.getElementById('aNome').value      = adm.nome_completo;
  document.getElementById('aUsername').value  = adm.username;
  document.getElementById('aEmail').value     = adm.email || '';
  document.getElementById('aAttivo').checked  = adm.attivo == 1;
  document.getElementById('aPass').value      = '';
  document.getElementById('aPass2').value     = '';
  document.getElementById('aPassReq').textContent = '';
  var ruoloSel = document.getElementById('aRuolo');
  if (ruoloSel) ruoloSel.value = adm.ruolo || 'admin';
  var sc = document.getElementById('aSendCredWrap'); if (sc) sc.style.display = 'none';
  openModal('modalAdmin');
}
function resetAdminModal() {
  document.getElementById('adminModalTitle').textContent = 'Nuovo Amministratore';
  document.getElementById('adminId').value    = '0';
  document.getElementById('aNome').value      = '';
  document.getElementById('aUsername').value  = '';
  document.getElementById('aEmail').value     = '';
  document.getElementById('aAttivo').checked  = true;
  document.getElementById('aPass').value      = '';
  document.getElementById('aPass2').value     = '';
  document.getElementById('aPassReq').textContent = '*';
  var r = document.getElementById('aRuolo'); if(r) r.value='admin';
  var sc = document.getElementById('aSendCredWrap'); if (sc) sc.style.display = '';
  var scb = document.getElementById('aSendCred'); if (scb) scb.checked = false;
  openModal('modalAdmin');
}

<?php if ($editAdmin): ?>
document.addEventListener('DOMContentLoaded', function(){ fillAdminModal(<?= jsVal(pick($editAdmin,$adminJsFields)) ?>); });
<?php endif; ?>
</script>

<?php include INCLUDES_PATH.'/footer.php'; ?>
