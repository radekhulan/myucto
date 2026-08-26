# 81. Účtový rozvrh

**Cesta: `Nástroje → Účtový rozvrh`**

Účtový rozvrh je seznam účtů, na které firma účtuje. Stránka i její API jsou
dostupné jen firmě v režimu **podvojného účetnictví**. Předkontace jsou po
rozdělení menu popsány samostatně v kapitole [Nástroje](88_Ucetni_nastroje.md);
šablony zápisů a pravidla nákladů v kapitole
[Šablony](80_Sablony.md).

## 81.1 Co stránka zobrazuje

Účty jsou seskupené do tříd **0–7** podle prvního znaku kódu. Samostatně se
zobrazují **podrozvahové účty**, **závěrkové účty** a ostatní kódy. V každé
skupině jsou účty seřazené podle kódu.

| Sloupec | Význam |
|---|---|
| Kód | Syntetika je zobrazena tučně, analytika odsazeně pod rodičem |
| Název | Uživatelský název účtu |
| Typ | Aktivum, pasivum, kapitál, výnos, náklad, podrozvaha nebo závěrkový účet |
| Strana | Obvyklá strana MD/Dal; u saldních účtů může být prázdná |
| Aktivní | Zda lze účet použít pro nové zápisy |

Prázdná obvyklá strana není chyba. Například účty 343, 341 nebo 431 mohou podle
skutečného salda skončit na MD i na Dal. Obvyklá strana slouží sestavám a
kontrolám; vlastní stranu každého řádku určuje účetní zápis.

Přepínač **Zobrazit neaktivní** načte i vyřazené účty. Neaktivní účet zůstává
čitelný v historickém deníku a sestavách, ale není nabízen pro nový zápis.

## 81.2 Automatické založení osnovy

Při zahájení aktivace podvojného účetnictví server idempotentně naseeduje
standardní syntetické účty, vybrané analytiky (mimo jiné **343.100 / 343.200 /
343.900** — viz [§ 81.3.2](#8132-analytiky-dph-343100-343200-a-343900), a nedaňové
nákladové analytiky **501.990 / 511.990 / 518.990 / 548.990**) a systémové
předkontace. Stejný seed lze bezpečně
spustit znovu: existující firemní účty ani jejich názvy se neduplikují.
Aktivační průvodce je popsán v kapitole
[Aktivace účetnictví](83_Aktivace_ucetnictvi.md).

Účty jsou vždy oddělené podle firmy (`supplier_id`). API a repozitář při každém
čtení i zápisu používají aktuální firmu z požadavku; znalost číselného ID účtu
jiné firmy nestačí k jeho načtení nebo změně.

## 81.3 Syntetické a analytické účty

- **Syntetický účet** je zpravidla třímístný účet standardní osnovy, například
  `311` nebo `501`.
- **Analytický účet** je firemní podúčet syntetiky, například `311.100` nebo
  `501.200`.

Tlačítko **Nová analytika** otevře formulář:

- aktivní syntetický rodič,
- unikátní kód o délce 3–10 znaků; povolené jsou číslice, písmena a tečka,
- název analytiky.

Analytika dědí **typ účtu a obvyklou stranu** po rodiči. Web proto tato pole
nenabízí. Kód musí být v rámci firmy unikátní. Nový účet vzniká jako aktivní.
Novou syntetiku nelze založit tímto formulářem; pro řízený hromadný přenos
slouží import.

### 81.3.1 Tečkovaný zápis analytik

Kanonický tvar analytiky je **syntetika, tečka, pořadové číslo** — `221.100`,
`211.500`, `343.900`, `501.200`. Tak analytiky vedou účetní i ostatní účetní
programy a tak je aplikace zakládá i zobrazuje. Dřívější beztečkový zápis
(`221100`) se při aktualizaci **přejmenoval automaticky** všude, kde je kód
uložený jako text — v účtovém rozvrhu, v předkontacích, v bankovních pravidlech
účtování, u pokladen, na kartách majetku i v mzdovém nastavení. Kde by
přejmenování narazilo na už existující tečkovaný kód, zůstane starý kód
nedotčený a funguje dál.

Formulář sám tečku nevynucuje — kód smí mít 3–10 znaků z číslic, písmen a tečky,
takže i účty jako `311D` nebo `461K` ze standardní šablony zůstávají platné.
Tečkovaný tvar má ale dva praktické důsledky:

- **Přesměrování z holé syntetiky.** Když má předkontace zadaný jen syntetický
  účet a firma pod ním má **právě jednu aktivní daňovou tečkovanou analytiku**, zápis se
  provede na tu analytiku. Řeší to naráz všechna místa, kde byl účet v enginu
  zadaný natvrdo (kurzové rozdíly, opravy a udržování, zaokrouhlení) a odpadá
  díky tomu ruční kontace pro každou drobnost.
- **Nedaňová analytika `.990` se do obecného přesměrování nepočítá.** Použije se
  pouze u nedaňového přijatého dokladu nebo nedaňové účetní alokace; její samotná
  existence proto nemůže změnit kontaci daňově uznatelných nákladů.
- **Víceznačné účty se nepřesměrovávají nikdy.** U **`211`, `221`, `343`, `336`,
  `315`, `345` a `501`** rozhoduje o analytice kontext dokladu — bankovní účet
  výpisu, pokladní registr nebo mzdové nastavení — ne osnova. Kdyby se
  přesměrovávalo i tady, skončily by všechny účty na první nalezené analytice.
- Netečkovaná analytika se pro přesměrování nepoužije. Je to záměrná pojistka:
  šablona má pod `311` jedinou analytiku `311D` (dlouhodobé pohledávky), na
  kterou by jinak spadly úplně všechny pohledávky.

Celý mechanismus jde firmě vypnout, pokud si účtování nad holými syntetikami
vyloženě přeješ.

### 81.3.2 Analytiky DPH 343.100, 343.200 a 343.900

Šablona rozvrhu zakládá pod syntetikou 343 tři analytiky:

| Účet | Název | Co na něj chodí |
|---|---|---|
| **343.100** | Daň z přidané hodnoty **vstup** | nárok na odpočet — přijaté faktury (i s kráceným odpočtem), odpočtová strana samovyměření (reverse charge), daňový doklad k poskytnuté záloze, nákup přes výdajový pokladní doklad |
| **343.200** | Daň z přidané hodnoty **výstup** | povinnost přiznat daň — vydané faktury, přiznávací strana samovyměření, daňový doklad k přijaté záloze, prodej přes příjmový pokladní doklad |
| **343.900** | Daň z přidané hodnoty **zúčtování** | jen dvě věci: měsíční doklad zúčtování (§ 81.3.3) a **platba nebo vratka finančnímu úřadu** z banky |

Všechny tři jsou závazkové a **saldní** — smějí stát na obou stranách, protože
nadměrný odpočet je pohledávka za státem.

Přednost má vždy **předkontace** (`invoice.vat.input`, `invoice.vat.output`);
analytika je jen výchozí hodnota. Kdo chce zůstat na plochém 343, přepíše si
kontaci a nic se pro něj nemění.

> [!NOTE]
> **Firma, která analytiky nemá, o nic nepřijde.** Chybí-li 343.100 nebo 343.200
> v rozvrhu (nebo je někdo deaktivoval), engine se sám vrátí na holou **343**
> a účtuje jako dřív. Cena za to je, že se vstup a výstup na jednom účtu
> vzájemně vynetují a firmu **přeskočí měsíční zúčtování DPH** (§ 81.3.3).

> [!IMPORTANT]
> Od zavedení analytik je **343 syntetika se třemi dětmi** — přímo na ni se už
> neúčtuje. Kde se v manuálu mluví o „obratu účtu 343" (kontroly proti přiznání
> k DPH, uzávěrka, měsíční kontrola), jde vždy o **součet syntetiky včetně
> analytik** — tak ho i všechny sestavy počítají, takže srovnání s přiznáním
> platí dál.

### 81.3.3 Měsíční zúčtování DPH

Za každé skončené zdaňovací období vznikne automaticky **interní doklad
„Zúčtování DPH"**, který převede obrat vstupní a výstupní daně na zúčtovací účet
— přesně tak, jak to účetní dělá ručně:

```text
    MD 343.200 / D 343.900     daň na výstupu za období
    MD 343.900 / D 343.100     daň na vstupu za období
```

Po dokladu jsou 343.100 i 343.200 za období nulové a na **343.900 leží přesně
to, co se odvede** (nebo co má finanční úřad vrátit). Ten zůstatek pak uzavře
platba z banky, takže je průběžně srovnatelný se saldem u správce daně — na
plochém 343 to z principu nešlo.

| Vlastnost | Chování |
|---|---|
| Číslo dokladu | `DPH-01/2026` (měsíční plátce) nebo `DPH-Q1/2026` (čtvrtletní) — nečerpá z číselné řady interních dokladů |
| Popis | „Zúčtování DPH za 01/2026"; v deníku je zdroj označený jako **Zúčtování DPH** |
| Datum zápisu | **poslední den období** — zápis padne do správného období i při opožděném běhu |
| Kdy běží | plánovaná úloha **1. den v měsíci ve 04:30** (viz [§ 5.5](05_Po_instalaci.md)), za období obsahující předchozí měsíc |
| Opakované spuštění | doklad se **přepočítá, nikdy nezdvojí** — vlastní zúčtovací doklady se přitom z obratu vylučují, aby si samy sebe nepřičítaly |
| Zpětné dohnání | jde spustit ručně pro konkrétní období |

Do obratu se **nepočítají** uzávěrkové a otevírací zápisy, dřívější zúčtovací
doklady ani nezaúčtované koncepty.

**Kdo doklad nedostane** (a proč) — vždy se to zapíše do reportu úlohy, nikdy se
to nestane tiše:

| Situace | Důvod |
|---|---|
| Daňová evidence | zúčtování dává smysl jen v podvojném účetnictví |
| Neplátce, který není ani identifikovaná osoba | nemá co zúčtovat |
| Vstup i výstup na témž účtu (ploché 343) | doklad by byl 343/343 |
| Některá z analytik chybí v rozvrhu nebo je neaktivní | není kam účtovat |
| Nulový vstup i výstup | doklad se nezakládá vůbec |
| Čtvrtletní plátce před koncem čtvrtletí | období ještě neskončilo — doklad počká, aby se tři měsíce po sobě nepřepisoval neúplnými čísly |

**Identifikovaná osoba** se vyhodnocuje měsíčně bez ohledu na nastavenou periodu
DPH. Převažují-li dobropisy a obrat vyjde záporný, obrátí se strany zápisu —
záporná částka se nikdy neúčtuje. Chyba u jedné firmy (uzavřené období, zámek
data, chybějící analytika) běh nezastaví, jen skončí v reportu.

> [!TIP]
> Zúčtovací doklad **nenahrazuje roční vypořádání koeficientu** podle § 76 odst.
> 7 ZDPH — to je pořád ruční zápis (viz [§ 36](36_Vykazy_DPH.md)).

## 81.4 Aktivace a deaktivace

Akce **Deaktivovat** účet nemaže. Historické řádky deníku, výpis účtu a výkazy
zůstávají beze změny. Opětovná akce **Aktivovat** účet vrátí do nabídek.

Deaktivovaný účet nelze nově použít:

- v ručním zápisu nebo šabloně,
- jako firemní předkontaci,
- při automatickém zaúčtování dokladu,
- jako cílový účet nového majetku.

Pokud starší pravidlo na mezitím deaktivovaný účet stále odkazuje, zaúčtování
nespadne na jiný účet potichu. `PostingService` vrátí chybu a účetní musí účet
znovu aktivovat nebo opravit pravidlo.

Účty se záměrně nemažou. Kód účtu je součást průkazné účetní stopy a mohou na něj
odkazovat deník, šablony, předkontace, střediska nebo karty majetku.

## 81.5 Import a export

Tlačítka **Export** a **Import** pracují s XLSX nebo CSV. Export zahrnuje i
neaktivní účty, aby šel použít jako úplný round-trip. Sloupce jsou `ucet`,
`nazev`, `typ`, `strana`, `nadrizeny_ucet` a `aktivni`; XLSX obsahuje také list
s nápovědou.

Import probíhá ve třech krocích:

1. nahrání `.xlsx` nebo `.csv` do 2 MB,
2. serverový **dry-run** s řádky Založit / Aktualizovat / Přeskočit / Chyba,
3. potvrzení stejného validovaného obsahu.

Náhled ukazuje změny pole po poli a lze jej omezit na problémy. Obsahuje-li
chybu, potvrzení je zablokované. Import **nikdy nemaže**.

### 81.5.1 Pravidla importu

- Identitou je kód účtu.
- `nadrizeny_ucet` označuje analytiku; rodič musí být syntetika existující
  v databázi nebo založená dříve ve stejném souboru.
- U existujícího účtu lze změnit jen název a aktivní stav. Typ, obvyklou stranu
  ani rodiče nelze přepsat.
- U nové syntetiky jsou povinné typ a název.
- Nová analytika přebírá typ a stranu z rodiče; odlišné hodnoty v souboru jsou
  nahlášeny.
- Deaktivace účtu používaného aktivní předkontací nebo nevyřazeným majetkem
  dostane varování. Import může projít, ale navazující automatické účtování
  bude vyžadovat opravu odkazu.

Zápis importu je tenantově omezený a probíhá až po úspěšném náhledu. Selhání
jednoho řádku v potvrzeném souboru nesmí vytvořit neohlášený napůl použitelný
rozvrh; výsledný report vždy uvádí založené, změněné, přeskočené a chybné řádky.

## 81.6 Ochrany při účtování

Vedle existence a aktivity účtu hlídá centrální účetní služba i následující
invarianty:

- účty **701, 702 a 710** lze použít jen v řízeném závěrkovém nebo otevíracím
  zápisu,
- podrozvahové účty 75x/79x se nesmějí v jednom zápisu míchat s rozvahovým či
  výsledkovým účtem,
- každý zápis musí být vyrovnaný na haléř,
- datum musí patřit do otevřeného období a nesmí spadat do zámku účtování
  k datu.

Tyto kontroly běží na backendu i při volání API. Omezení webového výběru proto
nelze obejít vlastním požadavkem.

## 81.7 Karta účtu

Kliknutí na řádek účtu (nebo na jeho kód) otevře **kartu účtu** — rozcestník
pro procházení účetnictví po jednom účtu.

Karta obsahuje:

- **počáteční stav, obrat MD, obrat Dal a konečný zůstatek** za zvolený rozsah;
  počítají se stejně jako v [Hlavní knize](48_Hlavni_kniha.md) a v opisu účtu,
  u syntetiky včetně pohybů jejích analytik,
- **kmenová data** — kód, typ, obvyklá strana, syntetika/analytika, aktivita a
  počet analytik,
- **analytiky** se zůstatky za tentýž rozsah; každá je odkaz na svoji vlastní
  kartu,
- u analytiky odkaz na **nadřízenou syntetiku**.

Rozsah se nastavuje poli **Od / Do** nebo zkratkou na účetní období. Tlačítka
v hlavičce vedou na [Opis účtu](48_Hlavni_kniha.md#485-opis-uctu), do
[Hlavní knihy](48_Hlavni_kniha.md) (kniha účet sama rozbalí) a do
[Účetního deníku](45_Ucetni_denik.md) filtrovaného na tento účet a rozsah;
u syntetiky pokrývá filtr i její analytiky.

Karta je jen ke čtení — název a aktivitu se mění v seznamu účtů.

## 81.8 Oprávnění a chyby

Čtení a export vyžadují oprávnění `accounting`. Založení analytiky, změna
aktivity a import vyžadují zápisové účetní oprávnění; readonly role vidí pouze
seznam a export.

Nejčastější chyby:

- **duplicitní kód** — zvol jinou analytiku nebo oprav import,
- **neexistující či neaktivní rodič** — aktivuj syntetiku nebo změň vazbu,
- **účet je používán** — ponech jej aktivní, případně nejdřív přesměruj
  předkontace a majetek,
- **režim není podvojné účetnictví** — dokonči aktivaci nebo použij funkce
  daňové evidence.

> [!TIP]
> Před větší změnou udělej export, uprav rozvrh v tabulkovém procesoru a nejprve
> projdi dry-run. Je bezpečnější opravit varování v náhledu než až chybu při
> zaúčtování dokladu.
