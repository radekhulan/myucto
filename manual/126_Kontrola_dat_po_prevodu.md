# 126. Kontrola dat po převodu

> Sada kontrol nejčastějších chyb dat převzatých z jiného účetního programu (POHODA, Money S3,
> ABRA Flexi a další). Každá kontrola ukáže stav, nálezy po dokladech s proklikem a tam, kde je
> oprava jednoznačná, náhled opravy a opravu. Ostatní nálezy jen ohlásí s postupem v této kapitole.

## 126.1 Kdy to potřebujete

Kontrolu spusťte:

- hned po ostrém převodu z POHODY ([§ 107.6](107_Prechod_z_POHODY.md#1076-krok-za-krokem-kontrola-prevzeti))
  nebo z Money S3 ([§ 106.6](106_Prechod_z_Money_S3.md#1066-krok-za-krokem-kontrola-prevzeti)),
- u firmy převedené dřív, když nesedí DPH, saldokonto, stav bankovního účtu nebo se uhrazený doklad
  vrátil mezi neuhrazené,
- před první uzávěrkou nebo prvním přiznáním k DPH v MyÚčtu.

Kontrola nic nemění, dokud opravu sami nepotvrdíte. Spouštět ji můžete opakovaně.

## 126.2 Krok za krokem: kontrola a oprava

1. Otevřete `Systém → Přechod z jiných účetních systémů` a poslední dlaždici **Kontrola dat po převodu**.
   Stejnou stránku otevře tlačítko **Zkontrolovat data po převodu** na konci průvodce převodu
   z POHODY a z Money S3.
2. Stránka ukáže programy, ze kterých firma data převzala, a jen kontroly, které se jich týkají.
   U každé kontroly vidíte stav, počet nálezů, kolik jich jde opravit a částku:
   - **V pořádku**: kontrola nic nenašla,
   - **K opravě**: aspoň jeden nález oprava opraví,
   - **Ke kontrole**: nálezy, které oprava nezmění (oprava není jednoznačná nebo leží v chráněném období).
3. Otevřete **Detail** kontroly. Nálezy můžete filtrovat podle stavu (K opravě, Chráněné období,
   Ke kontrole). Číslo dokladu otevře doklad, výpis nebo saldokonto účtu.
4. U kontroly s opravou zvolte **Opravit**. Náhled ukáže, které doklady se změní, jakou částkou
   a co oprava jen ohlásí. Kontrola bankovních účtů nabízí v náhledu volbu pro výpisy nahrané po převodu.
5. Zkontrolujte náhled a zvolte **Provést opravu**. Když se data mezi náhledem a potvrzením změnila
   (souběžná úprava), oprava neproběhne a náhled se načte znovu.
6. Po opravě se kontrola spustí znovu a ukáže zbylé nálezy.

**Jak poznáte, že je hotovo:** Kontroly s opravou nemají nálezy ve stavu K opravě a nálezy ke kontrole
máte projité podle postupu u jednotlivých kontrol.

## 126.3 Úhrady faktur bez záznamu platby

**Co kontrola hledá:** vydanou fakturu nebo zálohovou fakturu, jejíž uhrazená částka je vyšší než
součet evidovaných plateb. Převod nesl část úhrad jen v uhrazené částce dokladu:

- **POHODA**: úhrady z let před začátkem převedené agendy,
- **Money S3**: zápočty, úhrady interním dokladem a úhrady bez čísla dokladu úhrady.

Bez evidované platby by první spárování nebo smazání platby uhrazenou částku přepočetlo ze součtu
plateb, doklad by se vrátil mezi neuhrazené a odešla by na něj upomínka.

**Oprava:** doklad dostane chybějící platbu (u POHODY k poslednímu dni před začátkem agendy s poznámkou
„Převzato z POHODY, úhrada v minulém roce", u Money S3 k datu úhrady), u Money S3 přednostně zápočet
interním dokladem, na který převod navázal ruční zápis. Platba nemá bankovní pohyb ani zápis v deníku.
Deník, uhrazená částka, stav ani DPH dokladu se nemění. U Money S3 oprava zároveň doplní krytí přijatých
dokladů ([§ 126.8](#1268-prijate-doklady-uhrazene-bez-kryti)) a vazbu platby zálohy na DDKP z Money.

**Jen ke kontrole:**

- doklad vystavený v roce převedené agendy POHODY s uhrazenou částkou vyšší než platby: úhradou minulých
  let to být nemůže, ověřte úhrady v detailu dokladu,
- doklad Money S3, jehož úhrada je spárovaná s pohybem převedené banky bez evidované platby: platbu
  doplní opakovaný převod z Money S3 ([§ 106.10.8](106_Prechod_z_Money_S3.md#106108-opakovany-prevod)).

Oprava jen doplní evidenci úhrady, kterou doklad už nese, proto ji uzavřené účetní období (ani převedené
roky, které převod z Money S3 uzavřel) neblokuje. Neopraví úhradu za zámkem účtování k datu ani v podaném
období DPH ([§ 126.11](#12611-opravneni-a-pojistky)). Oprava opravuje firmu jen celou: leží-li některý
nález za zámkem nebo v podaném období, neopraví se nic a nálezy zůstanou ke kontrole.

## 126.4 Bankovní účet v cizí měně převedený v Kč

**Co kontrola hledá:** bankovní účet převzatý z POHODY v Kč, jehož pohyby jsou ve skutečnosti
přepočtem z cizí měny kurzem ČNB, a výpisy nahrané po převodu (GPC, PDF, banka) v jiné měně, než má
účet. Měnu účtu pozná podle pohybů (shoda s kurzem ČNB), zkratky a názvu účtu a měnových účtů firmy.
Chybějící kurzy ČNB kontrola stáhne.

**Oprava:** převede výpisy, pohyby, párování a deník analytiky 221 na měnu účtu, stavy výpisů spočte
od počátečního stavu v měně a upraví evidenci účtů. Korunové obraty analytiky v deníku se nezmění.
Korunový účet se stejným číslem (víceměnový účet banky) dostane stavy znovu od deníku. Výpisy
nahrané po převodu přepne na měnu účtu jen s volbou **Přepnout i výpisy nahrané po převodu** a jen ty,
které k účtu podle souboru jednoznačně patří a nemají spárování, úhradu ani zápis.

**Jen ke kontrole:** účet s nižší shodou s kurzem ČNB, s pohyby, které nezaložil převod, se společnou
analytikou s jiným účtem, s úhradami ostatních položek, nebo v uzavřeném či zamčeném období. U každého
nálezu je doporučený ruční postup. Nahraný výpis, který nenavazuje na předchozí výpis, nemažte: rozdíl
banky proti deníku dorovnejte zápisem na analytiku účtu.

## 126.5 Stavy převzatých výpisů proti deníku

**Co kontrola hledá:** převzaté výpisy korunového účtu, jejichž počáteční stav v roce se liší od
počátečního stavu analytiky 221 v deníku, nebo jejichž stavy nenavazují na pohyby. Stavy mohl posunout
první nahraný výpis banky (dorovnání převzatých výpisů podle banky) nebo převod, který spojil výpisy dvou
účtů se stejným číslem.

**Oprava:** stavy řetězu převzatých výpisů v roce se spočtou znovu od počátečního stavu v deníku a výpisy
vypadnou z evidence dorovnání, aby je smazání výpisu banky znovu neposunulo. Pohyby ani deník se nemění.
Oprava proběhne jen tam, kde je jednoznačná: všechny zápisy pohybů účtu jdou na jednu analytiku, výpisy
patří k jednomu účtu původního programu, deník má otevírací zápis analytiky v roce a období je otevřené
a bez zámku.

**Jen ke kontrole:** výpisy různých účtů původního programu se stejným číslem (nejdřív opravte měnu účtu,
[§ 126.4](#1264-bankovni-ucet-v-cizi-mene-prevedeny-v-kc)), deník bez počátečního stavu analytiky, zápisy
pohybů na více analytikách. Rozdíl mezi stavem banky a deníkem po opravě ukáže první nahraný výpis banky
jako chybějící pohyb. Výpisy v cizí měně tahle kontrola neporovnává.

## 126.6 Mzdové závazky v ostatních závazcích

**Co kontrola hledá:** rozdíl mezi zůstatkem mzdových účtů 331 (zaměstnanci), 336 (pojistné) a 342
(daň ze mzdy) v hlavní knize a neuhrazenými ostatními závazky na těchto účtech, ke konci posledního
převedeného roku (nejvýš k dnešku). Starší převody z POHODY a z ABRA Flexi nechaly mzdové závazky jen
v deníku, takže v agendě Ostatní pohledávky a závazky chyběly a nešly párovat s úhradami.

**Postup (oprava není automatická):**

1. Porovnejte rozdíl s neuhrazenými mzdovými předpisy v původním programu.
2. Je-li převod z POHODY, převeďte poslední rok znovu ([§ 107.9.7](107_Prechod_z_POHODY.md#10797-opakovany-prevod)):
   převod mzdové závazky založí bez nového zaúčtování.
3. Jinak chybějící závazky založte v `Účetnictví → Ostatní pohledávky a závazky` jako potvrzené bez
   zaúčtování (předpis už je v deníku) a spárujte je s úhradami.

## 126.7 Saldokonto proti hlavní knize

**Co kontrola hledá:** rozdíl mezi součtem otevřených položek saldokonta a zůstatkem účtu v hlavní knize
na účtech 311, 321, 324 a 325, ke konci každého účetního roku s deníkem (nebo k poslednímu zápisu).
Rozdíly do koruny jsou zaokrouhlení a kontrola je nehlásí.

**Postup:** číslo účtu otevře saldokonto k danému dni. Rozdíl může být oprávněný (přeplatek bez dokladu,
ruční zápis bez dokladu). Neoprávněný rozdíl má obvykle příčinu v jiné kontrole: úhrada bez záznamu
platby ([§ 126.3](#1263-uhrady-faktur-bez-zaznamu-platby)), přijatý doklad bez krytí
([§ 126.8](#1268-prijate-doklady-uhrazene-bez-kryti)) nebo doklad bez zápisu v deníku.

## 126.8 Přijaté doklady uhrazené bez krytí

**Co kontrola hledá:** přijatý doklad uhrazený podle původního programu (do konce posledního převedeného
roku), který evidované úhrady (banka, pokladna, zápočty, úhrada mimo evidenci) nepokrývají. Párování banky
by k němu nabídlo platbu se stejným symbolem znovu a úhradu by započetlo dvakrát.

**Oprava:** doplní chybějící krytí jako úhradu mimo evidenci (úhrada je už v deníku), u Money S3 přednostně
zápočet interním dokladem navázaným na doklad. Deník ani DPH se nemění, a proto opravu neblokuje uzavřené
účetní období, jen zámek účtování k datu a podané období DPH.

**Jen ke kontrole:** doklad z POHODY v cizí měně (úhrady POHODY jsou v Kč, krytí doplňte ručně v detailu
dokladu) a doklad s úhradou za zámkem účtování k datu nebo v podaném období DPH.

## 126.9 DDKP bez vazby na zálohu

**Co kontrola hledá:** vydaný daňový doklad k přijaté platbě (DDKP) bez vazby na zálohovou fakturu. Bez
vazby **Odečíst zálohu** na konceptu konečné faktury zálohu nenabídne, **Vystavit fakturu k záloze**
neodečte DPH z DDKP a ruční spárování se zálohou DPH zdvojí.

**Oprava:** naváže DDKP na zálohu, když:

- text DDKP uvádí číslo zálohy (POHODA ho píše jako „Daňový doklad k přijaté platbě (zálohová faktura
  č. …)" nebo „… k uhrazené zálohové faktuře č. …"),
- s tímto číslem existuje právě jedna nestornovaná záloha téhož odběratele ve stejné měně,
- celková částka DDKP a zálohy se liší nejvýš o 1 Kč a součet DDKP navázaných na zálohu nepřekročí její
  částku,
- DDKP neleží v uzavřeném či zamčeném účetním období ani v podaném období DPH.

Vazbu zapíše stejně jako tlačítko **Navázat na zálohu** v detailu dokladu: má-li záloha jedinou platbu se
stejnou částkou jako DDKP, naváže DDKP i na ni. Částky, DPH ani zaúčtování DDKP se nemění.

**Jen ke kontrole:** převzatý DDKP bez čísla zálohy v textu, záloha nenalezená nebo nejednoznačná, jiná
částka, záloha už vyčerpaná jinými DDKP. Takový DDKP navažte na zálohu ručně tlačítkem **Navázat na zálohu**
v detailu dokladu, patří-li k nějaké.

## 126.10 Konečná faktura s DPH ze zálohy podruhé

**Co kontrola hledá:** konečnou fakturu, která zálohu s DDKP odečítá jen v hlavičce (uhrazeno zálohou)
bez odpočtových řádků. DPH zálohy je pak v přiznání dvakrát: jednou z DDKP, podruhé z celé konečné faktury.
Částka nálezu je DPH z DDKP. Když částkou odpočtu sedí víc DDKP, nález ukáže všechny a je jen k ověření.

**Postup (oprava není automatická):** opravte konečnou fakturu odpočtem zálohy řádkem podle
[§ 15.4.3](15_Faktura_editor.md#1543-konecna-faktura-k-zaloze-s-danovym-dokladem-jak-nezdvojit-dph).
Leží-li faktura v podaném období DPH, nález to uvádí a opravu podejte dodatečným přiznáním a následným
kontrolním hlášením.

## 126.11 Oprávnění a pojistky

- **Zobrazení a náhled opravy:** role s přístupem do účetnictví (čtení).
- **Oprava:** správce firmy nebo role se zápisem do účetnictví, jen v aplikaci. API token a MCP server
  kontroly jen čtou.
- **Chráněné období:** co oprava nesmí změnit, ukáže ve stavu **Chráněné období**. Záleží na tom, na co
  oprava sahá:
  - doplnění evidence úhrad ([§ 126.3](#1263-uhrady-faktur-bez-zaznamu-platby),
    [§ 126.8](#1268-prijate-doklady-uhrazene-bez-kryti)) nemění deník, DPH ani uhrazenou částku. Brání mu jen
    zámek účtování k datu a podané období DPH, uzavřené účetní období ne,
  - převod účtu na měnu, přepočet stavů výpisů a vazba DDKP na zálohu mění podklady deníku, uzávěrky nebo DPH.
    Brání jim uzavřené účetní období, období v uzávěrce a zámek účtování k datu, vazbě DDKP i podané období DPH.

  Opravit je jde po otevření období nebo posunu zámku
  ([§ 52.14.10](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu)), jinak se opravují ručně.
- **Jedna transakce, auditní stopa:** každá oprava proběhne celá, nebo vůbec. Každou změnu i souhrn opravy
  zapíše do auditní stopy (`Systém → Log`). Opakované spuštění nic nezdvojí.
- **Souběh:** dvě opravy téže firmy neběží současně.

## 126.12 Příkazová řádka a MCP

Správce instalace spustí tytéž kontroly z příkazové řádky:

```bash
php api/bin/migration-data-check.php --all                     # nanečisto, všechny firmy s převodem
php api/bin/migration-data-check.php --supplier=12 --verbose   # nálezy jedné firmy
php api/bin/migration-data-check.php --supplier=12 --check=tax_document_advance_link --fix
php api/bin/migration-data-check.php --supplier=12 --check=foreign_bank_in_czk --option=include_uploaded --fix
```

Bez `--fix` nic nemění. Pojistky jsou stejné jako v aplikaci. AI asistent napojený přes MCP server čte
kontroly nástrojem `migration_data_checks` (přehled, nebo nálezy jedné kontroly); opravu nespustí.

## 126.13 Související kapitoly

- [Přechod z Money S3](106_Prechod_z_Money_S3.md)
- [Přechod z POHODY](107_Prechod_z_POHODY.md)
- [Přechod z ABRA Flexi](110_Prechod_z_ABRA_Flexi.md)
- [Faktura: konečná faktura k záloze](15_Faktura_editor.md#1543-konecna-faktura-k-zaloze-s-danovym-dokladem-jak-nezdvojit-dph)
- [Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)
- [Řešení problémů](999_Reseni_problemu.md)
