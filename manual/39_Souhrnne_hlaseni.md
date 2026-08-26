# 39. Souhrnné hlášení (DPHSHV)

### 39.1.1 Cesta: `Daně → Souhrnné hlášení`

Souhrnné hlášení vykazuje vybraná uskutečněná B2B plnění osobám registrovaným
k DPH v jiném členském státě EU. Může je podávat i identifikovaná osoba, pokud
takové plnění uskutečnila. Samotné pořízení zboží nebo služby ze zahraničí do SH
nepatří; vykazuje se v přiznání DPH, případně v KH.

## 39.2 Co se zahrne

Zdroj tvoří řádky vystavených, ne-stornovaných a ne-konceptních faktur ve stejné
evidenci DPH jako [DPHDP3](36_Vykazy_DPH.md). Rozhoduje DUZP, případně datum vystavení,
nikoli úhrada. Protistrana musí mít zemi EU mimo ČR a použitelné EU VAT ID. VAT ID se
normalizuje bez mezer; řecký prefix se pro XML převádí na `EL`.

Podporované mapování je:

| Klasifikace DPH | `k_pln_eu` v XML | Význam |
|---|---:|---|
| `20` | `0` | Dodání zboží do jiného členského státu |
| `31` | `2` | Třístranný obchod — prostřední osoba |
| `22` | `3` | Poskytnutí služby s místem plnění v EU |

Kód `1` formuláře pro přemístění vlastního zboží nemá samostatnou vestavěnou
klasifikaci. Takový případ doplň ručně v EPO a nech zkontrolovat poradcem. Faktura
bez VAT ID nebo bez podporované klasifikace se do XML nezařadí, proto před exportem
porovnej seznam faktur s náhledem; nejde o bezpečnou náhradu kontroly VIES.

## 39.3 Seskupení a zaokrouhlení

Řádky se seskupí podle státu, normalizovaného VAT ID a typu plnění. Hodnota je základ
v Kč ze všech dokladů skupiny a počet je počet různých faktur. Atribut `pln_hodnota`
se zapisuje na celé Kč zaokrouhlením **od nuly** — kladná hodnota nahoru, záporná dolů.
Souhrnný náhled může proto zobrazovat haléře, zatímco jednotlivý řádek XML celé koruny.

**Záporná hodnota plnění** (dobropis do JČS převyšuje v období dodávky téže protistraně)
do řádného hlášení nepatří: opravy se podávají **následným** souhrnným hlášením s kódem
storna, které aplikace zatím negeneruje, a EPO řádné hlášení se zápornou `pln_hodnota`
odmítne. Náhled na takový řádek upozorní se jménem protistrany a částkou, ať to zjistíš
před odesláním, ne až z chybové hlášky portálu.

## 39.4 Období a typ podání

Hlášení lze sestavit měsíčně nebo čtvrtletně. Čtvrtletí je určeno jen pro plátce,
kteří v něm poskytují výhradně služby s kódem `22`. Dodání zboží (`20`) nebo
třístranný obchod (`31`) vyžaduje měsíční režim. Aplikace na neslučitelnou kombinaci
upozorní, export však technicky nezablokuje. Prázdné SH se nepodává.

Generátor vytváří pouze řádnou formu DPHSHV. Opravné či následné
souhrnné hlášení a zvláštní případy dokonči ručně na portálu podle pokynů správce
daně. Termín je zpravidla do 25. dne po skončení období.

## 39.5 Náhled a kontrola před exportem

Po změně období se náhled přepočítá a ukáže počet souhrnných řádků, celkovou
hodnotu v Kč a termín podání. Tabulka rozepisuje stát, VAT ID, protistranu, kód a
typ plnění, počet zahrnutých dokladů a jejich součet. Jeden zobrazený řádek tedy
může zastupovat více faktur; před exportem porovnej počet v tabulce se zdrojovými
doklady a věnuj pozornost varováním nad náhledem.

Při čtvrtletním režimu se vybírá čtvrtletí, při měsíčním konkrétní měsíc. Pokud
náhled neobsahuje žádné řádky, ověř nejprve klasifikaci plnění, zemi a VAT ID
odběratele. Prázdný náhled sám o sobě neprokazuje, že firma neměla vykazované
plnění.

## 39.6 Export a důkaz podání

Stažené XML projde strukturální validací a uloží se do Archivu podání jako stažené.
Stažení samo neznamená odeslání. Po nahrání na portál zkontroluj jeho výsledek,
odešli formulář a uschovej potvrzení. Backend umí archivní záznam označit jako
odeslaný, běžná stránka Archivu podání ale tento krok nenabízí; bez něj
archiv není spolehlivým dokladem skutečného podání.

Tlačítko pro stažení je dostupné uživateli s právem exportovat výkazy. Uživatel
bez tohoto práva může náhled zkontrolovat, ale XML nestáhne.
