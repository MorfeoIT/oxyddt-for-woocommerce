# Architettura

Come è messo insieme il plugin, e le scelte che la specifica lasciava aperte.

## Gli strati

```
src/Domain/          PHP puro: nessuna funzione WordPress, nessun $wpdb
src/Infrastructure/  container, migrazioni, orologio, WooCommerce
src/Security/        capability
src/Settings/        la riga di opzione
src/Audit/           il registro in sola aggiunta
src/Admin/           la pagina WooCommerce → DDT
```

La regola che regge tutto: **il calcolo sta nel Domain e non conosce
WordPress.** Le quantità residue, la validazione dei documenti e le regole di
numerazione arrivano lì. È quello che permette alla suite unit di girare in un
secondo su qualunque macchina, senza database, e di provare ogni ramo — compresi
i casi che dentro WordPress si riproducono a fatica, come il capodanno alle 00:30
con il fuso del negozio diverso da UTC.

`ClockInterface` esiste per quello: nessun `time()` sparso nel codice.

## Il container

Serve a una cosa sola: permettere al PRO (e a un add-on di terzi) di
**sostituire** un servizio — la sequenza di numerazione, il renderer PDF, il
repository dei documenti — invece di modificare un file del gratuito. Il punto
di aggancio è `oxyddt_register_services`, che scatta quando i servizi sono
dichiarati e nessuno è ancora stato costruito.

Regola ereditata da OxyProfit, scritta prima di averne bisogno: **il PRO non
contiene formule**, contiene un provider. Il calcolo sta nel gratuito, scritto e
provato una volta sola. E il PRO senza licenza non deve mai spegnere il
gratuito.

## Dati

I documenti **non stanno nei post meta**. Un negozio con quattro anni di
spedizioni ha centinaia di migliaia di righe, e «quali righe di quest'ordine
sono già uscite» è una domanda che una tabella di meta non regge.

Tabelle previste (nomi definitivi, `{prefix}` è quello del sito):

| Tabella | Sprint |
|---|---|
| `{prefix}oxyddt_logs` | 1 ✅ |
| `{prefix}oxyddt_documents` | 2 ✅ |
| `{prefix}oxyddt_items` | 2 ✅ |
| `{prefix}oxyddt_orders` | 2 ✅ (DDT ↔ ordini, molti a molti) |
| `{prefix}oxyddt_sequences` | 3 ✅ |
| `{prefix}oxyddt_carriers` | 5-6 |

Lo schema è versionato in `Migrator::TARGET_VERSION` e ogni migrazione registra
la propria versione, così una migrazione interrotta riprende da dove si è
fermata. Gira all'attivazione **e** alla prima richiesta dopo un aggiornamento:
un plugin aggiornato via FTP o WP-CLI non esegue mai l'hook di attivazione.

### Perché il log è la prima tabella

Il registro deve poter annotare il cambio di impostazioni che viene *prima* del
primo documento. Un documento immutabile e un numero che non si riusa si
difendono solo se esiste traccia di chi ha fatto cosa.

Non c'è una colonna con l'indirizzo IP, di proposito: sarebbe un dato personale
conservato per anni, su un documento che nessuno consulta per quello, e l'utente
più la data rispondono già a tutte le domande per cui il registro esiste.

## WooCommerce

* `Requires Plugins: woocommerce` nell'intestazione: da WordPress 6.5 è
  WordPress stesso a impedire l'attivazione senza WooCommerce.
* Compatibilità **HPOS** e blocchi di checkout dichiarate con `FeaturesUtil`, a
  caricamento del file e non dentro un hook nostro: WooCommerce spara
  `before_woocommerce_init` mentre parte.
* **Mai leggere le tabelle degli ordini**, né quelle legacy né quelle HPOS: solo
  CRUD e API di WooCommerce. È l'unico modo perché il plugin funzioni con
  entrambe le memorizzazioni.
* Il boot del plugin è su `plugins_loaded` a priorità 20, dopo che WooCommerce
  ha avuto modo di esserci.

## Capability

Sette, come da specifica ma con il prefisso corretto. La mappa dei ruoli:

| Ruolo | Capability |
|---|---|
| `administrator` | tutte e sette |
| `shop_manager` | view, create, issue, send, cancel |

Un shop manager gestisce la giornata di spedizioni; numerazione e dati del
mittente sono configurazione fiscale che si tocca una volta e che, sbagliata,
è sbagliata su ogni documento stampato dopo.

`GRANT_VERSION` viene alzata ogni volta che la mappa cambia, e la concessione
viene rifatta: senza, un sito conserverebbe per sempre le capability del giorno
in cui ha installato il plugin. È il difetto che su OxyProfit è costato uno
sprint.

Nota: se `shop_manager` non esiste ancora (WooCommerce non ha ancora installato
i suoi ruoli) l'opzione **non** viene scritta, così si riprova alla richiesta
successiva.

## Interfaccia

Una sola voce sotto WooCommerce, chiamata **DDT**, con schede dentro — come fa
WooCommerce stesso. Un plugin che aggiunge quattro voci al menu di un negozio è
un plugin che si disinstalla. Le schede si registrano da sole (`Screen::add_tab`),
così lo sprint che aggiunge una schermata aggiunge un file invece di modificarne
tre. La barra delle schede non si disegna finché ce n'è una sola.

## Scelte ancora da fare, con la raccomandazione

**Motore PDF: dompdf, vendorato** (fatto nello sprint 5). LGPL 2.1, compatibile
GPL, prende HTML+CSS come sorgente: la personalizzazione del template dello §12
viene quasi gratis. Alternative scartate: TCPDF (API a coordinate, template
impossibili da personalizzare senza codice), mPDF (più pesante, stessa resa).

Due conseguenze pratiche:

* `composer.json` e `composer.lock` **vengono spediti**, e il pacchetto si
  costruisce con `git archive` + `composer install --no-dev`. Il job "package"
  della CI fa esattamente questo e verifica che `vendor/dompdf/dompdf` ci sia e
  che nessuna dipendenza di sviluppo si sia infilata dentro.
* `isRemoteEnabled` è **spento** nel renderer. La pagina è costruita in parte da
  quello che ha scritto un cliente: un documento che può scaricare URL mentre
  viene renderizzato è una SSRF con passaggi in più.

**Il PDF si genera una volta sola**, all'emissione, e si archivia con il suo
SHA-256 in `wp-content/uploads/oxyddt/<anno>/`. Tutto il resto (download, stampa,
allegato email) rilegge quel file: la copia del negozio e quella del cliente
sono la stessa copia. Se il file sparisce (ripristino senza uploads, cambio
host) viene ricostruito dallo snapshot e il registro lo annota.

La cartella ha `.htaccess`, `web.config` e `index.php`, e ogni nome file finisce
con venti caratteri casuali — ma la difesa vera è l'endpoint su `admin-post.php`
che controlla capability e nonce prima di servire i byte.

**FREE e PRO sono due plugin separati**, non uno con codice dormiente sbloccato
da licenza: la linea guida 5 di wordpress.org vieta il trialware. `oxyddt-for-woocommerce`
e `oxyddt-for-woocommerce-pro`.

## Mappa degli sprint

| Sprint | Contenuto | Stato |
|---|---|---|
| 1 | bootstrap, migrazioni, impostazioni azienda, capability | ✅ |
| 2 | modello DDT, relazione ordine-DDT, snapshot cliente | ✅ |
| 3 | creazione da ordine, quantità residue, evasione parziale | ✅ |
| 4 | numerazione atomica, emissione, immutabilità, annullamento | ✅ |
| 5 | PDF, download protetto, stampa, email | ✅ |
| 6 | registro, filtri, box nell'ordine | ✅ |
| 7 | HPOS, test di concorrenza, sicurezza, prestazioni | |
| 8 | i18n, documentazione, pacchetto per wordpress.org | |

## La numerazione, e cosa è davvero dimostrato

Il numero lo assegna il **database**, non PHP:

```sql
UPDATE …oxyddt_sequences
   SET next_number = LAST_INSERT_ID(next_number) + 1
 WHERE series = %s AND sequence_year = %d
```

MySQL prende un lock di riga per la durata dello statement, quindi due richieste
che arrivano insieme vengono serializzate; `LAST_INSERT_ID(expr)` registra per
**questa connessione** il valore preso, che si rilegge con
`SELECT LAST_INSERT_ID()` senza tornare sulla tabella — cioè senza finestra in
cui qualcun altro possa prendere lo stesso numero. L'alternativa ovvia (leggi,
somma uno, riscrivi) quella finestra ce l'ha, ed è larga quanto due istruzioni
PHP.

Seconda difesa: l'indice unico su `(series, sequence_year, sequence_number)`. Se
il numero è già in uso, l'INSERT viene rifiutato e l'`Issuer` **riprova** con il
successivo (fino a 5 volte). Mai un duplicato; al massimo un buco, e un buco si
spiega.

**Cosa è dimostrato dai test** (sprint 4): 100 allocazioni consecutive danno 100
numeri distinti e contigui; un numero già preso da «un'altra richiesta» viene
aggirato e produce 125 e 126; una bozza non pronta non consuma nulla; un numero
annullato non torna nel mazzo.

**Cosa NON è dimostrato**: il parallelismo vero. PHPUnit gira in un processo
solo e la suite di WordPress avvolge ogni test in una transazione, quindi una
seconda connessione non vedrebbe nemmeno le righe. La prova con processi
concorrenti su un server vero resta **allo sprint 7**, sul banco.

Il resto dello sprint 7 (HPOS, sicurezza, prestazioni) è invariato.
