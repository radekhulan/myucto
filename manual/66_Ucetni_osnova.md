# 66. Účtový rozvrh

> Návod, jak spravovat účty, na které firma účtuje: založit analytiku, účet vypnout, osnovu
> naimportovat nebo exportovat a projít pohyby po jednom účtu. Pro účetní firmy v podvojném účetnictví.

## 66.1 Kdy to potřebujete

Kapitolu otevřete, když:

- potřebujete pod syntetickým účtem vlastní analytiku (například `501.200`),
- chcete účet přestat nabízet pro nové zápisy,
- převádíte osnovu z jiného programu nebo ji chcete upravit v tabulkovém procesoru,
- chcete vidět obraty a zůstatky jednoho účtu i jeho analytik,
- zjišťujete, na které účty aplikace účtuje DPH.

Stránka `Nástroje → Účtový rozvrh` je dostupná jen firmě v **podvojném účetnictví**.
Předkontace jsou popsány v kapitole [Nástroje](73_Ucetni_nastroje.md), šablony zápisů
a pravidla nákladů v kapitole [Šablony](65_Sablony.md).

## 66.2 Než začnete

1. **Podvojné účetnictví.** Pokud ho firma ještě nemá zapnuté, dokončete aktivaci
   (viz [Aktivace účetnictví](68_Aktivace_ucetnictvi.md)). Při zahájení aktivace se osnova
   založí sama (viz [§ 66.8.2](#6682-automaticke-zalozeni-osnovy)).
2. **Správná firma.** Účty jsou vždy oddělené podle firmy. Zvolte firmu v hlavní liště.
3. **Oprávnění.** Zobrazení a export vyžadují účetní oprávnění. Založení analytiky, změna
   aktivity a import vyžadují účetní oprávnění k zápisu. Role jen pro čtení vidí seznam a export.

## 66.3 Krok za krokem: založit analytiku

1. Otevřete `Nástroje → Účtový rozvrh`.
2. Klikněte na **Nová analytika**.
3. Vyberte aktivní syntetický účet v poli **Syntetický účet (rodič)**.
4. Zadejte **Kód** o délce 3 až 10 znaků (číslice, písmena a tečka). Doporučený tvar je syntetika, tečka, pořadové číslo, například `501.200`. Zapíšete-li kód bez tečky, aplikace ji doplní.
5. Zadejte **Název**.
6. Klikněte na **Vytvořit**.

Analytika dědí typ účtu a obvyklou stranu po rodiči, proto je formulář nenabízí. Kód musí být
v rámci firmy jedinečný. Nový účet vzniká jako aktivní. Kód účtu už později změnit nejde.

**Jak poznáte, že je hotovo:** Analytika je v seznamu odsazená pod rodičem a lze ji vybrat v zápisech,
předkontacích a šablonách.

> [!TIP]
> Novou syntetiku tímto formulářem založit nejde. Pro řízený hromadný přenos slouží import
> (viz [§ 66.5](#665-krok-za-krokem-import-a-export-osnovy)).

## 66.4 Krok za krokem: deaktivovat, aktivovat nebo smazat účet

1. V seznamu najděte účet. Neaktivní účty zobrazíte přepínačem **Zobrazit neaktivní**.
2. Klikněte na **Deaktivovat** a potvrďte. Účet se přestane nabízet pro nové zápisy. Historické řádky deníku, výpis účtu a výkazy zůstávají beze změny.
3. Opětovným kliknutím na **Aktivovat** účet vrátíte do nabídek.
4. Chybně založenou analytiku bez jediného pohybu můžete smazat tlačítkem **Smazat** (v okně **Smazat analytický účet**).

**Jak poznáte, že je hotovo:** Deaktivovaný účet zmizí z nabídek a v seznamu je vidět jen při zapnutém přepínači
**Zobrazit neaktivní**.

> [!WARNING]
> Smazat jde jen analytika bez pohybu a bez odkazu z kontací, pravidel, pokladen, karet majetku
> či mzdového nastavení. Používaný účet místo mazání deaktivujte. Kód účtu je součást průkazné
> účetní stopy.

Má-li firma datované definice účtů, změna názvu nebo aktivity platí od dne provedení. Starší výkazy používají definici odpovídající svému účetnímu datu. Změnu zasahující do již zaúčtovaných řádků nebo uzamčeného období aplikace odmítne. Nový účet vzniká k datu založení a nenabízí se pro starší zápisy. Analytiku s datovanou historií nelze smazat; pro ukončení jejího používání ji deaktivujte.

## 66.5 Krok za krokem: import a export osnovy

Tlačítka **Export XLSX** a **Import** pracují s XLSX nebo CSV. Export zahrnuje i neaktivní účty,
aby šel použít jako úplný round-trip.

**Export a úprava:**

1. Na stránce `Nástroje → Účtový rozvrh` klikněte na **Export XLSX**.
2. Soubor upravte v tabulkovém procesoru. Sloupce jsou `ucet`, `nazev`, `typ`, `strana`, `nadrizeny_ucet` a `aktivni`; XLSX obsahuje také list s nápovědou.

**Import:**

1. Klikněte na **Import**. V okně **Import účtové osnovy** přetáhněte soubor `.xlsx` nebo `.csv` (nejvýše 2 MB) nebo ho vyberte.
2. V kroku **Náhled** zkontrolujte řádky se stavy **Nový**, **Změna**, **Beze změny** a **Chyba**. Změny se ukazují pole po poli. Volbou **Jen problémy** omezíte výpis na vady.
3. Obsahuje-li náhled chybu, potvrzení je zablokované. Opravte soubor a nahrajte ho znovu.
4. Klikněte na **Importovat**.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Import dokončen** a v kroku **Výsledek** uvede počet založených,
změněných, přeskočených a chybných řádků.

> [!TIP]
> Před větší změnou udělejte export, upravte rozvrh v tabulkovém procesoru a nejprve projděte náhled.
> Je bezpečnější opravit varování v náhledu než až chybu při zaúčtování dokladu.

## 66.6 Krok za krokem: karta účtu

Karta účtu je rozcestník pro procházení účetnictví po jednom účtu.

1. V seznamu klikněte na řádek účtu (nebo na jeho kód). Otevře se **Karta účtu**.
2. Rozsah nastavte poli **Od** a **Do**, nebo zkratkou na účetní období.
3. Přečtěte si počáteční stav, obrat MD, obrat Dal a konečný zůstatek. Počítají se stejně jako v [Hlavní knize](55_Hlavni_kniha.md) a v opisu účtu, u syntetiky včetně pohybů jejích analytik.
4. V části **Analytiky** vidíte zůstatky jednotlivých analytik za tentýž rozsah. Každá je odkaz na vlastní kartu. U analytiky najdete odkaz na **Nadřízenou syntetiku**.
5. Tlačítka v hlavičce vedou na [Opis účtu](55_Hlavni_kniha.md#5585-opis-uctu), do [Hlavní knihy](55_Hlavni_kniha.md) (kniha účet sama rozbalí) a do [Účetního deníku](52_Ucetni_denik.md) filtrovaného na tento účet a rozsah. U syntetiky pokrývá filtr i její analytiky.

Karta obsahuje také kmenová data: kód, druh, obvyklou stranu, syntetika/analytika, aktivitu a počet analytik.
Je jen ke čtení. Název a aktivitu měníte v seznamu účtů.

**Jak poznáte, že je hotovo:** Karta ukazuje obraty a zůstatky za zvolený rozsah a pohyby v něm.

## 66.7 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Stránka se nezobrazuje | Firma nevede podvojné účetnictví | Dokončete aktivaci ([Aktivace účetnictví](68_Aktivace_ucetnictvi.md)) nebo použijte funkce daňové evidence. |
| Duplicitní kód | Kód už ve firmě existuje | Zvolte jinou analytiku nebo opravte import. |
| Neexistující či neaktivní rodič | Syntetika je vypnutá nebo neexistuje | Aktivujte syntetiku nebo změňte vazbu. |
| Účet je používán | Účet je v kontacích, pravidlech nebo majetku | Ponechte ho aktivní, případně nejdřív přesměrujte předkontace a majetek. |
| Import je zablokovaný | Náhled obsahuje chybu | Opravte řádky se stavem **Chyba** a nahrajte soubor znovu. |
| Zaúčtování ohlásí chybu u deaktivovaného účtu | Starší pravidlo odkazuje na vypnutý účet | Účet znovu aktivujte nebo opravte pravidlo. |
| Účet 343 nejde zaúčtovat přímo | 343 je syntetika se třemi analytikami | Účtujte na analytiky nebo nechte kontaci na předkontaci ([§ 66.8.5](#6685-analytiky-dph-343100-343200-a-343900)). |
| Zápis nejde uložit | Období je uzavřené, datum zamčené, zápis nevyrovnaný nebo se míchají podrozvahové účty | Viz [§ 66.8.8](#6688-ochrany-pri-uctovani). |

## 66.8 Podrobnosti a pravidla

### 66.8.1 Co stránka zobrazuje

Účty jsou seskupené do tříd **0 až 7** podle prvního znaku kódu. Samostatně se zobrazují
**podrozvahové účty**, **závěrkové účty** a ostatní kódy. V každé skupině jsou účty seřazené podle kódu.

<!-- cols: 22 78 -->
| Sloupec | Význam |
|---|---|
| Kód | Syntetika je zobrazena tučně, analytika odsazeně pod rodičem |
| Název | Uživatelský název účtu |
| Typ | Aktivum, pasivum, kapitál, výnos, náklad, podrozvaha nebo závěrkový účet |
| Strana | Obvyklá strana MD/Dal; u saldních účtů může být prázdná |
| Aktivní | Zda lze účet použít pro nové zápisy |

Prázdná obvyklá strana není chyba. Například účty 343, 341 nebo 431 mohou podle skutečného salda skončit
na MD i na Dal. Obvyklá strana slouží sestavám a kontrolám; vlastní stranu každého řádku určuje účetní zápis.

Neaktivní účet zůstává čitelný v historickém deníku a sestavách, ale není nabízen pro nový zápis.
Deaktivovaný účet nelze nově použít v ručním zápisu nebo šabloně, jako firemní předkontaci, při automatickém
zaúčtování dokladu ani jako cílový účet nového majetku. Pokud starší pravidlo na mezitím deaktivovaný účet
stále odkazuje, zaúčtování nespadne na jiný účet potichu. Aplikace ohlásí chybu a účetní musí účet znovu
aktivovat nebo opravit pravidlo.

### 66.8.2 Automatické založení osnovy

Při zahájení aktivace podvojného účetnictví aplikace vybere šablonu podle účetního rámce firmy
účinného k datu založení. Pro rámec **500/2002 Sb.** idempotentně založí standardní syntetické účty,
vybrané analytiky (mimo jiné **343.100 / 343.200 / 343.900**, viz [§ 66.8.5](#6685-analytiky-dph-343100-343200-a-343900),
a nedaňové nákladové analytiky **501.990 / 511.990 / 518.990 / 548.990**) a systémové předkontace.
Stejné založení lze bezpečně spustit znovu: existující firemní účty ani jejich názvy se neduplikují.
Aktivační průvodce je popsán v kapitole [Aktivace účetnictví](68_Aktivace_ucetnictvi.md).

Rámec **504/2002 Sb.** má samostatnou šablonu osnovy, která existující osnovu jiného rámce nepřevádí
ani nedoplňuje. Zapíná se v [Nastavení](96_Nastaveni.md) nebo řízeným převodem z rámce 500. Používá třídy 0 až 3, 5, 6
a 9, včetně vlastního jmění a fondů 901/911, výsledku hospodaření 931/932/933 a závěrkových účtů
961/962. Náklady této šablony nemají potvrzenou daňovou uznatelnost; výchozí nedaňový příznak
nenahrazuje posouzení konkrétní činnosti a účetního případu.

Import kontroluje účetní rámec firmy platný k datu importu. Pro rámec 504 musí syntetika mít
třímístný číselný kód v přípustné účtové skupině a odpovídající typ a obvyklou stranu. Analytika
rozvíjí kód svého syntetického rodiče a dědí jeho typ i stranu. Volné třídy 4, 7 a 8 lze importovat
jen jako výslovně podrozvahové účty podle vnitřního předpisu, nikoli jako kapitálové nebo závěrkové
účty osnovy 500. Stejné kontroly platí v náhledu i při zápisu; jediná chyba zabrání celému importu.

Účty jsou vždy oddělené podle firmy. Aplikace při každém čtení i zápisu používá aktuální firmu; znalost
identifikátoru účtu jiné firmy nestačí k jeho načtení nebo změně.

### 66.8.3 Syntetické a analytické účty

- **Syntetický účet** je zpravidla třímístný účet standardní osnovy, například `311` nebo `501`.
- **Analytický účet** je firemní podúčet syntetiky, například `311.100` nebo `501.200`.

### 66.8.4 Tečkovaný zápis analytik

Kanonický tvar analytiky je **syntetika, tečka, pořadové číslo**: `221.100`, `211.500`, `343.900`, `501.200`.
Tak analytiky vedou účetní i ostatní účetní programy a tak je aplikace zakládá i zobrazuje. Beztečkový
zápis (`221100`) ze starší instalace aktualizace přejmenuje automaticky všude, kde je kód uložený jako text:
v účtovém rozvrhu, v předkontacích, v bankovních pravidlech účtování, u pokladen, na kartách majetku
i v mzdovém nastavení. Kde by přejmenování narazilo na už existující tečkovaný kód, zůstane starý kód
nedotčený a funguje dál.

Formulář tečku nevynucuje. Kód smí mít 3 až 10 znaků z číslic, písmen a tečky, takže i účty jako `311D`
nebo `461K` ze standardní šablony zůstávají platné. Tečkovaný tvar má ale praktické důsledky:

- **Přesměrování z holé syntetiky.** Když má předkontace zadaný jen syntetický účet a firma pod ním má
  právě jednu aktivní daňovou tečkovanou analytiku, zápis se provede na tu analytiku. Řeší to naráz všechna
  místa, kde byl účet natvrdo (kurzové rozdíly, opravy a udržování, zaokrouhlení), a odpadá díky tomu ruční
  kontace pro každou drobnost.
- **Nedaňová analytika `.990` se do obecného přesměrování nepočítá.** Použije se pouze u nedaňového přijatého
  dokladu nebo nedaňové účetní alokace; její samotná existence proto nemůže změnit kontaci daňově uznatelných
  nákladů.
- **Víceznačné účty se nepřesměrovávají nikdy.** U **211, 221, 343, 336, 315, 345 a 501** rozhoduje
  o analytice kontext dokladu (bankovní účet výpisu, pokladní registr nebo mzdové nastavení), ne osnova.
  Kdyby se přesměrovávalo i tady, skončily by všechny účty na první nalezené analytice.
- **Netečkovaná analytika se pro přesměrování nepoužije.** Je to záměrná pojistka: šablona má pod `311`
  jedinou analytiku `311D` (dlouhodobé pohledávky), na kterou by jinak spadly úplně všechny pohledávky.

Celý mechanismus jde firmě vypnout, pokud si přejete účtovat nad holými syntetikami.

### 66.8.5 Analytiky DPH 343.100, 343.200 a 343.900

Šablona rozvrhu zakládá pod syntetikou 343 tři analytiky:

<!-- cols: 14 26 60 -->
| Účet | Název | Co na něj chodí |
|---|---|---|
| **343.100** | Daň z přidané hodnoty **vstup** | nárok na odpočet: přijaté faktury (i s kráceným odpočtem), odpočtová strana samovyměření (reverse charge), daňový doklad k poskytnuté záloze, nákup přes výdajový pokladní doklad |
| **343.200** | Daň z přidané hodnoty **výstup** | povinnost přiznat daň: vydané faktury, přiznávací strana samovyměření, daňový doklad k přijaté záloze, prodej přes příjmový pokladní doklad |
| **343.900** | Daň z přidané hodnoty **zúčtování** | jen dvě věci: měsíční doklad zúčtování ([§ 66.8.6](#6686-mesicni-zuctovani-dph)) a platba nebo vratka finančnímu úřadu z banky |

Všechny tři jsou závazkové a **saldní**: smějí stát na obou stranách, protože nadměrný odpočet je pohledávka
za státem.

Přednost má vždy **předkontace** vstupní a výstupní DPH faktur; analytika je jen výchozí hodnota. Kdo chce
zůstat na plochém 343, přepíše si kontaci a nic se pro něj nemění.

Firma, která analytiky nemá, o nic nepřijde. Chybí-li 343.100 nebo 343.200 v rozvrhu (nebo je někdo
deaktivoval), aplikace se sama vrátí na holou **343** a účtuje jako dřív. Cena za to je, že se vstup
a výstup na jednom účtu vzájemně vynetují a firmu **přeskočí měsíční zúčtování DPH**.

> [!WARNING]
> **343 je syntetika se třemi analytikami** a přímo na ni se neúčtuje. Kde se v manuálu
> mluví o obratu účtu 343 (kontroly proti přiznání k DPH, uzávěrka, měsíční kontrola), jde vždy o součet
> syntetiky včetně analytik. Tak ho počítají i všechny sestavy, takže srovnání s přiznáním platí dál.

### 66.8.6 Měsíční zúčtování DPH

Za každé skončené zdaňovací období vznikne automaticky **interní doklad Zúčtování DPH**, který převede obrat
vstupní a výstupní daně na zúčtovací účet, přesně tak, jak to účetní dělá ručně:

```text
    MD 343.200 / D 343.900     daň na výstupu za období
    MD 343.900 / D 343.100     daň na vstupu za období
```

Po dokladu jsou 343.100 i 343.200 za období nulové a na **343.900 leží přesně to, co se odvede** (nebo co má
finanční úřad vrátit). Ten zůstatek pak uzavře platba z banky, takže je průběžně srovnatelný se saldem
u správce daně. Na plochém 343 to z principu nešlo.

<!-- cols: 26 74 -->
| Vlastnost | Chování |
|---|---|
| Číslo dokladu | `DPH-01/2026` (měsíční plátce) nebo `DPH-Q1/2026` (čtvrtletní); nečerpá z číselné řady interních dokladů |
| Popis | „Zúčtování DPH za 01/2026“; v deníku je zdroj označený jako **Zúčtování DPH** |
| Datum zápisu | **poslední den období**, takže zápis padne do správného období i při opožděném běhu |
| Kdy běží | plánovaná úloha **1. den v měsíci ve 04:30** (viz [§ 5.5](05_Po_instalaci.md#55-krok-za-krokem-naplanovani-uloh-cron)), za období obsahující předchozí měsíc |
| Opakované spuštění | doklad se **přepočítá, nikdy nezdvojí**; vlastní zúčtovací doklady se přitom z obratu vylučují, aby si samy sebe nepřičítaly |
| Zpětné dohnání | jde spustit ručně pro konkrétní období |

Do obratu se **nepočítají** uzávěrkové a otevírací zápisy, dřívější zúčtovací doklady ani nezaúčtované koncepty.

**Kdo doklad nedostane** (a proč). Vždy se to zapíše do reportu úlohy, nikdy se to nestane tiše:

<!-- cols: 40 60 -->
| Situace | Důvod |
|---|---|
| Daňová evidence | zúčtování dává smysl jen v podvojném účetnictví |
| Neplátce, který není ani identifikovaná osoba | nemá co zúčtovat |
| Vstup i výstup na témž účtu (ploché 343) | doklad by byl 343/343 |
| Některá z analytik chybí v rozvrhu nebo je neaktivní | není kam účtovat |
| Nulový vstup i výstup | doklad se nezakládá vůbec |
| Čtvrtletní plátce před koncem čtvrtletí | období ještě neskončilo; doklad počká, aby se tři měsíce po sobě nepřepisoval neúplnými čísly |

**Identifikovaná osoba** se vyhodnocuje měsíčně bez ohledu na nastavenou periodu DPH. Převažují-li dobropisy
a obrat vyjde záporný, obrátí se strany zápisu, záporná částka se nikdy neúčtuje. Chyba u jedné firmy
(uzavřené období, zámek data, chybějící analytika) běh nezastaví, jen skončí v reportu.

> [!TIP]
> Zúčtovací doklad **nenahrazuje roční vypořádání koeficientu** podle § 76 odst. 7 ZDPH. To je pořád
> ruční zápis (viz [§ 41.8](41_Vykazy_DPH.md#418-krok-za-krokem-koeficient-kraceni-odpoctu-76)).

### 66.8.7 Pravidla importu

- Identitou je kód účtu.
- `nadrizeny_ucet` označuje analytiku; rodič musí být syntetika existující v databázi nebo založená dříve
  ve stejném souboru.
- U existujícího účtu lze změnit jen název a aktivní stav. Typ, obvyklou stranu ani rodiče nelze přepsat.
- U nové syntetiky jsou povinné typ a název.
- Nová analytika přebírá typ a stranu z rodiče; odlišné hodnoty v souboru jsou nahlášeny.
- Deaktivace účtu používaného aktivní předkontací nebo nevyřazeným majetkem dostane varování. Import může
  projít, ale navazující automatické účtování bude vyžadovat opravu odkazu.
- Import **nikdy nemaže**.

Zápis importu je omezený na aktuální firmu a probíhá až po úspěšném náhledu. Selhání jednoho řádku
v potvrzeném souboru nesmí vytvořit neohlášený napůl použitelný rozvrh; výsledný report vždy uvádí založené,
změněné, přeskočené a chybné řádky.

### 66.8.8 Ochrany při účtování

Vedle existence a aktivity účtu hlídá centrální účetní služba i následující invarianty:

- účty typu **uzávěrkový** lze použít jen v řízeném závěrkovém nebo otevíracím
  zápisu; ochrana zahrnuje například 701/702/710 v rámci 500 a 961/962 v rámci 504,
- podrozvahové účty 75x/79x se nesmějí v jednom zápisu míchat s rozvahovým či výsledkovým účtem,
- každý zápis musí být vyrovnaný na haléř,
- datum musí patřit do otevřeného období a nesmí spadat do zámku účtování k datu.

Tyto kontroly běží na serveru i při volání API. Omezení webového výběru proto nelze obejít vlastním požadavkem.

## 66.9 Související kapitoly

- [Šablony a pravidla](65_Sablony.md)
- [Účetní nástroje a předkontace](73_Ucetni_nastroje.md)
- [Aktivace účetnictví](68_Aktivace_ucetnictvi.md)
- [Hlavní kniha](55_Hlavni_kniha.md)
- [Účetní deník](52_Ucetni_denik.md)
