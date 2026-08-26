# 82. Vzájemné zápočty

**Cesta: `Nástroje → Zápočty`**

Zápočet vyrovná pohledávky a závazky vůči **jednomu stejnému partnerovi** bez
bankovní platby. Modul sestaví dohodu, po potvrzení vytvoří účetní zápis
**321 / 311** a sníží otevřené částky vybraných vydaných i přijatých faktur.

Aktuální implementace pracuje jen s doklady v **CZK** a jen v podvojném
účetnictví.

> Tenhle modul páruje **dva doklady** téhož partnera. Když chceš závazek nebo pohledávku
> vyrovnat proti ÚČTU (pohledávka za společníkem, mzdový závazek, poskytnutá záloha), použij
> *Označit jako uhrazené → Zápočtem proti účtu* přímo na dokladu —
> viz [§ 23.3.4](23_Prijate_faktury.md#2334-zpusoby-uhrady-prijate-faktury). Oba kanály se
> navzájem započítávají do zbytku dokladu, takže se nemůžou překrýt.

## 82.1 Přehled dohod a jejich stavy

Tabulka zobrazuje číslo dohody, datum, partnera, částku a stav:

- **Koncept (`draft`)** — dohoda je sestavená, ale doklady ani deník se ještě
  nezměnily.
- **Potvrzeno (`confirmed`)** — vznikl účetní zápis a vyrovnání dokladů.
- **Zrušeno (`cancelled`)** — případný zápis byl stornován a vytvořené vyrovnání
  odvoláno.

U dohody lze stáhnout PDF. PDF je obraz evidované dohody; samotné stažení ani
vytvoření konceptu není potvrzením protistrany.

## 82.2 Sestavení zápočtu

Tlačítko **Nový zápočet** nejprve nabídne jen partnery, kteří mají současně
otevřenou pohledávku i závazek. Po výběru načte:

- vydané faktury typu `invoice` ve stavech vystaveno, odesláno nebo upomenuto,
- přijaté faktury typu `invoice` ve stavech přijato nebo zaúčtováno,
- jejich číslo, data, celkovou, dosud uhrazenou a zbývající částku.

U každého dokladu zadáš část, která se má započíst. Součet pohledávek se musí
rovnat součtu závazků; backend porovnává částky v haléřích a toleruje nejvýše
jednohaléřový rozdíl. Každá strana musí obsahovat alespoň jeden doklad a částka
nesmí být nulová ani vyšší než aktuální zbytek.

Datum dohody určuje rok číselné řady i datum budoucího účetního zápisu. Při
uložení se z řady `offset` přidělí jedinečné číslo, standardně
`ZAP-RRRR-NNNN`. Mezery po zrušených dohodách se nedoplňují.

Vytvoření konceptu je transakční: hlavička a všechny řádky vzniknou společně,
nebo nevznikne nic. Doklady se v této fázi nemění.

## 82.3 Jak se počítá otevřená částka

U vydané faktury je zbytek:

`amount_to_pay − paid_total`

`paid_total` zahrnuje evidované platby včetně dříve potvrzených zápočtů.

U přijaté faktury je zbytek:

`amount_to_pay − bankovní párování − potvrzené zápočty`

Přijatá faktura nemá stejný `paid_total` jako vydaná, proto repozitář odečítá
`payment_matches` a potvrzené řádky zápočtů samostatně. Koncept jiné dohody se
neodečítá; teprve potvrzení musí znovu ověřit, zda zbytek mezitím nesnížila
platba nebo jiný zápočet.

## 82.4 Potvrzení a zaúčtování

Potvrzením se v jedné databázové transakci:

1. zamkne řádek dohody,
2. znovu zamknou a zkontrolují všechny dotčené doklady,
3. načte předkontace `offset.mutual` (výchozí MD 321 / Dal 311),
4. vytvoří nebo aktualizuje jediný účetní zápis se zdrojem `offset`,
5. u vydaných faktur založí evidovanou platbu,
6. u přijaté faktury nastaví stav `paid`, jen pokud zápočet pokryl celý aktuální
   zbytek,
7. dohodu označí jako potvrzenou.

Částečně započtená přijatá faktura zůstává ve stavu přijato/zaúčtováno, ale
účetní saldo 321 je o zápočet snížené. V přehledu proto vždy posuzuj i skutečný
otevřený zbytek, ne jen textový stav.

Účty lze firemně změnit v předkontacích, ale musí zůstat věcně správné. DPH se
zápočtem znovu neúčtuje: vznikla už při zaúčtování faktur.

## 82.5 Idempotence a souběh

Účetní zápis používá přirozený klíč `source_type='offset'` a ID dohody.
Opakované potvrzení proto nevytvoří druhý zápis. Platby dokladů se zakládají
jen při přechodu koncept → potvrzeno.

Samotná stavová kontrola by nestačila při dvou souběžných požadavcích. Služba
proto používá `FOR UPDATE` nad dohodou i dotčenými doklady. Druhý požadavek
počká na první a uvidí už snížený zbytek.

Pokud se od vytvoření konceptu změnila otevřená částka, potvrzení skončí chybou
`remaining_changed_since_draft`. Bezpečný postup je koncept zrušit a sestavit
znovu; systém nikdy nevytvoří skrytý přeplatek jen proto, aby starý koncept
prošel.

## 82.6 Zrušení dohody

Koncept lze zrušit bez dopadu na doklady. U potvrzené dohody systém:

- stornuje účetní zápis,
- smaže jím vytvořené platby vydaných faktur a přepočte jejich stav,
- u přijaté faktury, kterou zápočet označil jako uhrazenou, vrátí stav na
  `received`,
- ponechá dohodu ve stavu zrušeno kvůli auditní stopě.

Zrušení také drží řádkový zámek, takže se nemůže předběhnout s potvrzením.
Storno respektuje otevřenost účetního období a zámek k datu. Pokud původní
období už nelze měnit, nejdřív rozhodni o správném opravném postupu; modul
nesmí obejít uzávěrku.

## 82.7 PDF, deník a audit

PDF obsahuje hlavičku dohody, obě sady dokladů a částky. Účetní zápis je v
deníku dohledatelný podle čísla ZAP a zdrojový panel odkazuje zpět na zápočet.

Vytvoření, potvrzení i zrušení zapisuje samostatnou auditní událost s firmou,
uživatelem a technickým kontextem požadavku. Data jsou tenantově oddělená:
partner, dohoda, faktury i výsledný zápis musí patřit stejné firmě.

## 82.8 Oprávnění a chybové scénáře

Čtení vyžaduje `accounting.offsets`; vytvoření, potvrzení a zrušení jeho
zápisovou variantu. Webová stránka je dostupná jen v podvojném účetnictví.

Nejčastější chyby:

- partner už nemá současně otevřenou pohledávku a závazek,
- doklad není CZK, není fakturou nebo už není v otevřeném stavu,
- započítaná částka přesahuje zbytek,
- strany se nerovnají nebo je jedna prázdná,
- zbytek se změnil po vytvoření konceptu,
- dohoda je zrušená nebo již potvrzená,
- účet předkontace je neaktivní,
- datum leží v uzavřeném období nebo před zámkem účtování.

> [!IMPORTANT]
> Zápočet řeší účetní vyrovnání dokladů. Neprokazuje automaticky doručení,
> přijetí nebo právní účinnost dohody protistranou; příslušné podklady archivuj
> spolu s PDF.
