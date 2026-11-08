<?php
require_once __DIR__.'/includes/bootstrap.php';
Auth::requireLogin();

$bid = Security::getInt('id');
if (!$bid) redirect(APP_URL.'/dashboard.php');
Auth::assertBuilding($bid);

$building = DB::one(
    "SELECT s.*, u.nome_completo admin_nome, u.email admin_email
     FROM buildingsync_stabili s JOIN buildingsync_utenti u ON u.id=s.admin_id WHERE s.id=?",
    [$bid]
);
if (!$building) { http_response_code(404); die('Stabile non trovato.'); }

$u        = Auth::user();
$bRole    = Auth::buildingRole($bid);          // admin | capocasa | user
$isAdmin  = $bRole === 'admin';
$isCasa   = $bRole === 'capocasa';
$stabNome = $building['nome'];
$showArch = $isAdmin && !empty($_GET['arch']); // vista elementi archiviati (solo admin)

// ID degli utenti attivi dello stabile (per validare assegnazioni e destinatari)
$buildingUsers = DB::all(
    "SELECT u.id, u.username, u.nome_completo, u.ruolo, us.ruolo_stabile, us.numero_unita
     FROM buildingsync_utenti u JOIN buildingsync_utenti_stabili us ON us.utente_id=u.id
     WHERE us.stabile_id=? AND us.attivo=1 AND u.attivo=1 ORDER BY u.nome_completo",
    [$bid]
);
$buildingUserIds = array_map('intval', array_column($buildingUsers, 'id'));

// ================================================================
// UPLOAD ALLEGATO
// ================================================================
/**
 * Gestisce l'upload dell'allegato.
 * Restituisce: null (nessun file), ['error'=>msg] oppure i dati del file salvato.
 * L'estensione è derivata dal MIME reale (mai dal nome inviato dal client): un file
 * "foto.php" con contenuto immagine viene salvato come .jpg/.png e non è eseguibile.
 */
function handleUpload(): ?array {
    if (!isset($_FILES['allegato']) || !is_array($_FILES['allegato']) || ($_FILES['allegato']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES['allegato'];
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
        return ['error' => 'Allegato troppo grande (max '.UPLOAD_MAX_MB.' MB).'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return ['error' => 'Caricamento allegato non riuscito.'];
    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $finfo  = new finfo(FILEINFO_MIME_TYPE);
    $mime   = $finfo->file($file['tmp_name']);
    if (!in_array($mime, UPLOAD_TYPES, true) || !isset($extMap[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return ['error' => 'Formato allegato non consentito (solo JPG, PNG, GIF, WEBP).'];
    }
    $name = bin2hex(random_bytes(16)).'.'.$extMap[$mime];
    if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_PATH.$name)) return ['error' => 'Impossibile salvare l\'allegato.'];
    @chmod(UPLOAD_PATH.$name, 0644);
    $orig = mb_substr(preg_replace('/[^\p{L}\p{N}\s._()-]/u', '_', basename((string)$file['name'])), 0, 200);
    return ['filename'=>$name,'original_name'=>$orig ?: 'immagine.'.$extMap[$mime],'mime_type'=>$mime,'dimensione'=>(int)$file['size']];
}

/** Salva l'allegato (se presente) collegandolo alla segnalazione; restituisce un eventuale messaggio d'errore */
function saveAttachment(int $rid, int $uid): ?string {
    $al = handleUpload();
    if (!$al) return null;
    if (isset($al['error'])) return $al['error'];
    DB::insert('buildingsync_segnalazioni_allegati', array_merge($al, [
        'segnalazione_id'=>$rid, 'utente_id'=>$uid, 'creato_il'=>date('Y-m-d H:i:s'),
    ]));
    return null;
}

/** Cambia lo stato completata/da_fare di un'attività; restituisce null se non permesso */
function toggleActivity(int $aid, int $bid, array $u, bool $isAdmin, string $stabNome): ?array {
    $act = DB::one("SELECT * FROM buildingsync_attivita WHERE id=? AND stabile_id=?", [$aid, $bid]);
    if (!$act || $act['stato'] === 'annullata') return null;
    if (!$isAdmin && (int)$act['assegnato_a'] !== (int)$u['id']) return null;
    $done = $act['stato'] === 'completata';
    DB::update('buildingsync_attivita', [
        'stato'         => $done ? 'da_fare' : 'completata',
        'completato_da' => $done ? null : $u['id'],
        'completato_il' => $done ? null : date('Y-m-d H:i:s'),
    ], 'id=?', [$aid]);
    if ($done) Mailer::notifyActivityReopened($act, $bid, $stabNome, $u);
    else       Mailer::notifyActivityCompleted($act, $bid, $stabNome, $u);
    return ['reopened' => $done];
}

// ================================================================
// AJAX
// ================================================================
if (!empty($_GET['ajax']) && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json; charset=UTF-8');
    Security::checkCsrf();
    if ($_GET['ajax']==='complete_activity') {
        $res = toggleActivity(Security::postInt('id'), $bid, $u, $isAdmin, $stabNome);
        if ($res === null) { echo json_encode(['ok'=>false]); exit; }
        echo json_encode([
            'ok'           => true,
            'done'         => !$res['reopened'],
            'completed_at' => $res['reopened'] ? null : date('d/m/Y H:i'),
            'completed_by' => $res['reopened'] ? null : $u['nome_completo'],
        ]);
        exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ================================================================
// POST HANDLERS
// ================================================================
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::checkCsrf();
    $action  = Security::postStr('action',50);
    $back    = APP_URL.'/stabile.php?id='.$bid;
    // Le "Comunicazioni ufficiali" possono essere pubblicate solo da amministratore e capocasa
    $tipi    = ($isAdmin || $isCasa) ? ['post','avviso','comunicazione'] : ['post','avviso'];
    $gravita = ['bassa','media','alta','critica'];
    $stati   = ['aperta','in_lavorazione','risolta','chiusa'];

    // ── BACHECA ──────────────────────────────────────────────────
    if ($action==='new_post') {
        $titolo    = Security::postStr('titolo',200);
        $contenuto = Security::postStr('contenuto',4000);
        $tipo      = Security::postEnum('tipo', $tipi, 'post');
        $pinned    = ($isAdmin||$isCasa)&&!empty($_POST['pinned'])?1:0;
        if ($titolo && $contenuto) {
            DB::insert('buildingsync_bacheca_post',[
                'stabile_id'=>$bid,'utente_id'=>$u['id'],
                'titolo'=>$titolo,'contenuto'=>$contenuto,
                'tipo'=>$tipo,'in_evidenza'=>$pinned,'creato_il'=>date('Y-m-d H:i:s'),
            ]);
            Mailer::notifyNewPost(['titolo'=>$titolo,'contenuto'=>$contenuto,'tipo'=>$tipo,'in_evidenza'=>$pinned],$bid,$stabNome,$u);
            flash('Post pubblicato.');
        } else {
            flash('Titolo e messaggio sono obbligatori.','error');
        }
        redirect($back.'&tab=bacheca');
    }
    if ($action==='delete_post') {
        $pid  = Security::postInt('pid');
        $post = DB::one("SELECT * FROM buildingsync_bacheca_post WHERE id=? AND stabile_id=?",[$pid,$bid]);
        if ($post && ($isAdmin||(int)$post['utente_id']===(int)$u['id'])) {
            DB::delete('buildingsync_bacheca_post','id=?',[$pid]);
            flash('Post eliminato.');
        }
        redirect($back.'&tab=bacheca');
    }
    if ($action==='edit_post') {
        $pid  = Security::postInt('pid');
        $post = DB::one("SELECT * FROM buildingsync_bacheca_post WHERE id=? AND stabile_id=?",[$pid,$bid]);
        if ($post && ($isAdmin||(int)$post['utente_id']===(int)$u['id'])) {
            $titolo    = Security::postStr('titolo',200);
            $contenuto = Security::postStr('contenuto',4000);
            $tipo      = Security::postEnum('tipo', $tipi, in_array($post['tipo'], $tipi, true) ? $post['tipo'] : 'post');
            if ($titolo && $contenuto) {
                DB::update('buildingsync_bacheca_post',[
                    'titolo'      => $titolo,
                    'contenuto'   => $contenuto,
                    'tipo'        => $tipo,
                    // chi non può mettere in evidenza non deve togliere l'evidenza impostata da altri
                    'in_evidenza' => ($isAdmin||$isCasa) ? (!empty($_POST['pinned'])?1:0) : (int)$post['in_evidenza'],
                ],'id=?',[$pid]);
                flash('Post aggiornato.');
            } else {
                flash('Titolo e messaggio sono obbligatori.','error');
            }
        }
        redirect($back.'&tab=bacheca');
    }

    // ── SEGNALAZIONI ─────────────────────────────────────────────
    if ($action==='new_report') {
        $titolo    = Security::postStr('titolo',200);
        $contenuto = Security::postStr('contenuto',4000);
        $grav      = Security::postEnum('gravita', $gravita, 'media');
        $privata   = !empty($_POST['privata'])?1:0;
        $privConUid= $isAdmin ? Security::postInt('privata_con_utente') : 0;
        if ($privConUid && !in_array($privConUid, $buildingUserIds, true)) $privConUid = 0; // solo utenti dello stabile

        if ($titolo && $contenuto) {
            $rid = DB::insert('buildingsync_segnalazioni',[
                'stabile_id'=>$bid,'utente_id'=>$u['id'],
                'titolo'=>$titolo,'contenuto'=>$contenuto,
                'gravita'=>$grav,'stato'=>'aperta',
                'privata'=>$privata,
                'privata_con_utente'=>($privata&&$privConUid)?$privConUid:null,
                'creato_il'=>date('Y-m-d H:i:s'),
            ]);
            $upErr = saveAttachment($rid, (int)$u['id']);
            Mailer::notifyNewReport(
                ['titolo'=>$titolo,'contenuto'=>$contenuto,'gravita'=>$grav],
                $bid,$stabNome,$u,(bool)$privata,($privata&&$privConUid)?$privConUid:null
            );
            if ($upErr) flash('Segnalazione inviata, ma l\'allegato non è stato salvato: '.$upErr,'warn');
            else        flash('Segnalazione inviata.');
        } else {
            flash('Oggetto e descrizione sono obbligatori.','error');
        }
        redirect($back.'&tab=segnalazioni');
    }
    if ($action==='delete_report') {
        $rid = Security::postInt('rid');
        $rep = DB::one("SELECT * FROM buildingsync_segnalazioni WHERE id=? AND stabile_id=?",[$rid,$bid]);
        if ($rep && ($isAdmin||(int)$rep['utente_id']===(int)$u['id'])) {
            $alleg = DB::all("SELECT filename FROM buildingsync_segnalazioni_allegati WHERE segnalazione_id=?",[$rid]);
            DB::delete('buildingsync_segnalazioni','id=?',[$rid]);
            foreach($alleg as $a) { @unlink(UPLOAD_PATH.basename($a['filename'])); }
            flash('Segnalazione eliminata.');
        }
        redirect($back.'&tab=segnalazioni');
    }
    if (in_array($action, ['archive_report','unarchive_report'], true) && $isAdmin) {
        $rid = Security::postInt('rid');
        if (DB::one("SELECT id FROM buildingsync_segnalazioni WHERE id=? AND stabile_id=?",[$rid,$bid])) {
            $arch = $action==='archive_report' ? 1 : 0;
            DB::update('buildingsync_segnalazioni',['archiviata'=>$arch],'id=?',[$rid]);
            flash($arch ? 'Segnalazione archiviata.' : 'Segnalazione ripristinata.');
        }
        redirect($back.'&tab=segnalazioni'.($action==='unarchive_report'?'&arch=1':''));
    }
    if (in_array($action, ['archive_activity','unarchive_activity'], true) && $isAdmin) {
        $aid = Security::postInt('aid');
        if (DB::one("SELECT id FROM buildingsync_attivita WHERE id=? AND stabile_id=?",[$aid,$bid])) {
            $arch = $action==='archive_activity' ? 1 : 0;
            DB::update('buildingsync_attivita',['archiviata'=>$arch],'id=?',[$aid]);
            flash($arch ? 'Attività archiviata.' : 'Attività ripristinata.');
        }
        redirect($back.'&tab=attivita'.($action==='unarchive_activity'?'&arch=1':''));
    }

    if ($action==='update_report_status' && $isAdmin) {
        $rid    = Security::postInt('rid');
        $stato  = Security::postEnum('stato', $stati, 'aperta');
        $rep    = DB::one(
            "SELECT r.*, u.nome_completo autore_nome, u.email email_autore, u.email_notifiche notifiche_autore
             FROM buildingsync_segnalazioni r JOIN buildingsync_utenti u ON u.id=r.utente_id
             WHERE r.id=? AND r.stabile_id=?",[$rid,$bid]
        );
        if ($rep && $rep['stato'] !== $stato) {
            DB::update('buildingsync_segnalazioni',[
                'stato'=>$stato,'aggiornato_il'=>date('Y-m-d H:i:s'),
                'modificato_da'=>$u['id'],'modificato_il'=>date('Y-m-d H:i:s'),
            ],'id=?',[$rid]);
            $rep['stato'] = $stato;
            Mailer::notifyReportStatusChanged($rep,$bid,$stabNome,$u);
            flash('Stato segnalazione aggiornato.');
        }
        redirect($back.'&tab=segnalazioni');
    }
    if ($action==='edit_report') {
        $rid = Security::postInt('rid');
        $rep = DB::one("SELECT * FROM buildingsync_segnalazioni WHERE id=? AND stabile_id=?",[$rid,$bid]);
        if ($rep && ($isAdmin||(int)$rep['utente_id']===(int)$u['id'])) {
            $titolo    = Security::postStr('titolo',200);
            $contenuto = Security::postStr('contenuto',4000);
            if (!$titolo || !$contenuto) {
                flash('Oggetto e descrizione sono obbligatori.','error');
                redirect($back.'&tab=segnalazioni');
            }
            $privata = !empty($_POST['privata']) ? 1 : 0;
            $upd = [
                'titolo'        => $titolo,
                'contenuto'     => $contenuto,
                'gravita'       => Security::postEnum('gravita', $gravita, $rep['gravita']),
                'privata'       => $privata,
                'modificato_da' => $u['id'],
                'modificato_il' => date('Y-m-d H:i:s'),
            ];
            if ($isAdmin) {
                $pc = Security::postInt('privata_con_utente');
                $upd['privata_con_utente'] = ($privata && $pc && in_array($pc, $buildingUserIds, true)) ? $pc : null;
            } elseif (!$privata) {
                $upd['privata_con_utente'] = null;
            }
            DB::update('buildingsync_segnalazioni',$upd,'id=?',[$rid]);
            $upErr = saveAttachment($rid, (int)$u['id']);
            if ($upErr) flash('Segnalazione aggiornata, ma l\'allegato non è stato salvato: '.$upErr,'warn');
            else        flash('Segnalazione aggiornata.');
        }
        redirect($back.'&tab=segnalazioni');
    }
    if ($action==='delete_allegato') {
        $alid = Security::postInt('alid');
        $al   = DB::one(
            "SELECT sa.* FROM buildingsync_segnalazioni_allegati sa
             JOIN buildingsync_segnalazioni s ON s.id=sa.segnalazione_id
             WHERE sa.id=? AND s.stabile_id=? AND (sa.utente_id=? OR 1=?)",
            [$alid, $bid, $u['id'], (int)$isAdmin]
        );
        if ($al) {
            DB::delete('buildingsync_segnalazioni_allegati','id=?',[$alid]);
            @unlink(UPLOAD_PATH.basename($al['filename']));
            flash('Allegato eliminato.');
        }
        redirect($back.'&tab=segnalazioni');
    }

    // ── ATTIVITÀ ─────────────────────────────────────────────────
    if ($action==='new_activity' && $isAdmin) {
        $titolo    = Security::postStr('titolo',200);
        $desc      = Security::postStr('descrizione',2000);
        $cat       = Security::postEnum('categoria', array_keys(catLabels()), 'altro');
        $assegnato = Security::postInt('assegnato_a') ?: null;
        if ($assegnato && !in_array($assegnato, $buildingUserIds, true)) $assegnato = null;
        $data_p    = validDate(Security::postStr('data_programmata',10));
        $data_s    = validDate(Security::postStr('data_scadenza',10));
        if (!$titolo || !$data_p) {
            flash('Titolo e data programmata (valida) sono obbligatori.','error');
            redirect($back.'&tab=attivita');
        }
        if ($data_s && $data_s < $data_p) {
            flash('La scadenza non può essere precedente alla data programmata.','error');
            redirect($back.'&tab=attivita');
        }
        DB::insert('buildingsync_attivita',[
            'stabile_id'=>$bid,'creato_da'=>$u['id'],
            'assegnato_a'=>$assegnato,'titolo'=>$titolo,'descrizione'=>$desc,
            'categoria'=>$cat,'data_programmata'=>$data_p,'data_scadenza'=>$data_s,
            'stato'=>'da_fare','creato_il'=>date('Y-m-d H:i:s'),
        ]);
        $assignedUser = $assegnato ? DB::one("SELECT id,nome_completo,email,email_notifiche FROM buildingsync_utenti WHERE id=?",[$assegnato]) : null;
        Mailer::notifyNewActivity(
            ['titolo'=>$titolo,'descrizione'=>$desc,'categoria'=>$cat,'data_programmata'=>$data_p,'data_scadenza'=>$data_s],
            $bid,$stabNome,$assignedUser,$u
        );
        flash('Attività creata.');
        redirect($back.'&tab=attivita');
    }
    if ($action==='delete_activity' && $isAdmin) {
        DB::delete('buildingsync_attivita','id=? AND stabile_id=?',[Security::postInt('aid'),$bid]);
        flash('Attività eliminata.');
        redirect($back.'&tab=attivita');
    }
    if ($action==='complete_activity_form') {
        $res = toggleActivity(Security::postInt('aid'), $bid, $u, $isAdmin, $stabNome);
        if ($res !== null) flash($res['reopened'] ? 'Attività riaperta.' : 'Attività completata!');
        redirect($back.'&tab=attivita');
    }

    redirect($back);
}

// ================================================================
// CARICA DATI
// ================================================================
$posts = DB::all(
    "SELECT p.*, u.nome_completo autore FROM buildingsync_bacheca_post p
     JOIN buildingsync_utenti u ON u.id=p.utente_id
     WHERE p.stabile_id=? ORDER BY p.in_evidenza DESC, p.creato_il DESC",
    [$bid]
);

$allReports = DB::all(
    "SELECT r.*, u.nome_completo autore, um.nome_completo modificato_da_nome, ud.nome_completo destinatario_nome
     FROM buildingsync_segnalazioni r
     JOIN buildingsync_utenti u ON u.id=r.utente_id
     LEFT JOIN buildingsync_utenti um ON um.id=r.modificato_da
     LEFT JOIN buildingsync_utenti ud ON ud.id=r.privata_con_utente
     WHERE r.stabile_id=? AND r.archiviata=? ORDER BY r.creato_il DESC",
    [$bid, $showArch ? 1 : 0]
);
$reports = array_values(array_filter($allReports, function($r) use($u,$isAdmin){ return canSeeReport($r,$u,$isAdmin); }));

// Allegati di tutte le segnalazioni visibili (una sola query)
$reportAllegati = [];
if ($reports) {
    $ids = array_column($reports, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (DB::all("SELECT * FROM buildingsync_segnalazioni_allegati WHERE segnalazione_id IN ($ph) ORDER BY creato_il", $ids) as $al) {
        $reportAllegati[$al['segnalazione_id']][] = $al;
    }
}

$activities = DB::all(
    "SELECT a.*, u2.nome_completo assegnato_nome, uc.nome_completo completato_nome
     FROM buildingsync_attivita a
     LEFT JOIN buildingsync_utenti u2 ON u2.id=a.assegnato_a
     LEFT JOIN buildingsync_utenti uc ON uc.id=a.completato_da
     WHERE a.stabile_id=? AND a.archiviata=?
     ORDER BY FIELD(a.stato,'in_corso','da_fare','completata','annullata'), a.data_scadenza IS NULL, a.data_scadenza ASC, a.data_programmata ASC",
    [$bid, $showArch ? 1 : 0]
);

$activeTab = in_array($_GET['tab']??'',['bacheca','segnalazioni','attivita','info'],true)?$_GET['tab']:'bacheca';
$pageTitle = $building['nome'];   // l'escape viene fatto in header.php

// Calendario: solo i campi necessari al rendering
$calActivities = array_map(function($a){
    return pick($a, ['id','titolo','categoria','stato','data_programmata','data_scadenza','assegnato_nome']);
}, DB::all(
    "SELECT a.*, u2.nome_completo assegnato_nome
     FROM buildingsync_attivita a LEFT JOIN buildingsync_utenti u2 ON u2.id=a.assegnato_a
     WHERE a.stabile_id=? AND a.stato <> 'annullata' AND a.data_programmata IS NOT NULL
     ORDER BY a.data_programmata ASC",
    [$bid]
));

$catLabels  = catLabels();
$tipoLabels = ['post'=>'Messaggio','avviso'=>'Avviso','comunicazione'=>'Comunicazione ufficiale'];

include INCLUDES_PATH.'/header.php';
?>

<input type="hidden" id="csrfToken" value="<?= h(Security::csrfToken()) ?>">
<div class="page-hd">
  <div>
    <h1><?= h($building['nome']) ?></h1>
    <p class="sub"><?= h($building['indirizzo'].', '.$building['citta']) ?> &mdash; Admin: <?= h($building['admin_nome']) ?></p>
  </div>
  <?php if ($isAdmin): ?>
  <a href="<?= APP_URL ?>/admin/stabili.php?manage=<?= $bid ?>" class="btn btn-outline btn-sm">Gestisci utenti</a>
  <?php endif; ?>
</div>

<!-- TABS -->
<div class="tabs">
  <button class="tab <?= $activeTab==='bacheca'?'active':'' ?>" data-tab="tab-bacheca">Bacheca</button>
  <button class="tab <?= $activeTab==='segnalazioni'?'active':'' ?>" data-tab="tab-segnalazioni">Segnalazioni
    <?php $aperte = count(array_filter($reports,function($r){return $r['stato']==='aperta';}));
    if($aperte): ?><span style="background:var(--red);color:#fff;border-radius:99px;font-size:.68rem;padding:1px 6px;margin-left:4px"><?= $aperte ?></span><?php endif; ?>
  </button>
  <button class="tab <?= $activeTab==='attivita'?'active':'' ?>" data-tab="tab-attivita">Attività</button>
  <button class="tab <?= $activeTab==='info'?'active':'' ?>" data-tab="tab-info">Informazioni</button>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var active='<?= $activeTab ?>';
  document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active');});
  var btn=document.querySelector('[data-tab="tab-'+active+'"]');
  if(btn)btn.classList.add('active');
  var panel=document.getElementById('tab-'+active);
  if(panel)panel.classList.add('active');
});
</script>

<!-- ================================================================
     BACHECA
================================================================ -->
<div class="tab-panel <?= $activeTab==='bacheca'?'active':'' ?>" id="tab-bacheca">
  <div class="flex mb">
    <h2>Bacheca</h2>
    <button class="btn btn-primary btn-sm ml-auto" onclick="openModal('newPost')">+ Nuovo messaggio</button>
  </div>

  <?php if (empty($posts)): ?>
  <div class="empty"><span class="ic">📌</span><p>Nessun messaggio in bacheca</p></div>
  <?php else: foreach ($posts as $p):
    $canEdit = $isAdmin || $p['utente_id']==$u['id'];
  ?>
  <div class="post type-<?= h($p['tipo']) ?> <?= $p['in_evidenza']?'pinned':'' ?>">
    <?php if ($canEdit): ?>
    <div class="post-acts">
      <?php if ($p['in_evidenza']): ?><span class="chip chip-pin">In evidenza</span><?php endif; ?>
      <span class="chip chip-type"><?= h($tipoLabels[$p['tipo']] ?? $p['tipo']) ?></span>
      <button class="btn btn-sm btn-outline btn-icon"
              onclick="openEditPost(<?= jsAttr(pick($p,['id','titolo','contenuto','tipo','in_evidenza'])) ?>)"
              title="Modifica">&#x270F;</button>
      <form method="POST" style="display:inline"
            onsubmit="return confirm('Eliminare questo messaggio?')">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="delete_post">
        <input type="hidden" name="pid" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Elimina">&#x1F5D1;</button>
      </form>
    </div>
    <?php else: ?>
    <div class="post-acts">
      <?php if ($p['in_evidenza']): ?><span class="chip chip-pin">In evidenza</span><?php endif; ?>
      <span class="chip chip-type"><?= h($tipoLabels[$p['tipo']] ?? $p['tipo']) ?></span>
    </div>
    <?php endif; ?>
    <div class="post-title"><?= h($p['titolo']) ?></div>
    <div class="post-body"><?= h($p['contenuto']) ?></div>
    <div class="post-meta">
      <span><?= h($p['autore']) ?></span>
      <span><?= formatDate($p['creato_il'],'d/m/Y H:i') ?></span>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <!-- Modal nuovo post -->
  <div class="overlay" id="newPost">
    <div class="modal">
      <div class="modal-head">
        <span class="modal-title" id="postModalTitle">Nuovo messaggio</span>
        <button class="modal-close" onclick="closeModal('newPost')">&#x2715;</button>
      </div>
      <form method="POST" id="postForm">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" id="postAction" value="new_post">
        <input type="hidden" name="pid" id="postPid" value="0">
        <div class="form-grid">
          <div class="fg full">
            <label for="pTitolo">Titolo <span class="req">*</span></label>
            <input type="text" id="pTitolo" name="titolo" maxlength="200" required>
          </div>
          <div class="fg">
            <label for="pTipo">Tipo</label>
            <select id="pTipo" name="tipo">
              <option value="post">Messaggio generico</option>
              <option value="avviso">Avviso</option>
              <?php if ($isAdmin || $isCasa): ?>
              <option value="comunicazione">Comunicazione ufficiale</option>
              <?php endif; ?>
            </select>
          </div>
          <?php if ($isAdmin||$isCasa): ?>
          <div class="fg" style="align-self:flex-end;padding-bottom:8px">
            <label class="check-label"><input type="checkbox" id="pPin" name="pinned" value="1"> In evidenza</label>
          </div>
          <?php endif; ?>
          <div class="fg full">
            <label for="pContenuto">Messaggio <span class="req">*</span></label>
            <textarea id="pContenuto" name="contenuto" rows="5" maxlength="4000" required></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Pubblica</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('newPost')">Annulla</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================================
     SEGNALAZIONI
================================================================ -->
<div class="tab-panel <?= $activeTab==='segnalazioni'?'active':'' ?>" id="tab-segnalazioni">
  <div class="flex mb">
    <h2>Segnalazioni<?= $showArch ? ' <span class="muted" style="font-size:.8rem">(archiviate)</span>' : '' ?></h2>
    <div class="ml-auto flex">
      <?php if ($isAdmin): ?>
      <a class="btn btn-outline btn-sm" href="<?= h($showArch ? APP_URL.'/stabile.php?id='.$bid.'&tab=segnalazioni' : APP_URL.'/stabile.php?id='.$bid.'&tab=segnalazioni&arch=1') ?>">
        <?= $showArch ? '&larr; Segnalazioni attive' : '&#x1F4C2; Archivio' ?>
      </a>
      <?php endif; ?>
      <?php if (!$showArch): ?>
      <button class="btn btn-primary btn-sm" id="btnNewReport" onclick="openModal('newReport')">+ Nuova segnalazione</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($reports)): ?>
  <div class="empty"><span class="ic">&#x2705;</span><p><?= $showArch ? 'Nessuna segnalazione archiviata' : 'Nessuna segnalazione' ?></p></div>
  <?php else: foreach ($reports as $r):
    $canEdit = $isAdmin || (int)$r['utente_id']===(int)$u['id'];
    $allegati = $reportAllegati[$r['id']] ?? [];
  ?>
  <div class="report">
    <div class="report-head">
      <div class="report-title"><?= h($r['titolo']) ?></div>
      <div class="report-acts">
        <?= badge($r['gravita'],'severity') ?>
        <?= badge($r['stato'],'status') ?>
        <?php if ($r['privata']): ?>
          <span class="badge badge-off" title="Visibile solo all'autore, al destinatario e all'amministratore">&#x1F512; Privata<?= ($isAdmin && $r['destinatario_nome']) ? ' &rarr; '.h($r['destinatario_nome']) : '' ?></span>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <button class="btn btn-sm btn-outline btn-icon"
                onclick="openEditReport(<?= jsAttr(pick($r,['id','titolo','contenuto','gravita','privata','privata_con_utente'])) ?>)"
                title="Modifica">&#x270F;</button>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Eliminare questa segnalazione?')">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="delete_report">
          <input type="hidden" name="rid" value="<?= $r['id'] ?>">
          <button type="submit" class="btn btn-sm btn-danger btn-icon">&#x1F5D1;</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="report-body"><?= h($r['contenuto']) ?></div>

    <?php if (!empty($allegati)): ?>
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach($allegati as $al): ?>
      <div style="position:relative">
        <?php $alUrl = APP_URL.'/allegato.php?id='.(int)$al['id']; ?>
        <img src="<?= h($alUrl) ?>" loading="lazy"
               style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid var(--gray-300);cursor:zoom-in"
               data-lightbox="<?= h($alUrl) ?>" data-caption="<?= h($al['original_name']) ?>"
               alt="<?= h($al['original_name']) ?>"
               title="<?= h($al['original_name']) ?> — clicca per ingrandire">
        <?php if ($isAdmin || $al['utente_id']==$u['id']): ?>
        <form method="POST" style="position:absolute;top:2px;right:2px"
              onsubmit="return confirm('Eliminare questo allegato?')">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="delete_allegato">
          <input type="hidden" name="alid" value="<?= $al['id'] ?>">
          <button type="submit" style="width:18px;height:18px;border-radius:50%;background:rgba(139,26,26,.85);color:#fff;border:none;cursor:pointer;font-size:.6rem;line-height:1;padding:0">&#x2715;</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="report-meta">
      <span>Inserita da <strong><?= h($r['autore']) ?></strong> il <?= formatDate($r['creato_il'],'d/m/Y H:i') ?></span>
      <?php if (!empty($r['modificato_il']) && !empty($r['modificato_da_nome'])): ?>
      <span style="color:var(--gray-400)">Modificata da <?= h($r['modificato_da_nome']) ?> il <?= formatDate($r['modificato_il'],'d/m/Y H:i') ?></span>
      <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <div class="flex" style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e5e5;flex-wrap:wrap;gap:8px">
      <form method="POST" class="flex">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="update_report_status">
        <input type="hidden" name="rid" value="<?= $r['id'] ?>">
        <select name="stato" style="width:auto">
          <?php foreach(['aperta','in_lavorazione','risolta','chiusa'] as $st): ?>
          <option value="<?= $st ?>" <?= $r['stato']===$st?'selected':'' ?>>
            <?= ucfirst(str_replace('_',' ',$st)) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline">Aggiorna stato</button>
      </form>
      <?php if ($showArch): ?>
      <form method="POST">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="unarchive_report">
        <input type="hidden" name="rid" value="<?= $r['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline">&#x21A9; Ripristina</button>
      </form>
      <?php else: ?>
      <form method="POST" onsubmit="return confirm('Archiviare questa segnalazione?')">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="archive_report">
        <input type="hidden" name="rid" value="<?= $r['id'] ?>">
        <button type="submit" class="btn btn-sm btn-outline" style="color:var(--gray-500)">&#x1F4C2; Archivia</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>

  <!-- Modal nuova segnalazione -->
  <div class="overlay" id="newReport">
    <div class="modal" style="max-width:580px">
      <div class="modal-head">
        <span class="modal-title" id="reportModalTitle">Nuova segnalazione</span>
        <button class="modal-close" onclick="closeModal('newReport')">&#x2715;</button>
      </div>
      <form method="POST" id="reportForm" enctype="multipart/form-data">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" id="reportAction" value="new_report">
        <input type="hidden" name="rid" id="reportRid" value="0">
        <div class="form-grid">
          <div class="fg full">
            <label for="rTitolo">Oggetto <span class="req">*</span></label>
            <input type="text" id="rTitolo" name="titolo" maxlength="200" required>
          </div>
          <div class="fg">
            <label for="rGrav">Gravità</label>
            <select id="rGrav" name="gravita">
              <option value="bassa">Bassa</option>
              <option value="media" selected>Media</option>
              <option value="alta">Alta</option>
              <option value="critica">Critica</option>
            </select>
          </div>
          <div class="fg" style="align-self:flex-end;padding-bottom:8px">
            <label class="check-label">
              <input type="checkbox" id="rPrivata" name="privata" value="1"
                     onchange="togglePrivataAdmin(this.checked)">
              Segnalazione privata
            </label>
            <span class="field-note">
              <?php if ($isAdmin): ?>Visibile solo all'utente selezionato
              <?php else: ?>Visibile solo a te e all'amministratore<?php endif; ?>
            </span>
          </div>
          <?php if ($isAdmin && !empty($buildingUsers)): ?>
          <div class="fg" id="privataConWrap" style="display:none">
            <label for="rPrivataCon">Utente destinatario (privata)</label>
            <select id="rPrivataCon" name="privata_con_utente">
              <option value="">— Solo amministratore —</option>
              <?php foreach($buildingUsers as $bu): ?>
              <option value="<?= $bu['id'] ?>"><?= h($bu['nome_completo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="fg full">
            <label for="rContenuto">Descrizione <span class="req">*</span></label>
            <textarea id="rContenuto" name="contenuto" rows="4" maxlength="4000" required></textarea>
          </div>
          <div class="fg full">
            <label for="rAllegato">Allega foto (JPG, PNG, GIF, WEBP — max <?= UPLOAD_MAX_MB ?>MB)</label>
            <input type="file" id="rAllegato" name="allegato" accept="image/jpeg,image/png,image/gif,image/webp">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Invia</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('newReport')">Annulla</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================================
     ATTIVITÀ
================================================================ -->
<div class="tab-panel <?= $activeTab==='attivita'?'active':'' ?>" id="tab-attivita">
  <div class="flex mb">
    <h2>Attività<?= $showArch ? ' <span class="muted" style="font-size:.8rem">(archiviate)</span>' : '' ?></h2>
    <div class="ml-auto flex">
      <button class="btn btn-outline btn-sm" id="btnOpenCal" type="button">&#x1F4C5; Calendario</button>
      <?php if ($isAdmin): ?>
      <a class="btn btn-outline btn-sm" href="<?= h($showArch ? APP_URL.'/stabile.php?id='.$bid.'&tab=attivita' : APP_URL.'/stabile.php?id='.$bid.'&tab=attivita&arch=1') ?>">
        <?= $showArch ? '&larr; Attività attive' : '&#x1F4C2; Archivio' ?>
      </a>
      <?php if (!$showArch): ?>
      <button class="btn btn-primary btn-sm" onclick="openModal('newActivity')">+ Nuova attività</button>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($activities)): ?>
  <div class="empty"><span class="ic">&#x1F4CB;</span><p><?= $showArch ? 'Nessuna attività archiviata' : 'Nessuna attività pianificata' ?></p></div>
  <?php else:
    $grouped = [];
    foreach($activities as $act) { $grouped[$act['stato']][] = $act; }
    foreach(['in_corso','da_fare','completata','annullata'] as $stato):
      if (empty($grouped[$stato])) continue;
      $statiLabel = ['in_corso'=>'In corso','da_fare'=>'Da fare','completata'=>'Completate','annullata'=>'Annullate'];
  ?>
  <h3 style="margin:18px 0 8px;font-size:.8rem;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-400)"><?= $statiLabel[$stato] ?></h3>
  <div class="card" style="padding:4px 16px">
    <?php foreach($grouped[$stato] as $act):
      $canComplete = !$showArch && ($isAdmin || (int)$act['assegnato_a']===(int)$u['id']);
      $done  = $act['stato']==='completata';
      $over  = !$done && $act['data_scadenza'] && $act['data_scadenza']<date('Y-m-d');
      $soon  = !$done && !$over && $act['data_scadenza'] && $act['data_scadenza']<=date('Y-m-d',strtotime('+7 days'));
    ?>
    <div class="act-row">
      <div class="act-check <?= $done?'done':'' ?>"
           data-complete-activity="<?= $canComplete&&!in_array($act['stato'],['annullata'])?$act['id']:'' ?>"
           style="<?= !$canComplete||in_array($act['stato'],['annullata'])?'cursor:default;opacity:.35':'' ?>"
           title="<?= $done?'Segna come da fare':'Segna come completata' ?>">
        <?= $done?'&#x2713;':'' ?>
      </div>
      <div class="act-body">
        <div class="act-title <?= $done?'done':'' ?>">
          <?= catIcon($act['categoria']) ?> <?= h($act['titolo']) ?>
          <span style="font-size:.7rem;font-weight:400;color:var(--gray-400);margin-left:6px;font-style:italic">
            <?= h($catLabels[$act['categoria']] ?? $act['categoria']) ?>
          </span>
        </div>
        <div class="act-meta">
          <?php if($act['assegnato_nome']): ?><span><?= h($act['assegnato_nome']) ?></span><?php endif; ?>
          <?php if($act['data_programmata']): ?><span><?= formatDate($act['data_programmata']) ?></span><?php endif; ?>
          <?php if($act['data_scadenza']): ?>
          <span class="<?= $over?'overdue':($soon?'soon':'') ?>">Scad. <?= formatDate($act['data_scadenza']) ?></span>
          <?php endif; ?>
          <?php if($done&&$act['completato_il']): ?>
          <span class="completed-at">Completata <?= formatDate($act['completato_il'],'d/m/Y H:i') ?><?= $act['completato_nome']?' da '.h($act['completato_nome']):'' ?></span>
          <?php endif; ?>
        </div>
        <?php if($act['descrizione']): ?>
        <div style="font-size:.8rem;color:var(--gray-400);margin-top:3px"><?= h($act['descrizione']) ?></div>
        <?php endif; ?>
      </div>
      <div class="act-right">
        <?= badge($act['stato'],'activity') ?>
        <?php if($canComplete && !in_array($act['stato'],['annullata'])): ?>
        <form method="POST" style="display:inline">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="complete_activity_form">
          <input type="hidden" name="aid" value="<?= $act['id'] ?>">
          <button type="submit" class="btn btn-sm <?= $done?'btn-warn':'btn-success' ?>">
            <?= $done?'Riapri':'Completa' ?>
          </button>
        </form>
        <?php endif; ?>
        <?php if($isAdmin): ?>
        <?php if($showArch): ?>
        <form method="POST" style="display:inline">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="unarchive_activity">
          <input type="hidden" name="aid" value="<?= $act['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline" title="Ripristina">&#x21A9;</button>
        </form>
        <?php elseif($done || $act['stato']==='annullata'): ?>
        <form method="POST" style="display:inline">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="archive_activity">
          <input type="hidden" name="aid" value="<?= $act['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline btn-icon" title="Archivia" style="color:var(--gray-500)">&#x1F4C2;</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Eliminare questa attività?')">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="delete_activity">
          <input type="hidden" name="aid" value="<?= $act['id'] ?>">
          <button type="submit" class="btn btn-sm btn-danger btn-icon">&#x1F5D1;</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; endif; ?>

  <!-- Modal calendario trimestrale -->
  <div class="overlay" id="modalCalendario">
    <div class="modal" style="max-width:980px;width:96vw">
      <div class="modal-head">
        <div class="cal-nav">
          <button onclick="calNav(-1)" class="btn btn-outline btn-sm" aria-label="Periodo precedente">&lsaquo;<span class="hide-xs"> Prec.</span></button>
          <span class="modal-title" id="calRangeLabel">Caricamento...</span>
          <button onclick="calNav(+1)" class="btn btn-outline btn-sm" aria-label="Periodo successivo"><span class="hide-xs">Succ. </span>&rsaquo;</button>
        </div>
        <button class="modal-close" onclick="closeModal('modalCalendario')">&#x2715;</button>
      </div>
      <div id="calContainer" style="overflow-x:auto;padding:4px 0"></div>
      <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e5e5e5;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <span style="font-size:.72rem;font-weight:600;color:#555">Legenda:</span>
        <span style="font-size:.72rem;background:#dbeafe;color:#1a3a5c;padding:2px 8px;border-radius:2px">Da fare</span>
        <span style="font-size:.72rem;background:#fef3c7;color:#7a5100;padding:2px 8px;border-radius:2px">In corso</span>
        <span style="font-size:.72rem;background:#dcfce7;color:#2e6b3e;padding:2px 8px;border-radius:2px">Completata</span>
        <span style="font-size:.72rem;color:#999;margin-left:6px">Le barre coprono l'intera durata dell'attività</span>
      </div>
    </div>
  </div>

  <script>
  (function() {
    var ACTS = <?= jsVal($calActivities) ?>;
    var offset = 0;

    function pad(n){ return n < 10 ? '0'+n : ''+n; }

    function toYMD(d){
      return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
    }

    function parseYMD(s){
      if(!s) return null;
      var p = s.split('-');
      return new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
    }

    function escHtml(s){
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var ICONS = {pulizia_scale:'&#x1F9F9;',manutenzione_giardino:'&#x1F33F;',assemblea:'&#x1F4CB;',manutenzione_impianti:'&#x1F527;',ispezione:'&#x1F50D;'};
    var COLORS = {da_fare:{bg:'#dbeafe',fg:'#1a3a5c'},in_corso:{bg:'#fef3c7',fg:'#7a5100'},completata:{bg:'#dcfce7',fg:'#2e6b3e'},annullata:{bg:'#f2f2f2',fg:'#999'}};

    function renderCalendar(){
      var today   = new Date();
      var todayS  = toYMD(today);
      var base    = new Date(today.getFullYear(), today.getMonth() + offset, 1);

      var months = [];
      for(var i=0;i<3;i++){
        months.push(new Date(base.getFullYear(), base.getMonth()+i, 1));
      }

      var label = months[0].toLocaleDateString('it-IT',{month:'long',year:'numeric'})
                +' — '+months[2].toLocaleDateString('it-IT',{month:'long',year:'numeric'});
      document.getElementById('calRangeLabel').textContent = label;

      var outer = document.createElement('div');
      // su schermi stretti i tre mesi vanno a capo uno sotto l'altro
      outer.style.cssText = 'display:flex;flex-wrap:wrap;gap:14px';

      months.forEach(function(mStart){
        var yr  = mStart.getFullYear();
        var mo  = mStart.getMonth();
        var dim = new Date(yr, mo+1, 0).getDate();
        var fdow= mStart.getDay(); // 0=dom
        var blank = fdow === 0 ? 6 : fdow - 1;

        var col = document.createElement('div');
        col.style.cssText = 'flex:1 1 185px;min-width:185px';

        var mname = mStart.toLocaleDateString('it-IT',{month:'long',year:'numeric'});
        var hdr = document.createElement('div');
        hdr.style.cssText = 'font-size:.88rem;font-weight:600;text-align:center;margin-bottom:6px;color:#3a3a3a;padding:4px 0;background:#f9f9f9;border-radius:4px';
        hdr.textContent = mname;
        col.appendChild(hdr);

        var grid = document.createElement('div');
        grid.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:1px';

        // Day headers
        ['L','M','M','G','V','S','D'].forEach(function(d){
          var dh = document.createElement('div');
          dh.style.cssText = 'font-size:.6rem;font-weight:700;text-align:center;color:#a0a0a0;padding:3px 0';
          dh.textContent = d;
          grid.appendChild(dh);
        });

        // Blank cells
        for(var b=0;b<blank;b++){
          grid.appendChild(document.createElement('div'));
        }

        // Day cells
        for(var day=1;day<=dim;day++){
          var ds  = yr+'-'+pad(mo+1)+'-'+pad(day);
          var dt  = new Date(yr,mo,day);
          var isTod = ds === todayS;
          var isWE  = dt.getDay()===0 || dt.getDay()===6;

          var cell = document.createElement('div');
          cell.style.cssText = 'border-radius:2px;padding:2px;min-height:42px;overflow:hidden;font-size:.68rem;'+
            'background:'+(isTod?'#e8eef4':(isWE?'#f9f9f9':'#fff'))+';'+
            'border:1px solid '+(isTod?'#c5d4e3':'#e5e5e5')+';'+
            'outline:'+(isTod?'1px solid #25527e':'none')+';';

          var dayNum = document.createElement('div');
          dayNum.style.cssText = 'font-weight:'+(isTod?'700':'400')+';color:'+(isTod?'#1a3a5c':'#555')+';line-height:1.4;margin-bottom:1px';
          dayNum.textContent = day;
          cell.appendChild(dayNum);

          // Activities covering this day
          ACTS.forEach(function(a){
            var dStart = parseYMD(a.data_programmata);
            var dEnd   = a.data_scadenza ? parseYMD(a.data_scadenza) : dStart;
            if(!dStart) return;
            if(dt < dStart || dt > dEnd) return;

            var col2 = COLORS[a.stato] || {bg:'#eee',fg:'#333'};
            var isFirst = toYMD(dStart) === ds;
            var icon = isFirst ? (ICONS[a.categoria]||'&#x1F4CC;') : '&#x25B6;';
            var ttxt = escHtml(a.titolo);
            if(a.data_scadenza && a.data_scadenza!==a.data_programmata){
              ttxt += ' (fino al '+a.data_scadenza.slice(8,10)+'/'+a.data_scadenza.slice(5,7)+')';
            }
            if(a.assegnato_nome) ttxt += ' — '+escHtml(a.assegnato_nome);

            var bar = document.createElement('div');
            bar.title = ttxt;
            bar.style.cssText = 'background:'+col2.bg+';color:'+col2.fg+';border-radius:2px;padding:1px 3px;margin-bottom:1px;'+
              'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.62rem;cursor:default';
            var shortTitle = a.titolo.length>13 ? a.titolo.slice(0,12)+'…' : a.titolo;
            bar.innerHTML = icon+' '+escHtml(isFirst ? shortTitle : '...'+shortTitle);
            cell.appendChild(bar);
          });

          grid.appendChild(cell);
        }

        col.appendChild(grid);
        outer.appendChild(col);
      });

      var container = document.getElementById('calContainer');
      container.innerHTML = '';
      container.appendChild(outer);
    }

    window.calNav = function(delta){
      offset += delta;
      renderCalendar();
    };

    // Apri e renderizza al click del bottone
    var calBtn = document.getElementById('btnOpenCal');
    if(calBtn){
      calBtn.addEventListener('click', function(){
        openModal('modalCalendario');
        renderCalendar();
      });
    }
  })();
  </script>


  <?php if ($isAdmin): ?>
  <!-- Modal nuova attività -->
  <div class="overlay" id="newActivity">
    <div class="modal" style="max-width:600px">
      <div class="modal-head">
        <span class="modal-title">Nuova attività</span>
        <button class="modal-close" onclick="closeModal('newActivity')">&#x2715;</button>
      </div>
      <form method="POST">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="new_activity">
        <div class="form-grid">
          <div class="fg full">
            <label for="aTitolo">Titolo <span class="req">*</span></label>
            <input type="text" id="aTitolo" name="titolo" maxlength="200" required>
          </div>
          <div class="fg">
            <label for="aCat">Categoria</label>
            <select id="aCat" name="categoria">
              <option value="pulizia_scale">Pulizia scale</option>
              <option value="manutenzione_giardino">Manutenzione giardino</option>
              <option value="assemblea">Assemblea</option>
              <option value="manutenzione_impianti">Manutenzione impianti</option>
              <option value="ispezione">Ispezione</option>
              <option value="altro">Altro</option>
            </select>
          </div>
          <div class="fg">
            <label for="aAssegnato">Assegna a</label>
            <select id="aAssegnato" name="assegnato_a">
              <option value="">— Nessuno —</option>
              <?php foreach($buildingUsers as $bu): ?>
              <option value="<?= $bu['id'] ?>"><?= h($bu['nome_completo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label for="aDataP">Data programmata <span class="req">*</span></label>
            <input type="date" id="aDataP" name="data_programmata" required min="<?= date('Y-m-d') ?>">
          </div>
          <div class="fg">
            <label for="aDataS">Scadenza (opzionale)</label>
            <input type="date" id="aDataS" name="data_scadenza" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="fg full">
            <label for="aDesc">Descrizione</label>
            <textarea id="aDesc" name="descrizione" rows="3" maxlength="2000"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Crea attività</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('newActivity')">Annulla</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ================================================================
     INFO
================================================================ -->
<div class="tab-panel <?= $activeTab==='info'?'active':'' ?>" id="tab-info">
  <h2 style="margin-bottom:16px">Informazioni stabile</h2>
  <div class="card">
    <div class="form-grid">
      <?php $info=[
        'Nome'=>$building['nome'],'Stato'=>$building['attivo']?'Attivo':'Inattivo',
        'Indirizzo'=>$building['indirizzo'],'Città'=>$building['citta'].' ('.$building['provincia'].')',
        'CAP'=>$building['cap'],'Unità totali'=>$building['unita_totali'],
        'Unità attive'=>$building['unita_attive'],'Amministratore'=>$building['admin_nome'],
      ];
      if ($building['codice_fiscale']) $info['Cod. Fiscale']=$building['codice_fiscale'];
      foreach($info as $k=>$v): ?>
      <div class="fg">
        <label><?= $k ?></label>
        <div style="padding:6px 0;font-size:.9rem"><?= h($v) ?></div>
      </div>
      <?php endforeach; ?>
      <?php if ($building['note']): ?>
      <div class="fg full">
        <label>Note</label>
        <div style="padding:6px 0;font-size:.9rem;white-space:pre-wrap"><?= h($building['note']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!empty($buildingUsers)): ?>
  <h3 style="margin:18px 0 10px">Condomini (<?= count($buildingUsers) ?>)</h3>
  <div class="tbl-wrap">
    <table>
      <thead><tr><th>Nome</th><th>Unità</th><th>Ruolo</th></tr></thead>
      <tbody>
        <?php foreach($buildingUsers as $bu):
          $ruoloVis = ($bu['ruolo_stabile']==='capocasa' || $bu['ruolo']==='capocasa') ? 'capocasa' : 'user'; ?>
        <tr>
          <td><?= h($bu['nome_completo']) ?> <span class="muted">@<?= h($bu['username']) ?></span></td>
          <td class="muted"><?= h($bu['numero_unita'] ?: '—') ?></td>
          <td><?= badge($ruoloVis,'role') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
// ── Post modal edit/new ───────────────────────────────────────────
function openEditPost(p) {
  document.getElementById('postModalTitle').textContent = 'Modifica messaggio';
  document.getElementById('postAction').value = 'edit_post';
  document.getElementById('postPid').value    = p.id;
  document.getElementById('pTitolo').value    = p.titolo    || '';
  document.getElementById('pContenuto').value = p.contenuto || '';
  var tipoSel = document.getElementById('pTipo');
  tipoSel.value = p.tipo || 'post';
  if (!tipoSel.value) tipoSel.value = 'post'; // tipo non più consentito a questo utente
  var pin = document.getElementById('pPin');
  if (pin) pin.checked = p.in_evidenza == 1;
  openModal('newPost');
}
document.querySelector('[onclick="openModal(\'newPost\')"]')?.addEventListener('click',function(){
  document.getElementById('postModalTitle').textContent = 'Nuovo messaggio';
  document.getElementById('postAction').value = 'new_post';
  document.getElementById('postPid').value    = '0';
  document.getElementById('pTitolo').value    = '';
  document.getElementById('pContenuto').value = '';
  document.getElementById('pTipo').value      = 'post';
  var pin = document.getElementById('pPin'); if(pin) pin.checked=false;
});

// ── Report modal edit/new ─────────────────────────────────────────
function openEditReport(r) {
  document.getElementById('reportModalTitle').textContent = 'Modifica segnalazione';
  document.getElementById('reportAction').value = 'edit_report';
  document.getElementById('reportRid').value    = r.id;
  document.getElementById('rTitolo').value      = r.titolo    || '';
  document.getElementById('rContenuto').value   = r.contenuto || '';
  var grav = document.getElementById('rGrav');
  if (grav) grav.value = r.gravita || 'media';
  var priv = document.getElementById('rPrivata');
  if (priv) { priv.checked = r.privata == 1; togglePrivataAdmin(priv.checked); }
  var pc = document.getElementById('rPrivataCon');
  if (pc) pc.value = r.privata_con_utente || '';
  var file = document.getElementById('rAllegato'); if (file) file.value = '';
  openModal('newReport');
}
document.getElementById('btnNewReport')?.addEventListener('click',function(){
  document.getElementById('reportModalTitle').textContent = 'Nuova segnalazione';
  document.getElementById('reportAction').value = 'new_report';
  document.getElementById('reportRid').value    = '0';
  document.getElementById('rTitolo').value      = '';
  document.getElementById('rContenuto').value   = '';
  var grav=document.getElementById('rGrav'); if(grav) grav.value='media';
  var priv=document.getElementById('rPrivata'); if(priv){ priv.checked=false; togglePrivataAdmin(false); }
  var pc=document.getElementById('rPrivataCon'); if(pc) pc.value='';
  var file=document.getElementById('rAllegato'); if(file) file.value='';
});

function togglePrivataAdmin(checked) {
  var wrap = document.getElementById('privataConWrap');
  if (wrap) wrap.style.display = checked ? 'block' : 'none';
}

// Il toggle AJAX del pallino attività è gestito in assets/js/main.js
</script>


<!-- ── Lightbox immagini ──────────────────────────────────────── -->
<div class="overlay" id="lightboxOverlay" onclick="closeLightbox()" style="z-index:800;align-items:center;justify-content:center;padding:20px">
  <div onclick="event.stopPropagation()" style="max-width:90vw;max-height:90vh;text-align:center;position:relative">
    <img id="lightboxImg" src="" alt=""
         style="max-width:90vw;max-height:80vh;object-fit:contain;border-radius:6px;box-shadow:0 4px 32px rgba(0,0,0,.5)">
    <div id="lightboxCaption" style="color:#fff;font-size:.82rem;margin-top:10px;opacity:.8"></div>
    <button onclick="closeLightbox()"
            style="position:absolute;top:-12px;right:-12px;width:30px;height:30px;border-radius:50%;background:#fff;border:none;cursor:pointer;font-size:1rem;line-height:1;box-shadow:var(--shadow-md)">
      &#x2715;
    </button>
  </div>
</div>

<script>
document.addEventListener('click', function(e){
  var img = e.target.closest('[data-lightbox]');
  if (img) openLightbox(img.dataset.lightbox, img.dataset.caption);
});
function openLightbox(src, caption) {
  document.getElementById('lightboxImg').src         = src;
  document.getElementById('lightboxImg').alt         = caption || '';
  document.getElementById('lightboxCaption').textContent = caption || '';
  document.getElementById('lightboxOverlay').classList.add('open');
}
function closeLightbox() {
  document.getElementById('lightboxOverlay').classList.remove('open');
  setTimeout(function(){ document.getElementById('lightboxImg').src=''; }, 300);
}
document.addEventListener('keydown', function(e){
  if(e.key==='Escape') closeLightbox();
});
</script>

<?php include INCLUDES_PATH.'/footer.php'; ?>
