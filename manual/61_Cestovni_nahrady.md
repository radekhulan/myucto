# Cestovní náhrady

## 61.1 Účel

Agenda cestovních náhrad eviduje pracovní cestu a její vyúčtování jako podklad pro nárok zaměstnance a případný dopad do mzdy.

## 61.2 Předpoklady a oprávnění

Musí existovat zaměstnanec a vztah. Připravte schválenou cestu, časy, místo, dopravní prostředek, zálohy a účetní doklady. Sazby a kurz musí odpovídat rozhodnému dni a platným pravidlům.

## 61.3 Krokový postup

1. Otevřete **Mzdy → Cestovní náhrady** a založte cestu pro správného zaměstnance.
2. Vyplňte začátek, konec, místo, účel a použitou dopravu.
3. Doplňte doložené výdaje, poskytnuté zálohy, měny a kurzy.
4. Zkontrolujte vypočtené stravné a krácení podle poskytnutého jídla.
5. Schválený výsledek předejte do platby nebo mzdového vstupu podle firemního postupu.

## 61.4 Stavy

Cesta může být rozpracovaná, připravená ke kontrole, schválená nebo vypořádaná. Rozpracovaný výpočet není účetním dokladem ani příkazem k úhradě.

## 61.5 Kontroly a bezpečnost

Ověřte časová pásma, měnu, kurz, zákonné sazby a odpočet zálohy. Přílohy mohou obsahovat osobní údaje; ukládejte je bezpečně. Nepoužívejte cestovní náhradu jako obecnou nezdaněnou mzdovou složku.

## 61.6 Časté chyby

- Chybné datum kurzu nebo měna.
- Neodečtená záloha či duplicitně vložená účtenka.
- Opomenuté krácení stravného.
- Schválení bez vazby na skutečný pracovní vztah a účel cesty.

## 61.7 Návaznosti

Mzdový dopad zkontrolujte v [rychlém měsíčním vstupu](62_Rychly_mesicni_vstup.md) a [mzdovém běhu](63_Mzdove_behy.md). Úhradu dokončete podle [kapitoly 58g](65_Platby_a_uhrady.md).



## 61.8 Podrobný pracovní postup a kontroly

V **Mzdy → Cestovní náhrady** vedeš tuzemské pracovní cesty a jejich vyúčtování.
U cesty zadej pracovní vztah, odjezd a návrat s časem, místo, účel a dopravní
prostředek. Čas zadáváš místní, tak jak ho vidíš na hodinách; systém si k němu
uloží časovou zónu, takže cesta a rozvržená směna na sebe sedí i v období změny
letního času. Hodinu, která se na jaře přeskakuje, formulář nepřijme — v místním
čase totiž neexistuje. K vyúčtování přidáš doložené výdaje (jízdné, ubytování, nutné
vedlejší výdaje), jízdy soukromým vozidlem v kilometrech a spotřebě na 100 km,
bezplatná jídla po dnech a poskytnutou zálohu.

Nárok se počítá z účinné vyhlášky k rozhodnému dni:

- stravné podle časových pásem pracovní cesty (5 až 12 h, nad 12 do 18 h,
  nad 18 h) za každý kalendářní den; u cesty spadající do dvou kalendářních dnů
  se použije výhodnější varianta;
- krácení stravného za každé poskytnuté bezplatné jídlo;
- základní náhrada za ujetý kilometr a náhrada za spotřebované pohonné hmoty
  z průměrné ceny podle vyhlášky, nebo z doložené ceny;
- doložené ubytování a nutné vedlejší výdaje.

Náhled ukazuje rozpad po dnech i po položkách a rozdělí výsledek na část **do
zákonného limitu**, která není předmětem daně, pojistného ani exekučních srážek,
a na **nadlimitní část**, která do mzdy vstupuje jako zdanitelný příjem a do
vyměřovacích základů. Sazba stravného nižší než zákonné minimum, chybějící
účinná sazba i zahraniční pracovní cesta skončí v ruční kontrole a vyúčtování
nelze schválit.

Schválené vyúčtování promítneš tlačítkem **Promítnout do mzdy**; založí mzdové
vstupy na složkách `CESTOVNI_NAHRADA_LIMIT` a `CESTOVNI_NAHRADA_NADLIMIT`
v období vyúčtování. Opakované promítnutí nevytvoří duplicitu.

V podvojném účetnictví se náhrada účtuje na nákladový účet cestovného (výchozí
kontace 512) proti závazkovému účtu pracovního vztahu, tedy proti témuž účtu,
ze kterého se zaměstnanci vyplácí mzda. Účet lze změnit v
[Nastavení mezd](73_Nastaveni_mezd.md#7381-predkontace-pro-zvlastni-mzdove-situace).
Na výplatu to nemá vliv — zaměstnanec dostane přesně tutéž částku. Zakládat a
upravovat cesty smí role s oprávněním pro mzdové vstupy, schválení a promítnutí
vyžaduje oprávnění pro schvalování.
