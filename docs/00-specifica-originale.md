# Oxy DDT — Documento di Trasporto Italiano per WooCommerce

## 1. Visione del prodotto

**Nome di lavoro:** Oxy DDT  
**Tipo:** Plugin WordPress / WooCommerce  
**Mercato:** Italia  
**Obiettivo:** creare e gestire Documenti di Trasporto italiani direttamente dagli ordini WooCommerce, con particolare attenzione a evasione parziale, numerazione, tracciabilità e semplicità.

Il prodotto non deve essere un generico "packing slip".

Flusso:

**Ordine WooCommerce → selezione merce da spedire → DDT → PDF → invio/stampa → storico**

---

# 2. Problema

WooCommerce non dispone nativamente di una gestione italiana dei DDT.

I plugin internazionali spesso offrono:
- packing slip;
- delivery note;
- shipping label;
- invoice PDF.

Questi documenti non coincidono necessariamente con un vero flusso gestionale DDT italiano.

Il bisogno reale è gestire:
- numerazione progressiva;
- data documento;
- causale;
- mittente/destinatario;
- luogo di destinazione;
- vettore;
- colli/peso/aspetto beni;
- righe e quantità;
- più DDT per lo stesso ordine;
- quantità residue;
- storico;
- collegamento ai documenti successivi.

---

# 3. Principi

1. Specifico per il mercato italiano.
2. Nessun servizio esterno obbligatorio.
3. Generazione PDF locale.
4. Compatibilità HPOS.
5. DDT come entità autonoma e immutabile dopo emissione, salvo procedure esplicite.
6. Audit log.
7. Numerazione sicura contro duplicati.
8. Gestione completa di evasione parziale.
9. FREE utile per piccoli negozi.
10. PRO per operatività logistica più evoluta.

---

# 4. Configurazione azienda

Impostazioni:
- ragione sociale;
- indirizzo;
- CAP;
- città;
- provincia;
- nazione;
- P.IVA;
- codice fiscale;
- telefono;
- email;
- logo;
- eventuale sede di partenza merce.

Possibilità PRO:
- più sedi/magazzini;
- più mittenti.

---

# 5. Numerazione DDT

Configurazione:
- numero progressivo;
- anno;
- prefisso;
- suffisso;
- formato.

Esempi:
- `125/2026`
- `DDT-2026-00125`
- `A/125/2026`

Requisiti:
- unicità;
- gestione concorrenza;
- reset annuale opzionale;
- sezionali PRO;
- preview prossimo numero;
- possibilità di impostare numero iniziale prima dell'uso;
- dopo emissione il numero non può essere silenziosamente riutilizzato.

La numerazione deve essere transazionale/atomicamente protetta per evitare duplicati in caso di utenti simultanei.

---

# 6. Creazione DDT da ordine WooCommerce

Nell'ordine aggiungere:
**Crea DDT**

Schermata:
- dati ordine;
- dati cliente;
- destinazione;
- elenco articoli;
- quantità ordinata;
- quantità già inclusa in DDT;
- quantità disponibile per nuovo DDT;
- quantità da includere.

Esempio:

| Prodotto | Ordine | Già in DDT | Disponibile | DDT attuale |
|---|---:|---:|---:|---:|
| A | 10 | 6 | 4 | 4 |
| B | 5 | 0 | 5 | 3 |

Il sistema deve impedire, salvo override autorizzato, di creare DDT per quantità superiori al residuo.

---

# 7. Più DDT per ordine

Requisito fondamentale.

Ordine #123:
- 10 × Prodotto A
- 5 × Prodotto B

DDT 1:
- 6 × A
- 5 × B

DDT 2:
- 4 × A

L'ordine deve mostrare:
- DDT emessi;
- quantità spedita;
- quantità residua;
- stato evasione.

Possibili stati:
- non evaso;
- parzialmente evaso;
- completamente evaso.

---

# 8. DDT da più ordini

PRO consigliata.

Permettere di selezionare più ordini compatibili dello stesso cliente/destinatario e generare un unico DDT.

Controlli:
- cliente;
- destinazione;
- valuta irrilevante per il DDT ma verificabile;
- sede/magazzino;
- coerenza causale.

Nel DDT mantenere riferimento agli ordini sorgente e, per ogni riga, provenienza.

---

# 9. Campi DDT

## Testata
- numero;
- data;
- ora opzionale;
- ordine/i di riferimento;
- mittente;
- destinatario;
- luogo di destinazione;
- causale trasporto;
- trasporto a cura di;
- vettore;
- data/ora inizio trasporto, quando utilizzata;
- porto;
- numero colli;
- peso lordo/netto opzionale;
- aspetto esteriore dei beni;
- note.

## Righe
- SKU;
- codice;
- descrizione;
- quantità;
- unità di misura;
- lotto/seriale futuro;
- ordine origine.

Prezzi:
- non necessari per impostazione predefinita;
- opzione per mostrarli se l'utente lo desidera.

---

# 10. Causali

Predefinite modificabili:
- vendita;
- conto visione;
- conto lavorazione;
- conto riparazione;
- reso;
- sostituzione;
- omaggio;
- trasferimento interno;
- altro.

L'utente deve poter aggiungere causali personalizzate.

---

# 11. Vettori

Anagrafica vettori:
- ragione sociale;
- P.IVA/CF opzionale;
- indirizzo;
- contatti;
- note.

FREE:
- vettore digitabile + elenco semplice.

PRO:
- anagrafica completa;
- vettore predefinito per metodo di spedizione;
- tracking/integrations future.

---

# 12. PDF

Generare PDF A4 professionale.

Contenuti:
- logo;
- mittente;
- destinatario;
- numero/data;
- riferimento ordine;
- causale;
- trasporto;
- tabella prodotti;
- colli/peso;
- note;
- eventuali spazi firma/consegna.

Funzioni:
- visualizza;
- scarica;
- stampa;
- invia email.

Template:
- configurazione base senza codice;
- override avanzato tramite template/hook.

---

# 13. Stato documento

Stati suggeriti:
- bozza;
- emesso;
- annullato.

Opzionali PRO:
- preparato;
- consegnato;
- firmato.

Una **bozza** non consuma necessariamente il numero definitivo, a seconda della scelta architetturale.

Consiglio: assegnare il progressivo definitivo solo al comando **Emetti DDT**.

---

# 14. Immutabilità

Dopo emissione:
- numero non modificabile normalmente;
- data non modificabile normalmente;
- righe non modificabili normalmente.

Per correzioni:
- annulla documento con motivazione;
- crea nuovo documento.

Prevedere capability speciale per operazioni amministrative eccezionali, sempre registrate nel log.

---

# 15. Registro DDT

Pagina:
**WooCommerce → DDT**

Colonne:
- numero;
- data;
- cliente;
- ordine;
- destinazione;
- causale;
- vettore;
- stato;
- PDF.

Filtri:
- anno;
- mese;
- cliente;
- ordine;
- causale;
- vettore;
- stato;
- intervallo numeri.

Ricerca full-text minima su numero, cliente, ordine.

---

# 16. Azioni massive

PRO:
- genera DDT da ordini selezionati;
- stampa PDF;
- scarica ZIP;
- invia email;
- cambia vettore;
- esporta CSV.

Le azioni massive non devono permettere sovraevasione.

---

# 17. Email

Invio manuale DDT:
- destinatario;
- CC/BCC;
- oggetto;
- messaggio;
- PDF allegato.

Opzione:
- allega DDT a specifiche email WooCommerce.

PRO:
- automazione per stato ordine;
- template multipli;
- reinvio;
- log consegna email.

---

# 18. Collegamento all'ordine

Nella pagina ordine mostrare box:

**DDT**
- DDT 125/2026 — 12/08/2026 — Emesso
- DDT 131/2026 — 13/08/2026 — Emesso

Stato:
`Evaso 8/10 articoli`

Azioni:
- nuovo DDT;
- visualizza;
- scarica;
- invia.

Compatibilità sia con legacy order screen sia con HPOS, privilegiando le API WooCommerce.

---

# 19. Dati cliente/destinazione

Acquisire una fotografia dei dati al momento dell'emissione.

Il DDT già emesso non deve cambiare se successivamente il cliente modifica indirizzo nell'account o l'ordine viene editato.

Memorizzare snapshot:
- nome/ragione sociale;
- indirizzo;
- CAP;
- città;
- provincia;
- nazione;
- P.IVA/CF, se presenti.

---

# 20. Free vs PRO

## FREE
- configurazione azienda;
- numerazione singola;
- DDT da singolo ordine;
- evasione parziale;
- più DDT per ordine;
- causali;
- vettore;
- colli/peso;
- PDF;
- download/stampa;
- email manuale;
- registro;
- HPOS;
- storico/audit essenziale.

## PRO
- sezionali;
- più sedi;
- DDT cumulativi da più ordini;
- azioni massive;
- ZIP;
- template avanzati;
- automazioni email;
- anagrafica vettori avanzata;
- tracking;
- firme/allegati;
- API/webhook;
- report;
- integrazioni gestionali;
- lotti/seriali tramite addon/integration futura.

---

# 21. Struttura dati suggerita

Tabelle:
- `{prefix}oxy_ddt`
- `{prefix}oxy_ddt_items`
- `{prefix}oxy_ddt_orders`
- `{prefix}oxy_ddt_carriers`
- `{prefix}oxy_ddt_sequences`
- `{prefix}oxy_ddt_logs`

Campi documento devono contenere snapshot dei dati rilevanti.

Il PDF può essere:
- generato e archiviato all'emissione;
- oppure rigenerabile da snapshot immutabile.

Consiglio: archiviare una copia definitiva del PDF emesso e hash del file.

---

# 22. Integrità numerazione

Implementare lock/transaction o meccanismo equivalente.

Scenario da testare:
due utenti premono "Emetti" contemporaneamente.

Risultato obbligatorio:
- DDT 125
- DDT 126

Mai:
- due DDT 125.

---

# 23. Sicurezza

- nonce;
- capability;
- escaping;
- sanitizzazione;
- prepared queries;
- PDF non enumerabili pubblicamente;
- endpoint download autorizzato;
- log modifiche;
- protezione CSRF/XSS;
- nessuna fiducia nei dati client-side;
- verifica quantità server-side.

---

# 24. Capability

- `oxy_ddt_view`
- `oxy_ddt_create`
- `oxy_ddt_issue`
- `oxy_ddt_send`
- `oxy_ddt_cancel`
- `oxy_ddt_manage_settings`
- `oxy_ddt_manage_sequences`

---

# 25. API

Namespace:
`/wp-json/oxy-ddt/v1/`

Endpoint futuri:
- GET DDT
- GET DDT by order
- POST draft
- POST issue
- POST send
- POST cancel

Qualunque endpoint di scrittura richiede capability e nonce/auth appropriata.

---

# 26. Hooks

Azioni:
- `oxy_ddt_created`
- `oxy_ddt_issued`
- `oxy_ddt_sent`
- `oxy_ddt_cancelled`

Filtri:
- dati documento;
- righe;
- causali;
- nome file PDF;
- template;
- email.

---

# 27. Normativa e validazione

Il plugin deve essere progettato sulla disciplina italiana applicabile ai documenti di trasporto, incluso il quadro derivante dal D.P.R. 14 agosto 1996 n. 472 e successive disposizioni.

**Importante:** prima della release pubblica far validare da un commercialista/consulente fiscale:
- campi obbligatori;
- casi particolari;
- numerazione;
- fatturazione differita;
- conservazione/documentazione;
- causali specifiche.

Il codice deve distinguere:
- requisiti normativi;
- campi gestionali facoltativi.

Evitare claim marketing come "garantisce la conformità fiscale" senza revisione professionale.

---

# 28. UX

Menu:

**WooCommerce**
- DDT
  - Tutti i DDT
  - Nuovo DDT
  - Vettori
  - Impostazioni

Da ordine:
pulsante evidente **Crea DDT**.

Obiettivo operativo:
creare un DDT standard da ordine in meno di 30 secondi.

---

# 29. MVP sprint

## Sprint 1
- bootstrap;
- migrazioni;
- impostazioni azienda;
- capability.

## Sprint 2
- modello DDT;
- relazione ordine-DDT;
- snapshot cliente.

## Sprint 3
- creazione da ordine;
- quantità residue;
- evasione parziale.

## Sprint 4
- numerazione atomica;
- emissione/immutabilità;
- annullamento.

## Sprint 5
- PDF;
- download sicuro;
- stampa/email.

## Sprint 6
- registro;
- filtri;
- box ordine.

## Sprint 7
- HPOS;
- test concorrenza;
- security test;
- performance.

## Sprint 8
- i18n;
- documentazione;
- packaging WordPress.org.

---

# 30. Acceptance criteria

1. creo DDT da un ordine;
2. posso includere solo parte delle quantità;
3. vedo correttamente il residuo;
4. creo un secondo DDT sul residuo;
5. non posso superare le quantità senza autorizzazione;
6. il numero è univoco anche in concorrenza;
7. il PDF rappresenta esattamente lo snapshot emesso;
8. modifiche successive all'ordine non alterano il DDT;
9. posso annullare con log;
10. posso scaricare/inviare il PDF;
11. posso ricercare il DDT nel registro;
12. HPOS funziona;
13. nessun warning/fatal con WP_DEBUG;
14. download protetto;
15. tutte le operazioni critiche rispettano capability.

---

# 31. Fuori scope MVP

- fatturazione elettronica;
- XML FatturaPA;
- invio SdI;
- contabilità;
- gestione corrieri completa;
- WMS;
- stampa etichette;
- packing list internazionale;
- firma elettronica qualificata;
- conservazione digitale a norma.

---

# 32. Posizionamento

> **DDT italiani, direttamente dentro WooCommerce.**

Non vendere il prodotto come "PDF generator".

Valore:
- evasione parziale;
- numerazione;
- storico;
- documenti collegati agli ordini;
- workflow italiano;
- indipendenza da gestionali esterni.

---

# 33. Evoluzioni

Possibili addon/integrations:
- Oxy Suppliers: DDT di ricezione/collegamento a PO;
- Oxy XML Invoice: riferimenti ai DDT nelle fatture differite;
- Oxy Serial: seriali/lotti sulle righe DDT;
- API verso gestionali;
- tracking corrieri.

---

# 34. Note per l'agente

- seguire WordPress Coding Standards;
- namespace PHP;
- autoloading;
- mai interrogare direttamente legacy WooCommerce order tables;
- usare CRUD/API WooCommerce;
- predisporre test automatici per quantità e numerazione;
- non usare post meta come archivio indiscriminato di documenti;
- mantenere schema dati versionato;
- tutte le stringhe traducibili;
- nessuna dipendenza SaaS.
