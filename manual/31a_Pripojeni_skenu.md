# Připojení skenů k dokladům

Funkce připojí hromadu naskenovaných dokladů k dokladům, které už v aplikaci jsou.
Hodí se po převodu dat z jiného účetního programu, kdy doklady máte, ale jejich
papírové originály jsou jen jako skeny ve složce, nebo když účetní kancelář dostane
od klienta krabici naskenovaných účtenek k už zaúčtovaným dokladům.

Nový doklad se tu nezakládá. Pro založení přijaté faktury ze skenu slouží
[AI extrakce](25_AI_extrakce.md).

Stránka je v menu **Dokumenty → Skeny k dokladům**.

## Nahrání dávky

1. Klikněte na **Nahrát skeny**.
2. Vyberte soubory: PDF a obrázky (JPG, PNG, WebP, HEIC, TIFF), nebo jeden ZIP se
   skeny. Adresářová struktura v ZIP nevadí, systémové soubory (`__MACOSX`,
   `Thumbs.db`) se přeskočí.
3. Zvolte, k čemu se skeny připojují: **přijaté faktury**, **vydané faktury**,
   **pokladní doklady**. Nabízí se jen typy, ke kterým máte oprávnění přikládat
   přílohy.
4. Volitelně omezte doklady datem vystavení. Menší rozsah znamená přesnější párování
   a kratší seznam dokladů bez skenu.
5. Nastavte volby:
   - **Vytěžit obsah skenů pomocí AI** (výchozí). Bez vytěžení se páruje jen podle
     čárového kódu a čísla dokladu v názvu souboru.
   - **Pravděpodobné shody připojit bez potvrzení**. Jinak čekají v záložce Ke kontrole.
   - **Číslu dokladu v názvu souboru věřit i bez potvrzení obsahem**. Zapněte jen
     tehdy, když složka obsahuje skeny výhradně této firmy.
6. Klikněte na **Nahrát a zpracovat**.

Soubory se nahrávají po částech, takže velikost dávky neomezuje nastavení serveru.
Zpracování pak běží na pozadí a stránku můžete zavřít. Jeden soubor smí mít nejvýš
32 MB, dávka nejvýš 20 000 souborů a dohromady 2 GB. Větší soubor se nenahraje a
v dávce ho uvidíte mezi chybami.

Firma může mít najednou nejvýš tři rozpracované dávky (nahrávané nebo čekající na
zpracování). Dávky jedné firmy se zpracovávají postupně: když jedna běží, další
počká, až doběhne.

## Jak aplikace skeny páruje

Každý sken se nejdřív uloží do sekce [Dokumenty](31_Dokumenty.md) (složka
**Skeny dokladů → Dávka N**) a AI z něj přečte dodavatele, odběratele, číslo dokladu,
variabilní symbol, data, částku, číslo z nálepky s čárovým kódem, SPZ vozidla
a poslední čtyři číslice platební karty.

Páruje se podle tří klíčů, od nejjistějšího:

| Klíč | Kdy platí | Jistota |
|---|---|---|
| Čárový kód | Číslo na začátku názvu souboru nebo z nálepky na skenu se shoduje s čárovým kódem dokladu převzatým z předchozího systému | jistá |
| Číslo dokladu v názvu | Název souboru začíná číslem dokladu (`PF20260268 foto1.jpg`) | jistá, když ji potvrdí obsah skenu; jinak kandidát |
| Obsah skenu | Firma je na správné straně dokladu, sedí částka a k tomu číslo dokladu nebo VS, případně protistrana s datem ±5 dní | jistá nebo pravděpodobná |

Firma se hledá na té straně dokladu, kam patří: u přijaté faktury a výdajového
pokladního dokladu jako odběratel, u vydané faktury a příjmového dokladu jako
dodavatel. Když účtenka odběratele neuvádí, pozná se firma podle SPZ vozidla
z [knihy jízd](32_Kniha_jizd.md). Když IČO chybí, rozhoduje jméno odběratele;
sken s cizím odběratelem se nepřipojí.

Jistota se posuzuje u každého souboru zvlášť. Sken, na kterém je na straně firmy
uvedená jiná firma, se nepřipojí ani tehdy, když název souboru začíná číslem dokladu
(složka může obsahovat skeny sesterské firmy se stejnou číselnou řadou). Když k němu
sedí čárový kód, nabídne se v záložce **Ke kontrole**.

Párování probíhá **ve dvou kolech**. Nejdřív se pro všechny doklady použijí čárové
kódy a čísla dokladů, teprve potom obsah, a to jen u skenů, které zatím nikam
nepatří. Pravidelná faktura téhož dodavatele na stejnou částku (například roční
poplatek) si tak nevezme sken, který podle čárového kódu patří faktuře z jiného roku.

Když na jeden doklad sedí dva stejně dobré skeny, nebo jeden sken na dva doklady,
aplikace nerozhoduje sama a nabídne je jako kandidáty k ručnímu výběru.

## Kam se sken připojí

- Sken je vždy v sekci Dokumenty s vazbou na doklad, takže je vidět v detailu dokladu
  v panelu propojených dokumentů.
- U přijaté faktury se první sken navíc uloží jako PDF faktury, pokud faktura žádné
  PDF nemá. Obrázek se převede na PDF. Faktura v uzavřeném účetním období PDF
  nedostane (stejně jako při ručním nahrání), sken je u ní jen jako propojený dokument.
- Doklad, který už má sken z dřívější dávky, další sken podle obsahu nedostane.
  Čárový kód a číslo dokladu v názvu souboru připojí další strany dál.
- Stejný soubor se neukládá dvakrát. Když firma sken se stejným obsahem v Dokumentech
  už má, dávka použije ten existující.
- Vytěžené údaje se zapíšou jako text dokumentu. Sken bez textové vrstvy tak najdete
  vyhledáváním v Dokumentech podle dodavatele, čísla dokladu nebo částky.
- Vytěžení se ukládá k obsahu souboru. Stejný sken se znovu nevytěžuje ani v další
  dávce.
- Účtenka za pohonné hmoty připojená k přijaté faktuře nebo pokladnímu dokladu
  založí nebo doplní tankování v [knize jízd](32_Kniha_jizd.md), pokud firma knihu
  jízd vede. Doklad, který tankování už má, se jen doplní.

## Přehled dávky

Po dokončení ukáže stránka souhrn a záložky:

| Záložka | Co obsahuje |
|---|---|
| **Připojeno** | Skeny připojené k dokladům, s klíčem, podle kterého se spárovaly |
| **Ke kontrole** | Pravděpodobné shody a kandidáti. U každého je vidět, co se ze skenu vyčetlo, a údaje dokladu. Tlačítkem **Připojit** sken připojíte, tlačítkem **Odmítnout** návrh zahodíte. |
| **Doklady bez skenu** | Doklady ve zvoleném rozsahu, ke kterým není připojená žádná příloha |
| **Skeny bez dokladu** | Skeny, které podle obsahu patří firmě, ale žádný doklad v aplikaci jim neodpovídá. Typicky chybějící zaúčtování nebo špatně přečtený údaj. |
| **Nerozpoznané** | Doklady jiné firmy, skeny, u kterých nejde určit komu patří, a soubory, které se nepodařilo vytěžit |
| **Rozpory** | Doklady, ke kterým dávka připojila sken a jejichž údaje se od skenu liší (viz níže) |

Potvrzením jednoho kandidáta se ostatní návrhy pro stejný doklad i stejný sken
odmítnou.

## Rozpory dokladů s přílohami

Aplikace porovná zaúčtovaný doklad s tím, co AI vyčetla z jeho přílohy. Příloha je
buď připojený sken, nebo PDF, ze kterého doklad vznikl [AI extrakcí](25_AI_extrakce.md).
Porovnává se:

| Údaj | Kdy je rozdíl | Význam |
|---|---|---|
| DUZP | DUZP na příloze padá do jiného kalendářního měsíce | **Varování**: doklad může být v nesprávném období DPH i kontrolním hlášení. Platí u dokladu, který vstupuje do DPH, a jen když je firma k DUZP plátce. |
| DUZP | jiný den téhož měsíce | informativně |
| Částka | celková částka nebo částka k úhradě se liší | informativně |
| IČO protistrany | u přijatého dokladu IČO dodavatele, u vydaného IČO odběratele | informativně |
| Variabilní symbol | VS na příloze je jiný | informativně |

Aby kontrola nehlásila zbytečné rozdíly, nehodnotí se:

- údaj, který AI z přílohy nevyčetla,
- zaokrouhlení dokladu a zaokrouhlení na celé koruny,
- záloha proti skenu konečného dokladu (a naopak) a u zálohové faktury nikdy DUZP,
- znaménko dobropisu,
- příloha v jiné měně než doklad (částka),
- IČO, když AI firmu na příloze posadila na opačnou stranu, než odpovídá dokladu.

Doklad bez vytěžené přílohy se nekontroluje.

**Kde rozpory uvidíte**

- V detailu přijaté faktury a u pokladního dokladu (v sekci příloh) je odznak:
  **Sedí s přílohou**, **Rozpor s přílohou** (dopad na DPH), **Rozdíl proti příloze**
  (informativní) nebo **Rozdíl potvrzen**. Kliknutím otevřete porovnání údajů.
- Na stránce **Dokumenty → Skeny k dokladům** je v přehledu dávky záložka **Rozpory**
  a pod dávkou přehled **Rozpory dokladů s přílohami** za celou firmu, včetně dokladů
  z AI importu. Filtr přepíná otevřené, potvrzené a všechny rozpory.
- V [měsíční kontrole](87_Uzaverka.md#877-mesicni-kontrola) a v předběžných
  kontrolách uzávěrky.

**Potvrzení „v pořádku"**

Když je rozdíl oprávněný (například příloha je dodací list a DUZP odpovídá smlouvě),
otevřete porovnání, napište důvod a klikněte na **Potvrdit, že je v pořádku**. Důvod
je povinný a potvrzení se zapíše do historie aktivit. Potvrzený rozdíl se v kontrolách
nehlásí, dokud se nezmění doklad ani vytěžení přílohy. Jakmile se některý z porovnávaných
údajů změní, rozdíl se ukáže znovu i s původním důvodem.

**Přepočet**

Porovnání se obnoví samo po připojení skenu, po AI importu faktury a po uložení přijaté
faktury nebo pokladního dokladu. Tlačítko **Přepočítat** v přehledu rozporů projde
všechny doklady firmy znovu. Měsíční kontrola a odznak porovnávají vždy aktuální stav.

## Opakované spuštění

Tlačítko **Spustit znovu** dávku zpracuje ještě jednou. Použijte ho po výpadku nebo
zrušení zpracování, nebo když mezitím přibyly doklady, ke kterým by skeny mohly patřit.
Uložené soubory a vytěžení se znovu nezpracovávají. Soubor, který se napoprvé
nepodařilo uložit do Dokumentů, se uloží znovu. Připojené, potvrzené i odmítnuté
páry zůstávají, znovu se počítají jen návrhy.

Nahrané soubory dávky aplikace drží, dokud je všechny neuloží do Dokumentů. Po
dokončení se smažou; když se některý soubor uložit nepodařilo, zůstanou pro Spustit
znovu ještě 7 dní. Nahrávání, které nikdo nedokončil, se uklidí po 48 hodinách.

**Smazat dávku** odstraní jen záznam o dávce. Soubory v Dokumentech i jejich
připojení k dokladům zůstanou.

## AI a oprávnění

- Vytěžení používá AI poskytovatele nastaveného pro firmu (viz
  [AI extrakce](25_AI_extrakce.md)). Když AI nastavená není, páruje se jen podle
  čárového kódu a čísla v názvu souboru.
- K nahrání dávky potřebujete oprávnění nahrávat dokumenty a k tomu oprávnění
  upravovat doklady typu, ke kterému skeny připojujete.
- Dávku vidí ten, kdo ji založil, a administrátor firmy.
