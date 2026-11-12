# 46. Účetní kontroly a inventarizace

> Návod, jak v MyÚčtu projít měsíční a roční účetní kontroly a jak provést
> inventarizaci rozvahových účtů. Pro účetní a každého, kdo před podáním daní
> nebo uzávěrkou ověřuje, že účetnictví sedí s nezávislými podklady.

## 46.1 Kdy to potřebujete

Kapitolu otevřete, když:

- končí měsíc a chcete ověřit, že jsou všechny doklady zaúčtované a zaplacené
  doklady spárované,
- se blíží podání DPH a potřebujete vysvětlit mezery v číslování vydaných
  dokladů,
- máte cizoměnové doklady a chcete ověřit kurz proti ČNB,
- je konec roku a připravujete inventarizaci rozvahových účtů,
- připravujete schválení účetní závěrky.

<!-- cols: 24 44 32 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| každý měsíc | Projít kontroly podle [§ 46.3](#463-krok-za-krokem-mesicni-kontrola) | `Účetnictví → K doúčtování`, `Úplnost dokladů`, `Měsíční kontrola` |
| před podáním DPH | Vysvětlit mezery v číslování vydaných dokladů, [§ 46.6](#466-krok-za-krokem-uplnost-ciselne-rady-vydanych-dokladu) | `Daně → Úplnost číselné řady` |
| při cizoměnových dokladech | Ověřit kurz proti ČNB, [§ 46.5](#465-krok-za-krokem-audit-kurzu-cnb) | `Daně → Audit kurzů (ČNB)` |
| k rozvahovému dni | Zadat skutečný stav účtů, [§ 46.4](#464-krok-za-krokem-inventarizace-rozvahovych-uctu) | `Nástroje → Inventarizace účtů` |
| před schválením závěrky | Doložit podklady k uzávěrce, [§ 46.7](#467-krok-za-krokem-priprava-podkladu-k-zaverce) | `Nástroje → Uzávěrka`, uzávěrkový balíček |

## 46.2 Než začnete

1. **Podvojné účetnictví.** Kontroly v sekci Účetnictví a inventarizace jsou
   dostupné firmám s aktivním podvojným účetnictvím.
2. **Právo k účetnictví.** Položky `K doúčtování` a `Úplnost dokladů` se
   zobrazí jen uživateli s právem k účetnictví, `Měsíční kontrola` vyžaduje
   právo číst účetnictví.
3. **Nezávislé podklady po ruce.** Bankovní výpisy, výpis ze saldokonta, podaná
   přiznání, inventurní soupisy. Systém sám neprokáže, že účetnictví odpovídá
   skutečnosti (viz [§ 46.9.1](#4691-tri-vrstvy-kontroly)).
4. **Doklady dokončené.** Koncepty a nezaúčtované doklady zkreslují zůstatky
   i výsledek kontrol.

> [!WARNING]
> Prázdná fronta nebo zelený technický check není důkaz, že nechybí smlouva,
> přijatá faktura, závazek, majetek nebo časové rozlišení, které systém neměl
> z čeho rozpoznat.

## 46.3 Krok za krokem: měsíční kontrola

1. Otevřete `Účetnictví → K doúčtování` a dokončete nebo vysvětlete položky
   ve frontě (viz [K doúčtování](54_Rucni_fronta_doctovani.md)).
2. Otevřete `Účetnictví → Úplnost dokladů` a projděte bankovní pohyby bez
   dokladu a neuhrazené doklady po splatnosti (viz
   [Úplnost dokladů](61_Uplnost_dokladu.md)).
3. Otevřete `Účetnictví → Měsíční kontrola`, zvolte účetní období a přesný
   měsíc nebo čtvrtletí a projděte nálezy (viz
   [Měsíční kontrola](62_Mesicni_kontrola.md)).
4. Porovnejte banku a pokladnu s výpisy, saldokonto s knihou pohledávek
   a závazků a účet 343 s DPH za stejné období.
5. U cizoměnových dokladů spusťte kontrolu podle
   [§ 46.5](#465-krok-za-krokem-audit-kurzu-cnb) a vysvětlete významné odchylky.
6. Před podáním přiznání DPH proveďte kontrolu podle
   [§ 46.6](#466-krok-za-krokem-uplnost-ciselne-rady-vydanych-dokladu).
7. Opravte zdrojové doklady a kontroly spusťte znovu.
8. Po potvrzeném podání DPH vědomě zamkněte období k datu a uložte důvod
   (viz [Měsíční kontrola](62_Mesicni_kontrola.md#624-krok-za-krokem-zamek-uctovani-k-datu)).

**Jak poznáte, že je hotovo:** Fronta **K doúčtování** je vyřízená, měsíční
kontrola nehlásí nevysvětlené nálezy a každý dříve nalezený rozdíl byl
odstraněn opravou zdroje nebo doložen podkladem.

> [!TIP]
> Nález se nemá „zazelenat“ ručním dorovnáním bez podkladu. Kontroly jsou
> jen ke čtení, pokud u nich není výslovně tlačítko pro vytvoření návrhu
> nebo zápisu.

## 46.4 Krok za krokem: inventarizace rozvahových účtů

1. Otevřete `Nástroje → Inventarizace účtů`.
2. V poli **Období** zvolte účetní období. Skutečný stav lze ukládat jen
   v období ve stavu otevřené nebo uzavírané.
3. Projděte nahoře upozornění. Má-li období nezaúčtované koncepty, stránka
   jejich počet uvede; do zůstatků se nezapočítají.
4. V části **Inventarizační protokol** vyplňte **Odpovědnou osobu**, **Datum
   inventury** a **Odkaz na protokol** (číslo jednací nebo odkaz na podepsaný
   protokol).
5. U každého účtu zjistěte skutečný stav z nezávislého podkladu (sloupec
   **Způsob doložení** napovídá, z jakého) a zapište ho do sloupce
   **Skutečný stav**. Zadávejte na normální straně účtu, u pasivních účtů tedy
   kladné číslo.
6. Zkontrolujte sloupec **Rozdíl**. Nulový rozdíl se považuje za vyřešený
   automaticky.
7. U nenulového rozdílu zaškrtněte **Vyřešeno**, jen když jej umíte doložit,
   a do pole **Poznámka k rozdílu** napište vysvětlení. Samotné zaškrtnutí nic
   nezaúčtuje; opravu proveďte zdrojovým nebo ručním zápisem.
8. Klikněte na **Uložit**, chcete-li protokol ponechat rozpracovaný.
9. Jsou-li všechny účty vyřešené, klikněte na **Uložit a dokončit**.
10. Chcete-li soupis vytisknout, klikněte na **Export PDF** nebo
    **Export XLSX**.

**Jak poznáte, že je hotovo:** Protokol má štítek **Dokončeno** a u počtu
nevyřešených rozdílů je nula. Zobrazí se hláška „Inventarizace dokončena.“

> [!WARNING]
> Nezahájená inventarizace je při uzavření knih jen varování, protože firma
> ji může vést mimo aplikaci. Jakmile ji ale v aplikaci založíte, rozpracovaný
> stav nebo nevyřešené rozdíly jsou chybou, která uzavření knih blokuje. Po
> změně deníku se zůstatky přepočtou znovu, takže dříve dokončený protokol
> může vyžadovat nové odsouhlasení.

Podrobný popis stránky je v kapitole
[Inventarizace účtů](69_Inventarizace_rozvahovych_uctu.md).

## 46.5 Krok za krokem: audit kurzů ČNB

1. Otevřete `Daně → Audit kurzů (ČNB)`.
2. Zvolte rok a nastavte **Práh odchylky** v procentech.
3. Projděte nalezené doklady. U každého vidíte kurz na dokladu, kurz ČNB,
   odchylku a dopad v Kč; číslo dokladu vede na detail.
4. U odchylky rozhodněte, zda je správná (doložený pevný kurz, kurz celní
   hodnoty nebo jiný zákonný postup), nebo ji je třeba opravit.
5. Bez doloženého důvodu opravte kurz na zdrojovém dokladu a použijte řízené
   přeúčtování, aby zůstala auditní stopa.

**Jak poznáte, že je hotovo:** Stránka hlásí, že žádný doklad nepřekračuje
práh odchylky, nebo je každá zbylá odchylka doložená.

## 46.6 Krok za krokem: úplnost číselné řady vydaných dokladů

1. Otevřete `Daně → Úplnost číselné řady`.
2. Zvolte **Rok**.
3. Projděte jednotlivé řady. U každé vidíte období, rozsah, počet použitých
   čísel a chybějící čísla.
4. U každé mezery dohledejte příčinu: stornovaný a přesto číslovaný doklad,
   ruční přečíslování, oprávněné vynechání.
5. Mezeru vysvětlete a vysvětlení uložte mezi podklady. Číslo zpětně
   nedoplňujte.

**Jak poznáte, že je hotovo:** Stránka hlásí, že řada je bez mezer, nebo má
každá nalezená mezera zdokumentované vysvětlení.

> [!TIP]
> Sestava jen hlásí, nic neopravuje. Mezera sama o sobě chybu nedokazuje,
> ale je typickým kontrolním bodem finančního úřadu.

## 46.7 Krok za krokem: příprava podkladů k závěrce

1. Dokončete inventarizaci rozvahových účtů podle
   [§ 46.4](#464-krok-za-krokem-inventarizace-rozvahovych-uctu).
2. Opatřete externí bankovní a partnerská potvrzení.
3. Připravte smlouvy a výpočty k významným dohadům a časovému rozlišení.
4. Uložte potvrzení podaných daňových formulářů.
5. Vysvětlete nevyřešená varování a nechte je schválit odpovědnou osobou.
6. Teprve potom schvalte závěrku a vygenerujte uzávěrkový balíček (viz
   [Uzávěrka](72_Uzaverka.md)).

**Jak poznáte, že je hotovo:** Ke každé oblasti ze seznamu máte uložený
podklad a schválení odpovědnou osobou.

## 46.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Na stránce Inventarizace účtů nelze zadávat skutečný stav | Období je uzavřené, skutečný stav lze ukládat jen v otevřeném nebo uzavíraném období | Zvolte otevřené nebo uzavírané období. U uzavřeného roku je stav doplněný z účetních zůstatků jen pro čtení. |
| „Nelze dokončit“ s počtem nevyřešených rozdílů | Zbývá účet s nenulovým rozdílem bez označení **Vyřešeno** | Rozdíl doložte a označte, nebo opravte zdrojový zápis. |
| Dříve dokončená inventarizace je zase nevyřešená | Po uložení se změnil deník a rozdíl se přepočítal | Znovu odsouhlaste dotčené účty a uložte. |
| Při uzavření knih jde o chybu inventarizace | Inventarizace je v aplikaci založená, ale rozpracovaná nebo s rozdíly | Dokončete ji podle [§ 46.4](#464-krok-za-krokem-inventarizace-rozvahovych-uctu). |
| Hláška, že firma používá pevný kurz, a audit kurzů se nespustil | Odchylka od ČNB je u pevného kurzu záměrná a zákonná | Nic neopravujte. |
| Audit kurzů ukazuje počet **Bez kurzu ČNB** | Pro rozhodný den chybí kurz ČNB | Ověřte datum dokladu a měnu. |
| Sestava číselné řady hlásí extrémně mnoho chybějících čísel | Ručně zadané nebo importem rozbité číslo vystřelilo řadu nahoru | Zkontrolujte nejvyšší doklad v období. |
| Importované doklady tvoří „Rozpoznanou řadu“ | Číslování z jiného programu nemá odpovídající šablonu | Ověřte, že doklady patří do stejné řady (viz [§ 46.9.7](#4697-uplnost-ciselne-rady-pravidla)). |
| Měsíční kontrola ukazuje nález mimo zvolený měsíc | Celoroční invarianty se posuzují za celé období | Řešte nález podle [Měsíční kontroly](62_Mesicni_kontrola.md). |
| Exportovaný soupis neobsahuje poslední změny skutečných stavů | Formulář nebyl před exportem uložen | Nejprve uložte protokol a pak export zopakujte. Export přebírá uložené hodnoty ([§ 46.9.4](#4694-inventarizace-pravidla)). |

## 46.9 Podrobnosti a pravidla

### 46.9.1 Tři vrstvy kontroly

MyÚčto nabízí několik kontrolních pohledů, ale žádný z nich sám neprokazuje
věcnou správnost účetnictví. Co systém pouze signalizuje, musí účetní ověřit
nezávislým podkladem.

1. **Provozní úplnost** - [K doúčtování](54_Rucni_fronta_doctovani.md)
   a [Úplnost dokladů](61_Uplnost_dokladu.md) hledají známé položky bez
   dokončeného zpracování.
2. **Účetní integrita** - měsíční kontrola, deník, předvaha a saldokonto hledají
   nevyrovnané zápisy, neobvyklé zůstatky, nezpracované úhrady a chybějící
   návaznosti.
3. **Inventarizace a daňová rekonciliace** - účetní zůstatek se porovnává
   s fyzickým, smluvním nebo externě potvrzeným skutečným stavem.

### 46.9.2 Provozní úplnost dokladů

Podrobný popis obou pracovních pohledů je rozdělený podle bodů menu:

- [K doúčtování](54_Rucni_fronta_doctovani.md) je operativní fronta skutečných
  bankovních pohybů bez návrhu, nezaúčtovaných dokladů a otevřených žádostí,
- [Úplnost dokladů](61_Uplnost_dokladu.md) kontroluje bankovní pohyby bez
  podkladu a opačným směrem otevřené saldo po splatnosti.

Pohyb může být oprávněně bez faktury, například daň, pojistné, bankovní
poplatek, převod mezi vlastními účty nebo výplata. Požadavek na chybějící
dokument pouze eviduje komunikaci; případ je vyřešený až po doručení,
kontrole a správném zaúčtování podkladu.

### 46.9.3 Audit párování plateb

[Měsíční kontrola](62_Mesicni_kontrola.md) porovnává stav úhrad, vazby
bankovních pohybů a otevřené saldo. Typické nálezy:

- doklad označený jako zaplacený bez odpovídající úhrady,
- aktivní úhrada k dokladu, který stále vystupuje jako neuhrazený,
- spárovaná záloha a finální doklad se špatným zůstatkem,
- částka nebo měna platby neodpovídá vazbě,
- vlastní převod nemá obě strany účtu 261.

Opravujte vazbu platby nebo účetní zápis. Neměňte ručně jen stav dokladu,
pokud by tím evidence úhrad a deník přestaly odpovídat skutečnosti.

### 46.9.4 Inventarizace: pravidla

Sestava vytvoří k rozvahovému dni soupis konečných zůstatků účtů typu
aktiva, pasiva a vlastní kapitál. V rámci 504 zahrnuje i příslušné účty 9xx;
výběr se neomezuje na číselné třídy 0-4.
Číslo účtu vede na opis a doporučený druh podkladu napovídá, čím zůstatek
doložit:

<!-- cols: 34 66 -->
| Oblast | Typický nezávislý podklad |
|---|---|
| Banka 221 | bankovní výpis nebo potvrzení banky |
| Pokladna 211 | fyzická inventura hotovosti |
| Pohledávky a závazky 311/321 | saldokonto, potvrzení partnera, následná úhrada |
| DPH 343 | podané přiznání, potvrzení a platební historie |
| Majetek 0xx | inventární karta, fyzické ověření, odpisový plán |
| Časové rozlišení a dohady | smlouva, období plnění, výpočet a schválení |
| Daně a pojistné | přiznání, přehled, předpis a platba |

Zůstatky se počítají z celého fiskálního období před závěrkovými převody na
účty 702/710. Koncepty se do nich nezapočítají; pokud v období existují,
stránka na jejich počet samostatně upozorní.

Dokončení je možné jen tehdy, když není žádný nevyřešený účet. Nulový rozdíl
se považuje za vyřešený automaticky; nenulový rozdíl musí účetní výslovně
označit za vyřešený a doložit mimo samotný číselný údaj. Poznámka u řádku je
v aplikaci volitelná. Uložení kontroluje verzi období a zapisuje auditní
událost, takže souběžná změna účetnictví nemůže být tiše překryta starým
formulářem.

Export PDF/XLSX přebírá **uložený inventarizační protokol**: hlavičku,
účetní i skutečné stavy, rozdíly, poznámky a podpisová pole. Dosud
nevyplněné skutečné stavy zůstanou prázdné k doplnění. Před exportem
formulář uložte. Při archivaci přiložte i podepsané důkazy a externí potvrzení
podle vnitřní směrnice. Starší již uzavřené období bez uložené inventarizace
je pouze pro čtení a aplikace jeho stav zpětně odvodí z ověřených účetních
zůstatků.

### 46.9.5 Kontrolní mapa K1-K11

<!-- cols: 10 28 62 -->
| Kód | Oblast | Co je rozhodující |
|---|---|---|
| **K1** | technické a clearingové účty | Nenulový zůstatek musí mít konkrétní případ a podklad; nemusí být automaticky chybou. |
| **K2** | neobvyklá strana účtu | Rozlište přeplatek či dobropis od obrácené kontace. |
| **K3** | úhrady a saldokonto | Stav dokladu, vazby plateb a účetní saldo musí vyprávět stejný příběh. |
| **K4** | kurz proti ČNB | Odchylku lze ponechat jen s doloženým kurzem nebo pevnou kurzovou politikou. |
| **K5** | položky proti hlavičce | Rozdíl nad toleranci opravte na dokladu; nevytvářejte nevysvětlené dorovnání. |
| **K6** | měnová stopa | Cizoměnový zůstatek musí nést měnu i původní částku pro přecenění. |
| **K7** | podvojnost a rozvaha | Nevyrovnaný deník nebo aktiva ≠ pasiva je strukturální bloker. |
| **K8** | kolize variabilních symbolů | Nejednoznačné shody potvrďte podle partnera, částky, měny a data. |
| **K9** | podané přiznání | Porovnávejte s XML, které bylo skutečně odesláno, nikoli jen vygenerováno. |
| **K10** | závěrková úplnost | Odpisy, rozlišení, opravné položky a opakované náklady jsou návrhy k odbornému posouzení. |
| **K11** | úplnost číselné řady | Mezera v číslování vydaných dokladů je auditní signál pro FÚ. Dohledejte a vysvětlete, nedoplňujte číslo zpětně. |

Podrobný rozpad průběžných kontrol a možnost exportovat všechna zjištění jsou
v kapitole [Měsíční kontrola](62_Mesicni_kontrola.md).

### 46.9.6 Kurzy ČNB: pravidla

Audit porovná uložený kurz dokladu s kurzem ČNB k rozhodnému dni a vypíše
odchylku. Neopravuje doklady ani deník. Odchylka může být správná, pokud firma
používá doložený pevný kurz, kurz celní hodnoty nebo jiný zákonný postup.
Používá-li firma pevný kurz podle § 24 odst. 7 ZoÚ, audit se pro ni nespouští.
Dopad v Kč je účetní přepočet (563/663); korunové částky DPH na dokladu se
netýká (§ 73 odst. 6 ZDPH).

Kurz na faktuře, kurz bankovní úhrady a závěrkový kurz plní různé účely.
Jejich rozdíl se nemá odstranit přepsáním historie; tvoří realizovaný nebo
nerealizovaný kurzový rozdíl podle povahy položky.

### 46.9.7 Úplnost číselné řady: pravidla

Sestava projde číslování vydaných faktur a dobropisů za zvolený rok
a nahlásí chybějící čísla v jinak souvislé řadě. Mezera v číselné řadě je
typický kontrolní bod finančního úřadu. Nedokazuje sama o sobě chybu, ale
musí mít vysvětlení (stornovaný a přesto číslovaný doklad, ruční přečíslování,
oprávněné vynechání). Sestava jen hlásí, nic neopravuje.

Faktury a dobropisy, které mají nastavenou **stejnou číselnou šablonu**,
sestava posuzuje jako jednu společnou řadu: číslo použité dobropisem se
u faktur nehlásí jako chybějící a naopak. Má-li klient v nastavení vlastní
šablonu číslování (`Systém → Firmy → Číslování faktur`), počítá se jako
samostatná, nezávislá řada. Kolize dvou různých šablon (dvě řady vyprodukující
stejný VS) hlásí samostatná kontrola v nastavení dodavatele, viz **K8**
v [§ 46.9.5](#4695-kontrolni-mapa-k1-k11).

Doklady importované s jiným číslováním se mohou zobrazit jako **odvozená
řada** i bez odpovídající současné šablony. Sestava ji rozpozná, pokud číslo
obsahuje rok shodný s rokem vystavení a má stejně široký číselný konec.
Kontroluje jen mezery mezi nejnižším a nejvyšším nalezeným číslem; začátek
případného částečného importu nezná. Čísla bez bezpečně rozpoznatelného
vzoru sestava do odvozené řady nezařadí. Nejednoznačné konce začínající
číslem měsíce vynechá, aby měsíční číslování nehlásilo falešné mezery
v roční řadě.

### 46.9.8 Vazba na uzávěrku a balíček

Uzávěrkový balíček umí shromáždit účetní výkazy, deník, knihu DPH, daňový
výstup, inventuru dlouhodobého a drobného majetku, staré saldo a časová
rozlišení. Balíček není účetní archiv a jeho dokončení není potvrzením
správnosti jednotlivých částí.

Před schválením závěrky ověřte a případně doplňte zejména:

- inventarizaci rozvahových účtů a podepsané inventurní soupisy,
- externí bankovní a partnerská potvrzení,
- smlouvy a výpočty k významným dohadům a časovému rozlišení,
- potvrzení podaných daňových formulářů,
- vysvětlení nevyřešených varování a schválení odpovědnou osobou.

## 46.10 Související kapitoly

- [K doúčtování](54_Rucni_fronta_doctovani.md)
- [Úplnost dokladů](61_Uplnost_dokladu.md)
- [Měsíční kontrola](62_Mesicni_kontrola.md)
- [Inventarizace účtů](69_Inventarizace_rozvahovych_uctu.md)
- [Uzávěrka](72_Uzaverka.md)
- [Kniha DPH](42_Kniha_DPH.md)
