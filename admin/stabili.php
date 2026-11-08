<?php
require_once __DIR__.'/../includes/bootstrap.php';
Auth::requireRole(['admin','superadmin']);

$u    = Auth::user();
$isSA = Auth::isSuperAdmin();
$pageTitle = 'Gestione Stabili';

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $action = Security::postStr('action', 30);

    if ($action === 'save_building') {
        $id      = Security::postInt('id');
        $nome    = Security::postStr('nome', 150);
        $ind     = Security::postStr('indirizzo', 255);
        $citta   = Security::postStr('citta', 100);
        $prov    = strtoupper(Security::postStr('provincia', 5));
        $cap     = Security::postStr('cap', 10);
        $utot    = Security::postInt('unita_totali');
        $uatt    = Security::postInt('unita_attive');
        $cf      = Security::postStr('codice_fiscale', 20);
        $note    = Security::postStr('note', 2000);
        $attivo  = !empty($_POST['attivo']) ? 1 : 0;
        $adminId = $u['id'];
        if ($isSA) {
            // Il superadmin può assegnare lo stabile a un amministratore attivo (o tenerlo per sé)
            $sel = Security::postInt('admin_id');
            if ($sel && DB::one("SELECT id FROM buildingsync_utenti WHERE id=? AND ruolo IN ('admin','superadmin') AND attivo=1", [$sel])) {
                $adminId = $sel;
            }
        }

        if (!$nome || !$ind || !$citta) {
            flash('Compilare i campi obbligatori.', 'error');
            redirect(APP_URL.'/admin/stabili.php');
        }
        if ($utot > 0 && $uatt > $utot) {
            flash('Le unità attive non possono superare le unità totali.', 'error');
            redirect(APP_URL.'/admin/stabili.php');
        }

        $data = [
            'nome'           => $nome,
            'indirizzo'      => $ind,
            'citta'          => $citta,
            'provincia'      => $prov,
            'cap'            => $cap,
            'unita_totali'   => $utot,
            'unita_attive'   => $uatt,
            'codice_fiscale' => $cf,
            'note'           => $note,
            'attivo'         => $attivo,
            'admin_id'       => $adminId,
        ];

        if ($id) {
            if ($isSA) {
                $existing = DB::one("SELECT id FROM buildingsync_stabili WHERE id=?", [$id]);
            } else {
                $existing = DB::one("SELECT id FROM buildingsync_stabili WHERE id=? AND admin_id=?", [$id, $u['id']]);
            }
            if (!$existing) {
                flash('Stabile non trovato.', 'error');
                redirect(APP_URL.'/admin/stabili.php');
            }
            DB::update('buildingsync_stabili', $data, 'id=?', [$id]);
            flash('Stabile aggiornato con successo.');
        } else {
            $data['creato_il'] = date('Y-m-d H:i:s');
            DB::insert('buildingsync_stabili', $data);
            flash('Stabile creato con successo.');
        }
        redirect(APP_URL.'/admin/stabili.php');
    }

    if ($action === 'delete_building') {
        $id = Security::postInt('id');
        if ($isSA) {
            $existing = DB::one("SELECT id FROM buildingsync_stabili WHERE id=?", [$id]);
        } else {
            $existing = DB::one("SELECT id FROM buildingsync_stabili WHERE id=? AND admin_id=?", [$id, $u['id']]);
        }
        if ($existing) {
            // I file degli allegati non vengono rimossi dal CASCADE del database: si eliminano a mano
            $files = DB::all(
                "SELECT a.filename FROM buildingsync_segnalazioni_allegati a
                 JOIN buildingsync_segnalazioni s ON s.id=a.segnalazione_id WHERE s.stabile_id=?", [$id]
            );
            try {
                DB::delete('buildingsync_stabili', 'id=?', [$id]);
                foreach ($files as $f) @unlink(UPLOAD_PATH.basename($f['filename']));
                Auth::logAction('delete_building', 'stabile_id='.$id);
                flash('Stabile eliminato.');
            } catch (PDOException $e) {
                error_log('delete_building: '.$e->getMessage());
                flash('Impossibile eliminare lo stabile: ci sono dati collegati.', 'error');
            }
        }
        redirect(APP_URL.'/admin/stabili.php');
    }

    if ($action === 'assign_user') {
        $bid          = Security::postInt('building_id');
        $uid2         = Security::postInt('user_id');
        $unit         = Security::postStr('numero_unita', 20);
        $ruoloStabile = in_array($_POST['ruolo_stabile']??'',['user','capocasa']) ? $_POST['ruolo_stabile'] : 'user';
        if ($isSA) {
            $existing_b = DB::one("SELECT id, unita_attive FROM buildingsync_stabili WHERE id=?", [$bid]);
        } else {
            $existing_b = DB::one("SELECT id, unita_attive FROM buildingsync_stabili WHERE id=? AND admin_id=?", [$bid, $u['id']]);
        }
        // L'utente deve essere un condomino/capocasa attivo gestito da questo admin (il superadmin vede tutti)
        $okUser = $uid2 ? DB::one(
            "SELECT id FROM buildingsync_utenti WHERE id=? AND ruolo IN ('user','capocasa') AND attivo=1".($isSA ? '' : ' AND admin_id=?'),
            $isSA ? [$uid2] : [$uid2, $u['id']]
        ) : null;
        if ($existing_b && !$okUser) {
            flash('Utente non valido.', 'error');
            redirect(APP_URL.'/admin/stabili.php?manage='.$bid);
        }
        if ($existing_b && $okUser) {
            // Controllo capienza
            $nAssegnati = (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_utenti_stabili WHERE stabile_id=? AND attivo=1", [$bid])['n'] ?? 0);
            if ($existing_b['unita_attive'] > 0 && $nAssegnati >= $existing_b['unita_attive']) {
                flash('Capienza massima dello stabile raggiunta ('.$existing_b['unita_attive'].' unità attive).', 'error');
                redirect(APP_URL.'/admin/stabili.php?manage='.$bid);
            }
            $exists = DB::one("SELECT id FROM buildingsync_utenti_stabili WHERE stabile_id=? AND utente_id=?", [$bid, $uid2]);
            if ($exists) {
                DB::update('buildingsync_utenti_stabili', ['attivo'=>1,'numero_unita'=>$unit,'ruolo_stabile'=>$ruoloStabile], 'stabile_id=? AND utente_id=?', [$bid, $uid2]);
            } else {
                DB::insert('buildingsync_utenti_stabili', [
                    'stabile_id'    => $bid,
                    'utente_id'     => $uid2,
                    'numero_unita'  => $unit,
                    'ruolo_stabile' => $ruoloStabile,
                    'attivo'        => 1,
                    'assegnato_il'  => date('Y-m-d H:i:s'),
                ]);
            }
            flash('Utente assegnato allo stabile.');
        }
        redirect(APP_URL.'/admin/stabili.php?manage='.$bid);
    }

    if ($action === 'remove_user') {
        $bid  = Security::postInt('building_id');
        $uid2 = Security::postInt('user_id');
        if (Auth::managesBuilding($bid)) {
            DB::update('buildingsync_utenti_stabili', ['attivo'=>0], 'stabile_id=? AND utente_id=?', [$bid, $uid2]);
            flash('Utente rimosso dallo stabile.');
        }
        redirect(APP_URL.'/admin/stabili.php?manage='.$bid);
    }

    if ($action === 'update_assignment') {
        $bid  = Security::postInt('building_id');
        $uid2 = Security::postInt('user_id');
        if (Auth::managesBuilding($bid)) {
            $ruoloStabile = Security::postEnum('ruolo_stabile', ['user','capocasa'], 'user');
            DB::update('buildingsync_utenti_stabili',
                ['ruolo_stabile'=>$ruoloStabile, 'numero_unita'=>Security::postStr('numero_unita', 20)],
                'stabile_id=? AND utente_id=?', [$bid, $uid2]);
            flash('Assegnazione aggiornata.');
        }
        redirect(APP_URL.'/admin/stabili.php?manage='.$bid);
    }

    redirect(APP_URL.'/admin/stabili.php');
}

// ---- GET ----
$editId   = Security::getInt('edit');
$manageId = Security::getInt('manage');

// Stabile da modificare
$editBuilding = null;
if ($editId) {
    if ($isSA) {
        $editBuilding = DB::one("SELECT * FROM buildingsync_stabili WHERE id=?", [$editId]);
    } else {
        $editBuilding = DB::one("SELECT * FROM buildingsync_stabili WHERE id=? AND admin_id=?", [$editId, $u['id']]);
    }
}

// Lista stabili
if ($isSA) {
    $buildings = DB::all(
        "SELECT s.*, u.nome_completo admin_nome,
            (SELECT COUNT(*) FROM buildingsync_utenti_stabili us WHERE us.stabile_id=s.id AND us.attivo=1) n_utenti
         FROM buildingsync_stabili s
         JOIN buildingsync_utenti u ON u.id=s.admin_id
         ORDER BY s.nome"
    );
} else {
    $buildings = DB::all(
        "SELECT s.*, u.nome_completo admin_nome,
            (SELECT COUNT(*) FROM buildingsync_utenti_stabili us WHERE us.stabile_id=s.id AND us.attivo=1) n_utenti
         FROM buildingsync_stabili s
         JOIN buildingsync_utenti u ON u.id=s.admin_id
         WHERE s.admin_id=?
         ORDER BY s.nome",
        [$u['id']]
    );
}

// Gestione utenti di uno stabile
$availableUsers = [];
$manageBuilding = null;
$assignedUsers  = [];

if ($manageId) {
    if ($isSA) {
        $manageBuilding = DB::one("SELECT * FROM buildingsync_stabili WHERE id=?", [$manageId]);
    } else {
        $manageBuilding = DB::one("SELECT * FROM buildingsync_stabili WHERE id=? AND admin_id=?", [$manageId, $u['id']]);
    }

    if ($manageBuilding) {
        $assignedUsers = DB::all(
            "SELECT u.id, u.username, u.nome_completo, u.ruolo, us.numero_unita, us.ruolo_stabile
             FROM buildingsync_utenti u
             JOIN buildingsync_utenti_stabili us ON us.utente_id=u.id
             WHERE us.stabile_id=? AND us.attivo=1
             ORDER BY u.nome_completo",
            [$manageId]
        );
        $assignedIds = array_column($assignedUsers, 'id');

        // Utenti disponibili (non ancora assegnati)
        if ($assignedIds) {
            $phExcl = implode(',', array_fill(0, count($assignedIds), '?'));
            if ($isSA) {
                $availableUsers = DB::all(
                    "SELECT id, username, nome_completo, ruolo FROM buildingsync_utenti
                     WHERE ruolo IN ('user','capocasa') AND attivo=1
                     AND id NOT IN ($phExcl)
                     ORDER BY nome_completo",
                    $assignedIds
                );
            } else {
                $paramsAvail = array_merge([$u['id']], $assignedIds);
                $availableUsers = DB::all(
                    "SELECT id, username, nome_completo, ruolo FROM buildingsync_utenti
                     WHERE ruolo IN ('user','capocasa') AND attivo=1
                     AND admin_id=?
                     AND id NOT IN ($phExcl)
                     ORDER BY nome_completo",
                    $paramsAvail
                );
            }
        } else {
            if ($isSA) {
                $availableUsers = DB::all(
                    "SELECT id, username, nome_completo, ruolo FROM buildingsync_utenti
                     WHERE ruolo IN ('user','capocasa') AND attivo=1
                     ORDER BY nome_completo"
                );
            } else {
                $availableUsers = DB::all(
                    "SELECT id, username, nome_completo, ruolo FROM buildingsync_utenti
                     WHERE ruolo IN ('user','capocasa') AND attivo=1
                     AND admin_id=?
                     ORDER BY nome_completo",
                    [$u['id']]
                );
            }
        }
    }
}

$allAdmins = $isSA ? DB::all("SELECT id, nome_completo, ruolo FROM buildingsync_utenti WHERE ruolo IN ('admin','superadmin') AND attivo=1 ORDER BY ruolo, nome_completo") : [];

include INCLUDES_PATH.'/header.php';
?>

<div class="page-hd">
  <div>
    <h1>Gestione Stabili</h1>
    <p class="sub"><?= $isSA ? 'Tutti gli stabili del sistema' : 'I tuoi stabili' ?></p>
  </div>
  <button class="btn btn-primary" onclick="openModal('modalBuilding')">+ Nuovo stabile</button>
</div>

<?php if ($manageBuilding): ?>
<!-- GESTIONE UTENTI STABILE -->
<div class="card">
  <div class="card-head">
    <span class="card-title">Utenti di: <?= h($manageBuilding['nome']) ?></span>
    <a href="<?= APP_URL ?>/admin/stabili.php" class="btn btn-sm btn-outline">&larr; Torna alla lista</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
    <div>
      <h3 style="margin-bottom:12px;font-size:.95rem">Utenti assegnati (<?= count($assignedUsers) ?>)</h3>
      <?php if (empty($assignedUsers)): ?>
        <div class="empty"><span class="ic">👥</span><p>Nessun utente assegnato</p></div>
      <?php else: foreach ($assignedUsers as $au): ?>
        <div class="flex" style="padding:9px 0;border-bottom:var(--border)">
          <div style="flex:1;min-width:0">
            <div style="font-size:.88rem;font-weight:600"><?= h($au['nome_completo']) ?></div>
            <div class="muted">@<?= h($au['username']) ?><?= $au['numero_unita'] ? ' &mdash; '.h($au['numero_unita']) : '' ?></div>
          </div>
          <form method="POST" class="flex" style="gap:6px">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="update_assignment">
            <input type="hidden" name="building_id" value="<?= $manageId ?>">
            <input type="hidden" name="user_id" value="<?= $au['id'] ?>">
            <input type="text" name="numero_unita" value="<?= h($au['numero_unita']) ?>" maxlength="20" placeholder="Unità" style="width:80px" aria-label="Numero unità">
            <select name="ruolo_stabile" style="width:auto" aria-label="Ruolo nello stabile">
              <option value="user" <?= $au['ruolo_stabile']!=='capocasa'?'selected':'' ?>>Condomino</option>
              <option value="capocasa" <?= $au['ruolo_stabile']==='capocasa'?'selected':'' ?>>Capocasa</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline" title="Salva ruolo e unità">&#x2713;</button>
          </form>
          <form method="POST" style="display:inline"
                onsubmit="return confirm(<?= jsAttr('Rimuovere '.$au['nome_completo'].' da questo stabile?') ?>)">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="remove_user">
            <input type="hidden" name="building_id" value="<?= $manageId ?>">
            <input type="hidden" name="user_id" value="<?= $au['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Rimuovi</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div>
      <h3 style="margin-bottom:12px;font-size:.95rem">Aggiungi utente</h3>
      <?php if (empty($availableUsers)): ?>
        <div class="alert alert-info">Nessun utente disponibile da aggiungere.</div>
      <?php else: ?>
        <form method="POST">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="assign_user">
          <input type="hidden" name="building_id" value="<?= $manageId ?>">
          <div class="fg" style="margin-bottom:12px">
            <label for="selUtente">Seleziona utente</label>
            <select name="user_id" id="selUtente" required>
              <option value="">— Seleziona —</option>
              <?php foreach ($availableUsers as $au): ?>
              <option value="<?= $au['id'] ?>">
                <?= h($au['nome_completo']) ?> (@<?= h($au['username']) ?>) — <?= roleLabel($au['ruolo']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg" style="margin-bottom:12px">
            <label for="numUnita">Numero unità / interno</label>
            <input type="text" name="numero_unita" id="numUnita" maxlength="20" placeholder="Es. 3, Scala A...">
          </div>
          <div class="fg" style="margin-bottom:14px">
            <label for="ruoloStabile">Ruolo in questo stabile</label>
            <select name="ruolo_stabile" id="ruoloStabile">
              <option value="user">Condomino</option>
              <option value="capocasa">Capocasa</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Assegna</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- LISTA STABILI -->
<div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>Nome</th>
        <th>Indirizzo</th>
        <?php if ($isSA): ?><th>Amministratore</th><?php endif; ?>
        <th>Unità</th>
        <th>Utenti</th>
        <th>Stato</th>
        <th>Azioni</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($buildings)): ?>
      <tr>
        <td colspan="7" style="text-align:center;padding:32px;color:var(--gray-400)">
          Nessuno stabile creato
        </td>
      </tr>
      <?php else: foreach ($buildings as $b): ?>
      <tr>
        <td><strong><?= h($b['nome']) ?></strong></td>
        <td class="muted"><?= h($b['indirizzo'].', '.$b['citta']) ?></td>
        <?php if ($isSA): ?><td class="muted"><?= h($b['admin_nome']) ?></td><?php endif; ?>
        <td><?= (int)$b['unita_attive'] ?>/<?= (int)$b['unita_totali'] ?></td>
        <td><?= (int)$b['n_utenti'] ?></td>
        <td>
          <?php if ($b['attivo']): ?>
            <span class="badge badge-ok">Attivo</span>
          <?php else: ?>
            <span class="badge badge-off">Inattivo</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="row-actions">
            <a href="<?= APP_URL ?>/stabile.php?id=<?= $b['id'] ?>"
               class="btn btn-sm btn-outline" title="Vai allo stabile">Apri</a>
            <button class="btn btn-sm btn-outline"
                    onclick="fillBuildingModal(<?= jsAttr(pick($b,['id','nome','indirizzo','citta','provincia','cap','codice_fiscale','unita_totali','unita_attive','attivo','note','admin_id'])) ?>)"
                    title="Modifica">Modifica</button>
            <a href="?manage=<?= $b['id'] ?>"
               class="btn btn-sm btn-outline" title="Gestisci utenti">Utenti</a>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm(<?= jsAttr('Eliminare lo stabile '.$b['nome'].'? Verranno eliminati anche bacheca, segnalazioni, allegati e attività. L\'operazione è irreversibile.') ?>)">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="delete_building">
              <input type="hidden" name="id" value="<?= $b['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal nuovo/modifica stabile -->
<div class="overlay" id="modalBuilding">
  <div class="modal" style="max-width:640px">
    <div class="modal-head">
      <span class="modal-title" id="modalBuildingTitle">Nuovo Stabile</span>
      <button class="modal-close" onclick="closeModal('modalBuilding')" aria-label="Chiudi">&#x2715;</button>
    </div>
    <form method="POST">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_building">
      <input type="hidden" name="id" id="buildingId" value="0">
      <div class="form-grid">
        <div class="fg full">
          <label for="bNome">Nome stabile <span class="req">*</span></label>
          <input type="text" id="bNome" name="nome" maxlength="150" required
                 placeholder="Es. Condominio Villa Rosa">
        </div>
        <div class="fg full">
          <label for="bIndirizzo">Indirizzo <span class="req">*</span></label>
          <input type="text" id="bIndirizzo" name="indirizzo" maxlength="255" required
                 placeholder="Via Roma 1">
        </div>
        <div class="fg">
          <label for="bCitta">Città <span class="req">*</span></label>
          <input type="text" id="bCitta" name="citta" maxlength="100" required>
        </div>
        <div class="fg">
          <label for="bProv">Provincia</label>
          <input type="text" id="bProv" name="provincia" maxlength="5"
                 placeholder="MI" style="text-transform:uppercase">
        </div>
        <div class="fg">
          <label for="bCap">CAP</label>
          <input type="text" id="bCap" name="cap" maxlength="10" placeholder="20100">
        </div>
        <div class="fg">
          <label for="bCf">Codice Fiscale</label>
          <input type="text" id="bCf" name="codice_fiscale" maxlength="20">
        </div>
        <div class="fg">
          <label for="bUtot">Unità totali</label>
          <input type="number" id="bUtot" name="unita_totali" min="0" max="9999" value="0">
        </div>
        <div class="fg">
          <label for="bUatt">Unità attive</label>
          <input type="number" id="bUatt" name="unita_attive" min="0" max="9999" value="0">
        </div>
        <?php if ($isSA && !empty($allAdmins)): ?>
        <div class="fg">
          <label for="bAdmin">Amministratore</label>
          <select id="bAdmin" name="admin_id">
            <option value="">— Me stesso —</option>
            <?php foreach ($allAdmins as $a): ?>
            <option value="<?= $a['id'] ?>"><?= h($a['nome_completo']) ?><?= $a['ruolo']==='superadmin' ? ' (Super Admin)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="fg" style="align-self:flex-end;padding-bottom:8px">
          <label class="check-label">
            <input type="checkbox" id="bAttivo" name="attivo" value="1" checked>
            Stabile attivo
          </label>
        </div>
        <div class="fg full">
          <label for="bNote">Note</label>
          <textarea id="bNote" name="note" rows="3" maxlength="2000"></textarea>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalBuilding')">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function fillBuildingModal(b) {
  document.getElementById('modalBuildingTitle').textContent = 'Modifica Stabile';
  document.getElementById('buildingId').value  = b.id;
  document.getElementById('bNome').value       = b.nome       || '';
  document.getElementById('bIndirizzo').value  = b.indirizzo  || '';
  document.getElementById('bCitta').value      = b.citta      || '';
  document.getElementById('bProv').value       = b.provincia  || '';
  document.getElementById('bCap').value        = b.cap        || '';
  document.getElementById('bCf').value         = b.codice_fiscale || '';
  document.getElementById('bUtot').value       = b.unita_totali  || 0;
  document.getElementById('bUatt').value       = b.unita_attive  || 0;
  document.getElementById('bAttivo').checked   = b.attivo == 1;
  document.getElementById('bNote').value       = b.note || '';
  var adminSel = document.getElementById('bAdmin');
  if (adminSel) adminSel.value = b.admin_id || '';
  openModal('modalBuilding');
}

// Reset modal quando si crea un nuovo stabile
document.querySelector('[onclick="openModal(\'modalBuilding\')"]')?.addEventListener('click', function() {
  var adminSel = document.getElementById('bAdmin'); if (adminSel) adminSel.value = '';
  document.getElementById('modalBuildingTitle').textContent = 'Nuovo Stabile';
  document.getElementById('buildingId').value = '0';
  document.getElementById('bNome').value      = '';
  document.getElementById('bIndirizzo').value = '';
  document.getElementById('bCitta').value     = '';
  document.getElementById('bProv').value      = '';
  document.getElementById('bCap').value       = '';
  document.getElementById('bCf').value        = '';
  document.getElementById('bUtot').value      = '0';
  document.getElementById('bUatt').value      = '0';
  document.getElementById('bAttivo').checked  = true;
  document.getElementById('bNote').value      = '';
});
</script>

<?php include INCLUDES_PATH.'/footer.php'; ?>
