# 42. Kniha DPH

> Návod, jak pomocí Knihy DPH dohledat, z jakých dokladů a řádků vzniklo
> přiznání DPH a kontrolní hlášení, a jak ji zkontrolovat před podáním.
> Pro plátce DPH, účetní a každého, kdo za firmu podává DPH.

## 42.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se blíží podání přiznání DPH a chcete si ověřit, že v období nic nechybí,
- potřebujete zjistit, z jakých dokladů vznikl konkrétní řádek přiznání,
- vám součet v knize nesedí s náhledem přiznání,
- přijatý doklad skončil v jiném měsíci, než jste čekali,
- potřebujete pracovní podklad v PDF pro účetní nebo auditora,
- finanční úřad chce soupis dokladů uvedených v oddílu kontrolního hlášení,
- potřebujete DPH po střediscích, projektech nebo jiných dimenzích.

Kniha DPH je interní kontrolní sestava za měsíc nebo čtvrtletí. Není
formulářem EPO, neodesílá se správci daně a její PDF se neukládá do Archivu
podání. Kde se podává přiznání a kontrolní hlášení, popisuje kapitola
[Výkazy DPH](41_Vykazy_DPH.md).

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| před každým podáním DPH | Projít knihu za stejné období jako přiznání a odstranit koncepty a chybějící klasifikace | `Daně → Kniha DPH`, postup v [§ 42.3](#423-krok-za-krokem-kontrola-knihy-pred-podanim-dph) |
| když doklad nesedí do období | Dohledat, podle jakého data se doklad zařadil | sloupec **Období odpočtu**, postup v [§ 42.4](#424-krok-za-krokem-proc-je-doklad-v-jinem-obdobi) |
| po podání, změnil-li se doklad | Posoudit, zda je nutné opravné nebo dodatečné podání | `Daně → DPH přiznání`, fronta změn po podání |
| při kontrole finančního úřadu | Vytisknout soupis dokladů oddílu KH (např. A.4) se součty | pole **Řádek přiznání / oddíl KH**, postup v [§ 42.5](#425-krok-za-krokem-soupis-dokladu-oddilu-kh-pro-kontrolu-financniho-uradu) |
| když sledujete DPH po střediscích | Zobrazit knihu nebo soupis KH jen za hodnotu dimenze | filtr **Dimenze** v knize, postup v [§ 42.6](#426-krok-za-krokem-kniha-dph-a-soupis-kh-podle-dimenze) |
| před podáním, máte-li dimenze | Rozdělit DPH za období po hodnotách dimenze a ověřit součet proti přiznání | `Daně → DPH podle dimenze`, postup v [§ 42.7](#427-krok-za-krokem-sestava-dph-podle-dimenze) |

## 42.2 Než začnete

Kniha čerpá ze stejné řádkové evidence DPH jako přiznání a kontrolní hlášení.
Aby byla úplná, potřebujete:

1. **Zaúčtované a klasifikované doklady.** Každý vystavený i přijatý doklad
   v období musí mít správnou klasifikaci DPH a u cizí měny kurz. Chybějící
   nebo neplatný kurz či klasifikaci opravte na zdrojovém dokladu, ne v knize.
2. **Oprávnění k exportu sestav.** Tlačítko **Stáhnout PDF** se zobrazí jen
   uživateli s tímto oprávněním (`reports.export`). Prohlížet knihu na
   obrazovce můžete i bez něj.
3. **Správné datum přijetí u přijatých dokladů.** Ovlivňuje, do kterého období
   se odpočet zařadí (viz [§ 42.4](#424-krok-za-krokem-proc-je-doklad-v-jinem-obdobi)).

## 42.3 Krok za krokem: kontrola knihy před podáním DPH

1. Otevřete `Daně → Kniha DPH`.
2. Nahoře přepněte **Měsíčně** nebo **Kvartálně** a zvolte měsíc (u čtvrtletí
   Q1 až Q4) a rok. Zvolte totéž období, za které podáváte přiznání.
   Výchozí režim odpovídá nastavení firmy; období se přebírá ze společné volby
   výkazů DPH během sezení. Bez uložené volby se otevře poslední uzavřený měsíc
   nebo čtvrtletí. Konkrétní období v odkazu má přednost.
3. Projděte řádky označené štítkem **Koncept**. Kniha koncepty záměrně
   zahrnuje, ostré přiznání a kontrolní hlášení je neobsahují. Každý koncept
   dokončete, nebo zrušte.
4. Zkontrolujte, že u dokladů nechybí klasifikace DPH. Chybějící klasifikaci
   doplňte na zdrojovém dokladu.
   Číslo vystavené nebo přijaté faktury či pokladního dokladu otevře jeho
   detail. Máte-li knihu vlevo a vedle ní další pracovní panel, detail se
   otevře vpravo; v jednom panelu se otevře na stejném místě.
5. Porovnejte součty sekcí **PŘIJATÁ** a **USKUTEČNĚNÁ** s řádky náhledu
   přiznání na `Daně → DPH přiznání`. Částky se v evidenci drží na haléře,
   zatímco XML přiznání zaokrouhluje jednotlivé formulářové údaje na celé Kč.
   Rozdíl několika korun proto může být jen důsledkem zákonného zaokrouhlení.
6. Zkontrolujte ve sloupci **KH**, do které sekce kontrolního hlášení doklad
   půjde. Limit 10 000 Kč se posuzuje z absolutní celkové částky dokladu
   včetně DPH. Přesně 10 000 Kč patří do souhrnné sekce A.5/B.3, individuální
   A.4/B.2 je až nad limitem a vyžaduje DIČ.
7. Klikněte na **Stáhnout PDF**, chcete-li mít pracovní podklad.

Z konkrétního řádku na stránce **DPH přiznání** otevřete nabídku **…** v posledním sloupci. Kniha se otevře za stejné období s filtrem na tento řádek. Pole **Řádek přiznání / oddíl KH** umožňuje řádek změnit nebo volbou **Celá kniha DPH** filtr zrušit. Filtr zachovává odlišné sloupce řádku, například `43` a `43k`. Zobrazují se jen příslušné sekce a jejich mezisoučty; celkové součty období a PDF celé knihy jsou dostupné po zrušení filtru.

Z nabídky **…** v posledním sloupci oddílu na stránce **Kontrolní hlášení** se otevře soupis pouze tohoto oddílu. Při více pracovních panelech mají oba prokliky přednostně cíl vpravo od výkazu, pokud je tam další panel.

**Jak poznáte, že je hotovo:** V knize nezůstal žádný **Koncept** ani doklad
bez klasifikace a součty odpovídají náhledu přiznání (až na zákonné
zaokrouhlení). V dolní části knihy vidíte **Výslednou DPH** jako **Vlastní
daňovou povinnost** nebo **Nadměrný odpočet**.

> [!WARNING]
> Důkazem podání je až potvrzení z portálu, nikoli PDF z Knihy DPH. Kniha
> ukazuje aktuální data; s XML, které bylo skutečně odesláno, porovnává
> doklady jen soupis podle podaného KH ([§ 42.5](#425-krok-za-krokem-soupis-dokladu-oddilu-kh-pro-kontrolu-financniho-uradu)). Změnil-li se
> doklad po stažení podkladů, použijte ve DPH přiznání frontu změn po podání
> a znovu posuďte, zda je nutné opravné nebo dodatečné podání.

## 42.4 Krok za krokem: proč je doklad v jiném období

Přijatý doklad se v knize někdy objeví v jiném měsíci, než kdy nastalo plnění.
Je to správně, pokud jde o období, ve kterém jste doklad mohli mít k dispozici.

1. V `Daně → Kniha DPH` najděte doklad v tabulce.
2. Ve sloupci **Období odpočtu** přečtěte datum a pod ním důvod zařazení:
   *dle DUZP*, *dle data vystavení*, nebo *dle data přijetí*. Najetím myši
   zobrazíte vysvětlení.
3. Je-li u data hvězdička, posunulo odpočet datum přijetí dokladu. V PDF je za
   hvězdičkou rozhodné datum.
4. Odpovídá-li datum přijetí skutečnosti, nic nedělejte.
5. Neodpovídá-li, klikněte na číslo dokladu a v jeho detailu opravte
   **datum přijetí**. Editor u dat rovnou píše, do jakého období odpočet půjde
   a proč.
6. Vraťte se do knihy a ověřte, že doklad je ve správném období.

**Jak poznáte, že je hotovo:** Doklad je v období, ve kterém jste ho skutečně
získali, a u data už není hvězdička s neodpovídajícím datem.

> [!TIP]
> U dokladu z importu nebo z AI extrakce je datum přijetí předvyplněné datem
> z dokladu (datum vystavení, jinak DUZP). Dokud na pole nesáhnete, do období
> odpočtu nevstupuje. Doklad, který jste po vytěžení upravili, tak skončí ve
> stejném období jako ten, kterého jste se nedotkli.

## 42.5 Krok za krokem: soupis dokladů oddílu KH pro kontrolu finančního úřadu

Při kontrole nebo postupu k odstranění pochybností chce správce daně často
vidět, které doklady jste uvedli v určitém oddílu kontrolního hlášení,
typicky v A.4. Soupis sestavíte přímo z Knihy DPH.

1. Otevřete `Daně → Kniha DPH` a zvolte období kontrolního hlášení.
2. V poli **Řádek přiznání / oddíl KH** vyberte oddíl, například **A.4 - Uskutečněná plnění nad
   10 000 Kč**. Volba **Soupis KH - všechny oddíly** ukáže všechny oddíly
   najednou, **Mimo KH** doklady z evidence DPH, které do kontrolního hlášení
   nepatří (například osvobozená plnění nebo vývoz).
3. Kniha se přepne na soupis dokladů. U každého dokladu vidíte číslo dokladu
   tak, jak je uvedené v KH, interní číslo, odběratele nebo dodavatele, DIČ,
   rozhodné datum (DUZP, resp. datum povinnosti přiznat daň) a základy daně
   a DPH podle sazeb.
4. Pod tabulkou je součet jen za doklady oddílu a tentýž součet zaokrouhlený
   na celé Kč, jak se objevuje v přiznání k DPH.
5. Klikněte na **Stáhnout PDF** nebo **Stáhnout XLSX**. Export nese název
   firmy, DIČ, období, použitý filtr, zdroj (aktuální data) a datum a čas
   sestavení.
6. Volbou **Kniha DPH (všechny doklady)** se vrátíte do běžné knihy.

**Jak poznáte, že je hotovo:** Soupis oddílu má stejné součty jako náhled
kontrolního hlášení za totéž období a export obsahuje hlavičku s obdobím
a filtrem.

> [!NOTE]
> Doklady se do oddílů zařazují stejnou logikou, jakou se sestavuje kontrolní
> hlášení. Limit 10 000 Kč se posuzuje podle celkové částky **dokladu**
> včetně DPH, ne podle jednotlivých položek, a přesně 10 000 Kč patří do
> souhrnného oddílu A.5/B.3. Soupis neobsahuje koncepty. Doklad, který by do KH
> nešel kvůli chybějícímu DIČ nebo rozdílnému režimu položek, je vypsaný pod
> soupisem zvlášť.

Částky dokladů jsou v Kč s haléři, jak se uvádějí ve větách kontrolního
hlášení. Součty se počítají z haléřových částek a zaokrouhlují se až na
konci. U souhrnných oddílů A.5 a B.3 odpovídá součet hodnotě souhrnné věty
KH; když se kvůli kurzovému přepočtu liší od součtu zaokrouhlených řádků
o haléře, soupis rozdíl uvede.

> [!WARNING]
> Soupis z Knihy DPH ukazuje **aktuální data**. Pokud se doklady po podání
> kontrolního hlášení změnily, nemusí odpovídat tomu, co jste podali. Pro
> správce daně proto sestavte soupis podle podaného hlášení (níže).

**Soupis podle podaného KH a dohledání rozdílů:**

1. Otevřete `Daně → EPO podání a archív` a vyberte podané kontrolní hlášení za
   dané období (stav **Podáno** nebo **Přijato**).
2. V liště akcí klikněte na **Soupis dokladů oddílu**.
3. Zvolte oddíl, například **A.4**. Doklady a částky se čtou přímo z podaného
   XML, jméno protistrany a interní číslo se doplní z evidence.
4. Sloupec **Stav** porovnává každý doklad s aktuálními daty:
   - **Shoda** - doklad je v aktuálních datech se stejnými částkami,
   - **Rozdíl částky** - doklad se po podání změnil, sloupce **Aktuálně
     základ** a **Aktuálně DPH** ukazují dnešní stav,
   - **Jen v podaném KH** - doklad v aktuálních datech v tomto oddílu není
     (byl smazán, stornován, přesunut do jiného období nebo oddílu),
   - **Jen v aktuálních datech** - doklad přibyl po podání.
5. Oddíly A.5 a B.3 jsou v KH souhrnné, porovnávají se proto jedním řádkem
   souhrnné věty. Jednotlivé doklady souhrnu najdete v Knize DPH (postup výše).
6. Klikněte na **Stáhnout PDF** nebo **Stáhnout XLSX**. Hlavička exportu
   uvádí „podle podaného KH" s datem podání a typem hlášení (řádné, opravné,
   následné).

**Jak poznáte, že je hotovo:** Všechny doklady mají stav **Shoda**, nebo
víte, proč se liší. Pokud se údaje podaného hlášení změnily, posuďte podání
následného kontrolního hlášení (`Daně → Kontrolní hlášení`).

## 42.6 Krok za krokem: kniha DPH a soupis KH podle dimenze

Máte-li zapnuté dimenze (kapitola [Dimenze](114_Dimenze.md)), zobrazí kniha
i soupis oddílu KH jen tu část dokladů, která připadá na zvolenou hodnotu,
například na středisko.

1. Otevřete `Daně → Kniha DPH` a zvolte období.
2. Pod horní lištou klikněte na **Přidat dimenzi** a vyberte hodnotu.
   Zaškrtávátko **včetně podřízených** zahrne i podřízené hodnoty; po
   odškrtnutí platí jen vybraná hodnota. Filtrovat lze podle hodnot jednoho
   typu dimenze: další řádek filtru nabízí jen typ první vybrané hodnoty
   a víc hodnot téhož typu se sčítá.
3. Kniha ukáže v hlavičce použitý filtr. Řádky a součty obsahují jen díl
   dokladů připadající na hodnotu:
   - položka s vlastní hodnotou dimenze patří své hodnotě,
   - položka bez vlastní hodnoty patří hodnotě hlavičky dokladu,
   - u rozpadu mezi víc hodnot (například 60 % a 40 %) se základ i DPH
     dělí podle podílů na haléře,
   - rozpis DPH přijatého dokladu patří dimenzi dokladu, mají-li ji všechny
     položky stejnou. Jinak ho kniha vypíše pod filtrem jako doklad s rozpisem
     DPH bez vazby na položky a do součtů hodnoty ho nepočítá.
4. Pro soupis oddílu KH zvolte v poli **Řádek přiznání / oddíl KH** oddíl.
   Oddíl i vyřazení dokladu se posuzují jako ve skutečném kontrolním hlášení,
   podle **celého** dokladu a po opravách (limit 10 000 Kč, vyúčtování
   vynulované odpočtem zálohy, rozdílný režim položek, oprava § 43, která
   přesune plnění mezi A.4 a A.5). Teprve potom se částky omezí na díl hodnoty.
5. Tlačítka **Stáhnout PDF** a **Stáhnout XLSX** exportují filtrovaný výpis,
   filtr je uvedený v hlavičce exportu.

**Jak poznáte, že je hotovo:** Součet knihy za hodnotu se rovná řádku té
hodnoty v sestavě DPH podle dimenze ([§ 42.7](#427-krok-za-krokem-sestava-dph-podle-dimenze))
za totéž období.

> [!NOTE]
> Částky oprav podle § 43, § 46, § 74b a § 79 se ve výpisu filtrovaném podle
> dimenze nezobrazují. Vážou se k dokladu po sazbách, ne k jeho položkám,
> a podíl na hodnotě dimenze nemají. V nefiltrované knize jsou a sestava DPH
> podle dimenze je vykazuje samostatným řádkem.

## 42.7 Krok za krokem: sestava DPH podle dimenze

Sestava rozdělí DPH za zdaňovací období po hodnotách jednoho typu dimenze,
například po střediscích, a ověří, že se součet rovná přiznání.

1. Otevřete `Daně → DPH podle dimenze`. Položka je v menu, jen když má firma
   zapnuté dimenze.
2. Zvolte **Měsíčně** nebo **Kvartálně**, období a **Typ dimenze**.
   Identifikovaná osoba podává přiznání vždy za měsíc: sestava se pak stejně
   jako přiznání sestaví za zvolený měsíc a upozorní na to.
3. Tabulka má řádek za každou hodnotu typu (podřízené hodnoty odsazené),
   řádek **Bez dimenze** a podle potřeby řádek **Rozpis DPH bez vazby na
   položky** (přijatý doklad s rozpisem DPH a položkami v různých dimenzích)
   a řádek **Opravy přiznání (§ 43, § 46, § 74b)**. U každého řádku vidíte
   počet dokladů, DPH na výstupu, DPH na vstupu (nárok na odpočet), výsledek
   (výstup minus vstup) a základy na výstupu a na vstupu v základní a snížené
   sazbě a ostatní. Řádek hodnoty obsahuje jen její vlastní díl, ne díl
   podřízených hodnot.
4. Pod tabulkou zkontrolujte kontrolní řádky:
   - **Celkem** - součet všech řádků, tedy úplná evidence za období,
   - **Evidence DPH** - evidence DPH za období včetně oprav, stejná data
     jako přiznání před zaokrouhlením,
   - **Rozdíl** - musí být nula; zelená nula znamená, že se do sestavy
     dostal každý řádek evidence právě jednou,
   - **Přiznání k DPH (ř. 62, 63, 64)** a **Úpravy přiznání a zaokrouhlení**
     - rozdíl součtu proti vyplněnému přiznání tvoří jen korekce odpočtu
     při registraci (§ 79), krácení odpočtu koeficientem (§ 76), úprava
     odpočtu (§ 78) a zaokrouhlení řádků na celé Kč.

   Chybí-li u dokladu v cizí měně kurz, sestava je označená jako
   **předběžná**: částky dokladu jsou přepočtené kurzem 1,0. Kurz doplňte
   na dokladu, stejně jako před stažením přiznání.
5. Kliknutím na hodnotu otevřete Knihu DPH za stejné období s filtrem na
   tuto hodnotu (bez podřízených hodnot). Tam vidíte doklady, ze kterých
   řádek vznikl.
6. V boxu **Kontrola obratů účtu 343** (jen v podvojném účetnictví) porovnejte
   výsledek hodnoty se saldem účtu 343 po dimenzích. Do obratu se
   nepočítají úhrady daně finančnímu úřadu, vratky nadměrného odpočtu,
   počáteční stavy ani uzávěrka. Rozdíl se vysvětluje, sestava ho
   neopravuje, viz [§ 42.9.5](#4295-dimenze-v-evidenci-dph).
7. Tlačítka **Stáhnout PDF** a **Stáhnout XLSX** exportují sestavu včetně
   kontrolních řádků. Zobrazí se jen s oprávněním `reports.export`.

**Jak poznáte, že je hotovo:** Řádek **Rozdíl** je nulový a řádek **Bez
dimenze** obsahuje jen doklady, u kterých dimenzi opravdu nechcete (například
pokladní doklady nebo doklady bez zadané dimenze, které po doplnění dimenze
přejdou k hodnotě).

## 42.8 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Hláška „Pro zvolené období nejsou v knize DPH žádné záznamy." | V období není žádné plnění, nebo máte zvolené jiné období | Zkontrolujte přepínač **Měsíčně** / **Kvartálně**, měsíc a rok. |
| Tlačítko **Stáhnout PDF** chybí nebo je neaktivní | Nemáte oprávnění k exportu sestav, nebo se kniha ještě načítá | Požádejte správce o oprávnění; počkejte na načtení tabulky. |
| Doklad s DUZP na konci měsíce je až v dalším měsíci | Odpočet lze uplatnit nejdříve v období, kdy máte doklad k dispozici (§ 73 odst. 1 písm. a) ZDPH) | Zkontrolujte **datum přijetí** a datum vystavení, viz [§ 42.4](#424-krok-za-krokem-proc-je-doklad-v-jinem-obdobi). |
| Řádek s nulovým základem i daní a poznámkou „nevykazuje se" | Typicky vyúčtování plně uhrazené zálohy; plnění je vykázané na daňovém dokladu k přijaté platbě | Nic neopravujte, do kontrolního hlášení se doklad neuvádí. |
| Za částkou DPH je písmeno D | DPH je oceněno jiným kurzem než základní účetní kurz | Zkontrolujte kurz na dokladu. |
| Součet knihy se liší od přiznání o několik korun | XML přiznání zaokrouhluje údaje na celé Kč, evidence drží haléře | Jde o zákonné zaokrouhlení; větší rozdíl hledejte v konceptech a v klasifikaci. |
| Doklad v knize chybí | Je stornovaný, jde o zálohovou výzvu, nebo je mimo zvolené období | Stornované doklady a zálohové výzvy se do knihy nezahrnují. |
| Doklad ve filtru dimenze chybí, i když ho seznam faktur s týmž filtrem ukazuje | Seznam ukazuje doklad, který hodnotu nese kdekoli (hlavička, položka, rozpad); kniha jen díl položek hodnoty. Položky s vlastní jinou hodnotou do dílu nepatří | Zkontrolujte dimenze položek dokladu. |
| Hláška, že evidenci DPH lze filtrovat jen podle jednoho typu dimenze | Ve filtru jsou hodnoty dvou typů (například středisko a projekt) | Ponechte ve filtru hodnoty jen jednoho typu. |
| Sestava DPH podle dimenze má velký řádek **Bez dimenze** | Doklady v období nemají dimenzi typu na hlavičce ani na položkách | Doplňte dimenzi na dokladech; výchozí dimenze klienta nebo zakázky se do sestavy nepočítají, viz [§ 42.9.5](#4295-dimenze-v-evidenci-dph). |
| Řádek **Rozdíl** v sestavě DPH podle dimenze není nulový | Rozpad dokladu nebo data v evidenci jsou v nekonzistentním stavu | Pod tabulkou je seznam řádků přiznání s rozdílem; doklady dohledejte v Knize DPH a nahlaste chybu. |

## 42.9 Podrobnosti a pravidla

### 42.9.1 Zdroj dat a rozhodné období

Kniha používá stejnou řádkovou evidenci DPH jako přiznání (DPHDP3) a kontrolní
hlášení (DPHKH1), a proto je stejná v daňové evidenci i v podvojném
účetnictví. Zahrnuje:

- položky vystavených faktur podle DUZP, případně podle data vystavení,
- položky přijatých faktur; u tuzemského odpočtu se respektuje také datum
  přijetí,
- řádky DPH zaúčtovaných pokladních daňových dokladů,
- samovyměření reverse charge a jeho případný zrcadlový nárok na odpočet.

Datum úhrady nerozhoduje o období DPH. Cizí měny se převádějí kurzem
uloženým na dokladu. Chybějící nebo neplatný kurz či klasifikace je důvodem
k opravě zdrojového dokladu, ne k ručnímu přepsání knihy.

Pracovní kniha záměrně zahrnuje i koncepty, které jsou v PDF označené.
Stornované doklady a zálohové výzvy se nezahrnují. Ostré DPHDP3 a KH naopak
koncepty neobsahují, proto se před podáním ujistěte, že v knize nezůstaly
neuzavřené doklady.

### 42.9.2 Členění knihy

Řádky se seskupují podle dokladu, klasifikace a sazby. Kód sekce kombinuje
pracovní skupinu a řádek přiznání, například:

- `15.xxx` - přijatá tuzemská plnění,
- `36.xxx` - uskutečněná plnění,
- `43.xxx` - samovyměření a zrcadlový odpočet reverse charge,
- `47.047` - doplňující hodnota pořízeného dlouhodobého majetku; do odpočtu
  se podruhé nepřičítá.

Každý řádek uvádí datum plnění, datum zaúčtování, období odpočtu, typ a číslo
dokladu, popis, základ, DPH a celkem v Kč, protistranu a DIČ, původní číslo
dokladu a účinnou sekci KH. Doklady se řadí přirozeně podle čísla.

### 42.9.3 Období odpočtu u přijatých dokladů

Sloupec **Období odpočtu** ukazuje datum, podle kterého přijatý doklad spadl
do zobrazeného období, a pod ním důvod: *dle DUZP*, *dle data vystavení*,
nebo *dle data přijetí*. U vystavených plnění a u oprav podle § 74b se
neuvádí.

Nárok na odpočet lze podle § 73 odst. 1 písm. a) ZDPH uplatnit nejdříve za
období, ve kterém má plátce doklad k dispozici. Samotné DUZP proto doklad do
svého měsíce nestáhne:

- doklad s DUZP 30. 6., ale vystavený 2. 7., patří do **července**. Dřív,
  než byl vystaven, jste ho mít nemohli, a posunutí data přijetí do minulosti
  na tom nic nezmění,
- doklad, který vám dorazil až v srpnu a datum přijetí jste na něm vyplnili,
  patří do **srpna**; v tabulce je označený hvězdičkou a v PDF je za ní
  rozhodné datum.

Datum přijetí ovlivní zařazení jen tehdy, když ho zadáte vy. U dokladu
z importu nebo z AI extrakce je předvyplněné datem z dokladu (datum
vystavení, jinak DUZP), a dokud na pole nesáhnete, do období odpočtu
nevstupuje. Když datum přijetí neodpovídá skutečnosti, opravte ho na dokladu.

Přijaté zahraniční reverse charge se řídí DUZP bez ohledu na datum přijetí
(§ 25, § 24), viz [Výkazy DPH](41_Vykazy_DPH.md).

### 42.9.4 Poměrný a krácený odpočet

U poměrného odpočtu (§ 75) se základ a DPH na vstupu krátí zadaným
procentem. U kráceného odpočtu (§ 76) kniha oddělí částku do krácené
skupiny; zálohový koeficient a konečné roční vypořádání se uplatní až
v DPHDP3, nikoli jako samostatný pokladní pohyb v knize. Plnění bez nároku
nevytváří odpočet, ale u samovyměření zůstává daň na výstupu.

### 42.9.5 Dimenze v evidenci DPH

Řádek evidence DPH je položka dokladu (u přijatého dokladu s rozpisem DPH
řádek rozpisu, u pokladního dokladu řádek DPH). Hodnotu dimenze dostane:

1. z položky, má-li položka vlastní hodnotu typu nebo rozpad,
2. jinak z hlavičky dokladu (hodnota nebo rozpad),
3. jinak je řádek **bez dimenze**.

Řádek rozpisu DPH přijatého dokladu k položce vázaný není. Mají-li všechny
položky dokladu stejnou dimenzi (jediná položka, jen hlavička, nebo všechny
položky stejně), patří řádek rozpisu jí. Jinak vazba jednoznačná není a částka
je v sestavě v řádku **Rozpis DPH bez vazby na položky**.

Rozhodují jen dimenze zadané na dokladu. Výchozí dimenze klienta, zakázky
a produktu ani pravidla dimenzí podle účtu se nepoužijí, protože určují
dimenze zaúčtovaných řádků nákladů a výnosů, ne dokladu. Pořadí položek je
pořadí v editoru dokladu a počítají se všechny položky včetně textových.

Rozpad se dělí po haléřích stejným pravidlem jako rozpad řádku účetního
deníku: každý díl se zaokrouhlí a zbytek dostane největší podíl. Součet
dílů je vždy přesně částka řádku, takže součet sestavy DPH podle dimenze
přes všechny hodnoty a řádek Bez dimenze se rovná evidenci za období na
haléř.

DPH na výstupu jsou řádky 1 až 13 přiznání, nárok na odpočet řádky 40 až 45
(krácený odpočet v plné výši), základy podle sazeb se sčítají z týchž řádků;
ostatní základ na výstupu jsou řádky 20 až 26, na vstupu dovoz zboží (ř. 42).
Zrcadlový odpočet ze samovyměření (ř. 43, 44) je v sestavě v haléřích,
přiznání ho vyplňuje po celých Kč; rozdíl je v řádku Opravy a zaokrouhlení
přiznání.

**Obrat účtu 343.** Kontrola v sestavě čte zaúčtované řádky účtu 343 (včetně
analytik) s datem účetního případu v období. Hodnota řádku je dimenze řádku
zápisu, u střediska navázaného na číselník středisek i textový kód střediska,
u rozpadu díl řádku. Mimo obrat jsou zápisy uzavření a otevření knih
a úhrady nebo vratky daně. Řádek 343 je úhradou nebo vratkou, když:

- zápis je z bankovního pohybu s protiúčtem u ČNB (kód banky 0710, účty
  finančních úřadů); poplatek nebo penále ve stejném zápisu na tom nic
  nemění,
- v jednoduchém zápisu je ve dvojici s peněžním účtem (211, 221, 261),
- zápis (z banky, pokladny i ruční) má jen účet 343 a peněžní účty.

DPH z bankovního poplatku bez protiúčtu u ČNB nebo pokladní doklad s DPH
protistranu nákladu či výnosu mají, a v obratu proto zůstávají. Stejné
pravidlo používá kontrola obratu 343 proti přiznání. Počet vyloučených
úhrad je počet zápisů.

Rozdíl obratu 343 proti sestavě běžně způsobí:

- jiné rozhodné datum: deník řadí podle data účetního případu, evidence DPH
  podle DUZP a u přijatých dokladů podle vzniku nároku na odpočet,
- jiná dimenze: řádek 343 dostane dimenzi hlavičky dokladu, případně výchozí
  dimenzi klienta, zakázky nebo pravidla; dimenze položky platí jen pro
  náklad a výnos. Sestava DPH dělí podle položek,
- ruční a interní zápisy na 343 (samovyměření, oprava odpočtu § 74b,
  převody mezi analytikami) s vlastní dimenzí řádku,
- koncepty a nezaúčtované doklady, které v deníku nejsou.

## 42.10 Související kapitoly

- [Výkazy DPH](41_Vykazy_DPH.md) - přiznání, kontrolní hlášení a souhrnné hlášení
- [Přijaté faktury](23_Prijate_faktury.md) - datum přijetí a zařazení odpočtu
- [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md) - kontrola DPH vůči účtu 343
- [Dimenze](114_Dimenze.md) - zadání dimenzí na dokladech a rozpad mezi hodnoty
