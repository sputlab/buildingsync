<?php
// includes/header.php — HTML head + sidebar
// Variabili attese: $pageTitle (string)
$u = Auth::user();
$myBuildings = Auth::myBuildings();
$flash = getFlash();

$currentFile = basename($_SERVER['PHP_SELF'], '.php');
$currentBid  = Security::getInt('id');

function navActive(string $page): string {
    global $currentFile;
    return $currentFile === $page ? ' active' : '';
}
function navBActive(int $id): string {
    global $currentFile, $currentBid;
    return ($currentFile === 'stabile' && $currentBid === $id) ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= h($pageTitle ?? '') ?> — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= h(APP_VERSION) ?>">
</head>
<body>
<div class="app-shell">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <button class="info-btn" onclick="openModal('modalInfo')" title="Informazioni su <?= h(APP_NAME) ?>" aria-label="Informazioni">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
      </svg>
    </button>
    <span class="brand-icon">🏛</span>
    <div>
      <strong class="brand-name"><?= h(APP_NAME) ?></strong>
      <span class="brand-role"><?= h(roleLabel($u['ruolo'])) ?></span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <a href="<?= APP_URL ?>/dashboard.php" class="nav-link<?= navActive('dashboard') ?>">
      <span class="nav-ic">🏠</span> Dashboard
    </a>

    <?php if (Auth::isSuperAdmin()): ?>
    <div class="nav-section">Gestione Sistema</div>
    <a href="<?= APP_URL ?>/admin/admins.php" class="nav-link<?= navActive('admins') ?>">
      <span class="nav-ic">👤</span> Amministratori
    </a>
    <?php endif; ?>

    <?php if (Auth::isAdmin()): ?>
    <div class="nav-section"><?= Auth::isSuperAdmin() ? 'Tutti gli stabili' : 'Gestione' ?></div>
    <a href="<?= APP_URL ?>/admin/stabili.php" class="nav-link<?= navActive('stabili') ?>">
      <span class="nav-ic">🏢</span> Stabili
    </a>
    <a href="<?= APP_URL ?>/admin/utenti.php" class="nav-link<?= navActive('utenti') ?>">
      <span class="nav-ic">👥</span> Utenti
    </a>
    <a href="<?= APP_URL ?>/admin/export_import.php" class="nav-link<?= navActive('export_import') ?>">
      <span class="nav-ic">&#x1F4C4;</span> Esporta / Importa
    </a>
    <?php endif; ?>

    <?php if (!empty($myBuildings)): ?>
    <div class="nav-section">I miei stabili</div>
    <?php foreach ($myBuildings as $b): ?>
    <a href="<?= APP_URL ?>/stabile.php?id=<?= $b['id'] ?>"
       class="nav-link nav-building<?= navBActive((int)$b['id']) ?>"
       title="<?= h($b['nome']) ?>">
      <span class="nav-ic">🏠</span>
      <span class="nav-label"><?= h($b['nome']) ?></span>
      <?php if (!$b['attivo']): ?><span class="chip-off">off</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-foot">
    <div class="foot-avatar"><?= strtoupper(mb_substr($u['nome_completo'], 0, 1)) ?></div>
    <div class="foot-info">
      <strong><?= h($u['nome_completo']) ?></strong>
      <span>@<?= h($u['username']) ?></span>
    </div>
    <a href="<?= APP_URL ?>/profilo.php" class="foot-btn" title="Profilo">⚙</a>
    <a href="<?= APP_URL ?>/logout.php" class="foot-btn danger" title="Esci">⏏</a>
  </div>
</aside>

<!-- TOGGLE MOBILE -->
<button class="mob-toggle" id="mobToggle" aria-label="Menu" aria-controls="sidebar">☰</button>

<!-- MAIN -->
<main class="main">
<?php if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible">
  <?= h($flash['msg']) ?>
  <button type="button" onclick="this.parentElement.remove()" aria-label="Chiudi">×</button>
</div>
<?php endif; ?>
