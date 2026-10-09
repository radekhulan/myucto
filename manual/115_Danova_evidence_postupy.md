# 115. Daňová evidence - postupy

> Postupy pro běžné situace u firmy, která vede daňovou evidenci podle § 7b zákona o daních z příjmů. Kapitola doplňuje
> [Daňovou evidenci](74_Danova_evidence.md): tam je popsaný peněžní deník a uzávěrka, tady najdete, jak zadat konkrétní případ,
> aby se v peněžním deníku objevil správně.

## 115.1 Kdy to potřebujete

- Vystavili jste nebo dostali dobropis k faktuře a nevíte, jak ho vyrovnat.
- Platíte energie podle platebního kalendáře a nechcete zakládat každou platbu zvlášť ručně.
- Platíte nebo přijímáte zálohy.
- Na výpisu máte bankovní poplatky, převody mezi vlastními účty, vklady nebo výběry, ke kterým neexistuje doklad.
- Platíte nebo inkasujete hotově.
- Kupujete majetek a potřebujete vědět, kdy je výdajem.
- Blíží se konec roku a chcete mít daňovou evidenci uzavřenou.

Nápověda k ostatním modulům je často psaná pro podvojné účetnictví (účty, předkontace, zaúčtování). V daňové evidenci se nic
nezaúčtovává: rozhoduje doklad, jeho úhrada a zařazení v peněžním deníku. Kde se postup liší, odkazuje nápověda sem.

## 115.2 Než začnete

- Firma má v nastavení dodavatele režim **Daňová evidence** (viz [§ 74.9.1](74_Danova_evidence.md#7491-zapnuti-rezimu-a-dostupnost-v-menu)).
- Bankovní výpisy načítáte do aplikace. Peněžní deník bere příjmy a výdaje ze skutečných úhrad, ne z vystavených dokladů.

## 115.3 Krok za krokem: dobropis a zápočet s fakturou

1. Dobropis k vydané faktuře vystavte v detailu faktury přes **Storno / dobropis**, přijatý dobropis zadejte jako přijatý
   doklad typu **Dobropis** a vyberte **Opravovanou fakturu**.
2. Po vystavení (resp. přijetí) se dobropis s nezaplacenou fakturou sám započte: faktuře klesne **Zbývá uhradit** o částku
   dobropisu a dobropis je vyrovnaný.
3. Odběratel (resp. vy) zaplatí jen rozdíl. Platbu spárujte v bance jako obvykle, aplikace ji páruje se zbytkem faktury.

**Jak poznáte, že je hotovo:** v detailu faktury je řádek **Zbývá uhradit sníženo ... dobropisem** a v platbách zápočet se zdrojem
**Zápočet dobropisu**. Po úhradě zbytku je faktura zaplacená.

- V peněžním deníku je jen skutečná úhrada zbytku. Zápočet dobropisu není platba, DPH se nemění.
- Byla-li faktura v době dobropisu už zaplacená, dobropis zůstává k vrácení peněz. Vrácení spárujte v bance jako odchozí platbu.
- Starší dobropis započtete v jeho detailu tlačítkem **Započíst s fakturou**, zápočet zrušíte tlačítkem **Zrušit zápočet**.

Podrobnosti: [§ 15.9.9](15_Faktura_editor.md#1599-storno-vs-dobropis) a [§ 23.11.11](23_Prijate_faktury.md#231111-zauctovani-dobropisu).

## 115.4 Krok za krokem: platební kalendář energií

Platební kalendář (typicky elektřina nebo plyn) je daňový doklad na zálohy podle § 31a zákona o DPH. DPH a výdaj vznikají
u každé platby zvlášť, proto každou platbu evidujte jako samostatný přijatý doklad.

1. Zadejte první platbu jako přijatou fakturu: dodavatel, **číslo dokladu** z kalendáře, datum vystavení kalendáře, **splatnost**
   a **DUZP** = den splatnosti platby, částka a sazba DPH podle kalendáře.
2. Další platby zadejte stejně, se stejným číslem dokladu. Aplikace upozorní, že doklad s tímto číslem už existuje, a zeptá
   se, zda ho uložit přesto. Potvrďte. Ochrana proti omylu tím zůstává zachovaná.
3. Každou platbu spárujte s bankovním pohybem. Zaplatíte-li jindy než ke dni splatnosti, opravte DUZP na den úhrady.
4. Roční vyúčtování zadejte podle dokladu včetně odečtených záloh, k úhradě zůstane nedoplatek. Přeplatek je doklad se
   zápornou částkou, vrácení spárujte jako příchozí platbu.

**Jak poznáte, že je hotovo:** každá platba kalendáře je v peněžním deníku jako výdaj ke dni úhrady a v kontrolním hlášení
jako samostatný řádek (stejné číslo dokladu, jiné datum povinnosti přiznat daň).

## 115.5 Krok za krokem: zálohy

- **Přijatá záloha (zálohová faktura dodavatele):** zadejte ji jako přijatý doklad typu **Záloha** a zaplaťte. Zaplacená záloha
  je v daňové evidenci výdajem ke dni úhrady. Konečnou fakturu propojte se zálohou (viz [§ 23.8](23_Prijate_faktury.md#238-krok-za-krokem-sparovat-zalohu-s-vyuctovaci-fakturou)),
  k úhradě pak zůstane jen rozdíl a výdaj se nezapočte dvakrát.
- **Vydaná záloha (zálohová faktura odběrateli):** přijatá platba zálohové faktury je příjmem ke dni úhrady. Konečnou fakturu
  vystavte ze zálohové, odečte zaplacenou zálohu.

## 115.6 Krok za krokem: bankovní poplatky, převody mezi účty, vklady a výběry

Pohyby bez dokladu zařaďte v peněžním deníku (viz [§ 74.4](74_Danova_evidence.md#744-krok-za-krokem-zaradit-nezarazeny-pohyb)),
nebo si na opakované případy založte pravidlo v `Daňová evidence → Pravidla bankovních pohybů`
(viz [§ 74.9.16](74_Danova_evidence.md#74916-pravidla-bankovnich-pohybu)).

<!-- cols: 30 34 36 -->
| Pohyb | Zařazení | Pravidlo |
|---|---|---|
| Bankovní poplatek | Daňový výdaj (poplatek s textem „poplatek" aplikace pozná sama) | text „poplatek", jen odchozí |
| Převod mezi vlastními účty | Převod | protiúčet vlastního účtu, **Ignorovat** + **Převod** |
| Vklad z osobních peněz, výběr pro osobní potřebu | Soukromé | protiúčet osobního účtu, **Ignorovat** + **Soukromé** |
| Platba vlastní daně z příjmů nebo pojistného OSVČ | Nedaňový výdaj (aplikace pozná podle textu) | podle VS nebo protiúčtu finančního úřadu či pojišťovny |

> [!TIP]
> **Ignorovat** znamená jen „nepárovat s doklady". Pohyb v peněžním deníku zůstává, jinak by zůstatek deníku nesouhlasil s bankou.

## 115.7 Krok za krokem: hotovost a pokladna

1. Založte pokladnu v [Pokladně](32_Pokladna.md).
2. Hotovostní úhradu faktury zadejte volbou formy úhrady **Hotově** s pokladnou, pokladní doklad vznikne sám.
3. Ostatní příjmy a výdaje v hotovosti zapište pokladním dokladem se správným účelem (prodej, nákup, převod, ostatní).
4. Vklad hotovosti na účet nebo výběr z bankomatu zapište pokladním dokladem s účelem **Převod** a bankovní pohyb zařaďte
   jako **Převod**.

**Jak poznáte, že je hotovo:** zůstatek pokladny v peněžním deníku odpovídá skutečné hotovosti.

## 115.8 Krok za krokem: majetek a odpisy

- **Dlouhodobý majetek** (nad hranici pro hmotný majetek podle zákona o daních z příjmů): kupní cena není výdajem při úhradě.
  Na přijaté faktuře ho označte jako majetek, založte kartu v [Majetku](28_Majetek.md) a výdajem jsou daňové odpisy za rok.
- **Drobný majetek** (pod hranicí): je výdajem při úhradě. Evidujte ho v [Drobném majetku](27_Drobny_majetek.md), aby byl dohledatelný
  při inventuře.
- Pravidla nákladů v daňové evidenci (viz [§ 74.9.15](74_Danova_evidence.md#74915-pravidla-nakladu-v-danove-evidenci)) umí druh
  nákladu a uznatelnost nastavit u opakovaných dodavatelů sama.

## 115.9 Krok za krokem: uzávěrka roku

1. V peněžním deníku nesmí zůstat nezařazené pohyby (viz [§ 74.3](74_Danova_evidence.md#743-krok-za-krokem-zkontrolovat-penezni-denik)).
2. Zkontrolujte pohledávky a závazky (viz [§ 74.5](74_Danova_evidence.md#745-krok-za-krokem-sledovat-pohledavky-a-zavazky)).
3. Potvrďte daňové odpisy majetku za rok.
4. Dokončete roční uzávěrku včetně inventury a nepeněžních úprav, například zápočtu pohledávky a závazku nebo naturálního
   příjmu (viz [§ 74.6](74_Danova_evidence.md#746-krok-za-krokem-dokoncit-rocni-uzaverku)).

**Jak poznáte, že je hotovo:** uzávěrka je dokončená a její stavy se přenesly do přiznání k dani z příjmů.

## 115.10 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Faktura s dobropisem má pořád celou částku k úhradě | Dobropis nemá vyplněnou opravovanou fakturu, nebo faktura už byla zčásti zaplacená víc, než kolik zbývá na dobropis | Doplňte vazbu, nebo dobropis vraťte penězi |
| V peněžním deníku je převod mezi účty jako nezařazený příjem | Pohyb nemá zařazení | Zařaďte ho jako **Převod**, nebo založte pravidlo |
| Ignorovaný pohyb je pořád v peněžním deníku | Tak to má být, ignorování jen ruší párování | Zařaďte ho nebo nastavte zařazení v pravidle |

## 115.11 Související kapitoly

- [Daňová evidence](74_Danova_evidence.md) - peněžní deník, zařazení pohybů, uzávěrka.
- [Přijaté faktury](23_Prijate_faktury.md) - zadání dokladu, zálohy, dobropisy.
- [Editor faktury](15_Faktura_editor.md) - dobropis a vyúčtování.
- [Banka](29_Banka.md) - načtení výpisů a párování.
- [Pokladna](32_Pokladna.md) - hotovost.
- [Majetek](28_Majetek.md) a [Drobný majetek](27_Drobny_majetek.md).
