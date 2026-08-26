# Výmaz osobních údajů

## Účel

Agenda připravuje řízené odstranění nebo anonymizaci osobních údajů po skončení retenčních povinností. Jde o nevratnou a odděleně oprávněnou operaci.

## Předpoklady a oprávnění

Je nutné oprávnění `payroll.erasure`, předchozí posouzení retenčních lhůt a schválení odpovědnou osobou. Před zahájením ověřte, že neexistuje zákonná, smluvní, účetní ani procesní překážka.

## Krokový postup

1. Otevřete **Mzdy → Výmaz osobních údajů** a vyhledejte osobu.
2. Spusťte náhled dopadu a projděte všechny navázané kategorie dat.
3. Vyřešte blokace, zejména neukončenou retenci, aktivní vztah, otevřené řízení či povinný dokument.
4. Znovu zkontrolujte rozsah a bezpečně zaznamenejte právní důvod bez nadbytečných osobních údajů.
5. Proveďte potvrzovací kroky zobrazené aplikací; poslední potvrzení považujte za nevratné.
6. Po dokončení ověřte výsledek a uchovejte pouze dovolený auditní záznam o operaci.

## Stavy

Požadavek může být připravený, blokovaný, čekající na potvrzení, dokončený nebo chybový. Náhled nic nemaže. Dokončený výmaz nelze použít k obnovení původního obsahu.

## Kontroly a bezpečnost

Výmaz může dokončit jedna oprávněná účetní. Před posledním krokem musí ověřit firmu, osobu i rozsah a aplikace její kroky zapíše do auditní stopy. Nevytvářejte nechráněnou kopii „pro jistotu“, protože by tím smysl výmazu zanikl. Zálohy a externí exporty řešte podle schválené politiky firmy.

## Časté chyby

- Výmaz jen kvůli žádosti bez kontroly zákonné povinnosti uchování.
- Záměna osoby se stejným jménem.
- Opomenutí exportovaných PDF, bankovních souborů nebo externího archivu.
- Uložení úplných mazáných údajů do auditní poznámky.

## Návaznosti

Nejdříve vždy projděte [retenční lhůty](76_Retencni_lhuty.md). Vazby mohou vést do [zaměstnanců](69_Zamestnanci.md), [dokumentů](66_Dokumenty_a_vystupy.md), [plateb](65_Platby_a_uhrady.md) a [podání](68_Podani_a_hlaseni.md).



## Podrobný pracovní postup a kontroly

Obrazovka **Mzdy → Výmaz osobních údajů** (oprávnění `payroll.erasure`) je
jediné místo, odkud se mzdová osobní data mažou. Výmaz je **nevratný** —
data zpátky nikdo nezadá —, proto je rozdělený do tří kroků, které se dají
zkontrolovat každý zvlášť.

### 1. Sestavit návrh

Zadá se den, ke kterému se posuzuje, a aplikace sestaví návrh **jen** z osob,
kterým lhůta uplynula a nic je nedrží. Když k tomu dni není koho navrhnout,
řekne to a nevytvoří nic — prázdný návrh by se dal schválit a v přehledu by
vypadal jako provedený výmaz.

### 2. Schválit nebo zamítnout

Detail návrhu jmenuje každou osobu a u ní uvádí:

- **Rozsah** — *úplný výmaz* u osoby bez účetní stopy, jinak *anonymizace*:
  účetní záznam zůstane, zmizí z něj jen osobní údaj.
- **Podle ustanovení** — lhůta, o kterou se rozhodnutí opírá, i s tím, jak je
  doložená.
- **Dopad** — kolik řádků osobních dat zmizí, po skupinách.
- **Zbytek** — osobní údaj, který zůstane ve zmrazeném obsahu (vystavená PDF,
  odeslaná XML). Ten se nepřepisuje a návrh to říká předem, ne až potom.

Zamítnutý návrh zůstává v přehledu jako doklad, ale provést už ho nelze.

### 3. Provést

Provedení je samostatný krok nad **schváleným** návrhem. Neschválený návrh
aplikace odmítne. Potvrzovací dialog vypíše dotčené osoby a vyžaduje dvojí
potvrzení — zaškrtnutí, že rozumíte nevratnosti, a opsání čísla návrhu.
Jedním kliknutím se výmaz spustit nedá.

Každá položka se **před provedením posuzuje znovu**. Co mezi schválením
a provedením dostalo zadržení nebo se u toho změnil rozsah, se přeskočí
s uvedeným důvodem místo aby se provedlo podle zastaralého rozhodnutí.
Výsledek u každé osoby je vidět ve sloupci **Výsledek**.

### Co po výmazu zůstane

Návrh zůstává jako **doklad, že výmaz proběhl**: kdo ho schválil, kdy se
provedl a podle které lhůty se rozhodovalo. V auditní stopě zůstává i jméno
osoby — je to vědomé rozhodnutí, aby šlo doložit, o koho šlo. Samotná osobní
data z evidence zmizí; u úplně vymazané osoby proto zůstane řádek návrhu bez
jména a obrazovka to napíše.
