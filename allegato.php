<?php
// allegato.php — Serve gli allegati delle segnalazioni dopo il controllo dei permessi.
// La cartella uploads/ non è accessibile direttamente (vedi uploads/.htaccess), quindi
// anche gli allegati delle segnalazioni private restano visibili solo a chi può vederle.
require_once __DIR__.'/includes/bootstrap.php';
Auth::requireLogin();

$id = Security::getInt('id');
$al = $id ? DB::one(
    "SELECT a.*, s.stabile_id, s.utente_id, s.privata, s.privata_con_utente
     FROM buildingsync_segnalazioni_allegati a
     JOIN buildingsync_segnalazioni s ON s.id=a.segnalazione_id
     WHERE a.id=? LIMIT 1",
    [$id]
) : null;

if (!$al || !Auth::canAccessBuilding((int)$al['stabile_id'])) {
    http_response_code(404); exit('Allegato non trovato.');
}
$isAdmin = Auth::buildingRole((int)$al['stabile_id']) === 'admin';
if (!canSeeReport($al, Auth::user(), $isAdmin)) {
    http_response_code(404); exit('Allegato non trovato.');
}

$path = UPLOAD_PATH.basename($al['filename']);
if (!is_file($path) || !in_array($al['mime_type'], UPLOAD_TYPES, true)) {
    http_response_code(404); exit('File non disponibile.');
}

// Rilascia il lock di sessione: il browser può caricare più miniature in parallelo
session_write_close();

$etag = '"'.md5($al['filename'].filemtime($path)).'"';
header('Cache-Control: private, max-age=86400');
header('ETag: '.$etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

header('Content-Type: '.$al['mime_type']);
header('Content-Length: '.filesize($path));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
$fname = preg_replace('/[^\w.\- ]/u', '_', $al['original_name']);
header('Content-Disposition: inline; filename="'.$fname.'"; filename*=UTF-8\'\''.rawurlencode($al['original_name']));
readfile($path);
