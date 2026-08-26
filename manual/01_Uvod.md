# 1. Úvod — co MyÚčto.cz umí

MyÚčto.cz je **kompletní moderní účetní systém**, který spojuje každodenní práci
s doklady, bankou a platbami s podvojným účetnictvím, daňovou evidencí, daňovými
výkazy, kontrolami a uzávěrkou. Jednotlivé moduly nejsou izolované ostrůvky:
údaj jednou pořízený na dokladu pokračuje celým procesem až do účetního deníku,
DPH, daně z příjmů, manažerského reportingu a závěrkových podkladů.

Cílem je **automatizovat maximum opakovaných operací a současně zachovat účetní
kontrolu**. Přijatý doklad lze vytěžit pomocí AI, zkontrolovat jeho součty a
daňové údaje, připravit k úhradě, spárovat s bankou a podle nastavených pravidel
zaúčtovat. Jednoznačné a bezpečně ověřitelné operace umí systém zpracovat sám;
nejasné případy, výjimky a AI návrhy předloží člověku k rozhodnutí. Automatizace
tak nezakrývá původ čísel ani účetní úsudek — každý důležitý krok zůstává
dohledatelný včetně zdroje, uživatele a času.

MyÚčto.cz průběžně hlídá vazby, které se v běžném provozu snadno rozcházejí:
kontroluje doklady proti platbám a účetnímu deníku, návaznost DPH na kontrolní a
souhrnné hlášení, rovnováhu účetních zápisů, saldokonto, uzamčená období i
úplnost podkladů před uzávěrkou. Měsíční a roční kontrolní postupy pomáhají
odhalit chybu dříve, než se promítne do podání nebo účetní závěrky.

Systém počítá také s **online spoluprací účetní a klienta**. Klientský portál
zpřístupní klientovi jeho doklady, požadavky a aktuální reporting, aniž by mu
otevřel účetní administraci. Responzivní rozhraní umožňuje klientovi i účetní
vyřídit běžnou práci z telefonu, tabletu i počítače — od předání dokladu a
kontroly stavu po schválení či dohledání potřebné informace.

> **Váš systém, vaše data, vaše značka.** MyÚčto.cz je nástupcem MIT projektu
> MyInvoice a všechny jeho funkce zůstávají v MyÚčto navždy zdarma.
> Podvojné účetnictví, účetní nástroje a uzávěrky, sklad a e-shop, majetek,
> EPO a rozšířené opravy DPH tvoří komerční nadstavbu. Systém může běžet na
> vlastní infrastruktuře, napojit se přes
> API na další systémy a v rozsahu sjednané licence se přizpůsobit interním
> procesům, integracím i vizuální identitě.
> Účetní kancelář si z něj může vytvořit vlastní klientské řešení; skupina firem
> nebo větší organizace jej může začlenit do svého informačního prostředí bez
> závislosti na uzavřeném dodavatelském cloudu.

Aplikace běží na vlastním serveru nebo v Dockeru — bez cizího aplikačního
backendu a bez telemetrie. Data i PDF doklady zůstávají pod vaší správou a
multi-arch Docker image usnadňuje nasazení na běžnou serverovou infrastrukturu.

Technologický základ tvoří PHP 8.5, Vue 3 a MariaDB 11.8. Systém lze nasadit na
vlastní server, VPS i do kontejneru. Konfigurace je soustředěná v `cfg.php` a
databázové schéma se bezpečně aktualizuje skriptem `migrate.php`; modulární
architektura usnadňuje dlouhodobý provoz, integrace i licencované úpravy.

Tato kapitola je **rozcestník** — u každé oblasti najdeš odkaz na kapitolu, kde
je popsaná do detailu. Pokud systém teprve nasazuješ, začni
[instalací](02_Instalace_Quickstart.md); pokud ho přebíráš hotový, stačí
[první spuštění](07_Setup_wizard.md).

## 1.1 Dvě rozhraní — účetní a klient

MyÚčto počítá s tím, že nad jedněmi daty pracují dva různí lidé s velmi
odlišnými potřebami. Nedostanou proto tutéž obrazovku v jiném rozsahu, ale dvě
samostatná rozhraní:

- **Přehled firem** pro účetní kancelář — všechny účetní jednotky v jednom
  seznamu seřazeném podle naléhavosti termínů, s počty nezaúčtovaných dokladů,
  nespárovaných plateb a datem posledního importu banky. Kliknutím přepneš
  aktivní firmu a systém tě přenese rovnou do odfiltrované agendy.
  Viz [10. Přehled](10_Prehled.md).
- **Klientský portál** — výrazně užší rozhraní, ve kterém si klient sám vystaví
  fakturu, nahraje přijatý doklad a vidí, jak na tom firma finančně je. K účetní
  administraci se nedostane ani úpravou URL: oprávnění se vyhodnocují zvlášť ve
  frontendu i v API. Viz [9. Klientský portál](09_Klientsky_portal.md).
- **Zaúčtované doklady klient nerozbije** — co už prošlo do deníku, má v portálu
  uzamčené a edituje se u zdroje.

## 1.2 Vystavování dokladů

Aplikace pokrývá celý český cyklus daňových dokladů — od proforma faktury, přes
ostrou fakturu, po dobropis. Každý doklad má **immutable PDF**: jakmile fakturu
vystavíš, PDF se vygeneruje a od té chvíle se nemění, i kdybys později měnil
adresu, banku nebo logo v Nastavení.

- **Faktura — daňový doklad** (pro plátce DPH) i **faktura** (pro neplátce)
- **Zálohová faktura (proforma)** s možností konverze na ostrou
- **Opravný daňový doklad (dobropis)** s vazbou na původní fakturu
- **Interní storno** (úplné zrušení vystavené faktury)
- **Klonování faktury** s automatickým inkrementem měsíce v popisech
  (`3/2026 → 4/2026`) — typický workflow pro pravidelnou měsíční fakturaci
- **Hromadné akce** nad vybranými fakturami — vystavit znovu (N), odeslat
  klientovi (N), upomínka (N), označit jako zaplacené (N)
- **Číselné řady** s nastavitelným formátem variabilního symbolu (`YYMM###`,
  `YY####`, vlastní šablony)
- **Multi-currency** — CZK, EUR, USD a další; per dodavatel může být
  více bankovních účtů v různých měnách
- **Activity log** u každé faktury — kdo a kdy ji vytvořil, vystavil, odeslal
  klientovi, dostal zaplacenou

Detaily v kapitolách [14. Faktury](14_Faktury.md) a
[15. Editor faktury](15_Faktura_editor.md); pravidelná fakturace má vlastní
kapitolu [17. Pravidelné faktury](17_Pravidelne_fakturace.md).

## 1.3 Daňový průvodce — plátce, neplátce, RC, OSS

MyÚčto umí **fakturaci podle českého ZDPH** — přepíná chování formuláře
podle toho, jestli jsi plátce nebo neplátce, a podporuje speciální režimy:

- **Plátce / neplátce DPH** — globální přepínač u dodavatele; ovlivňuje
  záhlaví dokladu, sloupce v tabulce, sumace i povinné poznámky
- **Sazby DPH** v číselníku (`CZ-21`, `CZ-12`, `CZ-0`, `CZ-RC`) —
  přiřazují se per položku, smíšené sazby v jedné faktuře
- **Reverse charge (přenesená daňová povinnost)** — tuzemský RC dle § 92a–g
  i EU B2B s VAT ID; aplikace automaticky doplní zákonnou poznámku
- **[OSS (One Stop Shop)](40_OSS.md)** pro prodej spotřebitelům v jiných členských
  státech EU s lokálními sazbami (např. `SK-23`) — zařazení řádků se odvozuje
  automaticky, kvartální přiznání a XML `OSSEI1` jsou součástí
- **VIES ověření** EU VAT ID — kontrola platnosti DIČ klienta v reálném čase
- **Auto-výpočet DPH** s rozpadem po sazbách v sumační tabulce
- **VAT klasifikace se přiřadí sama** podle sazby DPH a nese se až do
  kontrolního hlášení

Detaily jsou v kapitole [35. Fakturujeme](35_Fakturujeme.md). Pozor:
**správnost faktury je vždy na uživateli** — aplikace generuje doklady,
ale není daňový poradce.

## 1.4 Klienti, zakázky a schvalování výkazů

- **Klienti** s lookupem v **ARES** (zadáš IČO, doplní se název, adresa, DIČ,
  právní forma) a **VIES** (ověření EU VAT ID)
- **Zakázky** 1:N pod klientem — typicky jeden zákazník má víc projektů
  fakturovaných nezávisle; doklad se váže na zakázku, takže sedí i reporting
  ziskovosti
- **Fakturační e-maily** na úrovni zakázky (jiný kontakt na účetní oddělení
  než na project manažera)
- **Kontaktní šablony** — předvyplněné dodací podmínky, splatnost, sazba,
  popisky položek per zakázka
- **Výkaz víceprací (timesheet)** — druhá strana PDF s tabulkou (datum, popis,
  hodiny, sazba, suma). Suma se přenese do položky faktury, takže se hodiny
  neevidují dvakrát; odeslaný výkaz se archivuje jako snapshot.
- **Schvalování zákazníkem** — volitelné per zakázka. Před vystavením
  faktury pošleš zákazníkovi e-mail s odkazem na veřejnou stránku (chráněno
  jednorázovým tokenem + CAPTCHA), **bez zakládání účtu**. Po schválení se
  faktura **automaticky vystaví a odešle**; schválení i jeho čas zůstávají
  u dokladu.

Viz [18. Klienti](18_Klienti.md) a [19. Zakázky](19_Zakazky.md).

## 1.5 PDF, QR platba, e-mail

- **PDF s QR platbou** — **SPAYD** pro CZK (nascannuje libovolná česká
  bankovní aplikace), **SEPA EPC** pro EUR (evropský standard)
- **Vzhled PDF** — logo dodavatele, hlavička, footer, barevné schéma; CSS
  šablona v `mPDF` (lze upravit)
- **E-mail s PDF přílohou** přes vlastní **SMTP** (Postfix, SendGrid,
  Mailgun, Amazon SES, Gmail SMTP — cokoli s autentizací)
- **DKIM podpis** odchozích e-mailů — vyšší doručitelnost, méně spam-složek
- **Šablony e-mailů** v Nastavení — předmět + tělo s placeholdery
  (`{varsymbol}`, `{amount}`, `{due_date}`); vícejazyčné
- **Test odeslání** — pošle vzorový e-mail jen na tvůj e-mail (ne klientovi),
  pro vyzkoušení šablony i SMTP konfigurace

Viz [16. Faktura PDF a e-mail](16_Faktura_PDF.md).

## 1.6 Přijaté doklady a AI extrakce

Přijatou fakturu nemusíš opisovat. AI ji přečte z PDF — dodavatele, částky,
sazby DPH i jednotlivé položky — a předloží ti výsledek ke kontrole:

- **Extrakce z PDF i z obrázku**, včetně vícestránkových dokladů
- **Sledovaná e-mailová schránka** — doklad dorazí mailem a systém ho vytěží sám
- **Poskytovatele AI si volíš ty**, zvlášť pro každou firmu: **Anthropic Claude**
  (výchozí), **Azure OpenAI**, **OpenAI** nebo **Google Gemini**
- **Bez potvrzené zpracovatelské smlouvy se AI vůbec nespustí** — volání se
  tvrdě zablokuje. Souhlas je per poskytovatel, ne plošný.
- **Kontrolní součet nad extrakcí** — pokud se součet položek rozejde se čtenou
  základnou o víc než **2 %**, doklad se označí „ke kontrole" místo tichého
  uložení
- **Vypínač** pro okamžité zastavení AI pro celou firmu, se záznamem do logu
- **Návrhy nelze schválit hromadně** — každý doklad potvrzuje člověk

Detaily v kapitolách [23. Přijaté faktury](23_Prijate_faktury.md) a
[25. AI extrakce](25_AI_extrakce.md).

> **AI v MyÚčtu nikdy neúčtuje sama.** Je to vědomé rozhodnutí, ne technické
> omezení. AI pouze navrhuje; účtuje oddělený deterministický engine s pravidly,
> která si nastavíš (viz § 1.11). Za účetnictví ručíš ty.

## 1.7 Banka, pokladna a platební příkazy

Místo ručního označování faktur jako zaplacených naimportuj výpis
a aplikace platby spáruje sama:

- **Import výpisů** ve formátu **GPC/ABO i CSV** — KB, FIO, ČSOB, Raiffeisen,
  ČS, mBank a další
- **Automatický import** plánovanou úlohou každé ráno
- **Hash kontrola** (SHA-256) — duplicitní upload výpisu se odmítne
- **Validace bankovního účtu** v hlavičce výpisu proti účtům dodavatele
- **Chytré párování** podle variabilního symbolu (s normalizací zápisu), částky,
  protistrany a historie; tolerance ± 0,01 Kč
- **Manuální párování** nedotažených transakcí (chybný VS, částečná platba) —
  nespárované skončí v jasném seznamu k dořešení, ne v tichosti
- **E-mailová avíza** — příchozí platby se rekonciliují proti výpisu
- **Platební příkazy** a párování záloh s doklady
- **Pokladna** s příjmovými a výdajovými doklady (PPD/VPD) a pravidly
- **Multi-currency banky** — víc účtů per dodavatel (CZK + EUR + USD)

Viz [28. Banka](28_Banka.md), [29. Bankovní účty a avíza](29_Bankovni_ucty.md),
[30. Pokladna](30_Pokladna.md) a [26. Platební příkazy](26_Platebni_prikazy.md).

## 1.8 Upomínky a chybějící doklady

- **Manuální tlačítko** „Poslat upomínku" v detailu faktury
- **Hromadná akce** „Upomenout vybrané" v seznamu
- **Cron** — denní automatické upomínky podle pravidel (X dní po splatnosti)
- **Cooldown** — žádná druhá upomínka dřív než za 14 dní (anti-spam)
- **Šablony** — jiné znění pro 1., 2., 3. upomínku
- **Žádost o chybějící doklad** — účetní označí, co chybí, a systém klientovi
  sám připomíná, dokud se doklad neobjeví

Viz [22. Upomínky](22_Upominky.md).

## 1.9 Sklad a e-shop

Firma, která prodává zboží, nepotřebuje druhý systém. Skladový pohyb a účetní
zápis vznikají ze stejné události, takže se ta dvě čísla nemají jak rozejít:

- **Karty pro materiál, zboží i výrobky**, příjemky a výdejky s vlastním
  životním cyklem a číslováním
- **Automatický výdej při vystavení faktury** a **naskladnění z přijaté faktury**
- **Vedlejší pořizovací náklady** — doprava a clo se rozpustí do ceny zásoby
- **Oceňování klouzavým průměrem**, dohledatelné ve skladové knize karty
- **Více skladů** a hlídání minimálních zásob
- **Inventury** s rozdílovými doklady a zaúčtováním manka či přebytku
- **Ocenění skladu k uzávěrce** — vstupuje do závěrkových sestav
- **Katalog zboží** — kategorie ve stromu, atributy a parametry, vícejazyčné
  popisky, výrobci, tagy, cenotvorba a marže, hromadný import, archivace místo
  mazání; skladová karta a karta zboží jsou provázané

Viz [33. Sklad](33_Sklad.md) a [34. E-shop](34_Eshop.md).

## 1.10 Účetnictví — deník, hlavní kniha, sestavy

**Podvojné účetnictví i daňová evidence v jedné instalaci** — každá firma si
vede tu formu, která jí přísluší:

- **Účetní deník** s prolinkováním na zdrojový doklad **v obou směrech** —
  každý řádek deníku je prokliknutelný na doklad a zpět
- **Hlavní kniha** — obraty a zůstatky po účtech s rozpadem na zápisy
- **Obratová předvaha, Rozvaha, Výsledovka**
- **Saldokonto** — otevřené položky odběratelů i dodavatelů
- **Účtový rozvrh a předkontace** upravitelné pro každou firmu
- **Náhled dokladu** přímo z deníku
- **Storno místo mazání** — auditní stopa zůstává

Viz [43. Průvodce účetního](43_Pruvodce_ucetniho.md),
[45. Účetní deník](45_Ucetni_denik.md),
[účtový rozvrh](62_Ucetni_osnova.md),
[hlavní kniha](48_Hlavni_kniha.md),
[rozvaha](50_Rozvaha.md),
[výkaz zisku a ztráty](51_Vysledovka_druhova.md) a
[daňová evidence](71_Danova_evidence.md).

## 1.11 Automat účtování

Opakovanou práci odvede systém, ty ji potvrdíš:

- **Pravidla účtování** — z dokladu rovnou správná kontace
- **Pravidla nákladů** pro opakované dodavatele a typy plnění
- **Šablony banky** a doporučené účetní šablony rovnou v systému (dohadné
  položky aktivní i pasivní, kurzové rozdíly k rozvahovému dni, čerpání rezerv,
  mzdová rekapitulace)
- **Fronta „K doúčtování"** — nic nepropadne, ale nic se ani nezaúčtuje naslepo
- **Učení z tvých oprav** — opakovaný vzorec systém nabídne povýšit na pravidlo
- **Tři režimy: vypnuto / jen návrhy / plná automatizace**, zvlášť pro každý typ
  operace
- **Pojistky** — limit částky na pravidlo, denní objemový strop, účtování jen
  v otevřeném období a jen při jednoznačné kontaci, ochrana proti duplicitám
  a rozpadu saldokonta
- **Ranní souhrn e-mailem** a u každého zápisu dohledatelné, co ho způsobilo

Viz [46. Automat účtování](46_Automat.md).

## 1.12 Účetní kontroly a inventarizace

Chyby najdeš ty, ne finanční úřad. Kontroly neukazují jen hlášku, že něco
nesedí — ukážou konkrétní doklad:

- **Úplnost dokladů** — chybí něco v číselné řadě?
- **Měsíční kontrola** a měsíční přehled před podáním
- **Inventarizace účtů** a **saldokonto**
- **Zápočty** vzájemných pohledávek a závazků
- **Audit kurzů (ČNB)**
- **Kontrola integrity deníku** na pozadí — hlídá, že strana MD odpovídá straně D

Viz [60. Účetní kontroly a inventarizace](60_Ucetni_kontroly_a_inventarizace.md).

## 1.13 DPH, kontrolní a souhrnné hlášení

- **Přiznání k DPH, kontrolní i souhrnné hlášení** včetně **XML pro EPO**
- **Kniha DPH** s dohledáním každé částky až k dokladu
- **Odpočet ke dni** a upozornění na **časový posun odpočtu podle § 73 ZDPH**,
  s uvedením dokladu, který rozdíl způsobil
- **Oprava odpočtu podle § 74b** u nedobytných pohledávek
- **Predikce z konceptů** — do odhadu daňové povinnosti vstupují i rozpracované
  a plánované faktury, skutečnost a odhad ale zůstávají oddělené
- **Vývoj DPH za dvanáct měsíců** na jedné obrazovce

Viz [36. Výkazy DPH](36_Vykazy_DPH.md), [37. Kniha DPH](37_Kniha_DPH.md) a
[39. Souhrnné hlášení](39_Souhrnne_hlaseni.md).

## 1.14 Daň z příjmů — průběžně, ne až v březnu

Daň z příjmů se počítá z účetních dat průběžně, takže na otázku „kolik letos
zaplatíme" existuje odpověď v systému:

- **Projekce z účetních dat** — výsledek hospodaření, nedaňové náklady, rozdíl
  účetních a daňových odpisů. Poctivě označené jako projekce, ne jako přiznání.
- **U každého řádku je vidět zdroj** — rozklik až na zápis v deníku
- **Panel uzávěrkových návrhů** — dohadné položky, časové rozlišení, rezervy,
  kurzové rozdíly
- **DPFO i DPPO**, řádné, opravné i dodatečné přiznání, hospodářský rok,
  s XML pro EPO
- **Zálohy podle § 38a** se z finalizovaného přiznání vygenerují na příští rok
  samy, včetně rozhodnutí finančního úřadu
- **Přehledy pro ČSSZ a zdravotní pojišťovny**
- **EPO podání, archív a daňová rekonciliace** — asistované otevření formuláře,
  důkazní dokumenty a porovnání toho, co bylo podáno, s účetnictvím
- **Daňový optimalizátor** — porovnání režimů a predikce ročních limitů

Díky tomu se dá daňová optimalizace řešit v říjnu, ne v březnu, kdy už je pozdě.
Viz [38. Daň z příjmů](38_Dan_z_prijmu.md),
[70. EPO podání, archív a rekonciliace](70_Archiv_podani_a_rekonciliace.md) a
[41. Daňový optimalizátor](41_Danovy_optimalizator.md).

**Daňové výstupy jsou pomůcka** — před podáním je vždy ověř s účetní nebo
daňovým poradcem a samotné odeslání na portál či do datové schránky necháváme
na tobě.

## 1.15 Uzávěrka

Uzávěrka je průvodce o **deseti krocích** v pevném pořadí: kontroly → odpisy →
kurzové rozdíly → dohadné položky → časové rozlišení → opravné položky → daň
z příjmů → zásoby → uzavření knih → otevření nového roku.

Prvním krokem je sada **předběžných kontrol** se závažností — **chyba** zavření
knih zablokuje, **varování** projde, ale zůstane zaznamenané. Kontroluje se
mimo jiné:

- **Technické účty** — peníze na cestě `261`, vnitřní zúčtování `395`,
  nedokončené pořízení `041/042` a `111/131`, dohadné `388/389`, časové
  rozlišení `381–385`
- **Inventarizace podle § 29–30 ZoÚ** — bez dokončené inventarizace knihy
  nezavřeš
- **Spárované platby, které nesedí** — jiná částka, měna nebo protistrana
- **Zaplacené faktury s otevřeným saldem** na `311` a `321`
- **Saldo účtu `343`** proti podanému přiznání k DPH
- **Účty se zůstatkem na neobvyklé straně**
- **Kurz na dokladu proti dennímu kurzu ČNB** a úplnost měnové stopy
- **Majetek bez zaúčtovaných odpisů či oprávek**, drobný majetek nesedící na
  obrat `501`
- **Rozdělení výsledku hospodaření** z účtu `431` na `428/421/364`

Na konci vznikne **závěrkový balíček** a nový rok se otevře automaticky včetně
řad dokladů. Viz [68. Účetní období a uzávěrka](68_Uzaverka.md).

## 1.16 Majetek, mzdy, kniha jízd a dokumenty

Agendy, kvůli kterým účetní v jednodušších systémech vede paralelní tabulky:

- **Majetek a odpisy** — daňové i účetní, s automatickým zaúčtováním
- **Drobný majetek** a jeho životní cyklus
- **Mzdy** — plnohodnotný mzdový modul: osobní karty a pracovní vztahy,
  docházka, absence a dovolená, mzdové složky, řízený mzdový běh, srážky
  a exekuce, výplatní pásky a mzdový list, platby odvodů, účetní můstek
  a příprava zákonných hlášení; JMHZ umí po výslovném potvrzení také řízeně
  odeslat přes ISDS nebo VREP. Modul běží ve zkušebním provozu a jeho výstupy
  je potřeba ověřovat proti jinému důvěryhodnému zdroji.
- **Mzdová rekapitulace** — jednodušší cesta pro zaúčtování mezd z cizí
  mzdovky, i importem CSV
- **Kniha jízd** — vozidla, cesty, tankování a daňové souhrny
- **Dokumenty** — archiv s fulltextem a přiřazením k dokladům

Viz [58. Úplné mzdy](58_Uplne_mzdy.md), [57. Mzdová rekapitulace](57_Mzdy.md),
[59. Majetek a odpisy](59_Majetek.md), [32. Kniha jízd](32_Kniha_jizd.md)
a [31. Dokumenty](31_Dokumenty.md).

## 1.17 Exporty, importy a API

Standardní formáty pro předání dokladů externí kanceláři nebo internímu
účetnímu oddělení:

- **PDF ZIP po měsících** — klasická archivace, název souboru
  `<varsymbol>-<typ>.pdf`
- **ISDOC 6.0.2** — český národní standard pro elektronickou výměnu faktur,
  podporují ho všechny větší české účetní programy
- **Pohoda XML** (Stormware data package) — přímý import do Pohody bez
  ručního opisu
- **Stereo XML** — DocumentPack XML pro import vydaných faktur do Stereo
- **Money S3 XML** — seznam vydaných faktur pro přímý import do Money S3
- **CSV** — tabulkový přehled dokladů pro Excel a další zpracování
- Filtrování exportu podle období, typu dokladu (faktury / zálohové /
  dobropisy) a stavu (vystavené / zaplacené / vše)
- **Hromadné exporty účetnictví** a závěrkový balíček

Aplikace umí i **import** — Pohoda XML (zpětně nahrát doklady vystavené
v Pohodě), ISDOC, bankovní výpisy a číselníky. Export do ISDOC nebo Pohoda XML
je **volitelný**: hodí se, pokud část agendy řešíš jinde, ale není to nutná
součást postupu — účtování i výkazy si MyÚčto zvládne samo.

Nad tím vším je **REST API v1 popsané specifikací OpenAPI 3.1** s tokenovou
autentizací a výběrem firmy hlavičkou `X-Supplier-Id`. Napojí se na
něj e-shop, CRM, BI nástroj i automatizační platforma typu Make nebo Zapier.
Viz [78. REST API](78_API.md), [20. Exporty](20_Exporty.md),
[21. Importy](21_Importy.md) a [42. Hromadný export](42_Hromadny_export.md).

## 1.18 Multi-supplier — víc firem z jedné instalace

Z jedné instalace MyÚčto můžeš fakturovat za **libovolný počet
dodavatelů** (firem / IČO) s plně izolovanými daty:

- Vlastní číselné řady, klienty, zakázky a faktury per dodavatel
- Vlastní logo, bankovní účty, SMTP, DKIM klíče
- Přepínač dodavatele v UI — uživatel vidí jen ty, ke kterým má přístup
- **Izolace dat mezi firmami** je vynucená v API, sestavách, plánovaných úlohách
  i cestách k souborům
- Typické nasazení: účetní kancelář se samostatnými klientskými agendami,
  holding nebo skupina společností sdílející jednu instalaci

Viz [72. Více dodavatelů](72_Multi_supplier.md).

## 1.19 Tým, oprávnění a úlohy na pozadí

- **Role a oprávnění** — granulární práva uspořádaná do funkčních skupin,
  upravitelná pro každou firmu zvlášť
- **Log činnosti** se zamaskováním citlivých hodnot
- **Plánované úlohy** — rutinu na pozadí obstarávají cron úlohy (zálohy,
  import banky, čtení e-mailové schránky, upomínky, generování pravidelných
  faktur, kontrola integrity deníku, AI worker a další). Poslední běh, doba
  trvání i chyba jsou na jedné obrazovce.
- **Externí integrace** a **API tokeny s omezením rozsahu**
- **Branding** — logo, barva a šablony PDF pro každou firmu zvlášť
- **Elektronické podpisy** PDF dokladů i odchozích e-mailů
- **Zálohování** databáze, dokladů i dokumentů, volitelně šifrované

Viz [73. Nastavení](73_Nastaveni.md),
[74. Elektronické podpisy](74_Elektronicke_podpisy.md) a
[77. Aktualizace](77_Aktualizace.md).

## 1.20 Bezpečnost

Bezpečnost má dvě roviny — **kdo se dostane dovnitř** a **co se uvnitř může
stát s účetnictvím** (detail v [76. Bezpečnost](76_Bezpecnost.md)):

**Přístup a přihlášení**

- **Hesla** — bcrypt s pepperem uloženým mimo databázi, min. 12 znaků bez
  horního limitu, indikátor síly
- **2FA (TOTP)** — Google Authenticator, Authy, 1Password, Bitwarden…; správce
  ho může vynutit pro všechny uživatele instalace
- **Ověření e-mailem** jako alternativní druhý faktor, reset hesla odkazem
  s platností jedné hodiny
- **IP allowlist** (IPv4 + IPv6 + CIDR) — funguje i za reverse proxy
- **Brute-force ochrana** + **CAPTCHA** na login a veřejné stránky
- **Ochrana proti CSRF**, šifrování integračních tajemství, **DKIM** podpis
  odchozích e-mailů

**Účetní bezpečnost**

- **Deník je žurnál** — zápisy se neztrácejí ani nepřepisují potichu
- **Storno místo mazání**; doklad se zaúčtovaným zápisem nelze smazat — systém
  to odmítne a vysvětlí proč
- **Uzavřené období nelze měnit**; znovu otevřít smí výhradně administrátor
- **Doklady z navázané agendy mají uzamčený popis** — edituje se u zdroje
- **Activity log** všech mutací (kdo, kdy, co změnil)

## 1.21 Vlastní hosting, vlastní data

- **Kombinované licencování** — veškeré funkce původního MyInvoice zůstávají
  navždy zdarma; podvojné účetnictví, účetní nástroje a uzávěrky, sklad a
  e-shop, majetek, EPO a rozšířené opravy DPH vyžadují po 60denním zkušebním
  období komerční licenci
- **Žádný externí cloud** — data v tvojí MariaDB, PDF na tvém disku
- **Žádná telemetrie** — aplikace nikam neposílá data o tvém používání
- **Docker image** na GHCR (`ghcr.io/radekhulan/myucto`) — multi-arch
  (amd64 + arm64), připravený pro běžné nasazení přes Docker Compose
- **Nativní nasazení** na IIS i Apache, s volitelným Redisem
- **Migrace** přes `php api/bin/migrate.php` — verzované, idempotentní
- **Backup** = `mysqldump` + `tar` adresáře s PDF; obnovení obrácený postup

Viz [3. Instalace — Docker](03_Instalace_Docker.md) a
[4. Instalace — Nativní](04_Instalace_Nativni.md).

## 1.22 Systém, který roste s vaším provozem

MyÚčto.cz není omezené velikostí firmy ani jedním způsobem práce. Jednotlivé
moduly lze zavádět postupně: začít fakturací a bankou, doplnit přijaté doklady,
automatizaci, účetnictví a daně a nakonec řídit celý měsíční i roční cyklus v
jednom prostředí.

- **Jedna společnost s kompletní agendou** získá společný zdroj dat pro doklady,
  platby, sklad, účetnictví, daně, uzávěrku i reporting. Odpadá opakované
  přepisování mezi oddělenými aplikacemi a tabulkami.
- **Skupina společností nebo holding** vede více plně oddělených agend v jedné
  instalaci. Uživatelé mají přístup jen k přiděleným firmám a mezi nimi se
  přepínají bez směšování dat, číselných řad nebo nastavení.
- **Účetní kancelář** může obsluhovat klientské agendy, rozdělit oprávnění mezi
  účetní a klienty, automatizovat rutinní zpracování a nabídnout online přehledy
  pod vlastní značkou. Otevřený kód umožňuje doplnit vlastní workflow,
  integrace i specializované kontroly.
- **Větší organizace** může MyÚčto.cz propojit přes REST API s navazujícími
  systémy, reportingem nebo interními procesy a provozovat jej na infrastruktuře
  odpovídající vlastním bezpečnostním a provozním požadavkům.

Rozsah instalace proto neurčuje marketingová kategorie zákazníka, ale zvolená
infrastruktura, způsob organizace práce a požadované integrace.

## 1.23 Instalace aplikace na plochu (PWA)

MyÚčto.cz lze z podporovaného prohlížeče nainstalovat jako aplikaci. Otevři
menu prohlížeče a zvol **Nainstalovat aplikaci** nebo **Přidat na plochu**.
Na iPhonu a iPadu je volba **Přidat na plochu** v nabídce Sdílet v Safari.
Nainstalovaná aplikace se spouští ve vlastním okně a má ikonu MyÚčto.

Instalace vyžaduje zabezpečené HTTPS připojení (výjimkou je lokální
`localhost`). Při provozu jen přes nezabezpečenou LAN adresu se nabídka
instalace nemusí zobrazit.

Do mezipaměti se ukládají pouze statické soubory aplikace, jako jsou skripty,
styly, fonty a ikony. HTML stránky ani odpovědi API se neukládají. Pro práci
s doklady a ostatními daty proto aplikace stále potřebuje spojení se serverem.
