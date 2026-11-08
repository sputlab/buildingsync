<!-- Modale Informazioni BuildingSync -->
<div class="overlay" id="modalInfo">
  <div class="modal" style="max-width:560px">
    <div class="modal-head">
      <span class="modal-title"><?= h(APP_NAME) ?> — Informazioni</span>
      <button class="modal-close" onclick="closeModal('modalInfo')" aria-label="Chiudi">&#x2715;</button>
    </div>

    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:var(--border)">
      <div style="font-size:2.6rem;flex-shrink:0">&#x1F3DB;</div>
      <div>
        <div style="font-family:var(--font-head);font-size:1.2rem;color:var(--gray-900)"><?= h(APP_NAME) ?></div>
        <div style="font-size:.82rem;color:var(--gray-500)">Sistema di gestione condominiale &mdash; Versione <?= h(APP_VERSION) ?> <span style="color:var(--gray-400)">(<?= h(formatDate(APP_VERSION_DATE)) ?>)</span></div>
        <div style="font-size:.78rem;color:var(--gray-400);margin-top:2px">Sviluppato da <a href="https://sputlab.com" target="_blank" rel="noopener">sputlab.com</a></div>
      </div>
    </div>

    <div style="font-family:var(--font-head);font-size:.92rem;margin-bottom:8px;color:var(--gray-800)">Funzionalità principali</div>
    <ul style="font-size:.83rem;color:var(--gray-600);line-height:1.9;padding-left:18px;margin-bottom:20px">
      <li>4 livelli di accesso: Superadmin, Amministratore, Capocasa, Condomino</li>
      <li>Gestione stabili compartimentata per amministratore</li>
      <li>Bacheca messaggi, avvisi e comunicazioni per stabile</li>
      <li>Segnalazioni con privacy, allegati fotografici e lightbox</li>
      <li>Calendario attività trimestrale scrollabile con durata visiva</li>
      <li>Notifiche email automatiche per tutti gli eventi rilevanti</li>
      <li>Reset password self-service via email</li>
      <li>Export/Import CSV per tutti i dati</li>
      <li>Archiviazione segnalazioni e attività completate</li>
      <li>Preferenze notifiche email per singolo utente</li>
      <li>Cambio password obbligatorio al primo accesso</li>
      <li>Utilizzabile da smartphone, tablet e desktop</li>
    </ul>

    <div style="font-family:var(--font-head);font-size:.92rem;margin-bottom:8px;color:var(--gray-800)">Changelog</div>
    <div style="font-size:.8rem;color:var(--gray-600);line-height:1.8;max-height:200px;overflow-y:auto;padding-right:6px;border:var(--border);border-radius:var(--radius);padding:10px 14px">

      <div style="margin-bottom:10px">
        <strong style="color:var(--gray-900)">v2.1.2</strong>
        <span style="font-size:.7rem;background:var(--blue-lt);color:var(--blue);padding:1px 7px;border-radius:2px;margin-left:6px">corrente</span><br>
        &bull; Licenza Elastic License 2.0
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v2.1.1</strong><br>
        &bull; &ldquo;Segnala un bug&rdquo;: email a bug@sputlab.com con oggetto &ldquo;Segnalazione bug BuildingSync&rdquo;<br>
        &bull; &ldquo;Supporta lo sviluppo&rdquo; punta a www.sputlab.com/donate
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v2.1.0</strong><br>
        &bull; Cambio password obbligatorio al primo accesso e dopo un reset da parte dell&rsquo;amministratore<br>
        &bull; Generatore di password sicure (12 caratteri) e pulsante copia negli appunti<br>
        &bull; Pulsante mostra/nascondi nei campi password<br>
        &bull; Email con credenziali: avviso esplicito sul cambio password al primo accesso<br>
        &bull; &ldquo;Comunicazioni ufficiali&rdquo; pubblicabili solo da amministratore e capocasa<br>
        &bull; Nuova pagina Informativa privacy e cookie (dal login e da questo modale)<br>
        &bull; Cancellazione automatica di log accessi e log email dopo <?= (int)LOG_RETENTION_DAYS ?> giorni<br>
        &bull; Mobile: modali scrollabili con intestazione fissa, schede scorrevoli, campi senza zoom su iOS<br>
        &bull; Mobile: il pulsante del menu non copre più il titolo della pagina<br>
        &bull; Mobile: calendario con mesi impilati in verticale
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v2.0.0</strong><br>
        &bull; Nuovo nome: EasyCondo diventa <strong>BuildingSync</strong><br>
        &bull; Versioning semantico MAJOR.MINOR.PATCH<br>
        &bull; Tabelle database con prefisso <code>buildingsync_</code> + script di migrazione<br>
        &bull; Sicurezza: upload allegati blindato, allegati serviti con controllo permessi<br>
        &bull; Sicurezza: corretti XSS, controlli di proprietà su stabili/export/import, hash password non più esposti<br>
        &bull; Sessione limitata alla cartella dell&rsquo;app, timeout di inattività, disattivazione immediata degli account<br>
        &bull; Fix doppio toggle del pallino attività e errore JS del calendario<br>
        &bull; Fix segnalazioni private non visibili al destinatario<br>
        &bull; Fix capocasa per stabile non applicato nella pagina stabile<br>
        &bull; Fix auto-declassamento del ruolo modificando il proprio utente<br>
        &bull; Fix colonne disallineate nella tabella amministratori<br>
        &bull; Fix conferme di eliminazione che non comparivano con nomi contenenti apostrofi<br>
        &bull; Fix export/import non funzionante su PHP &lt; 8.1<br>
        &bull; Archivio segnalazioni e attività consultabile, con ripristino<br>
        &bull; Modifica di ruolo (capocasa) e unità per ogni utente assegnato a uno stabile<br>
        &bull; Limite alle richieste di reset password; ritorno alla pagina richiesta dopo il login<br>
        &bull; Fix hash del superadmin iniziale in install.sql<br>
        &bull; Preferenza notifiche email nel profilo (rispettata da tutte le notifiche)<br>
        &bull; Notifiche di sistema a tutti i superadmin<br>
        &bull; Email di benvenuto opzionale alla creazione di un account<br>
        &bull; Import CSV: separatore automatico (, o ;), validazione date, compatibilità con l&rsquo;export stabili<br>
        &bull; Export CSV protetto da formula injection<br>
        &bull; Rimossi Google Fonts (non utilizzati, conformità GDPR)
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v1.6.0</strong><br>
        &bull; Rimosso footer, aggiunto tasto info nella barra laterale<br>
        &bull; Modale informazioni accessibile dal menu laterale<br>
        &bull; Aggiornamento changelog versioni 1.5 e 1.6
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v1.5.0</strong><br>
        &bull; Fix errore 302 redirect loop su pagina stabile<br>
        &bull; Fix brace spuria (parse error stabile.php)<br>
        &bull; Password opzionale in modifica utente<br>
        &bull; Verifica password in tempo reale con feedback separato<br>
        &bull; Colonna telefono visibile nella lista utenti<br>
        &bull; Ruolo contestuale per stabile (capocasa per stabile, non globale)<br>
        &bull; Controllo capienza massima stabile all&rsquo;assegnazione utenti<br>
        &bull; Rimosso prefisso &ldquo;Int.&rdquo; automatico nel campo numero unità<br>
        &bull; Archiviazione segnalazioni e attività completate<br>
        &bull; Elimina allegato permesso anche al caricatore<br>
        &bull; Email notifica riapertura attività<br>
        &bull; Export/Import CSV completo (stabili, utenti, segnalazioni, attività)<br>
        &bull; Date CSV in formato italiano (gg-mm-aaaa)<br>
        &bull; Nome stabile nel filename CSV segnalazioni/attività<br>
        &bull; Creazione e modifica superadmin corretta
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v1.4.0</strong><br>
        &bull; Calendario trimestrale JS scrollabile con attività per durata<br>
        &bull; Fix pallino AJAX completamento attività<br>
        &bull; Etichetta categoria nelle liste attività<br>
        &bull; Info creatore e modificatore nelle segnalazioni<br>
        &bull; Campi telefono e note per ogni utente<br>
        &bull; Footer informativo (poi rimosso in v1.6)
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v1.3.0</strong><br>
        &bull; Reset password self-service via email<br>
        &bull; Creazione multipla superadmin<br>
        &bull; Dashboard: riquadri statistiche cliccabili<br>
        &bull; Lightbox immagini nelle segnalazioni
      </div>

      <div style="margin-bottom:10px;padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v1.2.0</strong><br>
        &bull; Sistema notifiche email completo<br>
        &bull; Segnalazioni private e allegati fotografici<br>
        &bull; Token reset password
      </div>

      <div style="padding-top:8px;border-top:var(--border)">
        <strong style="color:var(--gray-900)">v1.0.0 &mdash; v1.1.0</strong><br>
        &bull; Prima versione funzionante<br>
        &bull; Gestione stabili, utenti, bacheca, segnalazioni, attività
      </div>
    </div>

    <div style="margin-top:18px;padding-top:14px;border-top:var(--border);display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <a href="mailto:bug@sputlab.com?subject=<?= rawurlencode('Segnalazione bug BuildingSync') ?>&amp;body=<?= rawurlencode("Versione: ".APP_VERSION."\n\nDescrizione del problema:\n") ?>"
         class="btn btn-outline btn-sm">&#x1F41B; Segnala un bug</a>
      <a href="https://www.sputlab.com/donate" target="_blank" rel="noopener"
         class="btn btn-sm" style="background:#009cde;color:#fff;border-color:#009cde">&#x2665; Supporta lo sviluppo</a>
      <a href="<?= APP_URL ?>/privacy.php" class="btn btn-outline btn-sm">&#x1F512; Privacy</a>
      <span style="font-size:.72rem;color:var(--gray-400);margin-left:auto">&copy; <?= date('Y') ?> sputlab.com</span>
    </div>
  </div>
</div>

</main>
</div><!-- /.app-shell -->
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= h(APP_VERSION) ?>"></script>
</body>
</html>
