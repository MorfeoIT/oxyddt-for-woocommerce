# Cosa deve essere vero prima di un rilascio

Nessuna di queste righe è un dettaglio da rimandare: se una manca, il rilascio
non è chiuso.

## Prima di dire che una versione è pronta

* [ ] `composer check` pulito: PHPCS, PHPStan livello 8, suite unit.
* [ ] Suite di integrazione verde in CI, su PHP 8.1 e 8.3.
* [ ] `wp plugin check oxyddt-for-woocommerce` su un WordPress vero, zero errori.
      **Plugin Check ignora `phpcs.xml.dist`**: dove uno sniff non si applica lo
      si dice inline nel codice, mai nel ruleset.
* [ ] Prova su due plugin veri (gratuito + Pro) con una licenza vera emessa dal
      negozio: metà senza licenza (il Pro non regala niente, il gratuito resta
      intero), metà con licenza. Impronta `sha1` dei risultati identica prima e
      dopo aver staccato la licenza.
* [ ] `Tested up to:` nel `readme.txt` — **oggi manca di proposito**. Va scritta
      la versione di WordPress con cui si è provato **davvero** sul banco, non
      l'ultima uscita. Stessa cosa per WooCommerce.
* [ ] `dati-condivisi/plugin-compat.json` in `C:\Claude\sito-oxysoft` aggiornato
      con versione, `testatoConWordPress` e requisiti.
* [ ] `.pot` rigenerato (`wp i18n make-pot`) **prima** di costruire il pacchetto.

## Lo zip si costruisce su Linux

`Compress-Archive` di PowerShell scrive i separatori con la barra rovescia, e
WordPress si ritrova un unico file chiamato `plugin\src\Plugin.php`. Si
impacchetta sul server (`zip -r`) o via `git archive`, e si controlla con
`unzip -l` che i percorsi usino la barra normale.

Dopo **ogni** modifica: rifare lo zip e riscrivere `sha256_<slug>` nelle
impostazioni del negozio, altrimenti il plugin scarica un file che non
corrisponde e lo butta via.

## I tre siti si muovono insieme

Comanda **oxywp.com** (inglese): lì nasce il contenuto. `oxysoft.it` e
`appstore3000.com` traducono e pubblicano. Una modifica non è finita finché non
è su tutti e tre, e nessuno dei tre può avere una pagina scarna.

1. **oxywp.com** — `oxywp/plugins/oxyddt-for-woocommerce/index.html`, con
   hreflang reciproco verso l'italiana.
2. **oxysoft.it** — voce in `subpages` di `pluginwp` (`components.jsx`) **con i
   suoi `ctas`**, scheda in `products-pluginwp.jsx`, riga in `PRODUCT_URLS` e
   `SEO_ALTERNATES` (`seo-urls.jsx`), voce in allowlist di `infra/it-nginx.conf`,
   poi `node strumenti/genera-indice-pagine.mjs`.
3. **appstore3000** — prodotto in `store/data.jsx` con `download` (il gratuito) e
   `configuratore` (la Pro), più il seed SQL del prodotto nel negozio.
4. **`dati-condivisi/plugin-compat.json`** — una voce per plugin.
5. **`scaricabili-condivisi/oxyddt-for-woocommerce.zip`** — lo zip del gratuito.

Verifiche: `node strumenti/verifica-oxywp.mjs` (8/8), i `.jsx` toccati passati
per `@babel/standalone`, `deploy.ps1` sui tre siti e controllo con **curl** —
mai col browser integrato. Desktop **e** mobile: sono due bundle diversi.

## Prezzo e licenza della versione Pro

Uguali per tutti i plugin della famiglia, salvo diversa indicazione esplicita:

| | |
|---|---|
| Licenza Pro | **32,70 €** + IVA (`prezzo_cent` 3270), perpetua, 1 postazione |
| Rinnovo aggiornamenti | **16,35 €** + IVA (`prezzo_cent` 1635), 12 mesi |
| Plugin base | gratuito e **completo**, non una prova a tempo |

Sul negozio il rinnovo va marcato `nonIniziale`. La riga del prodotto ha anche
`prefisso_licenza`, `attivazioni_max` e `mesi_aggiornamenti`: si controllano
confrontandola colonna per colonna con quella del prodotto di riferimento.

## E la roadmap

Un aggiornamento è chiuso solo con i tre siti, la versione di WordPress provata
**e la roadmap pubblica** aggiornati insieme. Nella roadmap si scrive *cosa*
arriva, mai *quando*.

## Il blocco che non è tecnico

La pubblicazione su wordpress.org è **sospesa per tutta la famiglia** finché non
è presa la decisione sul prefisso Oxy-. Vedi `01-identita.md`.
