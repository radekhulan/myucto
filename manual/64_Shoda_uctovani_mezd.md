# Shoda účtování mezd

## 64.1 Účel

Kontrola shody porovnává uzavřený mzdový běh s účetními zápisy. Odhaluje chybějící, duplicitní nebo částkově odlišné zaúčtování.

## 64.2 Předpoklady a oprávnění

Musí existovat uzavřený běh a nastavené předkontace. Uživatel potřebuje oprávnění `payroll.post` a přístup k účetním dokladům dané firmy a období.

## 64.3 Krokový postup

1. Otevřete **Mzdy → Shoda účtování mezd** a vyberte uzavřený běh.
2. Zkontrolujte navržené účty, střediska, částky a strany Má dáti/Dal.
3. Vytvořte zaúčtování pouze jednou a poznamenejte jeho vazbu na běh.
4. Spusťte porovnání mzdových součtů s účetními zápisy.
5. Rozdíl opravte v příčině a kontrolu opakujte; nepřekrývejte jej nesouvisejícím ručním zápisem.

## 64.4 Stavy

Nezaúčtováno znamená, že pro běh není úplný účetní protějšek. Částečná nebo rozdílná shoda vyžaduje opravu. Shoda potvrzuje částkovou a vazební kontrolu, nikoli správnost celého účtového rozvrhu.

## 64.5 Kontroly a bezpečnost

Ověřte vyrovnanost, období, účty závazků, nákladů, daně, pojistného a srážek. U ručních změn zachovejte auditní stopu. Mzdový detail zpřístupněte jen oprávněným účetním.

## 64.6 Časté chyby

- Dvojí zaúčtování stejného běhu.
- Zaúčtování návrhu místo uzavřené revize.
- Rozdíl způsobený zaokrouhlením zakrytý nesouvisejícím zápisem.
- Smíchání účetních období nebo středisek.

## 64.7 Návaznosti

Zdroj je [mzdový běh](63_Mzdove_behy.md), účty nastavuje [kapitola 58o](73_Nastaveni_mezd.md) a peněžní vypořádání ověřují [mzdové příkazy a úhrady](65_Platby_a_uhrady.md).



## 64.8 Jak porovnání pracuje

V podvojném účetnictví je pro schválenou revizi dostupná stránka
**Mzdy → Shoda účtování mezd**. Pro zvolené období porovná mzdovou revizi,
skutečně zaúčtovaný deník a platební závazky po kategoriích (hrubé mzdy,
pojistné hrazené zaměstnavatelem, sociální a zdravotní pojištění, daň,
ostatní srážky, exekuční srážky a čistá mzda) a u každé ukáže, na které
straně případný rozdíl vznikl. Oprava schváleného měsíce se do porovnání
promítne správně — deník se sčítá napříč všemi revizemi běhu, protože
rozdílová revize účtuje jen rozdíl proti poslední zaúčtované revizi. Měsíc,
který ještě nebyl zaúčtován (vypnuté automatické zaúčtování, čekající krok,
nebo firma vedoucí daňovou evidenci), stránka označí jako nezaúčtovaný —
nejde o rozdíl. Stránka je čistě informační a nic nezapisuje ani do deníku,
ani do mzdové revize.

Do hrubých mezd se při porovnání započítají také nákladové účty převzaté ze
zmrazené dimenze nebo z výslovné předkontace mzdové složky. Nemusí proto jít
jen o syntetiky 521, 522 a 523. Opravná revize zachová v klasifikaci i původní
účet, aby jeho storno a přesun na nový účet skončily ve stejné mzdové kategorii.

### 64.8.1 Účetně neutrální nepeněžní plnění

Nepeněžní složka, která nemá vlastní dvojici účtů, se do mzdového deníku
nezaúčtuje — náklad je v knihách už ze zdrojového dokladu (faktura za ubytování,
za vzdělávání, leasing vozidla) a mzdový zápis by ho zaúčtoval podruhé. Pro daň
a pojistné je to ale zdanitelný příjem, takže do hrubé mzdy patří.

Dřív z toho vznikal trvale svítící rozdíl: kontrolní součty počítaly takové
plnění do hrubé mzdy, deník ne. Nově se hrubá mzda porovnává jako
**účtovatelná** část a neutrální nepeněžní plnění se z porovnání vyčleňuje —
deník ani platby k němu nemají co přiřadit, takže se z něj rozdíl nedělá.
Firma, která poskytuje například 1 % z ceny vozidla nebo přechodné ubytování,
tedy na této stránce už nevidí rozdíl, který ve skutečnosti žádný rozdíl nebyl.

Hrubá mzda na této stránce proto **není** totéž co hrubá mzda na mzdovém listu:
neutrální nepeněžní plnění v ní chybí. Pro kontrolu proti mzdovému listu
použijte mzdový list a výplatní pásku, ne kategorii hrubých mezd z porovnání.

### 64.8.2 Kdy je rozdíl v dani legitimní

Kategorie daně může vykázat rozdíl i tehdy, když je vše správně: převýší-li
vyplacené daňové bonusy sražené zálohy, kontrolní součty odvod podlahují nulou,
zatímco deník ne — účet 342 zůstane debetní. Je to skutečná **pohledávka za
finančním úřadem** podle § 35d odst. 5 zákona o daních z příjmů, kterou má
účetní vidět. Nepřekrývejte ji ručním zápisem; vypořádejte ji standardní cestou.

Pohledávka za zaměstnancem ze záporné čisté mzdy (účet 335) se do porovnání
záměrně nezapočítává jako záporná čistá mzda — obě strany rozvahy se nesčítají
do jednoho čísla se znaménkem.
