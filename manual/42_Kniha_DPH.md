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
- finanční úřad chce soupis dokladů uvedených v oddílu kontrolního hlášení.

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
| při kontrole finančního úřadu | Vytisknout soupis dokladů oddílu KH (např. A.4) se součty | pole **Oddíl KH**, postup v [§ 42.5](#425-krok-za-krokem-soupis-dokladu-oddilu-kh-pro-kontrolu-financniho-uradu) |

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
3. Projděte řádky označené štítkem **Koncept**. Kniha koncepty záměrně
   zahrnuje, ostré přiznání a kontrolní hlášení je neobsahují. Každý koncept
   dokončete, nebo zrušte.
4. Zkontrolujte, že u dokladů nechybí klasifikace DPH. Chybějící klasifikaci
   doplňte na zdrojovém dokladu.
5. Porovnejte součty sekcí **PŘIJATÁ** a **USKUTEČNĚNÁ** s řádky náhledu
   přiznání na `Daně → DPH přiznání`. Částky se v evidenci drží na haléře,
   zatímco XML přiznání zaokrouhluje jednotlivé formulářové údaje na celé Kč.
   Rozdíl několika korun proto může být jen důsledkem zákonného zaokrouhlení.
6. Zkontrolujte ve sloupci **KH**, do které sekce kontrolního hlášení doklad
   půjde. Limit 10 000 Kč se posuzuje z absolutní celkové částky dokladu
   včetně DPH. Přesně 10 000 Kč patří do souhrnné sekce A.5/B.3, individuální
   A.4/B.2 je až nad limitem a vyžaduje DIČ.
7. Klikněte na **Stáhnout PDF**, chcete-li mít pracovní podklad.

**Jak poznáte, že je hotovo:** V knize nezůstal žádný **Koncept** ani doklad
bez klasifikace a součty odpovídají náhledu přiznání (až na zákonné
zaokrouhlení). V dolní části knihy vidíte **Výslednou DPH** jako **Vlastní
daňovou povinnost** nebo **Nadměrný odpočet**.

> [!WARNING]
> Důkazem podání je až potvrzení z portálu, nikoli PDF z Knihy DPH. Kniha
> navíc neporovnává data s XML, které bylo skutečně odesláno. Změnil-li se
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
5. Neodpovídá-li, otevřete přijatou fakturu a opravte **datum přijetí**.
   Editor u dat rovnou píše, do jakého období odpočet půjde a proč.
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
2. V poli **Oddíl KH** vyberte oddíl, například **A.4 - Uskutečněná plnění nad
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
> kontrolního hlášení změnily, nemusí odpovídat tomu, co jste podali.

## 42.6 Když něco nejde

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

## 42.7 Podrobnosti a pravidla

### 42.7.1 Zdroj dat a rozhodné období

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

### 42.7.2 Členění knihy

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

### 42.7.3 Období odpočtu u přijatých dokladů

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

### 42.7.4 Poměrný a krácený odpočet

U poměrného odpočtu (§ 75) se základ a DPH na vstupu krátí zadaným
procentem. U kráceného odpočtu (§ 76) kniha oddělí částku do krácené
skupiny; zálohový koeficient a konečné roční vypořádání se uplatní až
v DPHDP3, nikoli jako samostatný pokladní pohyb v knize. Plnění bez nároku
nevytváří odpočet, ale u samovyměření zůstává daň na výstupu.

## 42.8 Související kapitoly

- [Výkazy DPH](41_Vykazy_DPH.md) - přiznání, kontrolní hlášení a souhrnné hlášení
- [Přijaté faktury](23_Prijate_faktury.md) - datum přijetí a zařazení odpočtu
- [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md) - kontrola DPH vůči účtu 343
