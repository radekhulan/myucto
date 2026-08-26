# Dokumenty a výstupy

## 66.1 Účel

Agenda vytváří mzdové listiny a zaměstnanecké dokumenty z uzavřených nebo jinak způsobilých dat a uchovává jejich vazbu na osobu, vztah a období.

## 66.2 Předpoklady a oprávnění

Uživatel potřebuje `payroll.documents`. Zdrojová data musí být zkontrolována a u měsíčních výstupů zpravidla uzavřena. Dokument nesmí být vydán, pokud aplikace nemá údaje potřebné pro jeho zákonný obsah.

## 66.3 Krokový postup

1. Otevřete **Mzdy → Dokumenty a výstupy** a vyberte typ, zaměstnance a období.
2. Zkontrolujte náhled, identifikaci zaměstnavatele, vztah, částky a data.
3. Vytvořte dokument a bezpečně jej doručte oprávněnému příjemci.
4. Evidujte vydání nebo převzetí bez ukládání přístupových tajemství.
5. Při opravě vytvořte navazující dokument a zachovejte původní auditní stopu.

## 66.4 Stavy

Návrh čeká na kontrolu, vygenerovaný dokument odpovídá konkrétním zdrojovým datům a vydaný má evidované předání. Vygenerování samo o sobě neznamená doručení ani podpis příjemce.

## 66.5 Kontroly a bezpečnost

PDF a exporty obsahují osobní a mzdové údaje. Chraňte je při stažení, přenosu i archivaci. Před vydáním zkontrolujte správnou osobu a období. Potvrzení pro úřad práce závislé na zákonném přepočtu průměrného výdělku dokončete ručně, pokud aplikace bezpečný výpočet nenabízí.

## 66.6 Časté chyby

- Výstup z návrhu běhu vydaný jako konečný.
- Odeslání dokumentu nesprávnému zaměstnanci.
- Domněnka, že stažení PDF je důkaz předání.
- Ruční úprava vygenerovaného dokumentu bez auditní návaznosti.

## 66.7 Návaznosti

Zdrojem je [mzdový běh](63_Mzdove_behy.md) a [zaměstnanci](69_Zamestnanci.md). Doby uchování řeší [retenční lhůty](76_Retencni_lhuty.md) a bezpečný výmaz [kapitola 58s](77_Vymaz_osobnich_udaju.md).



## 66.8 Podrobný pracovní postup a kontroly

V **Mzdy → Dokumenty a výstupy** vyber období. Seznam zobrazuje dokumenty
uložené ke schválené revizi mzdového běhu, zaměstnance, mzdovou účtárnu,
číslo revize, čas vytvoření a velikost. Na telefonu se tabulka mění na karty.

Záložka **Roční dokumenty** umožňuje zvolit rok a zaměstnance a vytvořit
**mzdový list**, **potvrzení k zálohové dani** nebo **potvrzení ke srážkové
dani**. Mzdový list vzniká pouze z posledních schválených revizí všech mzdových
účtáren v daném roce. Zahrne také více souběžných revizí v jednom měsíci,
například doplatek po skončení vztahu. Pokud chybí schválený výsledek,
historická identifikace nebo jiný povinný podklad, aplikace dokument nevytvoří
a zobrazí konkrétní důvod.

Daňová potvrzení jsou dva samostatné formuláře: `25 5460, MFin 5460 – vzor
č. 33` pro zálohovou daň a `25 5460/A, MFin 5460/A – vzor č. 12` pro příjmy
zdaněné srážkou. Automaticky se vytvářejí pro rok 2026. Zálohové potvrzení zmrazí
stav Prohlášení poplatníka i měsíce, ve kterých bylo podepsané; srážkové
potvrzení uvádí přesné měsíce příjmů. Pro přesná pole tiskopisu musí mít
historická identita zaměstnance vedle celého zobrazovaného jména vyplněné
také samostatné jméno a příjmení; systém je z celého jména neodhaduje.

Zálohové potvrzení podporuje také doloženého daňového nerezidenta. Nemá-li
přidělené české rodné číslo, uvede se datum narození. Řádek 14 se naplní
součtem povinného sociálního a zdravotního pojistného zaměstnance výhradně ze
schválených měsíčních výsledků. Srážkové potvrzení 25 5460/A lze podle
oficiálního tiskopisu vystavit českému rezidentovi nebo nerezidentovi EU/EHP;
jinou rezidenci aplikace odmítne.

Řádky 11 a 12 zálohového potvrzení přebírají doložené měsíční nároky na děti,
invaliditu I./II. stupně, invaliditu III. stupně a průkaz ZTP/P. Jméno a rodné
číslo dítěte, případně datum narození dítěte bez českého rodného čísla, se při
vydání uzamknou do šifrované roční revize. Pozdější úprava karty proto již
vydané PDF nezmění. Pokud bylo v aplikaci provedeno a schváleno roční
zúčtování, řádek 13 zobrazí jeho uložený přeplatek, doplatek ze zúčtování a
rozdíl na daňovém bonusu. Přeplatek před zvýhodněním na děti, přeplatek po
zvýhodnění i výsledný doplatek jsou na tiskopisu oddělené. Aplikace neprovádí
nový daňový výpočet; ověří vzájemné součty zmrazeného výsledku a pouze je
převede do odpovídajících polí formuláře.

Řádek skutečně vyplacených příjmů nevychází z plánovaného výplatního dne.
Aplikace jej povolí jen tehdy, když neměnná platební evidence dokládá úplnou
výplatu všech zahrnutých čistých mezd nejpozději do 31. ledna následujícího
roku. Chybějící, částečná, pozdní nebo zvrácená úhrada vytvoření zablokuje.
Stejně bezpečně se odmítnou situace, pro které snapshot nemá všechna
povinná pole formuláře, například nedoložený nárok, nerezident bez částky
povinného pojistného, podporovaný produkt spoření bez přesného mapování,
nepeněžní příjem nebo doplatek za minulý rok. Údaj se nikdy tiše nedopočítá.

Roční dokument má vlastní neměnnou revizi a není uměle připojený k prosincové
mzdě. Osobní údaje jeho zdrojového snapshotu jsou kontextově šifrované;
manifest obsahuje pouze interní identifikátory a kryptografické otisky.
Pozdější oprava mzdy vytvoří další revizi mzdového listu a původní soubor
zůstane dohledatelný. Roční zúčtování se v mzdovém listu nedopočítává a bez
samostatně schváleného ročního výsledku se označí jako neprovedené.
Opravné daňové potvrzení musí navazovat na poslední vydaný dokument stejného
druhu a vyžaduje konkrétní důvod. Nová revize uvádí datum nahrazovaného
potvrzení a důvod v příloze; původní PDF zůstává beze změny. Opakování stejné
opravné žádosti bezpečně vrátí již archivovanou revizi.

U ukončeného pracovního vztahu otevři v **Mzdy → Zaměstnanci** jeho detail
a část **Dokumenty při skončení vztahu**. Potvrzení o zaměstnání lze vytvořit
jen po kontrole přesné identity, adresy a smluvních podmínek účinných ke dni
skončení. Ve formuláři potvrď druh práce, kvalifikaci, pracovní expozici,
pokračující srážky a případné důchodové kategorie před rokem 1993. Částky
srážek se nezadávají — aplikace je přebírá z uzavřené evidence. Každá oprava
vyžaduje konkrétní důvod a vytvoří novou neměnnou revizi.

Samostatné potvrzení pro Úřad práce (§ 313 odst. 2) je zablokované.
Aplikace v modulu Absence a průměry rozlišuje, zda pro rozhodné čtvrtletí
chybí schválený snapshot průměrného výdělku, nebo zda snapshot existuje a
chybí jen ověřený přepočet na čistý měsíční výdělek podle zákona o
zaměstnanosti — v obou případech ale potvrzení nelze vydat a aplikace
nenabízí ruční zadání hotové čisté částky.

Stažení nejprve získá krátkodobé jednorázové oprávnění a potom soubor předá
prohlížeči. Původní dokument se při opravě nikdy nepřepisuje. Nový výstup má
vlastní revizi a původní zůstává dohledatelný.

Pro každou poslední schválenou revizi období lze vytvořit měsíční ZIP. Obsahuje
právě ty dokumenty, které už byly k revizi archivovány, a strojově čitelný
manifest s jejich otisky. Doplníš-li později další dokument, vznikne nová
revize balíčku; opakované vytvoření nad stejnou sadou vrátí stejný výsledek.

## 66.9 Měsíční a roční archiv mezd

Záložka **Archiv mezd** vytváří souhrnný ZIP za celou firmu. Není to export
jednoho zaměstnance a kvůli jeho sestavení se nenačítá ani nevybírá seznam
zaměstnanců. Hodí se pro pravidelnou měsíční zálohu mzdové uzávěrky, předání
kontrolovatelných podkladů a roční archivaci.

1. Otevřete **Mzdy → Dokumenty a výstupy → Archiv mezd**.
2. Pro měsíční archiv vyberte měsíc, pro roční archiv rok.
3. Stiskněte **Vytvořit a stáhnout měsíční ZIP** nebo **Vytvořit a stáhnout
   roční ZIP**. Příprava větší firmy může chvíli trvat; tlačítko po dobu práce
   nelze spustit podruhé.
4. Stažený soubor uložte do firemního zabezpečeného úložiště. Obsahuje osobní
   a mzdové údaje a musí mít stejnou ochranu jako výplatní pásky.
5. Při dlouhodobé archivaci ověřte `CHECKSUMS.txt` proti souborům v ZIPu a
   `manifest.json`, který popisuje období, zdrojové revize, velikosti a SHA-256
   otisky.

Archiv vychází jen ze schválených nebo pozdější revizí nahrazených zmrazených
mzdových zdrojů. Měsíční ZIP zahrne všechny takové revize vybraného měsíce ve
všech mzdových účtárnách, nikoli jen poslední opravu. Roční ZIP zahrne totéž za
celý rok a navíc dostupné neměnné roční snapshoty a jejich zdrojové vazby.
Přidají se již archivované mzdové dokumenty, datové věty a validační nebo
doručovací artefakty podání a protokoly JMHZ, u kterých je bezpečně doložené
období. Každý binární soubor se před zařazením znovu ověří uloženou velikostí
a SHA-256 otiskem.

Do archivu se záměrně nezařazují dnešní editovatelné karty zaměstnanců,
proměnlivý provozní stav podání, protokoly bez doloženého období ani libovolné
ručně přidané přílohy podání. Nikdy se neexportují hesla, komunikační kódy,
přístupové tokeny, certifikáty nebo soukromé klíče. Roční citlivý snapshot se
před zařazením dešifruje pouze v paměti a ověří klíčovaným otiskem; databázový
šifrovaný blob se do ZIPu nekopíruje.

Vytvořený ZIP je v serverovém archivu uložen šifrovaně a odděleně pro právě
zvolenou firmu. Prohlížeč nejprve vytvoří archiv, potom získá krátkodobé
jednorázové oprávnění a teprve nakonec soubor stáhne. Oprávnění je svázané s
přihlášeným uživatelem i firmou, neposílá se v adrese a po prvním použití už
neplatí. Pokud za období není žádná schválená mzdová revize nebo nesouhlasí
integrita zdroje, aplikace ZIP nevytvoří a zobrazí důvod.

> [!WARNING]
> Výplatní pásky vznikají automaticky při schválení; mzdový list a obě daňová
> potvrzení vytvoříš v záložce Roční dokumenty. Potvrzení při skončení vytváříš
> v detailu konkrétního vztahu a podací protokoly se evidují samostatně. Neúplný
> měsíční balíček proto neznamená, že jsou všechny povinné výstupy hotové.
