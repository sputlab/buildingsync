<?php
// includes/Mailer.php — Sistema notifiche email BuildingSync
// Usa mail() nativo di PHP (compatibile con hosting condiviso)
// Per SMTP configurare in config.php (opzionale, richiede PHPMailer)

class Mailer {

    // ----------------------------------------------------------------
    // INVIO BASE
    // ----------------------------------------------------------------
    private static function send(string $to, string $toName, string $subject, string $body): bool {
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

        $from     = MAIL_FROM;
        $fromName = MAIL_FROM_NAME;
        $replyTo  = MAIL_REPLY_TO ?: $from;
        $subject  = str_replace(["\r","\n"], ' ', $subject);

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?".base64_encode($fromName)."?= <$from>\r\n";
        $headers .= "Reply-To: $replyTo\r\n";
        $headers .= "X-Mailer: BuildingSync/".APP_VERSION."\r\n";
        $headers .= "Auto-Submitted: auto-generated\r\n";

        $html = self::wrapTemplate($subject, $body, $toName);

        $result = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);

        // Log
        try {
            DB::insert('buildingsync_mail_log', [
                'destinatario' => $to,
                'oggetto'      => $subject,
                'inviata'      => $result ? 1 : 0,
                'errore'       => $result ? null : 'mail() returned false',
                'creato_il'    => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {}

        return $result;
    }

    private static function wrapTemplate(string $subject, string $body, string $toName): string {
        $appName = htmlspecialchars(APP_NAME);
        $appUrl  = htmlspecialchars(APP_URL);
        $subject = htmlspecialchars($subject);
        $year    = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#f2f2f2;font-family:Arial,sans-serif;font-size:14px;color:#333">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f2;padding:30px 0">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
      <!-- Header -->
      <tr><td style="background:#1a3a5c;padding:20px 30px;border-radius:6px 6px 0 0">
        <p style="margin:0;color:#fff;font-size:18px;font-family:Georgia,serif">{$appName}</p>
        <p style="margin:4px 0 0;color:#a8c4e0;font-size:12px">Sistema di gestione condominiale</p>
      </td></tr>
      <!-- Body -->
      <tr><td style="background:#fff;padding:28px 30px;border-left:1px solid #e0e0e0;border-right:1px solid #e0e0e0">
        {$body}
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f8f8f8;padding:16px 30px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 6px 6px">
        <p style="margin:0;font-size:11px;color:#999;text-align:center">
          Questa è una notifica automatica di {$appName}.<br>
          <a href="{$appUrl}" style="color:#25527e">Accedi al sistema</a> &middot;
          <a href="{$appUrl}/privacy.php" style="color:#25527e">Privacy</a> &middot;
          Le notifiche si disattivano dal proprio profilo &mdash; &copy; {$year} {$appName}
        </p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
    }

    // ----------------------------------------------------------------
    // HELPERS INTERNI
    // ----------------------------------------------------------------

    /** L'utente ha un'email valida e non ha disattivato le notifiche? */
    private static function wants(?array $u): bool {
        return $u && !empty($u['email']) && (!array_key_exists('email_notifiche', $u) || (int)$u['email_notifiche'] === 1);
    }

    /** Tutti i superadmin attivi */
    private static function getSuperAdmins(): array {
        return DB::all("SELECT id, nome_completo, email, email_notifiche FROM buildingsync_utenti WHERE ruolo='superadmin' AND attivo=1");
    }

    /** Recupera l'admin di uno stabile */
    private static function getBuildingAdmin(int $stabileId): ?array {
        return DB::one(
            "SELECT u.id, u.nome_completo, u.email, u.email_notifiche FROM buildingsync_utenti u
             JOIN buildingsync_stabili s ON s.admin_id=u.id WHERE s.id=? AND u.attivo=1 LIMIT 1",
            [$stabileId]
        );
    }

    /** Recupera tutti gli utenti attivi di uno stabile (escluso il mittente) */
    private static function getBuildingUsers(int $stabileId, int $excludeUserId = 0): array {
        return DB::all(
            "SELECT u.id, u.nome_completo, u.email, u.email_notifiche FROM buildingsync_utenti u
             JOIN buildingsync_utenti_stabili us ON us.utente_id=u.id
             WHERE us.stabile_id=? AND us.attivo=1 AND u.attivo=1
             AND u.id != ? AND u.email != '' AND u.email_notifiche=1",
            [$stabileId, $excludeUserId]
        );
    }

    /** Nome stabile */
    private static function getBuildingName(int $stabileId): string {
        $r = DB::one("SELECT nome FROM buildingsync_stabili WHERE id=?", [$stabileId]);
        return $r ? $r['nome'] : "Stabile #$stabileId";
    }

    private static function btn(string $url, string $label): string {
        $url = htmlspecialchars($url, ENT_QUOTES);
        return "<p style='margin:20px 0 0'><a href='$url' style='display:inline-block;background:#1a3a5c;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-size:14px'>$label</a></p>";
    }

    private static function greeting(string $name): string {
        return "<p style='margin:0 0 16px'>Gentile <strong>".htmlspecialchars($name)."</strong>,</p>";
    }

    private static function divider(): string {
        return "<hr style='border:none;border-top:1px solid #e8e8e8;margin:20px 0'>";
    }

    // ================================================================
    // ATTIVITÀ
    // ================================================================

    /**
     * Notifica creazione nuova attività
     * - Utente assegnato
     * - Admin dello stabile (se non è lui il creatore)
     * - Superadmin (copia silenziosa)
     */
    public static function notifyNewActivity(array $activity, int $stabileId, string $stabileNome, ?array $assignedUser, array $creator): void {
        $url     = APP_URL."/stabile.php?id={$stabileId}&tab=attivita";
        $scad    = $activity['data_scadenza'] ? " — scadenza: <strong>".date('d/m/Y', strtotime($activity['data_scadenza']))."</strong>" : '';
        $cat     = htmlspecialchars(catLabels()[$activity['categoria']] ?? $activity['categoria']);

        // --- Notifica all'utente assegnato ---
        if (self::wants($assignedUser) && $assignedUser['id'] != $creator['id']) {
            $body = self::greeting($assignedUser['nome_completo'])
                ."<p>Le è stata assegnata una nuova attività nello stabile <strong>".htmlspecialchars($stabileNome)."</strong>:</p>"
                ."<table style='width:100%;border-collapse:collapse;margin:16px 0'>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;width:35%;font-weight:bold'>Attività</td><td style='padding:8px;border:1px solid #e8e8e8'>".htmlspecialchars($activity['titolo'])."</td></tr>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Categoria</td><td style='padding:8px;border:1px solid #e8e8e8'>$cat</td></tr>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Data programmata</td><td style='padding:8px;border:1px solid #e8e8e8'>".date('d/m/Y', strtotime($activity['data_programmata'])).$scad."</td></tr>"
                .($activity['descrizione'] ? "<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Note</td><td style='padding:8px;border:1px solid #e8e8e8'>".nl2br(htmlspecialchars($activity['descrizione']))."</td></tr>" : '')
                ."</table>"
                .self::btn($url, 'Visualizza nel sistema');
            self::send($assignedUser['email'], $assignedUser['nome_completo'],
                "[{$stabileNome}] Nuova attività assegnata: {$activity['titolo']}", $body);
        }

        // --- Notifica admin dello stabile (se non è il creatore) ---
        $admin = self::getBuildingAdmin($stabileId);
        if (self::wants($admin) && $admin['id'] != $creator['id']) {
            $assignedTo = $assignedUser ? htmlspecialchars($assignedUser['nome_completo']) : 'Nessuno';
            $body = self::greeting($admin['nome_completo'])
                ."<p>È stata creata una nuova attività nello stabile <strong>".htmlspecialchars($stabileNome)."</strong> da <strong>".htmlspecialchars($creator['nome_completo'])."</strong>:</p>"
                ."<table style='width:100%;border-collapse:collapse;margin:16px 0'>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;width:35%;font-weight:bold'>Attività</td><td style='padding:8px;border:1px solid #e8e8e8'>".htmlspecialchars($activity['titolo'])."</td></tr>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Assegnata a</td><td style='padding:8px;border:1px solid #e8e8e8'>$assignedTo</td></tr>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Categoria</td><td style='padding:8px;border:1px solid #e8e8e8'>$cat</td></tr>"
                ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Scadenza</td><td style='padding:8px;border:1px solid #e8e8e8'>".($activity['data_scadenza'] ? date('d/m/Y', strtotime($activity['data_scadenza'])) : '—')."</td></tr>"
                ."</table>"
                .self::btn($url, 'Gestisci nel sistema');
            self::send($admin['email'], $admin['nome_completo'],
                "[{$stabileNome}] Nuova attività: {$activity['titolo']}", $body);
        }

        // --- Copia silenziosa al superadmin ---
        self::notifySuperAdmin(
            "[SISTEMA] Nuova attività — {$stabileNome}",
            "Stabile: <strong>".htmlspecialchars($stabileNome)."</strong><br>"
            ."Creatore: ".htmlspecialchars($creator['nome_completo'])."<br>"
            ."Attività: <strong>".htmlspecialchars($activity['titolo'])."</strong><br>"
            ."Assegnata a: ".($assignedUser ? htmlspecialchars($assignedUser['nome_completo']) : 'nessuno'),
            $url,
            (int)$creator['id']
        );
    }

    /** Notifica riapertura attività all'utente assegnato */
    public static function notifyActivityReopened(array $activity, int $stabileId, string $stabileNome, array $reopenedBy): void {
        if (empty($activity['assegnato_a'])) return;
        $user = DB::one("SELECT id, nome_completo, email, email_notifiche FROM buildingsync_utenti WHERE id=? AND attivo=1", [$activity['assegnato_a']]);
        if (!self::wants($user) || $user['id'] == $reopenedBy['id']) return;
        $url  = APP_URL."/stabile.php?id={$stabileId}&tab=attivita";
        $body = self::greeting($user['nome_completo'])
            ."<p>L'attività <strong>".htmlspecialchars($activity['titolo'])."</strong> nello stabile <strong>".htmlspecialchars($stabileNome)."</strong> è stata <strong style='color:#7a5100'>riaperta</strong> da <strong>".htmlspecialchars($reopenedBy['nome_completo'])."</strong> e risulta nuovamente da svolgere.</p>"
            .self::btn($url, 'Visualizza nel sistema');
        self::send($user['email'], $user['nome_completo'],
            "[{$stabileNome}] Attività riaperta: {$activity['titolo']}", $body);
    }

    /** Notifica completamento attività all'admin */
    public static function notifyActivityCompleted(array $activity, int $stabileId, string $stabileNome, array $completedBy): void {
        $admin = self::getBuildingAdmin($stabileId);
        if (!self::wants($admin) || $admin['id'] == $completedBy['id']) return;

        $url  = APP_URL."/stabile.php?id={$stabileId}&tab=attivita";
        $body = self::greeting($admin['nome_completo'])
            ."<p>L'attività <strong>".htmlspecialchars($activity['titolo'])."</strong> nello stabile <strong>".htmlspecialchars($stabileNome)."</strong> è stata contrassegnata come <strong style='color:#2e6b3e'>completata</strong> da <strong>".htmlspecialchars($completedBy['nome_completo'])."</strong>.</p>"
            ."<p style='color:#666;font-size:13px'>Data completamento: ".date('d/m/Y H:i')."</p>"
            .self::btn($url, 'Visualizza nel sistema');
        self::send($admin['email'], $admin['nome_completo'],
            "[{$stabileNome}] Attività completata: {$activity['titolo']}", $body);
    }

    // ================================================================
    // SEGNALAZIONI
    // ================================================================

    /**
     * Notifica nuova segnalazione
     * - Se privata (utente→admin): solo admin
     * - Se privata (admin→utente): solo utente/i assegnati
     * - Se pubblica: admin + tutti gli utenti dello stabile
     */
    public static function notifyNewReport(array $report, int $stabileId, string $stabileNome, array $creator, bool $privata, ?int $privatoConUserId = null): void {
        $url     = APP_URL."/stabile.php?id={$stabileId}&tab=segnalazioni";
        $gravMap = ['bassa'=>'Bassa','media'=>'Media','alta'=>'Alta','critica'=>'🔴 CRITICA'];
        $grav    = $gravMap[$report['gravita']] ?? $report['gravita'];
        $admin   = self::getBuildingAdmin($stabileId);

        $dettagli = "<table style='width:100%;border-collapse:collapse;margin:16px 0'>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;width:35%;font-weight:bold'>Oggetto</td><td style='padding:8px;border:1px solid #e8e8e8'>".htmlspecialchars($report['titolo'])."</td></tr>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Gravità</td><td style='padding:8px;border:1px solid #e8e8e8'>$grav</td></tr>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Descrizione</td><td style='padding:8px;border:1px solid #e8e8e8'>".nl2br(htmlspecialchars($report['contenuto']))."</td></tr>"
            ."</table>";

        if ($privata && $privatoConUserId) {
            // Segnalazione privata admin→utente specifico
            $destUser = DB::one("SELECT id, nome_completo, email, email_notifiche FROM buildingsync_utenti WHERE id=? AND attivo=1", [$privatoConUserId]);
            if (self::wants($destUser)) {
                $body = self::greeting($destUser['nome_completo'])
                    ."<p>L'amministratore dello stabile <strong>".htmlspecialchars($stabileNome)."</strong> le ha inviato una comunicazione privata:</p>"
                    .$dettagli
                    .self::btn($url, 'Visualizza nel sistema');
                self::send($destUser['email'], $destUser['nome_completo'],
                    "[{$stabileNome}] Comunicazione riservata da parte dell'amministratore", $body);
            }
        } elseif ($privata && $creator['ruolo'] !== 'admin' && $creator['ruolo'] !== 'superadmin') {
            // Segnalazione privata utente→admin
            if (self::wants($admin)) {
                $body = self::greeting($admin['nome_completo'])
                    ."<p><strong>".htmlspecialchars($creator['nome_completo'])."</strong> ha inviato una segnalazione <strong>privata</strong> (visibile solo a lei) per lo stabile <strong>".htmlspecialchars($stabileNome)."</strong>:</p>"
                    .$dettagli
                    .self::btn($url, 'Gestisci nel sistema');
                self::send($admin['email'], $admin['nome_completo'],
                    "[{$stabileNome}] Segnalazione privata da {$creator['nome_completo']}: {$report['titolo']}", $body);
            }
        } else {
            // Segnalazione pubblica: notifica admin (se non è il creatore)
            if (self::wants($admin) && $admin['id'] != $creator['id']) {
                $body = self::greeting($admin['nome_completo'])
                    ."<p><strong>".htmlspecialchars($creator['nome_completo'])."</strong> ha inserito una nuova segnalazione nello stabile <strong>".htmlspecialchars($stabileNome)."</strong>:</p>"
                    .$dettagli
                    .self::btn($url, 'Gestisci nel sistema');
                self::send($admin['email'], $admin['nome_completo'],
                    "[{$stabileNome}] Nuova segnalazione: {$report['titolo']}", $body);
            }
            // Notifica agli altri utenti dello stabile
            $utenti = self::getBuildingUsers($stabileId, $creator['id']);
            foreach ($utenti as $ut) {
                if (empty($ut['email'])) continue;
                $body = self::greeting($ut['nome_completo'])
                    ."<p>È stata inserita una nuova segnalazione nello stabile <strong>".htmlspecialchars($stabileNome)."</strong>:</p>"
                    .$dettagli
                    .self::btn($url, 'Visualizza nel sistema');
                self::send($ut['email'], $ut['nome_completo'],
                    "[{$stabileNome}] Nuova segnalazione: {$report['titolo']}", $body);
            }
        }

        // Superadmin: sempre notificato
        self::notifySuperAdmin(
            "[SISTEMA] Nuova segnalazione".($privata?' (privata)':'')." — {$stabileNome}",
            "Stabile: <strong>".htmlspecialchars($stabileNome)."</strong><br>"
            ."Da: ".htmlspecialchars($creator['nome_completo'])."<br>"
            ."Oggetto: <strong>".htmlspecialchars($report['titolo'])."</strong><br>"
            ."Gravità: $grav | Privata: ".($privata?'Sì':'No'),
            $url,
            (int)$creator['id']
        );
    }

    /** Notifica cambio stato segnalazione all'autore */
    public static function notifyReportStatusChanged(array $report, int $stabileId, string $stabileNome, array $changedBy): void {
        if (empty($report['email_autore'])) return;
        if (isset($report['notifiche_autore']) && !(int)$report['notifiche_autore']) return;
        if (isset($report['utente_id']) && (int)$report['utente_id'] === (int)$changedBy['id']) return;
        $statiLabel = ['aperta'=>'Aperta','in_lavorazione'=>'In lavorazione','risolta'=>'Risolta','chiusa'=>'Chiusa'];
        $statoLabel = $statiLabel[$report['stato']] ?? $report['stato'];
        $url  = APP_URL."/stabile.php?id={$stabileId}&tab=segnalazioni";
        $body = self::greeting($report['autore_nome'])
            ."<p>Lo stato della sua segnalazione <strong>".htmlspecialchars($report['titolo'])."</strong> nello stabile <strong>".htmlspecialchars($stabileNome)."</strong> è stato aggiornato a <strong style='color:#1a3a5c'>$statoLabel</strong>.</p>"
            .self::btn($url, 'Visualizza nel sistema');
        self::send($report['email_autore'], $report['autore_nome'],
            "[{$stabileNome}] Segnalazione aggiornata: {$report['titolo']}", $body);
    }

    // ================================================================
    // BACHECA
    // ================================================================

    /**
     * Notifica nuovo post in bacheca a tutti gli utenti dello stabile
     */
    public static function notifyNewPost(array $post, int $stabileId, string $stabileNome, array $creator): void {
        $url      = APP_URL."/stabile.php?id={$stabileId}&tab=bacheca";
        $tipoMap  = ['post'=>'Messaggio','avviso'=>'Avviso','comunicazione'=>'Comunicazione ufficiale'];
        $tipo     = $tipoMap[$post['tipo']] ?? $post['tipo'];
        $pinLabel = $post['in_evidenza'] ? " <span style='color:#1a3a5c'>[IN EVIDENZA]</span>" : '';

        $dettagli = "<table style='width:100%;border-collapse:collapse;margin:16px 0'>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;width:35%;font-weight:bold'>Tipo</td><td style='padding:8px;border:1px solid #e8e8e8'>$tipo$pinLabel</td></tr>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Oggetto</td><td style='padding:8px;border:1px solid #e8e8e8'>".htmlspecialchars($post['titolo'])."</td></tr>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Contenuto</td><td style='padding:8px;border:1px solid #e8e8e8'>".nl2br(htmlspecialchars(mb_substr($post['contenuto'],0,400))).(mb_strlen($post['contenuto'])>400?'…':'')."</td></tr>"
            ."</table>";

        // Notifica utenti dello stabile (escluso il creatore)
        $utenti = self::getBuildingUsers($stabileId, $creator['id']);
        // Se il creatore è un utente, notifica anche l'admin
        $admin = self::getBuildingAdmin($stabileId);
        if (self::wants($admin) && $admin['id'] != $creator['id']) {
            $utenti[] = $admin;
        }

        foreach ($utenti as $ut) {
            if (!self::wants($ut)) continue;
            $body = self::greeting($ut['nome_completo'])
                ."<p><strong>".htmlspecialchars($creator['nome_completo'])."</strong> ha pubblicato un nuovo messaggio nella bacheca dello stabile <strong>".htmlspecialchars($stabileNome)."</strong>:</p>"
                .$dettagli
                .self::btn($url, 'Leggi nella bacheca');
            self::send($ut['email'], $ut['nome_completo'],
                "[{$stabileNome}] {$tipo}: {$post['titolo']}", $body);
        }

        // Superadmin
        self::notifySuperAdmin(
            "[SISTEMA] Nuovo post bacheca — {$stabileNome}",
            "Stabile: <strong>".htmlspecialchars($stabileNome)."</strong><br>"
            ."Da: ".htmlspecialchars($creator['nome_completo'])."<br>"
            ."Oggetto: ".htmlspecialchars($post['titolo'])."<br>"
            ."Tipo: $tipo",
            $url,
            (int)$creator['id']
        );
    }

    // ================================================================
    // ACCOUNT
    // ================================================================

    /** Notifica creazione account al nuovo utente */
    public static function notifyAccountCreated(array $newUser, string $password): void {
        if (empty($newUser['email'])) return;
        $body = self::greeting($newUser['nome_completo'])
            ."<p>Il suo account per accedere al sistema <strong>".APP_NAME."</strong> è stato creato.</p>"
            ."<table style='width:100%;border-collapse:collapse;margin:16px 0'>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;width:35%;font-weight:bold'>Username</td><td style='padding:8px;border:1px solid #e8e8e8;font-family:monospace'>".htmlspecialchars($newUser['username'])."</td></tr>"
            ."<tr><td style='padding:8px;background:#f8f8f8;border:1px solid #e8e8e8;font-weight:bold'>Password temporanea</td><td style='padding:8px;border:1px solid #e8e8e8;font-family:monospace'>".htmlspecialchars($password)."</td></tr>"
            ."</table>"
            ."<p style='color:#8b1a1a;font-size:13px;background:#f9eaea;border-left:3px solid #8b1a1a;padding:10px 12px'>"
            ."<strong>Importante:</strong> la password indicata è <strong>temporanea</strong>. "
            ."Al primo accesso il sistema le chiederà obbligatoriamente di sostituirla con una nuova password personale, "
            ."che conoscerà solo lei. Fino ad allora non sarà possibile utilizzare le altre funzioni.</p>"
            ."<p style='color:#666;font-size:12px'>Per sicurezza, dopo il primo accesso elimini questa email. "
            ."Può consultare l'informativa privacy all'indirizzo <a href='".htmlspecialchars(APP_URL)."/privacy.php' style='color:#25527e'>".htmlspecialchars(APP_URL)."/privacy.php</a>.</p>"
            .self::btn(APP_URL.'/login.php', 'Accedi al sistema');
        self::send($newUser['email'], $newUser['nome_completo'],
            "Benvenuto in ".APP_NAME." — credenziali di accesso (password da cambiare al primo accesso)", $body);
    }

    /** Email di reset password (inviata sempre, indipendentemente dalle preferenze notifiche) */
    public static function sendPasswordReset(array $user, string $resetUrl): bool {
        $body = self::greeting($user['nome_completo'])
            ."<p>È stata ricevuta una richiesta di reset della password per il suo account su <strong>".htmlspecialchars(APP_NAME)."</strong>.</p>"
            ."<p>Clicchi sul pulsante qui sotto per impostare una nuova password. Il link è valido per <strong>1 ora</strong>.</p>"
            .self::btn($resetUrl, 'Reimposta la password')
            ."<p style='color:#888;font-size:12px;margin-top:20px'>Se non ha richiesto questo reset, ignori questa email: la sua password non verrà modificata.</p>"
            ."<p style='color:#888;font-size:12px;word-break:break-all'>Link diretto: ".htmlspecialchars($resetUrl)."</p>";
        return self::send($user['email'], $user['nome_completo'], APP_NAME.' — Reset password', $body);
    }

    /** Notifica a tutti i superadmin (copia silenziosa), escluso l'autore dell'azione */
    private static function notifySuperAdmin(string $subject, string $details, string $url = '', int $excludeId = 0): void {
        foreach (self::getSuperAdmins() as $sa) {
            if (!self::wants($sa) || (int)$sa['id'] === $excludeId) continue;
            $body = self::greeting($sa['nome_completo'])
                ."<p style='color:#555;font-size:13px;border-left:3px solid #1a3a5c;padding-left:12px;margin:0 0 16px'><em>Notifica di sistema — riepilogo attività</em></p>"
                ."<p>$details</p>"
                .($url ? self::btn($url, 'Vai al sistema') : '');
            self::send($sa['email'], $sa['nome_completo'], $subject, $body);
        }
    }
}
