<?php
// privacy.php — Informativa sul trattamento dei dati personali (artt. 13-14 Reg. UE 2016/679)
// Pagina pubblica: raggiungibile senza login (schermata di accesso, modale Informazioni, email).
// I dati del titolare si configurano in config.php (costanti PRIVACY_*).
require_once __DIR__.'/includes/bootstrap.php';

$logged     = Auth::check();
$backUrl    = $logged ? APP_URL.'/dashboard.php' : APP_URL.'/login.php';
$backLabel  = $logged ? 'Torna alla dashboard' : 'Torna al login';
$logDays    = (int)LOG_RETENTION_DAYS;
$idleMin    = (int)round(SESSION_LIFETIME / 60);
$https      = strpos(APP_URL, 'https://') === 0;
$pageTitle  = 'Informativa privacy';
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= h($pageTitle) ?> — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= h(APP_VERSION) ?>">
</head>
<body>
<div class="privacy-wrap">
<article class="privacy-box">

  <p style="margin-bottom:14px"><a href="<?= h($backUrl) ?>">&larr; <?= h($backLabel) ?></a></p>

  <h1>Informativa sul trattamento dei dati personali</h1>
  <p class="sub">ai sensi degli artt. 13 e 14 del Regolamento (UE) 2016/679 (&laquo;GDPR&raquo;) e del D.Lgs. 196/2003 &mdash;
     piattaforma <?= h(APP_NAME) ?> &mdash; ultimo aggiornamento: <?= h(formatDate(APP_VERSION_DATE)) ?> (v<?= h(APP_VERSION) ?>)</p>

  <nav class="privacy-toc" aria-label="Indice">
    <ol>
      <li><a href="#titolare">Titolare del trattamento</a></li>
      <li><a href="#dati">Dati trattati</a></li>
      <li><a href="#finalita">Finalità e basi giuridiche</a></li>
      <li><a href="#conferimento">Natura del conferimento</a></li>
      <li><a href="#visibilita">Chi può vedere i dati</a></li>
      <li><a href="#destinatari">Destinatari esterni e trasferimenti</a></li>
      <li><a href="#conservazione">Tempi di conservazione</a></li>
      <li><a href="#cookie">Cookie</a></li>
      <li><a href="#email">Comunicazioni email</a></li>
      <li><a href="#sicurezza">Misure di sicurezza</a></li>
      <li><a href="#diritti">Diritti dell'interessato</a></li>
      <li><a href="#modifiche">Modifiche all'informativa</a></li>
    </ol>
  </nav>

  <h2 id="titolare">1. Titolare del trattamento</h2>
  <p>Il titolare del trattamento è <strong><?= h(PRIVACY_TITOLARE) ?></strong><?= PRIVACY_INDIRIZZO ? ', '.h(PRIVACY_INDIRIZZO) : '' ?>,
     contattabile all'indirizzo <a href="mailto:<?= h(PRIVACY_EMAIL) ?>"><?= h(PRIVACY_EMAIL) ?></a>.</p>
  <?php if (PRIVACY_DPO): ?>
  <p>Il Responsabile della Protezione dei Dati (RPD/DPO) è contattabile all'indirizzo
     <a href="mailto:<?= h(PRIVACY_DPO) ?>"><?= h(PRIVACY_DPO) ?></a>.</p>
  <?php endif; ?>
  <p>Per i dati relativi alla vita condominiale, ciascun amministratore dello stabile opera nell'ambito del proprio incarico
     di amministrazione. La piattaforma tecnica è gestita da <?= h(PRIVACY_GESTORE) ?>, che tratta i dati solo per
     fornire il servizio e per le finalità di sicurezza indicate di seguito.</p>

  <h2 id="dati">2. Dati trattati</h2>
  <div class="tbl-wrap">
  <table>
    <thead><tr><th>Categoria</th><th>Dati</th><th>Origine</th></tr></thead>
    <tbody>
      <tr><td><strong>Dati dell'account</strong></td>
          <td>username, nome e cognome, indirizzo email, telefono, note, ruolo (condomino, capocasa, amministratore),
              stabile e numero di unità/interno, preferenza sulle notifiche email. La password non viene mai
              conservata in chiaro: viene salvata solo un'impronta crittografica (bcrypt).</td>
          <td>inseriti dall'amministratore alla creazione dell'account (anche tramite importazione CSV) e aggiornabili
              dall'utente nel proprio profilo</td></tr>
      <tr><td><strong>Contenuti</strong></td>
          <td>messaggi e avvisi in bacheca, segnalazioni (pubbliche o private) con eventuali foto allegate,
              attività e incarichi assegnati, date di completamento</td>
          <td>inseriti dagli utenti e dagli amministratori</td></tr>
      <tr><td><strong>Dati tecnici e di sicurezza</strong></td>
          <td>indirizzo IP, tipo di browser (user agent), data e ora di accessi, uscite, tentativi di accesso falliti,
              blocchi temporanei, richieste di reset password, cambi password, esportazioni/importazioni ed eliminazioni;
              registro delle email inviate (destinatario, oggetto, esito)</td>
          <td>generati automaticamente dal sistema durante l'uso</td></tr>
    </tbody>
  </table>
  </div>
  <p>La piattaforma non richiede categorie particolari di dati (art. 9 GDPR, ad esempio dati sulla salute) né dati
     relativi a condanne penali. Si invitano gli utenti a <strong>non inserire</strong> tali informazioni nei messaggi,
     nelle segnalazioni e nelle foto, e a non fotografare persone riconoscibili, targhe o documenti se non strettamente necessario.</p>

  <h2 id="finalita">3. Finalità e basi giuridiche</h2>
  <div class="tbl-wrap">
  <table>
    <thead><tr><th>Finalità</th><th>Base giuridica</th></tr></thead>
    <tbody>
      <tr><td>Creazione e gestione dell'account, accesso all'area riservata dello stabile</td>
          <td>esecuzione del rapporto di amministrazione condominiale e del servizio (art. 6.1.b GDPR)</td></tr>
      <tr><td>Comunicazioni condominiali: bacheca, avvisi, segnalazioni, pianificazione di attività e manutenzioni</td>
          <td>esecuzione del rapporto (art. 6.1.b) e adempimento degli obblighi dell'amministratore previsti dal
              Codice civile, artt. 1129-1130 (art. 6.1.c)</td></tr>
      <tr><td>Invio di notifiche email relative agli eventi dello stabile</td>
          <td>esecuzione del rapporto (art. 6.1.b); disattivabili in ogni momento dal profilo</td></tr>
      <tr><td>Sicurezza della piattaforma: autenticazione, prevenzione di accessi abusivi, registro delle operazioni</td>
          <td>legittimo interesse del titolare alla sicurezza dei sistemi e dei dati (art. 6.1.f e art. 32 GDPR)</td></tr>
      <tr><td>Esportazione di dati da parte dell'amministratore (archivio, obblighi di legge, passaggio di consegne)</td>
          <td>adempimento di obblighi legali (art. 6.1.c) e legittimo interesse (art. 6.1.f)</td></tr>
    </tbody>
  </table>
  </div>
  <p>I dati non sono utilizzati per finalità di marketing, profilazione o decisioni basate unicamente su trattamenti automatizzati.</p>

  <h2 id="conferimento">4. Natura del conferimento</h2>
  <p>Username, nome e cognome sono necessari per creare l'account: senza di essi non è possibile utilizzare la piattaforma.
     L'indirizzo email è necessario per ricevere le notifiche e per il recupero autonomo della password.
     Telefono e note sono facoltativi.</p>

  <h2 id="visibilita">5. Chi può vedere i dati all'interno della piattaforma</h2>
  <ul>
    <li><strong>Gli altri condomini dello stesso stabile</strong> vedono: nome, username, ruolo nello stabile e numero di unità
        (sezione &laquo;Informazioni&raquo;); i messaggi in bacheca con il nome dell'autore; le segnalazioni <em>non private</em>
        con nome dell'autore e foto allegate; le attività con il nome della persona incaricata.
        Email, telefono e note <strong>non</strong> sono visibili agli altri condomini.</li>
    <li><strong>Le segnalazioni private</strong> e le relative foto sono visibili solo all'autore, all'eventuale destinatario
        indicato dall'amministratore e all'amministratore dello stabile.</li>
    <li><strong>L'amministratore dello stabile</strong> vede tutti i dati degli utenti dei propri stabili (incluse email, telefono
        e note) e tutte le segnalazioni, anche private; può esportarli in formato CSV.</li>
    <li><strong>Il superamministratore</strong> della piattaforma ha accesso a tutti i dati per la gestione tecnica del servizio
        e riceve copia delle notifiche di sistema.</li>
    <li>Gli utenti di altri stabili non possono vedere i dati di stabili a cui non sono assegnati.</li>
  </ul>

  <h2 id="destinatari">6. Destinatari esterni e trasferimenti</h2>
  <p>I dati possono essere trattati, esclusivamente per conto del titolare e in qualità di responsabili del trattamento
     (art. 28 GDPR), dal fornitore del servizio di hosting che ospita la piattaforma e dal fornitore del servizio di invio
     delle email. I dati non vengono venduti, ceduti a terzi né diffusi. Potranno essere comunicati ad autorità
     pubbliche solo nei casi previsti dalla legge.</p>
  <p><?= h(PRIVACY_TRASFERIMENTI) ?></p>
  <p>La piattaforma non utilizza servizi esterni di statistica, pubblicità, social network né font o script caricati
     da server di terze parti.</p>

  <h2 id="conservazione">7. Tempi di conservazione</h2>
  <ul>
    <li><strong>Account</strong>: per tutta la durata del rapporto con lo stabile. L'amministratore può disattivare l'account
        (accesso bloccato, dati conservati) o eliminarlo. L'eliminazione cancella anche i messaggi, le segnalazioni e le foto
        inserite dall'utente.</li>
    <li><strong>Contenuti dello stabile</strong>: fino alla loro eliminazione da parte dell'autore o dell'amministratore, o
        all'eliminazione dello stabile. L'&laquo;archiviazione&raquo; nasconde il contenuto ma non lo elimina.
        L'eliminazione di uno stabile cancella tutti i contenuti e le foto collegate.</li>
    <li><strong>Registro accessi e registro email</strong>: <?= $logDays ?> giorni, poi cancellazione automatica.</li>
    <li><strong>Link di reset password</strong>: validi 1 ora, cancellati automaticamente entro il giorno successivo alla scadenza.</li>
    <li><strong>Sessione di accesso</strong>: termina con l'uscita, con la chiusura del browser o dopo <?= $idleMin ?> minuti di inattività.</li>
    <li>Eventuali copie di backup del fornitore di hosting sono conservate secondo le relative politiche e sovrascritte periodicamente.</li>
  </ul>
  <p>Resta salva la conservazione per un periodo più lungo quando richiesta da obblighi di legge (ad esempio la
     documentazione condominiale che l'amministratore è tenuto a custodire).</p>

  <h2 id="cookie">8. Cookie</h2>
  <p>La piattaforma utilizza <strong>un solo cookie tecnico di sessione</strong>, necessario per mantenere l'accesso
     all'area riservata e per proteggere i moduli da richieste contraffatte (CSRF). Non sono utilizzati cookie di
     profilazione, di statistica o di terze parti; per questo motivo, in base all'art. 122 del D.Lgs. 196/2003 e alle
     Linee guida del Garante del 10 giugno 2021, non è richiesto il consenso e non è mostrato alcun banner.</p>
  <div class="tbl-wrap">
  <table>
    <thead><tr><th>Nome</th><th>Tipo</th><th>Finalità</th><th>Durata</th></tr></thead>
    <tbody>
      <tr><td><code><?= h(SESSION_NAME) ?></code></td><td>tecnico, prima parte</td>
          <td>identificazione della sessione di accesso</td><td>fino alla chiusura del browser o all'uscita</td></tr>
    </tbody>
  </table>
  </div>
  <p>Il cookie è <?= $https ? 'trasmesso solo su connessione cifrata (HTTPS), ' : '' ?>non accessibile agli script della pagina
     (HttpOnly) e limitato a questa applicazione.</p>

  <h2 id="email">9. Comunicazioni email</h2>
  <p>La piattaforma invia email automatiche relative allo stabile: nuovi messaggi in bacheca, nuove segnalazioni e
     cambi di stato, attività assegnate, completate o riaperte. Ogni utente può disattivarle in qualsiasi momento dal
     proprio <strong>Profilo</strong>. Le email di servizio indispensabili (credenziali del nuovo account e reset password)
     vengono inviate comunque.</p>

  <h2 id="sicurezza">10. Misure di sicurezza</h2>
  <p>Sono adottate misure tecniche e organizzative adeguate (art. 32 GDPR), tra cui: password conservate solo in forma
     cifrata irreversibile (bcrypt); obbligo di cambiare la password temporanea al primo accesso; blocco temporaneo dopo
     ripetuti tentativi di accesso errati; limite alle richieste di reset; scadenza delle sessioni inattive;
     <?= $https ? 'connessione cifrata HTTPS; ' : '' ?>protezione dei moduli contro richieste contraffatte;
     accesso ai dati separato per stabile e per ruolo; foto allegate non accessibili tramite indirizzo diretto ma
     solo dopo il controllo dei permessi; registro delle operazioni rilevanti.</p>

  <h2 id="diritti">11. Diritti dell'interessato</h2>
  <p>In qualsiasi momento l'interessato può esercitare i diritti previsti dagli artt. 15-22 GDPR:</p>
  <ul>
    <li><strong>accesso</strong> ai propri dati e copia degli stessi;</li>
    <li><strong>rettifica</strong>: nome, email, telefono e note sono modificabili direttamente dal proprio Profilo;</li>
    <li><strong>cancellazione</strong> dei dati, nei limiti degli obblighi di legge;</li>
    <li><strong>limitazione</strong> del trattamento;</li>
    <li><strong>portabilità</strong> dei dati forniti, in un formato strutturato e leggibile (CSV);</li>
    <li><strong>opposizione</strong> al trattamento basato sul legittimo interesse.</li>
  </ul>
  <p>Le richieste possono essere inviate al titolare all'indirizzo
     <a href="mailto:<?= h(PRIVACY_EMAIL) ?>"><?= h(PRIVACY_EMAIL) ?></a> oppure all'amministratore del proprio stabile.
     La risposta sarà fornita entro un mese dalla richiesta (prorogabile nei casi previsti dall'art. 12 GDPR).</p>
  <p>Se ritiene che il trattamento violi la normativa, l'interessato può proporre reclamo al
     <strong>Garante per la protezione dei dati personali</strong> (<a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">www.garanteprivacy.it</a>)
     o all'autorità di controllo dello Stato UE in cui risiede o lavora.</p>

  <h2 id="modifiche">12. Modifiche all'informativa</h2>
  <p>La presente informativa può essere aggiornata, ad esempio in caso di nuove funzionalità della piattaforma.
     La data dell'ultimo aggiornamento è indicata in cima alla pagina.</p>

  <p style="margin-top:28px;padding-top:14px;border-top:var(--border)">
    <a href="<?= h($backUrl) ?>">&larr; <?= h($backLabel) ?></a>
  </p>
</article>
</div>
</body>
</html>
