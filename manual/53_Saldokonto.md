# 53. Saldokonto

**Cesta: `Účetnictví → Saldokonto`**

Saldokonto porovnává otevřené položky podle partnerů se zůstatkem
saldokontního účtu v hlavní knize k vybranému dni. Je podkladem pro
inventarizaci pohledávek, závazků a záloh a navazuje na
[Inventarizaci účtů](84_Inventarizace_rozvahovych_uctu.md).

## 53.1 Období a účty

Vyberte účetní období a **Rozvahový den**. Bez data se použije dřívější z
posledního dne období a dneška. Rozvahový den smí ležet i mimo vybrané
období — třeba když chcete z otevřeného roku nahlédnout na 31. 12. loňska
(i uzavřeného/schváleného), abyste porovnali počáteční stav nového roku.
Sestava si skutečné období k datu dohledá sama a spočítá se k němu správně;
pokud se liší od období vybraného v rozbalovacím seznamu, obrazovka to
zvýrazní hláškou s možností na dohledané období rovnou přepnout. Pro datum
bez založeného účetního období se zůstatky počítají kumulativně od počátku
historie. Filtr **Účet** nabízí:

| Volba | Otevřené položky |
|---|---|
| Vše | 311, 321, 314 a 324; prázdné účty se vynechají |
| 311 | vydané faktury a dobropisy odběratelů |
| 321 | přijaté faktury a dobropisy dodavatelů |
| 314 | poskytnuté a dosud nezúčtované zálohy |
| 324 | přijaté a dosud nezúčtované zálohy |

Server podporuje i jiný existující číselný účet a volitelné omezení na
partnera, běžná obrazovka však nabízí uvedenou čtveřici. Explicitně zvolený
účet se zobrazí i s nulami.

## 53.2 Dvě nezávislé strany konfrontace

**Zůstatek hlavní knihy** vychází ze všech zaúčtovaných řádků účtu do
rozvahového dne. Používá stejnou otevírací kotvu jako rozvaha a vylučuje
vlastní závěrkový převod období, aby uzavření knih historický stav nevynulovalo.
Zůstatek se otočí na normální stranu účtu: pohledávka na MD i závazek na Dal
se proto zobrazují kladně.

**Otevřené položky** vznikají z dokladů navázaných na účetní zápis. U každého
dokladu se vezme jeho zaúčtovaná hodnota v Kč a poměr úhrady známý k
rozvahovému dni:

`zbývá = zaúčtováno × (1 − poměr úhrady)`

Výpočet úhrady respektuje datum platby. Pozdější úhrada nezmění historické
saldokonto. Plně vyrovnaná položka se vynechá, záporná otevřená položka
(například dobropis) se zachová a snižuje součet partnera. Částky v cizí měně
se konfrontují v zaúčtované Kč hodnotě; obrazovka současně ukáže původní měnu.

## 53.3 Zálohy 314 a 324

U záloh se neporovnává jen stav dokladu. Systém sleduje skutečnou
zaúčtovanou platbu a její následné čerpání:

- na **314** je otevřená poskytnutá záloha snížena o zúčtování ve finální
  přijaté faktuře i o kredit 314 z přijatého daňového dokladu k platbě. Platí to
  také pro samostatný daňový doklad k platbě použitý jako záloha při nákupu bez
  samostatné zálohové faktury,
- na **324** je přijatá platba vydané proformy snížena o daňový doklad k
  přijaté platbě a o vyúčtovací fakturu.

Plně zúčtovaná záloha proto zmizí z otevřených položek, její historické
účetní pohyby však zůstanou v deníku.

## 53.4 Rozdíl a jeho interpretace

Pro každý účet platí:

`Rozdíl = zůstatek hlavní knihy − Σ otevřených položek`

Rovnost se kontroluje na haléře. Nulový rozdíl znamená, že dokladový rozpad
vysvětluje zůstatek účtu. Nenulový rozdíl může ukazovat na ruční zápis bez
vazby na doklad, chybějící či špatně datovanou úhradu, storno, kurzový pohyb
nebo nezúčtovanou zálohu.

Nulový rozdíl sám nepotvrzuje existenci pohledávky, vymahatelnost ani úplnost
závazků. Tyto skutečnosti je nutné doložit nezávislými podklady.

## 53.5 Dva pohledy: podle partnera / podle dokladů

Přepínač nad sestavou volí zobrazení:

- **Podle dokladů** (výchozí) — plochý seznam všech otevřených položek napříč
  účty a partnery v jedné tabulce: účet, partner, doklad, datum vystavení,
  splatnost, dní po splatnosti, částka, uhrazeno, zbývá. Sloupce jdou řadit
  kliknutím na záhlaví (výchozí řazení dle splatnosti), sestavu lze zúžit
  filtrem na partnera a na minimální počet dní po splatnosti.
- **Podle partnera** — partneři řazeni podle názvu, rozbalení partnera
  zobrazí jeho doklady se stejnými sloupci.

Doklad v obou pohledech vede na detail vydané nebo přijaté faktury.

Storno datované až po rozvahovém dni položku k dřívějšímu dni neskrývá.
K datu storna a později se účetní i dokladová strana vyruší.

## 53.6 Export

PDF vytvoří inventarizační protokol pro aktuální účet, partnera a rozvahový
den vždy v podobě podle partnera (zůstatek hlavní knihy, součet otevřených
položek, rozdíl a rozpad partnerů s doklady). XLSX export respektuje aktuálně
zvolený pohled — podle partnera stejně jako protokol, podle dokladů jako
plochý seznam pro další zpracování (např. filtrování v Excelu).

> 💡 Nenulový rozdíl řešte od konfrontačního pruhu přes partnera a doklad; pro
> ruční či kurzový zápis pokračujte proklikem do opisu účtu v
> [Hlavní knize](48_Hlavni_kniha.md).
