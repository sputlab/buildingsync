# BuildingSync 2.1.2

Webapp di gestione condominiale (PHP 7.4+ · MySQL 5.7+ / MariaDB 10.3+). Ex EasyCondo.

## Installazione nuova
1. Copiare i file sul server (es. `/webapp/buildingsync`).
2. Creare il database e importare `sql/install.sql`.
3. Copiare `config.example.php` in `config.php` e compilare i valori `<<<`
   (`APP_URL` senza slash finale, `APP_KEY` casuale).
4. Verificare che `uploads/` e `logs/` siano scrivibili da PHP.
5. Compilare in `config.php` i dati del titolare del trattamento (`PRIVACY_TITOLARE`, `PRIVACY_INDIRIZZO`,
   `PRIVACY_EMAIL`, `PRIVACY_TRASFERIMENTI`): vengono mostrati nella pagina `privacy.php`.
   Il testo dell'informativa è un modello aderente al funzionamento dell'app: farlo verificare da un consulente privacy.
6. Accedere con `superadmin` / `Admin2026!`: l'app chiede subito di scegliere una nuova password.

## Aggiornamento da BuildingSync 2.0.0
Eseguire `sql/upgrade_2.0.0_to_2.1.0.sql` e aggiungere a `config.php` le costanti `PRIVACY_*` e
`LOG_RETENTION_DAYS` (vedi `config.example.php`; in mancanza vengono usati valori predefiniti).

## Aggiornamento da EasyCondo 1.6
1. **Backup** del database e dei file (soprattutto `uploads/segnalazioni/`).
2. Eseguire `sql/upgrade_1.6_to_2.0.0.sql` e poi `sql/upgrade_2.0.0_to_2.1.0.sql` (phpMyAdmin → SQL/Importa). Aggiunge le colonne
   eventualmente mancanti delle patch 1.1–1.5 e rinomina le tabelle con prefisso `buildingsync_`.
   Le altre tabelle del database non vengono toccate.
3. Rinominare la cartella `easycondo` in `buildingsync` (oppure adeguare `APP_URL`
   e le righe `ErrorDocument` di `.htaccess`).
4. Caricare i nuovi file conservando `config.php` e `uploads/segnalazioni/`, poi aggiornare in
   `config.php`: `APP_NAME`, `APP_URL`, prefisso `SESSION_NAME` (`bs_`), aggiungere
   `RESET_MAX_PER_HOUR` (opzionale: ha un valore predefinito).
5. Tutti gli utenti dovranno rifare il login.

## Struttura
```
admin/          pagine amministrative
assets/         CSS e JS
includes/       classi e bootstrap (accesso web negato)
logs/           log PHP (accesso web negato)
sql/            install.sql, script di upgrade, legacy/ (accesso web negato)
uploads/        allegati (accesso web negato, serviti da allegato.php)
allegato.php        download allegati con controllo permessi
cambio_password.php cambio password obbligatorio al primo accesso
privacy.php         informativa privacy e cookie (pubblica)
```

## Versione
La versione è definita in `includes/version.php` (`APP_VERSION`). Vedi `CHANGELOG.md`.

## Licenza
Copyright (C) 2026 Stefano Soranzio.

BuildingSync è distribuito con [Elastic License 2.0](LICENSE) (source-available). Puoi usarlo, modificarlo e
ridistribuirlo, ma **non** puoi offrirlo a terzi come servizio hosted o gestito, né aggirare le chiavi di licenza
o rimuovere gli avvisi di copyright. Per usi non previsti dalla licenza contattare l'autore.
