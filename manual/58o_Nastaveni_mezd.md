# Nastavení mezd

## Účel

Nastavení mezd obsahuje údaje zaměstnavatele, výchozí účty, termíny, institucionální identifikátory a předkontace používané napříč mzdovým tokem.

## Předpoklady a oprávnění

Je nutné oprávnění `payroll.settings`. Připravte ověřené identifikační údaje, symboly ČSSZ a zdravotních pojišťoven, bankovní účty a schválený účtový rozvrh. ISDS se nastavuje samostatně v obecném nastavení firmy.

## Krokový postup

1. Otevřete **Mzdy → Nastavení mezd**.
2. Vyplňte identifikaci zaměstnavatele a údaje pro dokumenty a podání.
3. Nastavte účty a splatnosti pro mzdy, daň, sociální a zdravotní pojištění.
4. Doplňte přidělené identifikátory institucí.
5. Nastavte výchozí předkontace a zkontrolujte jejich existenci v účtovém rozvrhu.
6. Pro podání vytvořte oddělené TEST a produkční profily; certifikát uložte jen do určeného bezpečného úložiště.

## Stavy

Rozpracované nastavení lze uložit, ale navazující krok může být blokován. Validační chyba označuje neúplný nebo neplatný údaj. Úspěšné uložení nepotvrzuje, že identifikátor či účet uznala externí instituce.

## Kontroly a bezpečnost

Ověřte každou hodnotu proti oficiálnímu zdroji a správné firmě. Privátní klíče, hesla a SMS kódy nepatří do poznámek ani příloh. Testovací certifikát ČSSZ používejte jen v TEST profilu a produkční konfiguraci ověřte samostatně.

## Časté chyby

- Identifikátor nebo účet zkopírovaný z jiné firmy.
- Záměna TEST a produkčního prostředí.
- Chybějící předkontace blokující účetní krok.
- Domněnka, že nastavení ISDS automaticky odesílá podání nebo načítá inbox.

## Návaznosti

Osoby a vztahy založíte v [kapitole 58k](58k_Zamestnanci.md), složky v [58p](58p_Mzdove_slozky_a_vstupy.md), účetní kontrolu v [58f](58f_Shoda_uctovani_mezd.md) a elektronické odeslání v [58j](58j_Podani_a_hlaseni.md).



## Podrobný pracovní postup a kontroly

V **Mzdy → Nastavení mezd** se evidují registrační a kontaktní údaje
pro mzdovou agendu. Stránka používá čtyři samostatné záložky:
**Zaměstnavatel a účtárny**, **Účty institucí**, **Automatické účtování** a
**Politiky a připravenost**. Firma může mít více mzdových účtáren, ale právě jedna
aktivní účtárna musí být označena jako výchozí. Každá účtárna má vlastní název,
kód a vlastní variabilní symbol pro platby sociálního pojištění. Vyplňuje se
**název**; kód se z něj předvyplní sám (bez diakritiky, velkými písmeny) a
při shodě s existující účtárnou se odliší číselnou příponou. Přepsat ho můžete
kdykoli — jakmile do něj sáhnete, přestane se z názvu odvozovat. Stejně se
chová kód mzdové složky, dimenze (středisko/zakázka/činnost) i ručně zadávané
instituce. Pole
**Registrační číslo zaměstnavatele** slouží pro evidenci a podání; není
variabilním symbolem platby.

V záložce **Podání** se potvrzuje samostatný profil REGZEL. Obsahuje
čtyřmístný `kodFU`, povinný `kodPracovisteFU` (kromě Specializovaného
finančního úřadu s kódem 4000), případné devítimístné VČP
začínající `6` a evidenční příznaky
zaměstnavatele. Kód pracoviště může aplikace nabídnout z daňového nastavení
firmy, ale použije jej až po výslovném potvrzení; `kodFU` nikdy neodvozuje.
VČP vyplň pouze tehdy, pokud je firmě skutečně přidělil správce daně; nejde
o registrační číslo zaměstnavatele ani o variabilní symbol ČSSZ.

V části **Platební účty institucí** se evidují účty ČSSZ, finančního úřadu,
zdravotních pojišťoven, zákonného pojištění a dalších příjemců. Pro každý účet
vyber typ instituce a ulož zaměstnavatelský variabilní symbol, měnu, období
platnosti, druh ověřovacího zdroje a datum ověření; reference zdroje (číslo
sdělení nebo dopisu) je nepovinná a lze ji nechat prázdnou. Stejně tak jsou
volitelné reference podkladů v politikách zaměstnavatele a u počátečních stavů
převzatých z předchozího zpracování. Povinná pole jsou ve formuláři označená
hvězdičkou. V seznamu jsou celé číslo účtu a variabilní symbol vidět hned
v prvních sloupcích, bez rozklikávání; v úložišti zůstává účet šifrovaný.
Změnu samotného účtu, typu nebo kódu
instituce či začátku platnosti založ jako nový historický záznam; u existujícího
záznamu lze bezpečně upravit název, platební symboly, konec platnosti a údaje
o ověření. Období stejné instituce a měny se nesmějí překrývat.

Osobní variabilní symbol ČSSZ a číslo pojištěnce OSVČ v obecném nastavení firmy
zůstávají určena pro vlastní odvody fyzické osoby. Platby zaměstnavatele je
nepřebírají. U právnické osoby se tato osobní pole v obecném nastavení
nezobrazují; identifikátory zaměstnavatele se ukládají jen v mzdovém nastavení.
Automatické návrhy a rozpoznání bankovních plateb používají aktivní mzdovou
účtárnu a účet příslušné instituce platný k datu platby; nejednoznačný nebo
historický údaj zůstane k ručnímu posouzení.

Na stejné stránce se nastavují výchozí účty automatického zaúčtování. Samostatně
se rozlišuje mzda zaměstnance mimo výkon funkce, příjem společníka a odměna za
výkon funkce člena orgánu. Dále se vybírají účty pojistného, daně a ostatních
srážek. Nabídka obsahuje jen aktivní účty vhodného typu z účtového rozvrhu firmy.
Příznak automatického zaúčtování se při uzamčení vstupů uloží do neměnné revize
mzdového běhu. Je-li pro dané období vypnutý, schválení automatický účetní deník
nevytvoří. Pozdější změna politiky už uzamčený běh nezmění; chybějící nebo
neplatná politika automatické účtování bezpečně zastaví.

V záložce **Politiky a připravenost** se vede časová historie výplatního dne,
pravidla posunu na pracovní den, zaokrouhlení doplatku, oprávnění účetní,
automatických kroků a bezpečného doručení. Jedna oprávněná účetní může celý
mzdový tok dokončit a odeslat bez povinného zásahu druhé osoby. Období dvou politik se nesmějí
překrývat. Nové budoucí pravidlo proto založ až po ukončení platnosti
předchozího záznamu. Původ systémového nebo migrovaného záznamu nelze při
ruční úpravě změnit.

V záložce **Dimenze** se vedou mzdová střediska, zakázky a činnosti — vlastní
číselník nezávislý na účetním rozvrhu, takže funguje i ve firmě v daňové
evidenci. Každá dimenze má typ, kód, název, období účinnosti a volitelný
výchozí analytický účet k předkontacím automatického můstku. Kód je unikátní
v rámci typu jen s ohledem na účinnou historii — stejný kód a typ lze znovu
použít v neprekrývajícím se pozdějším období. Dimenzi použitou ve schválené
mzdové revizi nejde smazat, jen ukončit její účinnost; nepoužitou dimenzi lze
smazat běžně. Konkrétní přiřazení střediska, zakázky nebo činnosti pracovnímu
vztahu se vede přímo na kartě daného vztahu v seznamu zaměstnanců, opět
s vlastním obdobím účinnosti a bez souběhu dvou dimenzí stejného typu.

Kontrola připravenosti se spouští k vybranému dni. Ukazuje každý ověřený
předpoklad i přesný blokující nedostatek. Kontrolují se jen funkce, které firma
skutečně zapnula; zapnutá automatizace, JMHZ nebo bezpečné doručení však bez
pozitivního důkazu zůstávají zablokované. Přepínač **Vést mzdy** je nadále jen
v obecném Nastavení firmy a na této stránce se neduplikuje.

Změny se ukládají s kontrolou souběžné editace. Pokud mezitím nastavení změnil
jiný uživatel, aplikace zobrazí přesný důvod konfliktu. Tlačítko pro načtení
aktuální verze obnoví také její nové číslo verze a teprve potom dovolí úpravu
uložit znovu.
