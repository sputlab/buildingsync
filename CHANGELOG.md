# Changelog — BuildingSync

Formato: [Semantic Versioning](https://semver.org/lang/it/) — MAJOR.MINOR.PATCH.
MAJOR = modifiche incompatibili · MINOR = nuove funzioni compatibili · PATCH = correzioni.

## [2.1.1] — 2026-09-24

### Modifiche
- Modale Informazioni, pulsante **Segnala un bug**: apre un'email a `bug@sputlab.com` con oggetto
  "Segnalazione bug BuildingSync" (prima l'oggetto includeva anche il numero di versione, ora spostato nel corpo
  dell'email già precompilato).
- Modale Informazioni, pulsante **Supporta lo sviluppo**: ora punta a https://www.sputlab.com/donate
  (prima a paypal.me).
- Nessuna modifica al database (`DB_SCHEMA_VERSION` resta 2.1.0).

## [2.1.0] — 2026-09-24

### Novità
- **Cambio password obbligatorio** al primo accesso (nuovi utenti, nuovi amministratori, utenti importati da CSV,
  superadmin iniziale) e quando un amministratore reimposta la password di un utente. Finché non viene scelta una
  password personale, diversa da quella temporanea, l'unica pagina accessibile è `cambio_password.php`.
- **Generatore di password** nei moduli di creazione/modifica di utenti e amministratori: 12 caratteri con maiuscole,
  minuscole, numeri e simboli (generati con `crypto.getRandomValues`, senza caratteri ambigui come O/0 o l/1).
- **Copia negli appunti** e **mostra/nascondi** nei campi password.
- Email con le credenziali: avviso evidenziato che la password è temporanea e andrà cambiata al primo accesso;
  l'oggetto lo indica esplicitamente.
- **Informativa privacy e cookie** completa (`privacy.php`), pubblica, raggiungibile da login, reset password,
  cambio password, modale Informazioni e dal piè di pagina delle email. Dati del titolare configurabili in
  `config.php` (costanti `PRIVACY_*`).
- **Cancellazione automatica** di log accessi e log email dopo `LOG_RETENTION_DAYS` giorni (default 180) e dei
  token di reset scaduti, come dichiarato nell'informativa.

### Modifiche
- Le **"Comunicazioni ufficiali"** in bacheca possono essere pubblicate solo da amministratore e capocasa
  (controllo lato server; l'opzione non compare agli altri utenti).
- Etichette leggibili per il tipo di post (es. "Comunicazione ufficiale" invece di "comunicazione").
- Campo "Note" nella gestione utenti: chiarito che è visibile anche all'utente (prima era indicato come
  "visibile solo agli amministratori", ma l'utente lo vede e lo modifica nel proprio profilo).

### Mobile
- **Modali scrollabili**: i modali più alti dello schermo non venivano tagliati in alto e ora scorrono;
  su smartphone occupano quasi tutto lo schermo con intestazione e pulsante di chiusura sempre visibili.
- Pagina sotto il modale bloccata durante lo scorrimento; niente apertura automatica della tastiera sui dispositivi touch.
- Schede (Bacheca / Segnalazioni / …) scorrevoli orizzontalmente.
- Il pulsante ☰ non copre più il titolo della pagina.
- Campi di input a 16px su smartphone (evita lo zoom automatico di iOS); pulsanti più facili da toccare.
- Righe di attività e segnalazioni che vanno a capo invece di uscire dallo schermo; supporto a notch / safe area.
- Calendario: su smartphone i tre mesi sono impilati in verticale e i pulsanti di navigazione restano visibili.

### Database
- Nuova colonna `buildingsync_utenti.password_da_cambiare`. Installazione pulita: `sql/install.sql`.
  Da 2.0.0: `sql/upgrade_2.0.0_to_2.1.0.sql`.

## [2.0.0] — 2026-09-24

### Modifiche incompatibili (richiedono migrazione)
- Nuovo nome: **EasyCondo → BuildingSync** (URL predefinito `/webapp/buildingsync`).
- Tutte le tabelle hanno il prefisso **`buildingsync_`**. Migrazione: `sql/upgrade_1.6_to_2.0.0.sql`.
- Nuova tabella `buildingsync_meta` con la versione dello schema.
- Cookie di sessione rinominato (`bs_…`) e limitato alla cartella dell'app: tutti gli utenti devono rifare il login.
- Export CSV con separatore `;` (formato Excel italiano). L'import accetta `;`, `,` e TAB.
- Requisito minimo: PHP 7.4.

### Sicurezza
- Upload allegati: l'estensione è ricavata dal contenuto reale del file (prima un'immagine
  chiamata `x.php` veniva salvata ed era eseguibile sul server).
- Allegati serviti da `allegato.php` con controllo permessi; `uploads/` non è più accessibile via URL
  (le foto delle segnalazioni private erano visibili a chiunque avesse il link).
- Corretto XSS negli attributi `onclick` (sequenze `&quot;` nei testi).
- Gli hash delle password non vengono più inseriti nel codice HTML delle pagine admin.
- Controlli di proprietà: rimozione/assegnazione utenti, export e import limitati agli stabili dell'admin.
- Validazione di utente assegnato, destinatario delle segnalazioni private e date.
- Timeout di inattività lato server; un account disattivato viene disconnesso subito.
- IP letto da `REMOTE_ADDR` (X-Forwarded-For era falsificabile nell'audit log).
- Limite di richieste di reset password per IP (`RESET_MAX_PER_HOUR`).
- Protezione da formula injection negli export CSV.
- `.htaccess`: rimosso `<DirectoryMatch>` (non ammesso nei .htaccess, causa errore 500 su Apache);
  bloccati `*.sql`, `*.md`, `*.log`.

### Correzioni
- Pallino attività: la richiesta AJAX partiva due volte (due gestori) e l'attività tornava allo stato iniziale.
- Calendario: errore JavaScript all'apertura (`renderCalendar` non definita).
- Segnalazioni private create dall'admin per un condomino: il destinatario non le vedeva.
- Capocasa assegnato per stabile (v1.5) non applicato nella pagina dello stabile.
- Un admin che modificava sé stesso dalla pagina Utenti veniva declassato a condomino.
- Tabella Amministratori: colonne disallineate rispetto alle intestazioni.
- Conferme di eliminazione assenti quando il nome contiene un apostrofo (es. "D'Amico").
- `export_import.php` usava il tipo `never` (solo PHP 8.1+).
- `install.sql`: l'hash del superadmin non corrispondeva a `Admin2026!`.
- Preferenza `email_notifiche` presente nel DB ma mai rispettata.
- Fuso orario MySQL/PHP non allineato (scadenza token di reset errata).
- Titolo pagina con doppio escape; box "Stabili" della dashboard in 403 per i condomini.
- Classi CSS mancanti (`btn-secondary`, `hint`, `td-act`).
- `patch_v1_6.sql` era un duplicato di `patch_v1_5.sql` (ora in `sql/legacy/`).

### Novità
- Preferenza notifiche email nel profilo; notifiche di sistema a tutti i superadmin.
- Email di benvenuto con credenziali (opzionale) alla creazione di utenti e admin.
- Archivio segnalazioni/attività consultabile con "Ripristina".
- Modifica di ruolo nello stabile e numero unità per ogni utente assegnato.
- Import CSV: rilevamento separatore e codifica, date gg-mm-aaaa / gg/mm/aaaa / aaaa-mm-gg,
  compatibilità con il file di export degli stabili, transazione unica (tutto o niente).
- Dopo il login si torna alla pagina richiesta (es. link ricevuto via email).
- Badge "Bloccato" per gli account in blocco temporaneo; sblocco automatico al cambio password.
- Rimossi Google Fonts (non utilizzati dal CSS; evita il trasferimento di IP a Google).

## [1.6.0] e precedenti
Vedi il changelog nel modale Informazioni dell'applicazione.
