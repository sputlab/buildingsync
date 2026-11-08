<?php
require_once __DIR__.'/../includes/bootstrap.php';
Auth::requireRole(['admin','superadmin']);

$u    = Auth::user();
$isSA = Auth::isSuperAdmin();
$pageTitle = 'Esporta / Importa dati';

// ── Helper CSV ──────────────────────────────────────────────────
/** Neutralizza le formule (=, +, -, @) che Excel eseguirebbe all'apertura del file */
function csvSafe($v): string {
    $v = (string)$v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) $v = "'".$v;
    return $v;
}

function csvRow(array $row): string {
    return implode(';', array_map(function($v){
        $v = str_replace('"','""',csvSafe($v));
        if (strpbrk($v, ";,\"\r\n") !== false) $v = '"'.$v.'"';
        return $v;
    }, $row))."\r\n";
}

function fmtDateIt(?string $d): string {
    if (!$d || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '';
    if (strlen($d) > 10) return date('d-m-Y H:i', strtotime($d));
    return date('d-m-Y', strtotime($d));
}

/** Converte gg-mm-aaaa, gg/mm/aaaa o aaaa-mm-gg in aaaa-mm-gg; null se non valida */
function parseDateIt(string $d): ?string {
    $d = trim($d);
    if ($d === '') return null;
    if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})#', $d, $m)) $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $d, $m)) $d = "$m[1]-$m[2]-$m[3]";
    return validDate($d);
}

function safeName(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/i', '_', $s), '_') ?: 'stabile';
}

// Il separatore è ";" (standard di Excel in italiano); l'import accetta sia ";" sia ","
function sendCsv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 per Excel
    echo csvRow($headers);
    foreach ($rows as $r) echo csvRow($r);
    exit;
}

function adminFilter(): array {
    global $u, $isSA;
    return $isSA ? [] : [$u['id']];
}

function adminWhere(string $table = 's'): string {
    global $isSA;
    return $isSA ? '' : " AND $table.admin_id=?";
}

/** Nome dello stabile se l'utente corrente lo gestisce, altrimenti null */
function ownedBuilding(int $bid): ?string {
    if (!$bid || !Auth::managesBuilding($bid)) return null;
    return DB::one("SELECT nome FROM buildingsync_stabili WHERE id=?", [$bid])['nome'] ?? null;
}

// ================================================================
// EXPORT
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['export'])) {
    $type = Security::str('export', 'GET', 30);
    $bid  = Security::getInt('bid');
    $p    = adminFilter();

    if (in_array($type, ['utenti_stabile','segnalazioni','attivita'], true)) {
        $bName = ownedBuilding($bid);
        if ($bName === null) { flash('Selezionare uno stabile valido.','error'); redirect(APP_URL.'/admin/export_import.php'); }
    }
    Auth::logAction('export', 'tipo='.$type.($bid ? ' stabile_id='.$bid : ''));

    switch ($type) {

        case 'stabili':
            $rows = DB::all("SELECT s.id,s.nome,s.indirizzo,s.citta,s.provincia,s.cap,
                s.unita_totali,s.unita_attive,s.codice_fiscale,s.attivo,s.note,
                u.nome_completo amministratore
                FROM buildingsync_stabili s JOIN buildingsync_utenti u ON u.id=s.admin_id
                WHERE 1=1".adminWhere('s')." ORDER BY s.nome", $p);
            sendCsv('stabili_'.date('Ymd').'.csv',
                ['ID','Nome','Indirizzo','Città','Provincia','CAP','Unità totali','Unità attive','Cod.Fiscale','Attivo','Note','Amministratore'],
                array_map(function($r){ return [$r['id'],$r['nome'],$r['indirizzo'],$r['citta'],$r['provincia'],$r['cap'],$r['unita_totali'],$r['unita_attive'],$r['codice_fiscale'],$r['attivo'],$r['note'],$r['amministratore']]; }, $rows));
            break;

        case 'utenti':
            if ($isSA) {
                $rows = DB::all("SELECT u.id,u.username,u.nome_completo,u.email,u.telefono,u.ruolo,u.attivo,u.last_login,a.nome_completo admin FROM buildingsync_utenti u LEFT JOIN buildingsync_utenti a ON a.id=u.admin_id WHERE u.ruolo!='superadmin' ORDER BY u.nome_completo");
            } else {
                $rows = DB::all("SELECT u.id,u.username,u.nome_completo,u.email,u.telefono,u.ruolo,u.attivo,u.last_login FROM buildingsync_utenti u WHERE u.admin_id=? ORDER BY u.nome_completo", [$u['id']]);
            }
            $hdr = ['ID','Username','Nome','Email','Telefono','Ruolo','Attivo','Ultimo accesso'];
            if ($isSA) $hdr[] = 'Admin';
            sendCsv('utenti_'.date('Ymd').'.csv', $hdr,
                array_map(function($r) use($isSA){ $row=[$r['id'],$r['username'],$r['nome_completo'],$r['email'],$r['telefono']??'',$r['ruolo'],$r['attivo'],fmtDateIt($r['last_login']??'')]; if($isSA) $row[]=$r['admin']??''; return $row; }, $rows));
            break;

        case 'utenti_stabile':
            $rows = DB::all("SELECT u.id,u.username,u.nome_completo,u.email,u.telefono,us.ruolo_stabile,us.numero_unita,us.attivo,us.assegnato_il FROM buildingsync_utenti u JOIN buildingsync_utenti_stabili us ON us.utente_id=u.id WHERE us.stabile_id=? ORDER BY u.nome_completo", [$bid]);
            sendCsv('utenti_'.safeName($bName).'_'.date('Ymd').'.csv',
                ['ID','Username','Nome','Email','Telefono','Ruolo stabile','Unità','Attivo','Assegnato il'],
                array_map(function($r){ return [$r['id'],$r['username'],$r['nome_completo'],$r['email'],$r['telefono']??'',$r['ruolo_stabile']??'user',$r['numero_unita']??'',$r['attivo'],fmtDateIt($r['assegnato_il']??'')]; }, $rows));
            break;

        case 'segnalazioni':
            $rows = DB::all("SELECT r.id,r.titolo,r.contenuto,r.gravita,r.stato,r.privata,r.archiviata,r.creato_il,r.aggiornato_il,u.nome_completo autore FROM buildingsync_segnalazioni r JOIN buildingsync_utenti u ON u.id=r.utente_id WHERE r.stabile_id=? ORDER BY r.creato_il DESC", [$bid]);
            sendCsv('segnalazioni_'.safeName($bName).'_'.date('Ymd').'.csv',
                ['ID','Titolo','Descrizione','Gravità','Stato','Privata','Archiviata','Creata il','Aggiornata il','Autore'],
                array_map(function($r){ return [$r['id'],$r['titolo'],$r['contenuto'],$r['gravita'],$r['stato'],$r['privata'],$r['archiviata'],fmtDateIt($r['creato_il']),fmtDateIt($r['aggiornato_il']),$r['autore']]; }, $rows));
            break;

        case 'attivita':
            $rows = DB::all("SELECT a.id,a.titolo,a.descrizione,a.categoria,a.stato,a.data_programmata,a.data_scadenza,a.completato_il,a.archiviata,uc.nome_completo creato_da,ua.nome_completo assegnato_a,ucp.nome_completo completato_da FROM buildingsync_attivita a LEFT JOIN buildingsync_utenti uc ON uc.id=a.creato_da LEFT JOIN buildingsync_utenti ua ON ua.id=a.assegnato_a LEFT JOIN buildingsync_utenti ucp ON ucp.id=a.completato_da WHERE a.stabile_id=? ORDER BY a.data_programmata DESC", [$bid]);
            sendCsv('attivita_'.safeName($bName).'_'.date('Ymd').'.csv',
                ['ID','Titolo','Descrizione','Categoria','Stato','Data programmata','Scadenza','Completata il','Archiviata','Creata da','Assegnata a','Completata da'],
                array_map(function($r){ return [$r['id'],$r['titolo'],$r['descrizione']??'',$r['categoria'],$r['stato'],fmtDateIt($r['data_programmata']),fmtDateIt($r['data_scadenza']??''),fmtDateIt($r['completato_il']??''),$r['archiviata'],$r['creato_da']??'',$r['assegnato_a']??'',$r['completato_da']??'']; }, $rows));
            break;

        default:
            flash('Tipo di export non valido.','error');
            redirect(APP_URL.'/admin/export_import.php');
    }
}

// ================================================================
// IMPORT
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::checkCsrf();
    $type = Security::postStr('import_type', 30);
    $bid  = Security::postInt('import_bid');
    $back = APP_URL.'/admin/export_import.php';

    if (!in_array($type, ['utenti','stabili','segnalazioni','attivita'], true)) { flash('Tipo di importazione non valido.','error'); redirect($back); }
    if (empty($_FILES['csvfile']['tmp_name']) || !is_uploaded_file($_FILES['csvfile']['tmp_name'])) { flash('Selezionare un file CSV.', 'error'); redirect($back); }
    if ($_FILES['csvfile']['size'] > 5 * 1024 * 1024) { flash('File troppo grande (max 5 MB).', 'error'); redirect($back); }

    // Stabile obbligatorio per segnalazioni/attività, facoltativo per utenti: deve essere dell'admin corrente
    if ($bid && ownedBuilding($bid) === null) { flash('Stabile non valido.','error'); redirect($back); }
    if (in_array($type, ['segnalazioni','attivita'], true) && !$bid) { flash('Selezionare lo stabile di destinazione.','error'); redirect($back); }

    $raw = file_get_contents($_FILES['csvfile']['tmp_name']);
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); // CSV salvati da Excel "ANSI"
    // Separatore: quello più presente nella prima riga tra ; , e TAB
    $firstLine = strtok($raw, "\n");
    $cand = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
    arsort($cand);
    $sep = key($cand);

    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $raw); rewind($fp);
    $header = fgetcsv($fp, 0, $sep, '"', '');
    $rows   = [];
    while (($row = fgetcsv($fp, 0, $sep, '"', '')) !== false) {
        if (array_filter($row, function($c){ return trim((string)$c) !== ''; })) {
            // toglie l'apostrofo anti-formula aggiunto dall'export
            $rows[] = array_map(function($c){ $c = trim((string)$c); return (strlen($c) > 1 && $c[0] === "'" && strpbrk($c[1], "=+-@") !== false) ? substr($c, 1) : $c; }, $row);
        }
    }
    fclose($fp);
    if (!$rows) { flash('Il file non contiene righe da importare.','error'); redirect($back); }

    // Se la prima colonna dell'intestazione è "ID" (file prodotto dall'export) si scarta
    $hasId = isset($header[0]) && strtolower(trim($header[0])) === 'id';

    $ok = 0; $skip = 0; $errors = [];
    $pdo = DB::get();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $i => $r) {
            $ln = $i + 2;
            // Stabili e utenti: il template non ha la colonna ID; segnalazioni/attività sì (ignorata)
            if ($type === 'stabili' || $type === 'utenti') { if ($hasId) array_shift($r); }
            else { array_shift($r); }

            if ($type === 'stabili') {
                [$nome, $ind, $citta] = [$r[0] ?? '', $r[1] ?? '', $r[2] ?? ''];
                if (!$nome || !$ind || !$citta) { $errors[] = "Riga $ln: nome, indirizzo, città obbligatori"; $skip++; continue; }
                DB::insert('buildingsync_stabili',[
                    'admin_id'=>$u['id'],'nome'=>mb_substr($nome,0,150),'indirizzo'=>mb_substr($ind,0,255),'citta'=>mb_substr($citta,0,100),
                    'provincia'=>strtoupper(mb_substr($r[3]??'',0,5)),'cap'=>mb_substr($r[4]??'',0,10),
                    'unita_totali'=>max(0,(int)($r[5]??0)),'unita_attive'=>max(0,(int)($r[6]??0)),
                    'codice_fiscale'=>mb_substr($r[7]??'',0,20),'attivo'=>1,'creato_il'=>date('Y-m-d H:i:s'),
                ]);
                $ok++;
            }

            elseif ($type === 'segnalazioni') {
                $titolo = mb_substr($r[0] ?? '', 0, 200);
                $grav   = in_array($r[2]??'',['bassa','media','alta','critica'],true)?$r[2]:'media';
                $stato  = in_array($r[3]??'',['aperta','in_lavorazione','risolta','chiusa'],true)?$r[3]:'aperta';
                if (!$titolo) { $errors[] = "Riga $ln: titolo obbligatorio"; $skip++; continue; }
                DB::insert('buildingsync_segnalazioni',[
                    'stabile_id'=>$bid,'utente_id'=>$u['id'],'titolo'=>$titolo,
                    'contenuto'=>mb_substr($r[1]??'',0,4000),'gravita'=>$grav,'stato'=>$stato,
                    'privata'=>0,'creato_il'=>date('Y-m-d H:i:s'),
                ]);
                $ok++;
            }

            elseif ($type === 'attivita') {
                $titolo = mb_substr($r[0] ?? '', 0, 200);
                $cat    = array_key_exists($r[2]??'', catLabels()) ? $r[2] : 'altro';
                $stato  = in_array($r[3]??'',['da_fare','in_corso','completata','annullata'],true)?$r[3]:'da_fare';
                $dataP  = parseDateIt($r[4] ?? '');
                $dataS  = parseDateIt($r[5] ?? '');
                if (!$titolo || !$dataP) { $errors[] = "Riga $ln: titolo e data programmata valida obbligatori"; $skip++; continue; }
                if ($dataS && $dataS < $dataP) $dataS = null;
                DB::insert('buildingsync_attivita',[
                    'stabile_id'=>$bid,'creato_da'=>$u['id'],'titolo'=>$titolo,
                    'descrizione'=>mb_substr($r[1]??'',0,2000),'categoria'=>$cat,'stato'=>$stato,
                    'data_programmata'=>$dataP,'data_scadenza'=>$dataS,
                    'creato_il'=>date('Y-m-d H:i:s'),
                ]);
                $ok++;
            }

            elseif ($type === 'utenti') {
                // Formato: username, nome_completo, email, telefono, ruolo
                $username = strtolower($r[0] ?? '');
                $nome     = mb_substr($r[1] ?? '', 0, 120);
                $email    = filter_var($r[2] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
                $ruolo    = in_array($r[4]??'',['user','capocasa','admin'],true)?$r[4]:'user';
                if (!$isSA && $ruolo==='admin') $ruolo='user';
                if (!$username || !$nome) { $errors[] = "Riga $ln: username e nome obbligatori"; $skip++; continue; }
                if (!preg_match('/^[a-z0-9_\.]{3,50}$/', $username)) { $errors[] = "Riga $ln: username non valido"; $skip++; continue; }
                if (DB::one("SELECT id FROM buildingsync_utenti WHERE username=?",[$username])) { $errors[] = "Riga $ln: username '$username' già esistente"; $skip++; continue; }
                // Password temporanea = Username + "Tmp1" (comunicata all'admin nella pagina)
                $tmpPass = ucfirst($username).'Tmp1';
                $newId = DB::insert('buildingsync_utenti',[
                    'username'      => $username,
                    'password_hash' => Security::hashPassword($tmpPass),
                    'password_da_cambiare' => 1,
                    'nome_completo' => $nome,
                    'email'         => $email,
                    'telefono'      => mb_substr($r[3] ?? '', 0, 30),
                    'ruolo'         => $ruolo,
                    'admin_id'      => $u['id'],
                    'attivo'        => 1,
                    'creato_il'     => date('Y-m-d H:i:s'),
                ]);
                if ($bid && $ruolo !== 'admin') {
                    DB::insert('buildingsync_utenti_stabili',[
                        'stabile_id'=>$bid,'utente_id'=>$newId,
                        'ruolo_stabile'=>$ruolo==='capocasa'?'capocasa':'user','attivo'=>1,'assegnato_il'=>date('Y-m-d H:i:s'),
                    ]);
                }
                $ok++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('import '.$type.': '.$e->getMessage());
        flash('Importazione annullata: errore alla riga '.($ln ?? '?').'. Nessun dato è stato inserito.', 'error');
        redirect($back);
    }

    $labels = ['utenti'=>'Utenti','stabili'=>'Stabili','segnalazioni'=>'Segnalazioni','attivita'=>'Attività'];
    $msg = $labels[$type]." importati: $ok, saltati: $skip.";
    if ($errors) $msg .= ' Errori: '.implode('; ', array_slice($errors,0,5)).(count($errors) > 5 ? ' …' : '');
    Auth::logAction('import', "tipo=$type ok=$ok skip=$skip");
    flash($msg, $errors ? 'warn' : 'success');
    redirect($back);
}

// ── GET: carica stabili per i select ───────────────────────────
if ($isSA) {
    $mybBuildings = DB::all("SELECT id,nome FROM buildingsync_stabili ORDER BY nome");
} else {
    $mybBuildings = DB::all("SELECT id,nome FROM buildingsync_stabili WHERE admin_id=? ORDER BY nome", [$u['id']]);
}

include INCLUDES_PATH.'/header.php';
?>

<div class="page-hd">
  <div>
    <h1>Esporta / Importa dati</h1>
    <p class="sub">Esporta in CSV o importa utenti da file CSV</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<!-- EXPORT -->
<div class="card">
  <div class="card-head"><span class="card-title">&#x1F4E4; Esporta CSV</span></div>

  <p style="font-size:.85rem;color:var(--gray-600);margin-bottom:16px">
    I file sono in formato CSV UTF-8 con separatore <code>;</code> (si aprono direttamente in Excel italiano).
  </p>

  <div style="display:flex;flex-direction:column;gap:10px">
    <div style="padding:12px;background:var(--gray-50);border:var(--border);border-radius:var(--radius)">
      <div style="font-weight:600;font-size:.88rem;margin-bottom:8px">Stabili</div>
      <a href="?export=stabili" class="btn btn-outline btn-sm">&#x1F4C5; Esporta tutti gli stabili</a>
    </div>

    <div style="padding:12px;background:var(--gray-50);border:var(--border);border-radius:var(--radius)">
      <div style="font-weight:600;font-size:.88rem;margin-bottom:8px">Utenti</div>
      <a href="?export=utenti" class="btn btn-outline btn-sm">&#x1F4C5; Esporta tutti gli utenti</a>
    </div>

    <div style="padding:12px;background:var(--gray-50);border:var(--border);border-radius:var(--radius)">
      <div style="font-weight:600;font-size:.88rem;margin-bottom:8px">Dati per stabile</div>
      <form method="GET" class="flex" style="flex-wrap:wrap;gap:8px;margin-bottom:8px">
        <select name="bid" style="width:auto;flex:1">
          <option value="">— Seleziona stabile —</option>
          <?php foreach ($mybBuildings as $b): ?>
          <option value="<?= $b['id'] ?>"><?= h($b['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="export" value="utenti_stabile" class="btn btn-outline btn-sm">Utenti stabile</button>
        <button type="submit" name="export" value="segnalazioni"   class="btn btn-outline btn-sm">Segnalazioni</button>
        <button type="submit" name="export" value="attivita"       class="btn btn-outline btn-sm">Attività</button>
      </form>
    </div>
  </div>
</div>

<!-- IMPORT -->
<div class="card">
  <div class="card-head"><span class="card-title">&#x1F4E5; Importa CSV</span></div>

  <div class="alert alert-info" style="font-size:.82rem">
    <div>
      <strong>Formato richiesto per importazione utenti:</strong><br>
      Colonne: <code>username, nome_completo, email, telefono, ruolo</code><br>
      Ruolo: <code>user</code> / <code>capocasa</code><?= $isSA ? ' / <code>admin</code>' : '' ?><br>
      La prima riga deve essere l'intestazione. Password temporanea: <code>Username+Tmp1</code> (da cambiare obbligatoriamente al primo accesso)
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data" style="margin-top:16px">
    <?= Security::csrfField() ?>
    <div class="fg" style="margin-bottom:12px">
      <label>Tipo di importazione</label>
      <select name="import_type" id="importType" onchange="toggleBidField()">
        <option value="utenti">Utenti</option>
        <option value="stabili">Stabili</option>
        <option value="segnalazioni">Segnalazioni (richiede stabile)</option>
        <option value="attivita">Attività (richiede stabile)</option>
      </select>
    </div>
    <div class="fg" id="importBidWrap" style="margin-bottom:12px">
      <label>Stabile di riferimento</label>
      <select name="import_bid">
        <option value="">— Nessuno —</option>
        <?php foreach ($mybBuildings as $b): ?>
        <option value="<?= $b['id'] ?>"><?= h($b['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg" style="margin-bottom:16px">
      <label>File CSV (separatore ; oppure , &mdash; UTF-8) <span class="req">*</span></label>
      <input type="file" name="csvfile" accept=".csv,text/csv" required>
    </div>
    <button type="submit" class="btn btn-primary">Importa</button>
  </form>
  <script>
  function toggleBidField(){
    var t=document.getElementById('importType').value;
    var wrap=document.getElementById('importBidWrap');
    if(wrap) wrap.style.display=(t==='segnalazioni'||t==='attivita'||t==='utenti')?'block':'none';
  }
  document.addEventListener('DOMContentLoaded',toggleBidField);
  </script>

  <div style="margin-top:20px;padding-top:14px;border-top:var(--border)">
    <p style="font-size:.82rem;font-weight:600;color:var(--gray-700);margin-bottom:8px">Template CSV utenti</p>
    <p style="font-size:.78rem;color:var(--gray-500)"><strong>Utenti:</strong> username, nome_completo, email, telefono, ruolo<br>
    <strong>Stabili:</strong> nome, indirizzo, città, provincia, cap, unità_totali, unità_attive, cod_fiscale (accettato anche il file dell'export, con colonna ID)<br>
    <strong>Segnalazioni:</strong> (id ignorato), titolo, descrizione, gravità, stato<br>
    <strong>Attività:</strong> (id ignorato), titolo, descrizione, categoria, stato, data_programmata (gg-mm-aaaa), scadenza</p>
    <p style="font-size:.75rem;color:var(--gray-400);margin-top:6px">Per segnalazioni e attività selezionare lo stabile di destinazione. Le date devono essere in formato gg-mm-aaaa.</p>
  </div>
</div>

</div>

<?php include INCLUDES_PATH.'/footer.php'; ?>
