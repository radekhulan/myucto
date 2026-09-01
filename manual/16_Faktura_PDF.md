# 16. Faktura — PDF, QR platba, odeslání e-mailem

Vystavená faktura má **immutable PDF** — vygeneruje se v okamžiku vystavení a
od té chvíle se nemění (snapshot dodavatele, klienta, banky). Tím se zajišťuje
neměnnost dokladu i kdybyste si v Nastavení změnil adresu nebo bankovní účet.

## 16.1 Detail faktury

Klik na číslo faktury v seznamu otevře detail.

![Detail faktury](img/10_detail.webp)

Detail ukazuje:

- **Hlavičku** — variabilní symbol, typ, klient, data, částka, stav
- **Položky** — read-only zobrazení řádků
- **Daňové zařazení** — karta se vším, co jde nastavit v editoru: reverse charge,
  VAT klasifikace (kód i popis), zjednodušený daňový doklad § 30, ceny zadané
  včetně DPH, osvobození od daně z příjmů i s důvodem a kategorie tržby
- **Náhled PDF** — embed iframe (lze otevřít na celou obrazovku)
- **Zdrojové PDF z importu** — jen u faktur naimportovaných z iDoklad/Fakturoid:
  originální PDF dokladu, jak dorazil ze zdrojového systému (náhled + stažení).
  Je oddělené od našeho vygenerovaného PDF — viz [21. Importy](21_Importy.md).
- **Activity log** — kdo a kdy fakturu vytvořil / vystavil / odeslal / označil
  zaplacenou

### 16.1.1 Akční tlačítka (vpravo nahoře)

Závisí na stavu faktury:

| Stav | Dostupné akce |
|---|---|
| `issued` | Stáhnout PDF, Odeslat e-mailem, Web faktura, Označit zaplacené, Částečná úhrada, Storno, Dobropis, Test odeslání, Test upomínky, **Editovat (force)**, Zaúčtovat* |
| `sent` | Stáhnout PDF, Odeslat znovu, Web faktura, Označit zaplacené, Částečná úhrada, Upomínka, Dobropis, Zaúčtovat* |
| `reminded` | Stáhnout PDF, Další upomínka (cooldown 14 dní), Web faktura, Označit zaplacené, Částečná úhrada, Zaúčtovat* |
| `paid` | Stáhnout PDF, Web faktura, Dobropis (vrátit peníze), Zaúčtovat* |

\* jen podvojné účetnictví a dokud faktura nemá účetní ikonu **Zaúčtováno** — viz
[§ 16.1.3](#1613-zauctovani-do-deniku).

> 💡 **Test odeslání / Test upomínky** — pošle e-mail jen na **tvůj** e-mail
> (ne klientovi). Užitečné pro vyzkoušení šablony nebo SMTP konfigurace.

### 16.1.2 Platby a částečné úhrady

Každá faktura i zálohová faktura může mít **více evidovaných plateb** (splátky,
více převodů, e-mailová avíza). Platby vznikají:

- **automaticky** při párování bankovního výpisu nebo e-mailového avíza
  (viz [28. Banka](28_Banka.md)) — i částečná platba se shodným variabilním
  symbolem se zaeviduje,
- tlačítkem **Částečná úhrada** — modal s částkou (předvyplněn zbytek), datem
  platby, volitelným VS, referencí a poznámkou,
- tlačítkem **Označit zaplacené** — to je zkratka „platba na celý zbytek".

Detail faktury zobrazuje box **Platby**: datum, částku, zdroj (ručně / banka /
označeno zaplaceno), referenci a u záloh odkaz na daňový doklad k platbě.
Platbu lze smazat (✕) — pokud tím doklad přestane být pokrytý, vrátí se ze
stavu *Zaplaceno* mezi pohledávky. Platba navázaná na bankovní transakci se
maže přes **Zrušit spárování** v detailu výpisu; platba s vystaveným daňovým
dokladem až po jeho smazání/stornu.

Pokud iDoklad importoval související bankovní pohyb, ale nevytvořil z něj
samostatnou evidovanou platbu, zobrazí se pod platbami jako **Související
bankovní pohyb** s odkazem na výpis. Faktura tak neztratí dohledatelnou vazbu
na zdrojový pohyb ani v případě, kdy ji iDoklad už označil jako uhrazenou.

Stav úhrady ukazuje badge: **Částečně uhrazeno** (přijata část peněz, zbytek
se dál upomíná a počítá do pohledávek) a **Přeplaceno** (přišlo víc než
částka k úhradě). V sumaci detailu je řádek **Uhrazeno** a **Zbývá uhradit**;
stejný rozpis má i PDF a QR platba v PDF, e-mailu a upomínce zní vždy jen na
zbývající částku.

#### Zálohová faktura: daňový doklad k přijaté platbě

Plátce DPH musí ke každé úplatě přijaté před uskutečněním plnění vystavit
**daňový doklad k přijaté platbě** (§ 28 odst. 2 ZDPH) s DUZP = den přijetí
platby. U **částečné úhrady zálohové faktury** ho MyÚčto vystaví jako
koncept automaticky (bankovní párování) nebo na klik (zaškrtávátko v modalu
Částečná úhrada / tlačítko v boxu Platby):

- DPH se počítá **shora koeficientem** (§ 37) a platba se rozdělí mezi sazby
  DPH zálohy poměrně podle jejich vah,
- doklad se čísluje v řadě **faktur**, do výkazů DPH/KH/Knihy DPH vstupuje
  v měsíci platby a vystavením je rovnou „zaplacený",
- u **neplátce DPH** a u plnění v **přenesené daňové povinnosti** se
  nevystavuje (u RC se záloha nedaní — daň vzniká až k DUZP plnění).

Finální doklad (vyúčtování) pak ke zdaněným platbám přidá **záporné odpočtové
řádky** (§ 37a) — daní se jen zbytek, nic dvakrát. Vyúčtovat lze i jen
částečně uhrazenou zálohu; odpočet pokryje přijaté platby a zbytek zůstane
na finálním dokladu k úhradě. Jakmile finál existuje, další daňový doklad
k platbě už vystavit nejde (a obráceně ruční párování zálohy s daňovými
doklady k platbě je blokované) — ochrana proti dvojímu zdanění. Daňový doklad
k platbě ani vazbu finálu s odpočtovými řádky § 37a nelze samostatně stornovat
nebo rozpojit; chybný cyklus se opravuje nejdřív stornem finální faktury.
U cizoměnové platby se pro DPH použije kurz k datu přijetí platby, ne kurz
původní proformy.

### 16.1.3 Zaúčtování do deníku

Vystavení faktury a její zaúčtování jsou **dva oddělené kroky** — vystavená
faktura sama o sobě do [Účetního deníku](45_Ucetni_denik.md) nic nezapíše.
Tlačítko **Zaúčtovat** (sekundární, vedle hlavní platební/upomínkové akce) se
zobrazí jen firmám v režimu **podvojné účetnictví**, dokud faktura nemá
ikonu **Zaúčtováno** (text a datum jsou v tooltipu) a není v konceptu ani stornu. Klikem se zeptá na
potvrzení („Zaúčtovat doklad do účetního deníku?") a vytvoří zápis podle
[předkontace](88_Ucetni_nastroje.md#883-predkontace).
Po úspěchu se detail obnoví, badge se změní na **Zaúčtováno** (s datem v
tooltipu) a v menu s dalšími akcemi se objeví **Zobrazit v deníku** — proklik
rovnou na vzniklý zápis.

Zaúčtovat smí jen **admin nebo účetní** (role `client` tlačítko sice uvidí,
ale klik skončí chybou oprávnění).

Pokud zaúčtování selže, appka zobrazí konkrétní důvod místo obecné chyby:

| Chyba | Co znamená / jak opravit |
|---|---|
| Doklad nemá řádky k zaúčtování | Faktura je proforma, záloha nebo storno — ty se neúčtují (proforma až po vyúčtování). |
| Doklad v cizí měně nemá vyplněný směnný kurz | Doplň kurz k datu účetního případu na faktuře. |
| Pro datum dokladu neexistuje účetní období | Založ období v **Nástroje → Uzávěrka**. |
| Účetní období je uzavřené | Do uzavřeného období nelze účtovat — viz [Uzávěrka](87_Uzaverka.md). |
| Účetní zápis není vyvážený (MD ≠ Dal) | Zkontroluj předkontaci a částky dokladu. |
| V účtové osnově chybí potřebný účet | Chybí nebo je deaktivovaný účet v předkontaci — doplň/aktivuj v [Účtovém rozvrhu](81_Ucetni_osnova.md). |
| Zápis dokladu je stornovaný | Opravu zaúčtuj **novým** zápisem, ne přepisem stornovaného. |
| Doklad nebyl nalezen / neexistuje | Faktura mezitím byla smazána nebo změnila stav — obnov stránku. |

**Hromadné zaúčtování.** V [seznamu faktur](14_Faktury.md#143-hromadne-akce)
označíš víc faktur a klikneš **Zaúčtovat (N)** — nabídne se jen z vybraných ty
vystavené a dosud nezaúčtované. Appka doklady účtuje **jeden po druhém**
(chyba jednoho neblokuje ostatní) a na konci zobrazí souhrn *„Zaúčtováno {ok},
chyby: {err}"* s konkrétní hláškou u každého selhaného čísla dokladu. Dávka je
omezená na **500 dokladů** — větší výběr rozděl.

**Automatické zaúčtování při vystavení** (volitelné, nastavuje admin) —
viz [§ 92.11 Automatické zaúčtování](92_Nastaveni.md#9211-automaticke-zauctovani-pri-vystaveniprijeti-dokladu).

## 16.2 PDF struktura

Vygenerované PDF obsahuje:

1. **Hlavičku** — logo dodavatele, jméno, adresa, IČO, DIČ, kontakt
2. **Adresát** — klient (firma + adresa + IČO + DIČ). U zemí s národním daňovým číslem se tiskne navíc s nativním labelem — slovenský klient má `IČO → DIČ → IČ DPH` (u neplátce jen IČO + DIČ), německý/rakouský Steuernummer, polský NIP, maďarský Adószám (viz [§ 18.2.1a](18_Klienti.md#1822-1821a-slovensky-klient-a-narodni-danova-cisla))
3. **Číslo faktury** + **typ** (Faktura / Proforma / Dobropis / Storno)
4. **Data** — vystaveno, DUZP, splatnost
5. **Bankovní spojení** — číslo účtu / IBAN, BIC, banka, variabilní symbol
6. **Položky** — tabulka (Popis / Množství / Cena / DPH / Celkem)
7. **Sumář** — mezisoučet, sleva, DPH rozpis, **CELKEM**
8. **(EUR / cizí měna) Přepočet do CZK** — kurz ČNB + tabulka základů DPH a DPH v CZK (světle šedé podbarvení)
9. **QR platbu** — vpravo dole (CZK SPAYD nebo EUR SEPA EPC)
10. **Patičku** — text z Nastavení dodavatele (volitelný)
11. **(volitelně) 2. strana** — Výkaz víceprací. Pokud faktura výkaz má, položka „Výkaz víceprací" v tabulce položek je **proklikávací odkaz** (podtržená) — kliknutí přeskočí přímo na stránku s výkazem.

> 💡 **Branding hlavičky** — pokud má dodavatel v *Nastavení → Branding* zapnutý
> branding, PDF přebere jeho **logo** a **akcentovou barvu** (čára pod hlavičkou,
> nadpisy, hlavička tabulky položek, světlá podbarvení). Když je logo malé nebo
> neobsahuje název firmy, zapni **„Zobrazit i název firmy vedle loga"** — vedle
> loga se pak vykreslí obchodní (nebo firemní) název. Sémantické barvy zůstávají
> vždy stejné (dobropis červená, storno šedá).

### 16.2.1 Přepočet do CZK (faktury v cizí měně)

Pokud je faktura v jiné měně než CZK, PDF obsahuje navíc:

- **Drobnou řádku v hlavním sumáři**: „Kurz ČNB: 24,360 CZK / 1 EUR (2026-05-03)"
- **Samostatnou tabulku „Přepočet do CZK"** pod sumářem se světle šedým
  podbarvením, kde je rozpis základů a DPH per sazba v CZK + celkové součty.

Kurz se ukládá na fakturu v okamžiku **prvního uložení** a nemění se ani po
vystavení, ani po editaci items (pokud se nezmění `issue_date` nebo měna).
Pokud faktura nemá zafixovaný kurz, MyÚčto ho **doplní automaticky** při
příštím otevření detailu nebo PDF (cache → ČNB →
poslední známý). Detail viz [§ 15.4.2 Faktura v cizí měně](15_Faktura_editor.md#1542-faktura-v-cizi-mene-eur-usd-prepocet-do-czk).

### 16.2.2 PDF/A-3b (archivní formát)

Všechna generovaná PDF (faktury, přijaté faktury, výkazy práce, Kniha DPH,
Kniha jízd) jsou ve formátu **PDF/A-3b** (ISO 19005-3) — standardu pro
**dlouhodobou archivaci**. Dokument je soběstačný a vykreslí se stejně na
každém zařízení i tiskárně, dnes i za 20 let.

- **Vložené fonty** — písmo je součástí souboru, takže text jde vyhledávat
  a kopírovat a nikde nedojde k záměně fontu.
- **Barevný profil** — dokument nese barevný profil **sRGB**. Logo nebo obrázek
  v jiném barevném prostoru (CMYK) se **automaticky převede** na sRGB, aby
  archiv zůstal konzistentní (PDF/A nepovoluje míchání barevných prostorů).
- **ISDOC příloha** — strukturovaná data faktury jsou vložená přímo v PDF jako
  příloha, viz [§ 20.3.5](20_Exporty.md).
- **Elektronický podpis** — PAdES podpis archivní konformitu **zachová**.

> 🔎 **Ověření konformity.** Výstup je validován referenčním ISO validátorem
> **veraPDF** (ISO 19005-3, flavour `3b`). Procházejí všechny varianty — faktury
> i přijaté faktury, s logem (RGB i CMYK) i bez, podepsané i nepodepsané.

## 16.3 QR platba

![QR platba na PDF](img/10_qr_platba.webp)

Pro **CZK** se generuje **SPAYD** (Short Payment Descriptor — český národní
standard). Aplikace banky to umí přečíst (KB, FIO, Air Bank, Raiffeisen,
Revolut, Wise…).

Pro **EUR** (a další non-CZK měny) se generuje **SEPA EPC** (European Payments
Council) QR — funguje pro všechny EUR účty v EU.

QR obsahuje:

- Číslo účtu / IBAN
- Částku v měně faktury
- Variabilní symbol (jen CZK SPAYD; SEPA EPC ho používá jen v poznámce)
- Měnu
- Zprávu pro příjemce (varsymbol + jméno odběratele)
- Datum splatnosti (volitelné pole `DT`, jen CZK SPAYD)

Volba **Firma → Nastavení → Fakturace → Datum splatnosti v QR platbě →
Vystavené doklady** určuje, zda se do nově generovaného SPAYD kódu vloží skutečné
datum splatnosti dokladu. Ve výchozím stavu je vypnutá; po zapnutí se pole `DT`
do QR doplní. Nastavení platí stejně pro QR v PDF, e-mailu, upomínce a veřejném
náhledu. Formát SEPA EPC datum splatnosti nepodporuje, takže u jiných měn nemá
přepínač vliv.

Změna volby zneplatní uložené PDF nezaplacených CZK dokladů s QR, aby se při
příštím otevření vygenerovalo podle nové volby. Předchozí vystavená verze zůstává
dohledatelná v **Historii PDF**.

> ⚠️ QR se vygeneruje jen pokud bankovní účet projde **mod-11 kontrolou**
> (CZ účty) nebo **IBAN checksum** (EUR). Neplatný účet → QR se v PDF
> nezobrazí, zbytek faktury OK.

> 💡 **CZK vs SEPA QR pro koncepty (drafts):** CZK SPAYD vyžaduje variabilní
> symbol jako povinné pole — koncepty bez VS proto nemají QR. SEPA EPC VS
> jako identifikátor nepoužívá (jen volitelný text v poznámce), takže
> **EUR/SEPA koncepty mají QR i bez VS** — užitečné pro náhled klientovi
> před vystavením.

## 16.4 Odeslání e-mailem

### 16.4.1 Manuální odeslání

Tlačítko **Odeslat e-mailem** (na detailu faktury). E-mail jde na:

- `klient.hlavni_email`
- `+ zakazka.fakturacni_emaily[]` (až 3 dodatečné adresy)

Předmět + tělo e-mailu se vezme ze šablony `invoice_new` (CZ / EN podle jazyka
klienta) — viz [92. Nastavení](92_Nastaveni.md).

Po odeslání:

- Status faktury → `sent`
- V activity logu záznam `invoice.sent` s adresami příjemců

### 16.4.2 Hromadné odeslání

Z [Seznamu faktur](14_Faktury.md) vybereš více faktur a klikneš **Odeslat
klientovi (N)** — bulk action.

### 16.4.3 Odesílatel a Reply-To

Per dodavatel lze nastavit:

- **From: jméno** — co se zobrazí jako odesílatel (např. „Vzorová firma s.r.o.")
- **Reply-To** — kam má klient odpovědět (např. `fakturace@vzorova-firma.cz` ≠
  technická adresa, ze které jde SMTP)

Nastavuje se v **Systém → Dodavatelé → [tvůj dodavatel] → Editovat**.

### 16.4.4 Volitelné přílohy emailu

V detailu faktury (i u **konceptu**) je sekce **Přílohy emailu**, kam lze
nahrát další soubory, které se přibalí k PDF faktury při odeslání klientovi.
Typické použití: smlouva, cenová nabídka, fotodokumentace, předávací protokol.

- **Přidání** — drag-and-drop nebo tlačítko **Přidat přílohu** (multi-select).
- **Limity** — 10 MiB na soubor, 20 MiB celkem na fakturu.
- **Povolené formáty** — PDF, MS Office (DOC/DOCX, XLS/XLSX, PPT/PPTX),
  OpenDocument (ODT/ODS/ODP), TXT/CSV, obrázky (JPG/PNG/GIF/WEBP/HEIC/HEIF),
  ZIP. Kontroluje se reálný obsah souboru (ne jen přípona).
- **Odeslání** — přílohy se automaticky přibalí k mailu při akci **Odeslat
  e-mailem** i u **Test odeslání**.
- **Smazání** — křížek u řádku odstraní soubor i z disku.

> ⚠️ **Přílohy se NEpřibalují k upomínkám** ani k mailu schválení výkazu —
> jdou jen s běžným odesláním faktury / proformy / dobropisu. K internímu
> stornu nelze přílohy přidat (interní typ se klientovi neposílá).

> 💡 Přílohy přežijí editaci faktury i přečíslování — jsou navázané přes
> `invoice_id`. Smazání faktury (jen u konceptů) přílohy odstraní spolu s ní.

### 16.4.5 Elektronický podpis e-mailu (S/MIME)

Odchozí e-maily lze volitelně podepisovat S/MIME certifikátem. Nastavuje se v
**Systém -> Elektronické podpisy** per dodavatel a per typ e-mailového výstupu.
Podpis se aplikuje až na sestavený e-mail včetně příloh; příjemce ho ověří v
běžném e-mailovém klientovi.

Detail nastavení je v [kapitole 74. Elektronické podpisy](95_Elektronicke_podpisy.md).

## 16.5 Web faktura (trvalý veřejný odkaz)

Každá **vystavená** faktura (i proforma či dobropis) může mít trvalý veřejný
odkaz ve tvaru `https://vase-domena/invoice/{token}` — klient si na něm fakturu
**bez přihlášení** prohlédne v prohlížeči a stáhne PDF. Obdoba „web faktury"
z Fakturoidu.

- **Otevření** — v detailu faktury tlačítko **Web faktura**. První použití
  odkaz vytvoří (token se generuje lazy), další otevření vrací tentýž odkaz.
- **Kopírovat / Otevřít** — v modalu lze odkaz zkopírovat do schránky nebo
  rovnou otevřít v novém panelu.
- **E-mail klientovi** — odkaz se automaticky vkládá do e-mailu při akci
  **Odeslat e-mailem** (tlačítko „Zobrazit fakturu online" + textový odkaz).
- **Zobrazeno klientem** — první anonymní návštěva stránky se zapíše; v detailu
  faktury se pak ukazuje badge **👁 Zobrazeno klientem** (datum posledního
  zobrazení je v modalu Web faktury a v historii akcí, včetně stažení PDF).
  Náhled přihlášeného uživatele indikaci neovlivní.
- **Vygenerovat nový odkaz** — revokace: stávající URL okamžitě přestane
  platit (hodí se při úniku odkazu nesprávnému příjemci). Nový odkaz se pak
  posílá i v dalších e-mailech.

Veřejná stránka zobrazuje jen to, co je na PDF faktury: dodavatele, odběratele,
položky, součty s rozpadem DPH, platební údaje s QR kódem a poznámky z dokladu.
Klient si stáhne i **přílohy e-mailu** nahrané k faktuře (smlouva, výkaz…).
Stav úhrady se ukazuje živě (Uhrazeno / Částečně uhrazeno / Po splatnosti).
Koncepty veřejný odkaz nemají — stránka je dostupná až po vystavení dokladu.

> 🔒 Odkaz obsahuje 48znakový náhodný token — nelze ho uhodnout ani odvodit.
> Kdo odkaz má, fakturu vidí; pokud se dostal do nesprávných rukou, vygenerujte
> nový odkaz (starý tím zneplatníte).

## 16.6 Historie PDF

V detailu faktury je sekce **Historie PDF** — seznam všech archivovaných
verzí PDF, které tato faktura kdy měla:

| Stav v seznamu | Co znamená |
|---|---|
| **Odesláno** (zelený badge) | PDF v této verzi bylo skutečně odesláno klientovi e-mailem. Nikdy se neníčí — je to důkaz, co klient dostal. |
| **Vystavení** | PDF z okamžiku, kdy se draft povýšil na vystavenou fakturu (změna varsymbolu / snapshotů). |
| **Úprava faktury** | PDF z doby před tím, než někdo fakturu editoval (typicky admin force edit). |
| **Změna výkazu** | Výkaz víceprací se změnil → původní PDF s 2. stranou výkazu se odložilo. |
| **Změna bank. údajů** | V Číselníku → Měny se změnil bankovní účet → PDF konceptů (bez snapshotu) se invalidovala. |

Každý řádek má tlačítka **Zobrazit** (otevře v novém tabu) a **Stáhnout**.
U odeslaných verzí navíc vidíš **kam to šlo** (seznam příjemců).

> 🛈 **Proč to existuje:** vystavená faktura má snapshot dodavatele/klienta/
> banky, takže PDF nemůže být změněno tichou cestou. Ale když se faktura
> opraví přes admin force edit, původní verze by se ztratila — historie
> PDF zachová obě (původní + novou) a u odeslané varianty navíc eviduje,
> komu konkrétně šla.

> 💡 **Retence** — historie PDF se nemaže automaticky. Cron `cron-cleanup.sh`
> odeslané (`was_sent=1`) verze nemaže. Případnou individuální retenční politiku
> řeš až po ověřené záloze a nikdy neodstraňuj doklad o tom, co klient skutečně
> obdržel.

## 16.7 Admin akce nad vystavenou fakturou

Sekce **Další akce** v detailu faktury skrývá několik nástrojů, které jsou
přístupné jen adminovi a používají se v krajních případech.

### 16.7.1 Editace vystavené faktury (force=1)

V krajní nouzi (admin udělal v vystavené faktuře překlep, klient ji ještě
nedostal):

1. Z detailu faktury klikni **Upravit (admin)** — vyžaduje admin roli.
2. Otevře se editor s URL `?force=1`.
3. Změny se uloží + původní PDF se invaliduje + zaloguje se `invoice.force_updated`
   v activity logu.

> ⚠️ **Editace vystavené faktury obecně NENÍ doporučená.** Změny snapshotů
> mohou být rozpor s tím, co klient dostal e-mailem. Preferuj **storno + nová
> faktura** nebo **dobropis**.

> 🛈 **Var. symbol je immutable** — force-edit ho NEzmění. Pokud chceš číslo
> změnit, vystav storno/dobropis a fakturu znovu pod novým číslem.

### 16.7.2 Nezaplacené (vrátit ze stavu paid)

Tlačítko **Nezaplacené** je viditelné jen u faktur ve stavu `paid` (admin only).
Vrátí fakturu ze stavu zaplacené zpět do `sent` (pokud byla odeslaná) nebo
`issued`, vyčistí `paid_at` a přepočítá revenue stats.

Použití:

- Někdo omylem označil fakturu jako zaplacenou (špatný klik na „Označit jako
  zaplacené").
- Přišla ti vratka — peníze odešly zpět klientovi, takže faktura už není
  reálně zaplacená.

> ⚠️ **Pokud má faktura spárovanou bankovní transakci**, akce vrátí 409 chybu
> s návodem. V tom případě musíš nejdřív v detailu výpisu kliknout **Zrušit
> spárování** — ta cascade sama vrátí jak transakci, tak fakturu zpátky
> (faktura → `issued`, transakce → `unmatched`).

Activity log: `invoice.unmark_paid` s `previous_paid_at` pro forenzní stopu.

### 16.7.3 Smazání vystavené faktury (force-delete, admin)

Force-delete je 3. možnost ve **Storno / Dobropis** modalu (otevřeš tlačítkem
„Storno / Dobropis" v detailu vystavené faktury). Volby v modalu:

1. **Vystavit dobropis** (preferované) — vytvoří draft dobropisu se zápornými
   položkami, klient dostane oficiální opravu.
2. **Stornovat (interní)** — interní označení, klient nedostane nic.
3. **⚠ Smazat fakturu (admin, force-delete)** — admin only.

Třetí možnost **nenávratně odstraní účetní doklad** z databáze:

- Cached PDF se z disku smaže (`storage/invoices/sup-X/`).
- Archiv odeslaných verzí (PDF historie) se vymaže — fyzické soubory
  v `_archive/` i DB řádky.
- Uživatelské přílohy z `attachments/{invoiceId}/` se vymažou.
- Pokud má faktura **navazující storno nebo dobropis**, smažou se
  ZÁROVEŇ přes ON DELETE CASCADE (FK `parent_invoice_id`).
- Pokud byla spárovaná s bankovní transakcí, transakce zůstane jen ztratí
  pair (najdeš ji znovu v nespárovaných).
- Var. symbol se uvolní pro znovupoužití.
- Revenue / KPI dashboardu i u klienta/zakázky se přepočítají.
- Activity log: `invoice.force_deleted` s detaily (status, total, currency,
  cascade_deleted_ids, počet smazaných souborů).

Pokud faktura ani žádný navázaný doklad nebyly zaúčtovány, admin force-delete
provede smazání přímo. U zaúčtovaného dokladu zůstává aktivní retenční ochrana
účetních a daňových záznamů; v běžící retenční lhůtě proto použij storno nebo
dobropis.

Před skutečným smazáním systém ukáže **detailní per-status varování**
(jiné pro vystavenou / odeslanou / zaplacenou / stornovanou) s doporučenou
alternativou (storno / dobropis / Nezaplacené).

> ⚠️ **Force-delete vystavené faktury používej výjimečně.** Účetní doklad
> může být v evidenci u tvé účetní, klient ho má v emailu. Smazání u tebe
> nevymaže to, co má klient nebo účetní. Defaultní řešení je **vystavit
> dobropis** — účetně správné a nechá auditní stopu.

> 💡 **Typický legální use case:** vystavil jsi fakturu omylem (jiný klient,
> špatná částka) a klient ji ještě nedostal. Pokud už dostal, vystav dobropis.

## 16.8 Změna bankovního účtu po vystavení

Pokud změníš bankovní účet v **Systém → Číselníky → Měny**, automaticky se
**invalidují PDF všech faktur**, které renderují bank info live (drafty +
faktury bez snapshotu). Faktury v stavu `issued` a vyšším mají immutable
`bank_snapshot` — jejich PDF zůstává s **původními** údaji (správně, klient ji
už dostal).

V activity logu uvidíš `currency.updated` s počtem invalidovaných PDF.

## 16.9 Tipy

- **PDF náhled v iframe na detailu** se neobnoví automaticky po editaci —
  refreshni stránku (F5).
- **Test odeslání** je nejlepší způsob, jak ověřit, že máš správně SMTP
  + DKIM + e-mailovou šablonu, **bez rizika**, že to půjde klientovi.
- **Jeden e-mail s víc fakturami nelze poslat** — každá faktura jde
  v samostatném mailu. (Hromadné odeslání = N e-mailů, ne jeden.)
- **Po odeslání e-mailu nejde stáhnout PDF zpět** — pokud se klient zeptá, je
  to v jeho schránce.
