# 51. Přehled firem

> Rozcestník pro účetní kancelář nebo jiného uživatele s přístupem k více firmám. Na jedné stránce vidíte termíny a resty všech firem a jedním kliknutím se dostanete tam, kde je potřeba zasáhnout.

## 51.1 Kdy to potřebujete

Kapitolu otevřete, když:

- začínáte pracovní den a chcete vědět, která firma má nejbližší daňový termín,
- hledáte firmu, ve které zbývá nejvíc nezaúčtovaných dokladů nebo nespárovaných plateb,
- potřebujete rychle přepnout do jiné firmy rovnou do správné agendy,
- chcete před termínem DPH zkontrolovat, jestli některá firma nemá akutní problém.

Položka se v menu zobrazí jen tehdy, když máte k dispozici více firem. Stránka nic neúčtuje a nepřepočítává doklady. Skládá aktuální provozní ukazatele ze všech povolených firem.

## 51.2 Než začnete

1. **Přístup k více firmám.** Vidíte jen firmy, ke kterým máte přiřazení a účinné oprávnění. Přiřazení spravuje správce v `Systém → Firmy`.
2. **Podvojné účetnictví.** Pruh **Akutní kontroly** (viz [§ 51.4](#514-krok-za-krokem-kontrola-akutnich-nalezu)) se zobrazuje jen u firem s podvojným účetnictvím. Ostatní ukazatele ukazuje přehled i u firem v daňové evidenci.

## 51.3 Krok za krokem: denní průchod firmami

1. Otevřete `Systém → Přehled firem`.
2. Projděte karty firem. Nahoře jsou firmy s nejvyšší urgencí, štítek **Urgence** u názvu firmy vypisuje důvody. Řazení a filtry nad kartami popisuje [§ 51.7.2](#5172-razeni-filtry-a-urgence).
3. U každé firmy zkontrolujte **Nejbližší termín**, **Nezaúčtováno**, **Nespárováno (banka)** a **Koncepty PF**.
4. Klikněte na konkrétní ukazatel. Aplikace přepne aktivní firmu a otevře zdrojovou agendu (viz tabulka níže).
5. Po dokončení práce se vraťte do Přehledu firem a klikněte na **Obnovit**.
6. Chcete-li firmu jen otevřít, klikněte na **Otevřít firmu**.

**Jak poznáte, že je hotovo:** Čísla u vašich firem klesla na nulu nebo na hodnotu, kterou vědomě necháváte (například koncepty, které čekají na podklad). Čas **Vygenerováno** dole ukazuje okamžik posledního načtení, nejde o trvale uložený snímek.

> [!TIP]
> Mobilní zobrazení používá zkrácené karty firmy s nejbližším termínem, stavem období a třemi provozními počty. Plná podoba karet s posledním importem banky a přímými odkazy je dostupná na širší obrazovce.

## 51.4 Krok za krokem: kontrola akutních nálezů

1. U karty firmy s podvojným účetnictvím najděte pruh **Akutní kontroly**. Ukazuje počet chyb a varování, nebo text **Nic akutního**.
2. Klikněte na **Detail**. Otevře se okno s nálezy.
3. Chcete-li vidět celý seznam dokladů a opravy, klikněte na **Otevřít měsíční kontrolu**. Aplikace přepne firmu a otevře [Měsíční kontrolu](62_Mesicni_kontrola.md).

**Jak poznáte, že je hotovo:** Pruh je zelený a hlásí **Nic akutního**.

> [!WARNING]
> Zelený pruh znamená "nic akutního", ne "účetnictví je hotové". Práce vázaná na rozvahový den se v pruhu nezobrazuje (viz [§ 51.7.3](#5173-pruh-akutnich-kontrol)).

## 51.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Přehled je prázdný (**Žádné firmy k zobrazení.**) | Nemáte přiřazenou žádnou firmu | Požádejte správce o přiřazení v `Systém → Firmy`. |
| Chybí firma, kterou čekáte | Nemáte k ní přiřazení nebo účinné oprávnění | Požádejte správce o přiřazení. Systémový superadmin vidí všechny firmy. |
| U pruhu je **kontrola…** nebo **kontrolu nelze načíst** | Kontrola se načítá zvlášť, nebo se nepodařila | Klikněte na **Obnovit**. Pokud se chyba opakuje, otevřete měsíční kontrolu firmy přímo. |
| U firmy je **Neplátce DPH** místo termínu | Firma nemá žádný termín DPH, KH ani SH | Nejde o chybu. Termíny jiných daní přehled nehlídá. |
| **Nezaúčtováno** ukazuje nulu, ale doklad chybí | Přehled počítá jen doklady, které v systému existují | Doklad nejdřív nahrajte nebo vyžádejte. U firmy v daňové evidenci je ukazatel vždy nula. |
| Číslo **Nezaúčtováno** nesedí s tím, co vidíte ve faktuře | Číslo skládá tři různé agendy | Rozpad vidíte pod číslem. Celý seznam otevřete přes [K doúčtování](54_Rucni_fronta_doctovani.md). |

## 51.6 Co znamenají ukazatele

<!-- cols: 24 46 30 -->
| Ukazatel | Co znamená | Kam vede |
|---|---|---|
| **Firma** | Zobrazovaný název firmy a IČ. | Tlačítko **Otevřít firmu** přepne firmu a otevře její Přehled. |
| **Nejbližší termín** | Nejbližší termín DPH, KH nebo SH. Ukazuje datum a počet dní, záporná hodnota znamená po termínu. | Přepne firmu a otevře přiznání k DPH. |
| **Nezaúčtováno** | Součet nezaúčtovaných vydaných dokladů podporovaných pro zaúčtování, nezaúčtovaných přijatých dokladů kromě zálohových výzev a skutečných bankovních pohybů bez aktivního zápisu. U firmy v daňové evidenci je vždy nula. | Přepne firmu a otevře vydané faktury s filtrem nezaúčtovaných. |
| **Nespárováno (banka)** | Nespárované příchozí pohyby za posledních 90 dní. Vlastnictví se ověřuje podle bankovního účtu firmy, nikoli jen podle hlavičky výpisu. | Přepne firmu a otevře Banku. |
| **Koncepty PF** | Přijaté faktury ve stavu koncept. | Přepne firmu a otevře filtrovaný seznam přijatých faktur. |
| **Čeká na vytěžení** | Doklady v příchozích dokladech, které čekají na vytěžení, včetně těch, jejichž vytěžení se nepovedlo. Nepočítají se doklady čekající na doplnění od klienta. | Přepne firmu a otevře příchozí doklady. |
| **Účetní období** | Nejnovější založené účetní období a jeho stav **otevřené**, **uzavírá se** nebo **uzavřené**. Firma bez období má pomlčku. | Jen informace. |
| **Poslední import banky** | Nejnovější čas importu výpisu, který podle bankovních účtů náleží dané firmě. | Jen informace. |
| **Objem dat** | Počty vydaných a přijatých faktur, bankovních výpisů a pohybů, pokladních dokladů a zápisů v deníku (jen u podvojného účetnictví). | Kliknutí otevře příslušnou agendu. |

> [!WARNING]
> **Nezaúčtováno je pracovní součet.** Číslo skládá tři různé agendy. Kliknutí vede na vydané faktury, ne na úplný rozpad. Celý seznam známých nedokončených případů otevřete přes [K doúčtování](54_Rucni_fronta_doctovani.md). Čekající návrhy bankovní kontace jsou v [Automatu](53_Automat.md).

## 51.7 Podrobnosti a pravidla

### 51.7.1 Které firmy uživatel vidí

Běžný uživatel vidí výhradně firmy, ke kterým má řádek přiřazení a účinné oprávnění. Uživatel bez přiřazené firmy neuvidí žádnou. Systémový superadmin vidí všechny firmy. Do přehledu patří i přiřazené firmy v režimu daňové evidence.

Omezení se provádí na serveru, nikoli pouze skrytím řádků v prohlížeči. Přehled proto není závislý na právě zvolené firmě v přepínači. Po kliknutí na ukazatel se aktivní firma nastaví a stránka se načte znovu s jejím kontextem.

Naléhavost nejbližšího daňového termínu ukazuje barevný pruh u karty: termín po splatnosti je výraznější než budoucí termín, firma bez vypočteného termínu nemá pruh upozornění.

### 51.7.2 Řazení, filtry a urgence

Nad kartami je hledání podle názvu nebo IČO, volba řazení s přepínačem směru a filtry **Plátci / Neplátci DPH**, **Podvojné účetnictví / Daňová evidence** a **Jen s urgencí / Jen bez urgence**. Nastavení se ukládá do adresy stránky, takže odkaz na konkrétní pohled lze poslat kolegovi.

<!-- cols: 34 66 -->
| Řazení | Pořadí |
|---|---|
| **Podle urgence** (výchozí) | Nejvyšší urgence nahoře. Při shodě rozhoduje bližší daňový termín, potom název. |
| **Abecedně** | Podle názvu firmy. |
| **Nejbližší termín** | Termíny po splatnosti nahoře, firmy bez termínu na konci. |
| **Nejvíc k doúčtování** | Podle ukazatele **Nezaúčtováno**. |
| **Nejvíc nespárovaných plateb** | Podle ukazatele **Nespárováno (banka)**. |
| **Nejvíc dokladů k vytěžení** | Podle ukazatele **Čeká na vytěžení**. |
| **Nejvíc faktur po splatnosti** | Podle počtu vystavených faktur po splatnosti. |
| **Poslední vystavená faktura**, **Poslední aktivita** | Nejčerstvější nahoře, firmy bez faktury nebo aktivity na konci. |

Urgence je stejná jako ve [správě firem](95_Multi_supplier.md). Skládá se z vážených položek:

- přiznání DPH za poslední období po termínu bez doloženého podání (nejvyšší váha), nebo s termínem do 7 dní,
- přijaté faktury po splatnosti,
- vystavené faktury po splatnosti,
- nespárované příchozí platby za posledních 90 dní,
- koncepty přijatých faktur ke kontrole (bez konceptů čekajících na schválení).

Stav přiznání DPH se počítá jen u firem, které DPH podávají přes aplikaci, tedy mají aspoň jedno doložené podání. Počty dokladů se započítávají jen do stropu, aby firma se stovkou starých nespárovaných plateb nepřebila firmu s nepodaným přiznáním. Podle výsledku je urgence **Vysoká**, **Střední**, **Nízká**, nebo štítek hlásí **Nic nečeká**.

### 51.7.3 Pruh akutních kontrol

Firma s podvojným účetnictvím má nad ukazateli barevný pruh **Akutní kontroly**. Ukazuje vybrané nálezy z měsíční kontroly za aktuální účetní období do dnešního dne, jen jako názvy a počty. Kliknutím na nález se otevře měsíční kontrola té firmy, kde je celý seznam dokladů i opravy.

Pruh záměrně ukazuje **jen to, s čím lze hnout dnes**: chybějící účetní zápis dokladu, saldo na 311 nebo 321, které nesedí na zaplacený doklad, nevyrovnaný deník, koncept v období, nezaúčtovaný kurzový rozdíl nebo rozdíl mezi účtem 343 a podaným přiznáním k DPH.

Práce vázaná na rozvahový den se v pruhu **nezobrazuje**, protože u otevřeného roku chybí naprosto legitimně a svítila by měsíce:

- účetní odpisy roku (účtují se v uzávěrce),
- inventarizace rozvahových účtů podle § 29-30 zákona o účetnictví,
- nerozdělený výsledek hospodaření na účtu 431,
- neuzavřený minulý rok.

Tyto kontroly najdete v [Měsíční kontrole](62_Mesicni_kontrola.md) a hlídá je uzávěrková brána, která bez jejich vyřešení nedovolí rok uzavřít. Zelený pruh proto znamená "nic akutního", nikoli "účetnictví je hotové".

### 51.7.4 Co přehled nekontroluje

Přehled neověřuje věcnou správnost kontace, úplnost všech účetních podkladů, shodu saldokonta, DPH ani stav inventarizace. Například:

- nula u banky bez párování neznamená, že jsou všechny platby správně zaúčtované,
- nula u nezaúčtovaných dokladů nezahrnuje doklad, který v systému vůbec chybí,
- termín DPH neznamená, že je přiznání připravené nebo podané,
- stav **uzavřené** neprokazuje, že proběhly všechny odborné kontroly.

## 51.8 Související kapitoly

- [Průvodce účetního](50_Pruvodce_ucetniho.md) - denní, měsíční a roční postup
- [Automat](53_Automat.md) a [K doúčtování](54_Rucni_fronta_doctovani.md) - denní práce s nezaúčtovanými případy
- [Úplnost dokladů](61_Uplnost_dokladu.md) a [Měsíční kontrola](62_Mesicni_kontrola.md) - periodické kontroly
