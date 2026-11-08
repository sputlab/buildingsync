<?php
require_once __DIR__.'/includes/bootstrap.php';
Auth::requireLogin();

$u         = Auth::user();
$pageTitle = 'Dashboard';
$buildings = Auth::myBuildings();

$stats = [];
if (Auth::isAdmin()) {
    if (Auth::isSuperAdmin()) {
        $where  = '';
        $params = [];
    } else {
        $where  = 'WHERE admin_id=?';
        $params = [$u['id']];
    }
    $stats['stabili'] = (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_stabili $where", $params)['n']);

    $bids = array_column(DB::all("SELECT id FROM buildingsync_stabili $where", $params), 'id');
    if ($bids) {
        $ph = implode(',', array_fill(0, count($bids), '?'));
        $stats['utenti']       = (int)(DB::one("SELECT COUNT(DISTINCT utente_id) n FROM buildingsync_utenti_stabili WHERE stabile_id IN ($ph)", $bids)['n']);
        $stats['seg_aperte']   = (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_segnalazioni WHERE stabile_id IN ($ph) AND archiviata=0 AND stato='aperta'", $bids)['n']);
        $stats['att_scadenza'] = (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_attivita WHERE stabile_id IN ($ph) AND archiviata=0 AND stato NOT IN ('completata','annullata') AND data_scadenza <= CURDATE() + INTERVAL 7 DAY AND data_scadenza >= CURDATE()", $bids)['n']);
    } else {
        $stats['utenti'] = $stats['seg_aperte'] = $stats['att_scadenza'] = 0;
    }
    if (Auth::isSuperAdmin()) {
        $stats['admins'] = (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_utenti WHERE ruolo='admin' AND attivo=1")['n']);
    }
    $allBids = $bids;
} else {
    $myBids = array_column($buildings, 'id');
    $allBids = $myBids;
    if ($myBids) {
        $ph = implode(',', array_fill(0, count($myBids), '?'));
        $stats['stabili']      = count($myBids);
        $pSeg  = $myBids; $pSeg[]  = $u['id'];
        $pAtt  = $myBids; $pAtt[]  = $u['id'];
        $stats['seg_aperte']   = (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_segnalazioni WHERE stabile_id IN ($ph) AND utente_id=? AND archiviata=0 AND stato='aperta'", $pSeg)['n']);
        $stats['att_assegnate']= (int)(DB::one("SELECT COUNT(*) n FROM buildingsync_attivita WHERE stabile_id IN ($ph) AND assegnato_a=? AND archiviata=0 AND stato NOT IN ('completata','annullata')", $pAtt)['n']);
    }
}

// Attività in scadenza
$upcoming = [];
if (!empty($allBids)) {
    $ph2 = implode(',', array_fill(0, count($allBids), '?'));
    $upcoming = DB::all(
        "SELECT a.*, s.nome stabile_nome, u2.nome_completo assegnato_nome
         FROM buildingsync_attivita a
         JOIN buildingsync_stabili s ON s.id=a.stabile_id
         LEFT JOIN buildingsync_utenti u2 ON u2.id=a.assegnato_a
         WHERE a.stabile_id IN ($ph2)
           AND a.archiviata=0
           AND a.stato NOT IN ('completata','annullata')
           AND a.data_scadenza IS NOT NULL
         ORDER BY a.data_scadenza ASC LIMIT 8",
        $allBids
    );
}

include INCLUDES_PATH.'/header.php';
?>

<div class="page-hd">
  <div>
    <h1>Bentornato, <?= h(explode(' ', $u['nome_completo'])[0]) ?>!</h1>
    <p class="sub"><?= h(roleLabel($u['ruolo'])) ?> — <?= date('d/m/Y') ?></p>
  </div>
</div>

<?php
  // Link segnalazioni/attività: primo stabile disponibile
  $firstBid = !empty($buildings) ? (int)$buildings[0]['id'] : null;
  $segUrl = $firstBid ? APP_URL.'/stabile.php?id='.$firstBid.'&tab=segnalazioni' : APP_URL.'/dashboard.php';
  $attUrl = $firstBid ? APP_URL.'/stabile.php?id='.$firstBid.'&tab=attivita'    : APP_URL.'/dashboard.php';
  // I condomini non possono aprire la gestione stabili (403): li si porta al proprio stabile
  $stabUrl = Auth::isAdmin() ? APP_URL.'/admin/stabili.php' : ($firstBid ? APP_URL.'/stabile.php?id='.$firstBid : APP_URL.'/profilo.php');
?>
<div class="stat-row">
  <a href="<?= h($stabUrl) ?>" class="stat stat-link" style="text-decoration:none;color:inherit" title="<?= Auth::isAdmin() ? 'Gestisci stabili' : 'I miei stabili' ?>">
    <span class="stat-ic">&#x1F3E2;</span>
    <div><div class="stat-val"><?= (int)($stats['stabili'] ?? 0) ?></div><div class="stat-lbl">Stabili</div></div>
  </a>
  <?php if (Auth::isSuperAdmin()): ?>
  <a href="<?= APP_URL ?>/admin/admins.php" class="stat stat-link" style="text-decoration:none;color:inherit" title="Gestisci amministratori">
    <span class="stat-ic">&#x1F464;</span>
    <div><div class="stat-val"><?= (int)($stats['admins'] ?? 0) ?></div><div class="stat-lbl">Amministratori</div></div>
  </a>
  <?php endif; ?>
  <?php if (Auth::isAdmin()): ?>
  <a href="<?= APP_URL ?>/admin/utenti.php" class="stat stat-link" style="text-decoration:none;color:inherit" title="Gestisci utenti">
    <span class="stat-ic">&#x1F465;</span>
    <div><div class="stat-val"><?= (int)($stats['utenti'] ?? 0) ?></div><div class="stat-lbl">Utenti totali</div></div>
  </a>
  <?php endif; ?>
  <a href="<?= h($segUrl) ?>" class="stat stat-link" style="text-decoration:none;color:inherit" title="Vai alle segnalazioni">
    <span class="stat-ic">&#x1F6A8;</span>
    <div><div class="stat-val"><?= (int)($stats['seg_aperte'] ?? 0) ?></div><div class="stat-lbl">Segnalazioni aperte</div></div>
  </a>
  <?php if (Auth::isAdmin()): ?>
  <a href="<?= h($attUrl) ?>" class="stat stat-link" style="text-decoration:none;color:inherit" title="Vai alle attività">
    <span class="stat-ic">&#x23F0;</span>
    <div><div class="stat-val"><?= (int)($stats['att_scadenza'] ?? 0) ?></div><div class="stat-lbl">Att. in scadenza</div></div>
  </a>
  <?php else: ?>
  <a href="<?= h($attUrl) ?>" class="stat stat-link" style="text-decoration:none;color:inherit" title="Vai alle attività">
    <span class="stat-ic">&#x1F4CB;</span>
    <div><div class="stat-val"><?= (int)($stats['att_assegnate'] ?? 0) ?></div><div class="stat-lbl">Att. assegnate</div></div>
  </a>
  <?php endif; ?>
</div>

<?php $showBuildingCard = Auth::isAdmin(); ?>
<div style="display:grid;grid-template-columns:<?= $showBuildingCard ? '1fr 1fr' : '1fr' ?>;gap:20px">
  <?php if ($showBuildingCard): ?>
  <div class="card">
    <div class="card-head">
      <span class="card-title">I tuoi stabili</span>
      <a href="<?= APP_URL ?>/admin/stabili.php" class="btn btn-sm btn-outline">Gestisci &rsaquo;</a>
    </div>
    <?php if (empty($buildings)): ?>
    <div class="empty"><span class="ic">&#x1F3E2;</span><p>Nessuno stabile creato</p></div>
    <?php else: foreach ($buildings as $b): ?>
    <a href="<?= APP_URL ?>/stabile.php?id=<?= $b['id'] ?>" class="building-link">
      <div>
        <div style="font-weight:600;font-size:.88rem"><?= h($b['nome']) ?></div>
        <div class="muted"><?= h($b['indirizzo'].', '.$b['citta']) ?></div>
      </div>
      <?php if (!$b['attivo']): ?><span class="badge badge-off">Inattivo</span><?php endif; ?>
    </a>
    <?php endforeach; endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head"><span class="card-title">Prossime attività</span></div>
    <?php if (empty($upcoming)): ?>
    <div class="empty"><span class="ic">✅</span><p>Nessuna attività in scadenza</p></div>
    <?php else: foreach ($upcoming as $act):
      $overdue = $act['data_scadenza'] && $act['data_scadenza'] < date('Y-m-d');
    ?>
    <div class="flex" style="padding:9px 0;border-bottom:1px solid var(--gray-200)">
      <span><?= catIcon($act['categoria']) ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:.88rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($act['titolo']) ?></div>
        <div class="muted"><?= h($act['stabile_nome']) ?> — <span class="<?= $overdue ? 'overdue' : '' ?>"><?= formatDate($act['data_scadenza']) ?></span></div>
      </div>
      <?= badge($act['stato'], 'activity') ?>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php include INCLUDES_PATH.'/footer.php'; ?>
