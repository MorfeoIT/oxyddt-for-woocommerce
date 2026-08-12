# Identità del prodotto

Deciso il 12/08/2026, prima di scrivere codice. Cambiare una di queste stringhe
dopo la pubblicazione su wordpress.org non si può: lo slug è definitivo dal
giorno dell'approvazione.

## Le stringhe

| | |
|---|---|
| Nome | **OxyDDT – Italian Delivery Notes (DDT) for WooCommerce** |
| Slug e text domain | `oxyddt-for-woocommerce` |
| Namespace PHP | `Oxysoft\OxyDDT` |
| Prefisso | `oxyddt_` (tabelle, opzioni, capability, hook) |
| Costanti | `OXYDDT_` |
| REST | `oxyddt/v1` |
| Pagina prodotto | `https://oxywp.com/plugins/oxyddt-for-woocommerce/` |
| Repo | `github.com/MorfeoIT/oxyddt-for-woocommerce` (privato) |

## Perché non `oxy_`, come diceva la specifica

La specifica di partenza propone tabelle `{prefix}oxy_ddt`, capability
`oxy_ddt_view` e namespace REST `/oxy-ddt/v1/`. Non si usa, per due motivi che
valgono per tutta la famiglia:

1. **Tre caratteri non sono un prefisso.** Le linee guida di wordpress.org
   chiedono un prefisso univoco di almeno quattro caratteri; `oxy_` è sotto la
   soglia e uno sniff di Plugin Check lo segnala.
2. **`oxy_` si legge come Oxygen Builder.** L'ecosistema del page builder di
   Soflyy usa quel prefisso da anni: un utente che trova `oxy_ddt_view` fra le
   capability non ha modo di sapere di chi è.

Stessa scelta già fatta su OxyArea (`oxyarea_`, mai `oxy_`).

## Verifiche fatte il 12/08/2026

| Registro | Esito |
|---|---|
| `api.wordpress.org/plugins/info/1.0/oxyddt-for-woocommerce.json` | 404 — slug libero |
| `api.wordpress.org` slug `oxyddt` | 404 — libero |
| `api.wordpress.org` query_plugins «DDT» | 3 plugin, il maggiore a 10 installazioni |
| packagist.org `oxyddt` | nessun pacchetto |
| registry.npmjs.org `oxyddt` | nessun pacchetto |
| api.github.com repositories `oxyddt` | zero risultati |
| rdap.verisign.com `oxyddt.com` | non registrato |

Nessun dominio dedicato: il prodotto vive sotto oxywp.com, come tutta la
famiglia.

## Cosa resta aperto, e blocca la pubblicazione (non lo sviluppo)

**Il prefisso Oxy- ha una decisione commerciale non ancora presa.** Soflyy
pubblica su `oxygenbuilder.com/brand/` una policy che dice di non usare «oxygen»
o «oxy» nei nomi prodotto, e dichiara che la farà valere come la fa valere la
WordPress Foundation. È una policy privata e non un marchio registrato, ma
wordpress.org agisce sulle segnalazioni di marchio e **lo slug non si cambia
dopo l'approvazione**: l'esposizione è asimmetrica.

Conseguenze già decise per la famiglia:

* OxyArea è pronto ma **non è stato proposto** a wordpress.org: si aspetta di
  sottomettere la famiglia insieme, perché il primo slug approvato fissa il
  prefisso per tutti.
* OxyDDT eredita la stessa attesa. Il codice si scrive, il rilascio no.
* La ricerca di anteriorità sui registri veri (EUIPO, TMview, WIPO, UIBM) non è
  interrogabile da script — captcha e proof-of-work — e va fatta a mano o da un
  consulente.

Il rischio concreto rilevato su OxyProfit non è «OxyDDT» ma «OXY»: *oxysales,
UAB* (Oxylabs) rivendica OXY in classi 9 e 42 con enforcement documentato. Da
aggiungere alla prossima ricerca: classe 35, ricerca fonetica e troncata `OXY*`.

## Nome del prodotto in inglese, mercato italiano

Il prodotto serve solo all'Italia, ma la fonte dei contenuti della famiglia è
oxywp.com, in inglese: il nome resta inglese con «DDT» dentro, che è la sigla
che un negoziante italiano cerca. Le pagine italiane su oxysoft.it e
appstore3000 traducono, come per gli altri plugin.
