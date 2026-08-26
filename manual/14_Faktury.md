# 14. Faktury — seznam a hromadné akce

Faktury jsou srdce systému. Tato kapitola popisuje **seznam faktur** a **hromadné
akce**. Editaci jednotlivé faktury popisuje [15. Editor faktury](15_Faktura_editor.md),
PDF a odeslání e-mailem [16. Faktura PDF](16_Faktura_PDF.md).

## 14.1 Seznam faktur

V hlavním menu **Faktury**.

![Seznam faktur](img/08_faktury_list.webp)

Seznam je seskupený **po měsících vystavení** (sticky header s názvem měsíce).
V každé skupině jsou faktury seřazené podle data vystavení (nejnovější nahoře).

| Sloupec | Význam |
|---|---|
| ☐ | Checkbox pro hromadnou akci |
| Číslo | Variabilní symbol — např. `2605001` (formát YYMMNNN) |
| Typ | 🟦 Faktura / 🟨 Zálohová / 🟥 Dobropis / ⚫ Storno / 🧾 Daňový doklad k platbě |
| Klient | Jméno klienta (klikatelné) |
| Vystaveno | Datum vystavení |
| Splatnost | Datum splatnosti — červeně pokud po dni a faktura není zaplacená |
| Částka | Celková částka v měně faktury |
| Stav | Barevný badge — viz § 14.2 |
| Akce | PDF, Detail, … |

### 14.1.1 Filtry (vlevo)

| Filtr | Hodnoty |
|---|---|
| Stav | Koncept / Vystaveno / Odesláno / Po splatnosti / Upomínka / Zaplaceno / Storno / Dobropis |
| Typ | Faktura / Zálohová / Dobropis / Storno |
| Klient | Dropdown se všemi klienty |
| Zakázka | Závisí na vybraném klientovi |
| Měna | CZK / EUR / … |
| Období | Tento měsíc / minulý měsíc / tento rok / minulý rok / vlastní rozsah |
| Neuhrazené k datu | Datum — vypíše doklady vystavené do zvoleného dne, u kterých k tomu dni nebyla uhrazena celá částka |
| Kategorie tržby | Výběr několika kategorií najednou + přepínač **Zobrazit jen vybrané / Skrýt vybrané** — viz níže |
| Zaúčtování | Vše / Zaúčtováno / Nezaúčtováno — jen podvojné účetnictví, viz [§ 16.1.3](16_Faktura_PDF.md#1613-zauctovani-do-deniku) |
| Místo plnění (OSS) | Vše / Nejisté místo plnění (OSS) / Nejisté — v OSS podání / Nejisté — v tuzemsku. Vypíše doklady s řádkem, u kterého si systém není jistý místem plnění — viz [§ 35.5](35_Fakturujeme.md#355-zahranicni-fakturace-eu-oss-a-treti-zeme). Filtr je vidět, i když OSS zapnuté nemáš. |
| Hledat | Volný text — varsymbol, popis položky, jméno klienta |

Filtr **Zaúčtování** jde do URL (sdílitelný odkaz) a do [uložených
filtrů](92_Nastaveni.md#929-ulozene-filtry-a-predvolby-zobrazeni); promítne se
i do CSV exportu (řádky, ale ne samostatný sloupec — export neobsahuje
příznak zaúčtování).

Filtr **Neuhrazené k datu** je jiná otázka než checkbox „Nezaplacené" — ten se
dívá na **dnešní** stav dokladu, kdežto „Neuhrazené k datu" na stav **k
historickému dni** (např. „kdo mi k 30. 6. dlužil"). Doklad zaplacený až po
tomto dni se proto ve výpisu objeví (k danému dni ještě nebyl uhrazen), i
když má dnes stav „Zaplaceno". Používá stejnou definici úhrady jako
[Saldokonto](53_Saldokonto.md), takže si obě sestavy neodporují.

#### Kategorie tržby

Filtr bere **několik kategorií najednou** a přepínačem vedle něj rozhodneš, co s nimi:

| Režim | Co vypíše |
|---|---|
| **Zobrazit jen vybrané** | Pouze doklady s některou z vybraných kategorií |
| **Skrýt vybrané** | Všechno ostatní — typicky „ukaž mi velké faktury bez drobných za předplatné" |

V nabídce je i volba **Bez kategorie** pro doklady, které kategorii vyplněnou nemají.
Ta je důležitá u režimu **Skrýt vybrané**: doklady bez kategorie zůstávají ve výpisu,
dokud mezi skrytými nezaškrtneš právě **Bez kategorie**. V seznamu jsou i archivované
kategorie — visí na starých fakturách, takže bez nich by je nešlo dohledat ani skrýt.

Filtr se zapisuje do URL i do [uložených
filtrů](92_Nastaveni.md#929-ulozene-filtry-a-predvolby-zobrazeni) včetně režimu, takže
si pohled „prodej bez předplatného" uložíš a příště vyvoláš jedním klikem.

#### Nejisté místo plnění (OSS)

Sporné řádky končí na **dvou různých místech** a každé se řeší jinou otázkou. Proto má
filtr tři hodnoty, ne zaškrtávátko:

| Volba | Co vypíše |
|---|---|
| **Nejisté místo plnění (OSS)** | Obojí najednou — rozliš je podle štítku u varsymbolu |
| **Nejisté — v OSS podání** | Řádek se do OSS zařadil, ale s otazníkem |
| **Nejisté — v tuzemsku** | Řádek zůstal mimo OSS a vstupuje do přiznání k DPH na ř. 1 a 2 |

U dokladu v seznamu je vidět, který otazník nese: štítek **OSS ?** u varsymbolu značí
řádek v OSS podání, štítek **ČR ?** řádek v tuzemsku. Doklad rozpadlý mezi obojí nese
oba štítky.

Jak oba stavy vznikají a co s každým z nich dělat, popisuje
[§ 40.4](40_OSS.md#405-plneni-k-rucnimu-posouzeni).

Filtr se zapisuje do URL i do uložených filtrů. Souhrnná volba **Nejisté místo
plnění (OSS)** zobrazí oba dílčí stavy najednou, takže se žádný nejistý doklad
neschová.

## 14.2 Stavy faktur

| Stav | Význam | Co lze udělat |
|---|---|---|
| 📝 **Koncept** (`draft`) | Rozpracovaná, neviditelná pro klienta | Editovat, smazat, vystavit |
| ✅ **Vystaveno** (`issued`) | Číslo přiděleno, immutable PDF, ale klientovi nešla | Odeslat e-mailem, zaplatit, upomínka, dobropis, storno |
| 📧 **Odesláno** (`sent`) | E-mail s PDF odešel klientovi | Zaplatit, upomínka |
| ⏰ **Upomínka** (`reminded`) | Upomínkový e-mail odešel | Zaplatit, další upomínka (s cooldownem), dobropis |
| 💰 **Zaplaceno** (`paid`) | Platba přišla a byla spárována | (terminální) |
| 🟠 **Částečně uhrazeno** | Přišla jen část peněz (evidence plateb) — zbytek je dál pohledávka | Doplatit, částečná úhrada, upomínka |
| 🟣 **Přeplaceno** | Evidované platby převyšují částku k úhradě | (řeší se ručně — vratka / dobropis) |
| ⚫ **Storno** (`cancellation`) | Interní storno — faktura ztratila platnost | (terminální) |
| 🔄 **Dobropis** (`credit_note`) | Vytvořen opravný daňový doklad | (terminální) |

> 💡 **Edituj jen koncepty.** Vystavená faktura má immutable snapshot dodavatele,
> klienta a banky — pro změnu je třeba storno + nová faktura, nebo dobropis.
> Admin má v krajní nouzi možnost editace s `?force=1` (s audit logem).

## 14.3 Hromadné akce

Zaškrtni více faktur (checkbox). Nahoře se objeví lišta s akcemi:

| Akce | Funkce | Aplikuje se na |
|---|---|---|
| **Vystavit znovu (N)** | Vytvoří klony jako nové koncepty s auto-inkrementem měsíce v popiscích položek (`3/2026 → 4/2026`) | Faktury libovolného stavu |
| **Odeslat klientovi (N)** | Hromadně odešle e-mail s PDF přílohou | Vystavené, neodeslané (`issued`) |
| **Označit zaplacené (N)** | Manuálně označí jako zaplacené dnešním datem | Vystavené / odeslané / upomínkované |
| **Upomínka (N)** | Pošle upomínkový e-mail | Po splatnosti, ne zaplacené, cooldown 14 dní mezi upomínkami |
| **Stáhnout PDF ZIP** | ZIP archiv všech vybraných PDF | Vystavené (status ≥ `issued`) |
| **PDF export (N)** | Sloučí PDF vybraných vystavených dokladů do jednoho souboru; volitelně jej elektronicky podepíše nastaveným profilem | Vystavené faktury a dobropisy, maximálně 200 dokladů |
| **Stáhnout ISDOC ZIP** | ISDOC 6.0.2 XML pro každou + ZIP | Vystavené |
| **Stáhnout Pohoda XML** | Sloučený dataPack pro import do Pohody | Vystavené |
| **Zaúčtovat (N)** | Zaúčtuje vybrané do deníku, jednu po druhé (chyba jedné neblokuje ostatní); na konci souhrn ok/chyby. Max 500 dokladů na dávku. | Vystavené a dosud nezaúčtované — jen podvojné účetnictví, viz [§ 16.1.3](16_Faktura_PDF.md#1613-zauctovani-do-deniku) |
| **Nastavit OSS (N)** | Hromadně nastaví režim OSS, zemi spotřeby, typ sazby a typ plnění na položkách. Náhled je povinný. Max 200 dokladů na dávku — viz [§ 14.3.2](#1432-hromadne-nastaveni-oss) | Doklady, které nejsou stornované, zamčené ani v podaném období |

> ⚠️ **Vystavit znovu** vždy vytvoří **nové koncepty** — nepřevede automaticky
> klony do `issued`. Tím tě chrání před omylem; po klonování si v každé nové
> projdi a klikni „Vystavit" ručně.

Sloučený PDF export obsahuje pouze samotné faktury v pořadí výběru. Nepřidává
uživatelské přílohy, ISDOC soubory ani výkazy víceprací jako samostatné
dokumenty. Pro archiv jednotlivých souborů použij **Stáhnout PDF ZIP**.

### 14.3.1 Workflow měsíční retainer

Typický měsíc:

1. **1. den měsíce** — otevřu Faktury, filtr „Minulý měsíc", označím všechny
   retainerové faktury, klik **Vystavit znovu (N)**.
2. **Dostanu N konceptů** s popisy automaticky inkrementovanými (`Konzultace
   3/2026 → Konzultace 4/2026`).
3. **Projdu, případně upravím** položky (přidám hodiny navíc, slevu, …).
4. **Označím všechny → Vystavit** (hromadná akce — vznikne číselná řada,
   PDF se vygeneruje).
5. **Označím všechny → Odeslat klientovi**.
6. **Hotovo** za 5 minut.

### 14.3.2 Hromadné nastavení OSS

Po migraci nebo po importu zůstanou desítky až stovky řádků, u kterých je potřeba
doplnit nebo opravit údaje k [režimu OSS](40_OSS.md). Proklikat je po jednom není
reálné, proto má seznam faktur hromadnou akci **Nastavit OSS (N)**.

Nejdřív si vyber doklady (typicky přes filtr **Místo plnění (OSS)** —
[§ 14.1.1](#1411-filtry-vlevo)), pak v dialogu nastav:

| Pole | Význam |
|---|---|
| **Které položky** | Jen řádky k ručnímu posouzení / jen OSS řádky bez typu sazby / všechny OSS řádky / všechny položky dokladu |
| **Režim OSS** | Zapnout OSS / Vypnout OSS (plnění je tuzemské) / Ponechat beze změny |
| **Země spotřeby** | Členský stát, do kterého plnění patří |
| **Typ sazby** | Základní / Snížená / Druhá snížená / Parkovací |
| **Typ plnění** | Zboží / Služby |
| **Označit řádky jako posouzené** | Zhasne příznak „místo plnění k ručnímu posouzení" |

Každé pole má volbu **— ponechat —**, takže lze nastavit třeba jen typ plnění
a všeho ostatního se nedotknout.

**Náhled je povinný.** Tlačítko **Zobrazit náhled** vypíše, kolik dokladů a položek
se změní, u každého dokladu konkrétní změnu z původní hodnoty na novou, případná
varování ze sazebníku a doklady, které se přeskočí i s důvodem. Teprve pak jde
kliknout **Provést změnu**. Na dávku je limit 200 dokladů.

**Akce nemá „provést i tak."** Příznak OSS rozhoduje, jestli řádek jde do českého
přiznání, nebo do OSS podání, takže doklad, který je stornovaný, uzamčený, pod
retenčním holdem, v podaném období nebo mimo platnost registrace, se celý přeskočí
s uvedeným důvodem. Stejně přísně je hlídané **vypnutí OSS** jako jeho zapnutí —
zhasnutí příznaku přesouvá daň na ř. 1 českého přiznání, takže projde jen tam, kde
číselník sazeb států OSS sazbu v zemi dodavatele potvrdí.

Úplný seznam důvodů přeskočení, pravidla pro vypnutí OSS a chování dávky při chybě
popisuje [§ 40.5](40_OSS.md#406-hromadna-editace-oss).

## 14.4 Ikony stavu (legenda)

V horní liště nad seznamem jsou ikony — klik přepne filtr na daný stav:

- 🟢 počet zaplacených tento měsíc
- 🟣 počet odeslaných (čekajících na platbu)
- 🟡 počet vystavených (neodeslaných)
- 🔴 počet po splatnosti
- 🟠 počet upomínkovaných

## 14.5 Vyhledávání

Pole **Hledat** vlevo nahoře. Hledá v:

- Variabilním symbolu (přesná shoda i prefix)
- Popisu položek (LIKE)
- Jménu klienta
- Čísle projektu / smlouvy

Funguje fulltext česky i anglicky.

## 14.6 Tipy

- **Nepoužívej hromadné odesílání bez review** — pokud máš v koncepcích
  drobné chyby (špatná částka, chybějící popis), pošlou se klientovi všechny
  najednou.
- **„Označit zaplacené" je manuální fallback** — primárně se faktury označují
  zaplacenými automaticky při importu bankovního výpisu (viz [28. Banka](28_Banka.md)),
  u hotovosti pak volbou způsobu úhrady **Hotově** s pokladnou přímo v editoru
  ([§ 15.2.7](15_Faktura_editor.md#1527-zpusob-uhrady-a-platba-hotove)).
  Částečné platby a evidenci úhrad popisuje [§ 16.1.2](16_Faktura_PDF.md).
- **Filtr „Po splatnosti"** je nejrychlejší způsob, jak zjistit, kdo dluží —
  klik na řádek a hned máš tlačítko **Upomínka**.
- **Klik na číslo faktury** otevře [Detail faktury](16_Faktura_PDF.md).
- **Klik na ikonu PDF** stáhne přímo PDF (bez otvírání detailu).
