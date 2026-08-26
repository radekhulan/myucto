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
