# Retenční lhůty

## Účel

Agenda retenčních lhůt určuje, jak dlouho se jednotlivé kategorie mzdových záznamů a dokumentů uchovávají a které položky se blíží konci stanovené doby.

## Předpoklady a oprávnění

Uživatel potřebuje `payroll.retention`. Firma musí mít schválená pravidla uchování zohledňující zákonné povinnosti, právní nároky, probíhající řízení a vlastní oprávněné potřeby.

## Krokový postup

1. Otevřete **Mzdy → Retenční lhůty** a projděte kategorie údajů.
2. Ověřte délku lhůty, počátek jejího běhu a právní důvod.
3. Zkontrolujte seznam záznamů, kterým lhůta končí, a případné blokace výmazu.
4. Před rozhodnutím ověřte vazby na dokumenty, běhy, platby, účetnictví, podání a otevřená řízení.
5. Samotný výmaz spouštějte jen v oddělené agendě a po schválení odpovědnou osobou.

## Stavy

Záznam může být v aktivní retenční době, blízko konce, po lhůtě nebo blokovaný právním důvodem. Uplynutí lhůty není automatickým výmazem a blokace má přednost před plánovaným odstraněním.

## Kontroly a bezpečnost

Retenci pravidelně revidujte a používejte kontrolu druhou osobou. Nezkracujte lhůty jen kvůli úspoře místa. Přehledy obsahují osobní údaje a musí mít omezený přístup. Export seznamu nemažte ani nesdílejte bez stejné ochrany jako původní data.

## Časté chyby

- Počítání lhůty od data vložení místo právně rozhodné události.
- Ignorování probíhajícího sporu, kontroly nebo exekuce.
- Domněnka, že stav „po lhůtě“ data automaticky odstranil.
- Posouzení jen databázového záznamu bez dokumentů a exportů.

## Návaznosti

Fyzický proces popisuje [výmaz osobních údajů](58s_Vymaz_osobnich_udaju.md). Uchovávané výstupy vznikají v [dokumentech](58h_Dokumenty_a_vystupy.md) a [podáních](58j_Podani_a_hlaseni.md).



## Podrobný pracovní postup a kontroly

Mzdový modul drží nejcitlivější osobní údaje v aplikaci a nesmí je držet
navždy ani je zahodit dřív, než smí. Přehled **Mzdy → Retenční lhůty** ukazuje,
jak dlouho se která skupina mzdových dat uchovává, od kdy lhůta běží a kde to
stojí psané. Otevřít ho může role s oprávněním `payroll.retention`.

Odsud se nic nemaže. Uplynulá lhůta je konec povinnosti uchovávat, ne příkaz
ke skartaci. Nastavit jde dvojí: **odchylka firmy** od katalogové lhůty
a **zadržení výmazu** konkrétní osoby. Samotný výmaz má vlastní obrazovku
([Výmaz osobních údajů](58s_Vymaz_osobnich_udaju.md)).

U každé kategorie je vidět:

- **Lhůta** — počet let, podle kterého se opravdu počítá, tedy včetně
  případného prodloužení, které si firma sama dohodla.
- **Běží od** — kalendářní roky po roce, kterého se záznam týká, roky po roce
  vyhotovení, nebo roky od konce účetního období.
- **Právní pramen** — konkrétní ustanovení, ne jen číslo zákona, a u lhůt,
  jejichž číslo se v posledních letech měnilo, i novela, která dnešní znění
  zavedla.
- **Ověřeno** — den, ke kterému se citace porovnala s účinným zněním předpisu.
- **Dotčené tabulky** — čeho přesně se lhůta drží.

### Původ lhůty

Nejdůležitější sloupec není číslo, ale odkud se vzalo. Rozlišují se tři stavy
a jejich počty stojí jako dlaždice nad tabulkou, takže rozdíl je vidět hned:

- **Ze zákona** — číslo stojí v předpise a pramen říká kde.
- **Dodaná politika** — číslo dodala aplikace, protože zákon pro tuhle skupinu
  záznamů uschovávací lhůtu nemá. Týká se to zdravotního pojištění: v zákoně
  č. 592/1992 Sb. žádná uschovávací lhůta není, deset let je bezpečné
  rozhodnutí, ne právo, a přehled to říká nahlas.
- **Bez lhůty** — doloženo, že předpis lhůtu nestanoví. Spis k exekučním
  srážkám žádnou uschovávací lhůtu nemá: v občanském soudním řádu se
  uschovávání týká jen prodeje nevyzvednutých movitých věcí a v exekučním řádu
  je povinnost uložena exekutorovi, ne plátci mzdy.

Kategorie bez lhůty se k výmazu **nikdy** nenavrhne, dokud lhůtu nedodá firma
vlastní politikou. Sloupec **Výmaz** to u každé kategorie říká přímo.

### Co z lhůt plyne pro výmaz

Spodní panel přepočítá lhůty na konkrétní osoby k zadanému dni: kolik jich lze
navrhnout k výmazu a — hlavně — proč se ostatní nenavrhly. Rozlišuje běžící
retenční lhůtu, zadržení výmazu (kontrola, odvolání, spor, exekuce,
insolvence), neurčenou lhůtu, chybějící základ výpočtu a osoby, které už
anonymizované jsou. Návrh, který někoho mlčky vynechá, se nedá zkontrolovat.

Panel osoby **jmenuje**, nejen počítá: kdo je na řadě k výmazu a koho drží
zadržení. Nevratný úkon se podle samotného čísla odklepnout nedá.

Lhůty účetních a daňových záznamů firmy jako celku (§ 31 a § 32 zákona
o účetnictví) mají vlastní přehled na **Účetnictví → Retenční lhůty**.

### Odchylka firmy od katalogové lhůty

Tlačítko **Odchylka firmy** na řádku kategorie otevře jeden formulář s jedním
tlačítkem Uložit. Zadává se v něm:

- **Prodloužení o (roky)** — přičte se ke lhůtě z katalogu. Použije se, když
  firmu váže smlouva nebo vnitřní předpis s delší lhůtou.
- **Dodaná lhůta (roky)** — nabídne se **jen** u kategorií, které lhůtu
  v katalogu nemají (dnes spis k exekučním srážkám). Dokud ji nikdo nedodá,
  osoba se k výmazu nikdy nenavrhne.
- **Zdůvodnění** — povinné. Odchylka od zákonné lhůty musí být doložitelná
  a ukládá se s ní.

**Lhůtu nelze zkrátit.** Není to omezení formuláře, ale pravidlo aplikace:
zkrácení pod hodnotu z katalogu — ať už zákonnou, nebo dodanou politikou —
se odmítne s vysvětlením, které řekne, odkud lhůta pochází. Odchylku jde
kdykoli zrušit tlačítkem **Zrušit odchylku**; lhůta se tím vrátí na hodnotu
z katalogu.

### Zadržení výmazu (legal hold)

Zadržení drží data osoby i po uplynutí lhůty — kvůli daňové kontrole,
odvolání, soudnímu sporu, exekuci nebo insolvenci (§ 32 zákona o účetnictví
a mzdové důvody). Zadává se tlačítkem **Zadržet výmaz** a vyžaduje osobu,
důvod, č. j. nebo popis řízení a datum. Bez popisu se neuloží: jinak by
nešlo doložit, proč se výmaz zadržel.

Dokud zadržení trvá, osoba se k výmazu nenavrhne a už schválený návrh ji
přeskočí. **Uvolnění** je vědomý úkon a potvrzuje se — výmaz osoby tím zase
půjde navrhnout a provést. Uvolněný záznam nezmizí, jen dostane datum
uvolnění; zaškrtnutím **Zobrazit i uvolněná** se zobrazí i historie.

Zadržení zadané na účetní straně (**Účetnictví → Retenční lhůty**) platí na
celou firmu, a tedy i na mzdy. Opačně to neplatí: zadržení jedné osoby
nemá co blokovat mazání faktur.
