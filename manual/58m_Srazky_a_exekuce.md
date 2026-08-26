# Srážky a exekuce

## Účel

Agenda spravuje exekuce, insolvenční a další nucené srážky, jejich pořadí, rozhodné údaje, výpočet a závazky příjemcům.

## Předpoklady a oprávnění

Je nutné oprávnění `payroll.enforcement` a podle případu `payroll.insolvency`. Připravte rozhodnutí, datum doručení, pořadí, typ pohledávky, příjemce a stav řízení. Nejasný případ posuďte s odborníkem.

## Krokový postup

1. Otevřete **Mzdy → Srážky a exekuce** a založte případ u správné osoby.
2. Vyplňte rozhodné datum, pořadí, druh pohledávky, spis, příjemce a částky.
3. Zkontrolujte vyživované osoby a další údaje ovlivňující nezabavitelnou částku.
4. Před uzavřením běhu projděte rozdělení srážek a zůstatky všech souběžných případů.
5. Po úhradě aktualizujte stav; změnu pořadí nebo skončení proveďte podle doložené události.

Detail případu vždy nahoře ukazuje **doporučený další krok** a tlačítko vede
přímo na část, kterou je nutné doplnit. Běžný postup tak
vede od pohledávky přes ověření podkladů a zahájení srážení až k ověření
příjemce a povolení odesílání. Méně časté změny stavu jsou pod volbou **Další
stavové kroky**; jejich skrytí nemění právní kontroly ani dostupné možnosti.
Použijte je pouze tehdy, když odpovídají doloženému rozhodnutí.

V měsíčních podkladech je běžná kontrola oddělená od výjimek. Rejstřík
pohledávek a právě uplatněné nároky zkontrolujete přímo. Souběh plátců,
důchodovou podmínku, soudem určenou nezabavitelnou částku a insolvenci otevřete
volbou **Zkontrolovat výjimky** pouze při změně. Uložená výjimka je vidět už v
souhrnu zavřené části, takže ji nelze přehlédnout. Stejně tak se seznam
vyživovaných osob spravuje jen při vzniku, zániku nebo ověření nároku; v běžném
měsíci se celý seznam znovu nevyplňuje.

Volby insolvenčního režimu u každé možnosti popisují dopad na výpočet. Režim
**Schválené oddlužení — srážet a deponovat** vypočte srážku, ale částku zatím
nezařadí do automatické platební dávky; účetní ji odešle insolvenčnímu správci
ručně a úhradu doloží. Volby **Pouze evidovat upozornění** a **Individuální
částka určená soudem** vyvolají ruční kontrolu a samy částku nepoužijí ani
neodešlou. Potvrzení příjemce v měsíčních podkladech nenahrazuje rozhodnutí ani
ověřený platební účet.

## Stavy

Případ může být evidovaný, aktivní, pozastavený, čekající v pořadí, doplacený nebo ukončený. Samotné vložení rozhodnutí nezaručuje srážku v uzavřeném běhu; rozhodují účinnost, pořadí a disponibilní částka.

## Oprava omylem založeného případu

Prázdný případ ve stavu **Přijato — čeká na ověření** můžete v nabídce
akcí detailu smazat. Aplikace před smazáním znovu ověří, že k případu
není uložena pohledávka, rozhodnutí ani jiný dokument, změna stavu,
alokace ve výsledku výpočtu, pohyb srážky, platební závazek nebo platba. Smazání je
nevratné a vyžaduje potvrzení.

Jakmile případ začal mít právní, mzdovou nebo platební historii, smazat jej
nelze. Aplikace uvede konkrétní důvod; případ zastavte nebo uzavřete standardním
stavovým krokem. Tím zůstane zachováno, podle jakého podkladu a v jakém období
se postupovalo.

## Kontroly a bezpečnost

Použijte nejvyšší míru omezení přístupu. Ověřte pravidla účinná v měsíci, pořadí doručení, přednostní charakter, nezabavitelnou částku a souběh. Citlivé listiny neukládejte do veřejných odkazů a neměňte historické rozhodné datum bez auditní stopy.

## Časté chyby

- Chybné pořadí více exekucí.
- Záměna přednostní a nepřednostní pohledávky.
- Neaktualizovaný zůstatek po externí platbě.
- Ruční srážka navíc k automaticky vypočtené částce.

## Návaznosti

Dobrovolné srážky jsou v [kapitole 58l](58l_Dohody_o_srazkach.md), účinné parametry v [58q](58q_Legislativni_pravidla_mezd.md), výpočet v [58e](58e_Mzdove_behy.md) a úhrada příjemci v [58g](58g_Platby_a_uhrady.md).



## Podrobný pracovní postup a kontroly

V **Mzdy → Srážky a exekuce** založíš zaměstnanci zákonnou srážku, exekuci
nebo dohodu o srážkách. Případ začíná ve stavu **Přijato — čeká na ověření**.
Nejdříve doplň pohledávky, jejich kategorii, den pořadí a potvrď ověření
právního titulu, doručení a přednosti. Aktivace srážení vyžaduje počáteční
rozhodnutí z agendy **Dokumenty**. Do ověření příjemce aplikace částky jen
deponuje. Odesílání musí uživatel povolit samostatnou akcí a doložit
odpovídajícím rozhodnutím. Aplikace ověří firmu i oprávnění uživatele k
dokumentu, uloží jeho otisk a dokument od té chvíle chrání jako právní důkaz.
Také odklad, obnovení deponování či odesílání a zastavení vyžadují vybraný
dokument; u odkladu a zastavení je navíc povinný důvod.

Výpočet používá celé haléře a uchovává neměnný měsíční vstup, použitou verzi
pravidel, mezikroky zaokrouhlení, přidělení částek pohledávkám a pohyby
**sraženo / deponováno**. Odeslané peníze eviduje samostatně platební vrstva,
aby výpočet nemohl předstírat skutečnou úhradu. Kontroluje zejména nezabavitelnou částku,
třetiny, plně zabavitelný zbytek, pořadí přednostních pohledávek, běžné a dlužné
výživné, více exekučních příkazů, více plátců, oddlužení a paušální náhradu
nákladů zaměstnavatele. Chybějící měsíční podklady nezastoupí odhadem — výsledek
označí pro ruční kontrolu.

Měsíční podklady se ale vyžadují jen tam, kde mají co doložit. Zaměstnanec bez
jediné aktivní pohledávky a bez oddlužení nic zadávat nemusí: rozdělovat není
co, takže potvrzení rejstříku pohledávek za takový měsíc nechybí — jen se
nevyžaduje. Potvrzení vyživovaných osob a slevy na manžela se ptá jen tehdy,
když je nárok uplatněný, protože jen tehdy zvedá nezabavitelnou částku.
U schválené mzdy je pak z výsledku vidět, jestli byl podklad doložený, nebo
proč se v tom měsíci nevyžadoval. Uplatněný a nedoložený nárok mzdový běh
neblokuje, ale do vyčerpání kapacity dobrovolných dohod o srážkách nepustí —
nezabavitelná částka, ze které se strop dohody počítá, není doložená.

Číslo řízení, bankovní účet příjemce ani právní dokument se do polí případu
nepřepisují. Patří do zabezpečených dokumentů; agenda srážek pracuje pouze
s interním identifikátorem a ověřenými skutečnostmi. Odklad a zastavení vyžadují
ověřené rozhodnutí a důvod. Ukončený případ nelze zkratkou znovu otevřít.
Označení případu za uhrazený projde teprve tehdy, když potvrzené úhrady pokryjí
celý zůstatek pohledávek — samotné sražení ze mzdy k tomu nestačí.

### Odeslání sražených částek příjemci

Aby aplikace sražené peníze skutečně odeslala, vyber v případu **příjemce srážky**
z katalogu **Mzdy → Nastavení mezd → Účty institucí** (typ *ostatní příjemce*). Účet
musí být ověřený a účinný k datu výplaty; číslo účtu ani symboly se do případu
neopisují. Po schválení mzdové revize vytvoří akce **Připravit závazky**
v **Mzdy → Mzdové příkazy a úhrady** závazek vůči tomuto příjemci — ale jen z částek, které jsou
ve stavu **odesílání**. Cokoli je deponované (nový případ, odklad, zastavení)
se do odchozí platební dávky nedostane. Opakovaná příprava nevytvoří druhý
závazek; oprava mzdy promítne jen rozdíl a pokles vznikne jako samostatný
příchozí opravný závazek.

Blok **Sraženo, depozitum a odeslané platby** v detailu případu ukazuje, kolik
bylo sraženo, kolik drží depozitum, kolik je připraveno k úhradě, kolik už
příjemce dostal a kolik na pohledávce zbývá. Poslední dva údaje se mění až
po spárování skutečné bankovní nebo pokladní platby v **Mzdy → Mzdové příkazy a úhrady →
Spárování úhrad**.
