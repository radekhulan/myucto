# 49. EPO podání, archív a daňová rekonciliace

> Návod, jak daňový výkaz z MyÚčta podat přes EPO, doložit podání a ověřit, že
> podaný stav odpovídá dnešním datům. Pro každého, kdo podává DPH, kontrolní
> a souhrnné hlášení, daň z příjmů nebo OSS.

Kapitola odlišuje **výpočet**, **vygenerované XML**, skutečně **odeslané podání**
a pozdější potvrzení správce daně. Tyto stavy se nesmějí zaměňovat: soubor
uložený v MyÚčtu není sám o sobě důkazem, že byl přijat finanční správou.

Související výpočty jsou popsány v kapitolách [Výkazy DPH](41_Vykazy_DPH.md),
[Souhrnné hlášení](44_Souhrnne_hlaseni.md) a [Daň z příjmů](43_Dan_z_prijmu.md).

## 49.1 Kdy to potřebujete

- Máte vygenerované XML výkazu a chcete ho podat na Daňový portál.
- Podali jste výkaz mimo aplikaci a potřebujete doložit podání.
- Chcete ověřit, že podaný stav odpovídá dnešním datům (rekonciliace).
- Hledáte starší podání, jeho XML nebo potvrzení.
- Potřebujete rozhodnout, zda podat řádné, opravné nebo dodatečné tvrzení.

<!-- cols: 34 38 28 -->
| Co potřebujete | Jak | Kde |
|---|---|---|
| Podat výkaz s kontrolou v interaktivním formuláři | Asistované podání, [§ 49.3](#493-krok-za-krokem-asistovane-podani) | `Daně → EPO podání a archív` |
| Podat výkaz podepsaný certifikátem přímo z aplikace | Přímé podání, [§ 49.4](#494-krok-za-krokem-prime-podani-pres-epo-api) | `Daně → EPO podání a archív` |
| Doložit podání | Označit jako podané, [§ 49.5](#495-krok-za-krokem-dolozte-podani) | detail podání |
| Porovnat DPPO nebo OSS s podaným stavem | Rekonciliace, [§ 49.6](#496-krok-za-krokem-rekonciliace) | `Daně → Daň z příjmů`, `Daně → OSS přiznání` |

## 49.2 Než začnete

- **Hotová účetní nebo evidenční kontrola** daného období a vygenerované XML, které nehlásí blokující chybu.
- **Pro přímé podání osobní kvalifikovaný certifikát** ve formátu P12/PFX se soukromým klíčem (jak ho získat, viz [§ 49.8.5](#4985-jak-ziskat-vhodny-certifikat)). Nahrajete ho v `Systém → E-maily a certifikáty`, záložka **Certifikáty a elektronické podpisy**, a v každé firmě povolíte tlačítkem **Povolit pro tuto firmu** v části **Certifikáty EPO**. Správce instalace musí mít nastavený šifrovací klíč (viz [§ 49.8.6](#4986-zabezpeceni-certifikatu)).
- **Oprávnění jednat za firmu.** Certifikát prokazuje totožnost podepisující osoby, ne její oprávnění jednat. Jednatel podepisuje v rozsahu svého oprávnění, účetní nebo daňový poradce potřebuje plnou moc uplatněnou u příslušného finančního úřadu každé zastupované firmy.
- **Pro OSS** se EPO nepoužívá, viz upozornění v [§ 49.3](#493-krok-za-krokem-asistovane-podani).

## 49.3 Krok za krokem: asistované podání

Asistované podání předvyplní formulář na portálu MOJE daně. Odeslání provedete vy.

1. Dokončete účetní nebo evidenční kontrolu období.
2. Vygenerujte XML a ověřte, že interní kontrola nehlásí blokující chybu.
3. Otevřete `Daně → EPO podání a archív`, najděte snapshot a zkontrolujte období, formulář, variantu, otisk a výsledek validace **XSD · OK**.
4. Klikněte na **Otevřít a podat v EPO**. MyÚčto odešle přesný snapshot na oficiální endpoint finanční správy a otevře předvyplněný formulář. Toto předání samo ještě není podáním.
5. V EPO spusťte kontroly, porovnejte částky a typ podání s MyÚčtem a teprve potom podání odešlete.
6. Z EPO stáhněte odeslané XML a potvrzení (dodejku). Přetáhněte je do vyznačené plochy detailu podání. Složka v Dokumentech se vytvoří automaticky.
7. Aplikace z dodejky (`.p7s` nebo `.p7m`) ověří podpis, vazbu na archivovaný formulář a přečte obsah. V detailu podání ukáže podací číslo, rozhodný čas, příznak ZAREP, kontrolní součet odeslaného souboru, kód finančního úřadu a identitu pečeti. Podací číslo a čas předvyplní do formuláře **Označit jako podané**.
8. Klikněte na **Označit jako podané** (viz [§ 49.5](#495-krok-za-krokem-dolozte-podani)).

**Jak poznáte, že je hotovo:** Snapshot má stav podaný, u něj je podací číslo a nahraná dodejka.

> [!TIP]
> Pro vizuální kontrolu můžete předvyplněný formulář EPO nejdřív jen otevřít a zavřít bez odeslání. Otevřená asistovaná relace ale do svého vypršení blokuje přímé ostré odeslání (viz [§ 49.8.8](#4988-zkusebni-prostredi-pro-vyvoj)).

> [!WARNING]
> **OSS přiznání (OSSEI1) se přes EPO podat nedá, žádnou z obou cest.** Portál písemnost rozpozná, ale odmítne ji s tím, že musíte být přihlášeni v samostatné aplikaci **MOSS/OSS**. Přímé podepsané podání míří na týž endpoint jako asistované předání, takže dopadne stejně, jen po zbytečném odemčení klíče. MyÚčto proto u OSS snapshotu nenabízí ani asistované předání, ani přímé podání (a obě cesty odmítá i přes API). Stáhněte XML a nahrajte ho v aplikaci MOSS/OSS na Daňovém portálu, viz [Kde se OSS přiznání podává](45_OSS.md#451014-kde-se-oss-priznani-podava).

> [!WARNING]
> Zavření okna EPO, úspěšné otevření formuláře ani zelená lokální validace XSD neprokazují odeslání. Rozhoduje potvrzení podatelny.

Po úspěšném podání uzamkněte příslušné daňové období k datu přes [zámek účtování k datu](62_Mesicni_kontrola.md#624-krok-za-krokem-zamek-uctovani-k-datu), pokud to už neprovedlo řízené workflow firmy. Samotné stažení XML stav neposouvá na odesláno a ruční zámek období nenahrazuje.

## 49.4 Krok za krokem: přímé podání přes EPO API

Přímý režim vytvoří uznávaný elektronický podpis ZAREP, provede test oficiální podatelny a po samostatném potvrzení odešle skutečné podání.

1. Ověřte, že je certifikát nahraný a povolený pro firmu (viz [§ 49.2](#492-nez-zacnete)). V části **Certifikáty EPO** zkontrolujte vlastníka, vydavatele a platnost certifikátu. Používáte-li ho pro další firmu, přepněte se na ni a povolte ho i tam.
2. Otevřete `Daně → EPO podání a archív` a detail validního XML snapshotu.
3. Vyberte certifikát a klikněte na **Zkontrolovat na EPO**.
4. Aplikace vytvoří připojený podpis PKCS#7 v DER, odešle ho s příznakem testu a zobrazí všechny zprávy EPO. Test kontroluje podpis, strukturu i věcná pravidla, ale daňové podání nevytvoří.
5. Chyby typu struktura, kritická chyba nebo systémová výjimka odstraňte v datech a vygenerujte nový snapshot. Propustná upozornění jsou zobrazena, ale úspěšný test neblokují.
6. Po úspěšném testu klikněte na **Podepsat a podat**. Akce je viditelná stále, ale odemkne se až po úspěšném testu a po skončení případné asistované relace.
7. Znovu se ověřte (přístupovým klíčem, nebo heslem a případným kódem TOTP) a potvrďte právně účinné odeslání.
8. Je-li podání rozsáhlé, EPO nejdřív vrátí identifikátor předání. Potvrzení vyzvednete později tlačítkem **Obnovit stav EPO**.

**Jak poznáte, že je hotovo:** U pokusu je podací číslo a čas převzatý z ověřeného potvrzení a v Dokumentech je uložené zdrojové XML, odchozí podepsaný balíček, testovací protokol a potvrzení P7S.

Úspěšný test lze pro ostré podání použít jednou a nejdéle 30 minut. Do prvního úspěšného testu aplikace certifikát označuje jako **Dosud neověřen EPO**, protože samotné načtení PFX neprokazuje jeho kvalifikovanost ani přijatelnost pro EPO.

> [!WARNING]
> Pokud se při ostrém odeslání přeruší spojení bez jednoznačné odpovědi, pokus se označí jako **Výsledek je nejistý**. MyÚčto jej automaticky neopakuje. Nejdřív ověřte stav u finanční správy, protože slepé opakování může vytvořit duplicitní podání. Dohledáte-li původní P7S nebo P7M, použijte **Ověřit nalezené P7S**. Systém je kryptograficky sváže s uloženým odchozím balíčkem. Jen když pokus nemá podací číslo ani stavové heslo a přímo v EPO ověříte, že podání nevzniklo, lze po 15 minutách použít **Uvolnit nepřijatý pokus**. Akce vyžaduje nové ověření identity, výslovné potvrzení a auditní poznámku.

## 49.5 Krok za krokem: doložte podání

Použijte po asistovaném podání, po podání mimo aplikaci nebo když dodejka nedorazila automaticky.

1. Otevřete `Daně → EPO podání a archív` a detail podání.
2. Nahrajte dodejku (`.p7s`, `.p7m`) nebo PDF potvrzení přetažením do vyznačené plochy. ZFO není podporovaný typ ručního uploadu.
3. Zkontrolujte předvyplněné **Podací číslo** a **Čas podání**.
4. V části **Ruční doložení podání** klikněte na **Označit jako podané**.
5. Chcete-li zjistit aktuální stav zpracování, klikněte na **Obnovit stav EPO**. Je dostupné, jakmile aplikace z dodejky převezme heslo pro dotaz na stav.

**Jak poznáte, že je hotovo:** Snapshot je označený jako podaný a obsahuje podací číslo. Až potvrzené podání používejte jako poslední známý stav pro další opravné nebo dodatečné tvrzení.

> [!TIP]
> Pro každý formulář archivujte trojici **odeslané XML + potvrzení + stručné vysvětlení ručních změn**. Při kontrole nebo dalším dodatečném podání pak lze přesně doložit, z jakého stavu se vycházelo.

## 49.6 Krok za krokem: rekonciliace

Rekonciliace odpovídá na otázku: „Shoduje se dnešní výpočet MyÚčta s tím, co bylo skutečně odesláno?" Není to totéž jako kontrola, že dvě interní sestavy čerpají ze stejných dokladů.

**DPPO (import podaného XML):**

1. Vyberte stejné období jako v podaném formuláři.
2. Nahrajte XML, které bylo skutečně odesláno, nikoli nově vygenerovanou kopii.
3. Projděte všechny rozdíly, zejména výsledek hospodaření, nedaňové náklady, odpisové rozdíly, dary, ztráty a zálohy.
4. Rozdíl vysvětlete opravou zdroje, doloženou ruční úpravou nebo identifikací změny, která nastala až po podání.

**OSS:**

1. Otevřete `Daně → OSS přiznání` a záložku **Rekonciliace**.
2. Zvolte období a prohlédněte rozdíly mezi archivovaným podáním a dnešním náhledem.
3. Rozhodněte, zda je třeba opravné podání. Rozhodnutí zůstává na účetní.

**Kontrolní hlášení (po dokladech):**

1. U podaného kontrolního hlášení klikněte v liště akcí na **Soupis dokladů oddílu**.
2. Zvolte oddíl a projděte sloupec **Stav**: shoda, rozdíl částky, doklad jen v podaném KH, nebo jen v aktuálních datech.
3. Soupis stáhněte do PDF nebo XLSX, třeba jako podklad pro správce daně. Podrobný postup je v kapitole [Kniha DPH](42_Kniha_DPH.md#425-krok-za-krokem-soupis-dokladu-oddilu-kh-pro-kontrolu-financniho-uradu).

**Jak poznáte, že je hotovo:** Každý rozdíl je vysvětlený. Neshodu nikdy neodstraňujte mechanickým dorovnávacím zápisem bez účetního případu.

## 49.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Filtr stavu (například zamítnuté) nic neukazuje, ale podání máte | Filtr a hledání pracují nad celým archivem. Podání může být dál než na první stránce, nebo je jiný výkaz či rok. | Vraťte filtry na **Všechny stavy** a **Všechny formuláře** a hledejte podle podací značky, otisku nebo roku |
| Snapshot nejde smazat | Snapshot je označený jako podaný, má podací číslo, doručenku, nebo běží zadržení podle § 32 ZoÚ | Viz [§ 49.8.3](#4983-kdy-jde-snapshot-smazat) |
| Tlačítko **Pokračovat do EPO** zmizelo | Odkaz je jednorázový, byl použitý, uplynulo 20 minut, nebo se XML změnilo | Klikněte na **Vytvořit nový odkaz EPO** |
| Nahrané XML se liší od snapshotu | Podání se v EPO upravovalo a archiv už neodpovídá tomu, co bylo podáno | Aplikace vypíše počet rozdílných údajů, případně upozorní na jiný formulář. Ověřte podané hodnoty. |
| U tištěného PDF se nepředvyplnilo číslo | Skenované PDF bez textové vrstvy se jen archivuje | Nahrajte dodejku P7S |
| Pokus je ve stavu **Výsledek je nejistý** | Přerušené spojení nebo dodejka nepotvrzená kryptograficky | Postup v upozornění v [§ 49.4](#494-krok-za-krokem-prime-podani-pres-epo-api) |
| Aplikace certifikát nepřijme | Chybí šifrovací klíč, soukromý klíč v PFX, nebo jde o certifikát pro pečeť či o QSCD | Viz [§ 49.8.5](#4985-jak-ziskat-vhodny-certifikat) a [§ 49.8.6](#4986-zabezpeceni-certifikatu) |
| Upozornění, že certifikát neobsahuje IK MPSV | Certifikát nemá identifikátor klienta MPSV | Požádejte o certifikát s IK MPSV, nebo ověřte podání zkušebně |
| Odeslání se po změně konfigurace odmítne | Pokus si pamatuje prostředí (ostré, zkušební), ve kterém vznikl | Vraťte konfiguraci na původní prostředí, nebo založte nový pokus |

## 49.8 Podrobnosti a pravidla

### 49.8.1 Stavy daňového výstupu

<!-- cols: 22 39 39 -->
| Stav | Co prokazuje | Co neprokazuje |
|---|---|---|
| **Náhled** | Aktuální výpočet z dat firmy. | Neměnnost dat, podání ani přijetí správcem daně. |
| **Vygenerováno / staženo** | XML vzniklo, prošlo dostupnou technickou validací a bylo archivováno se svým otiskem. | Že uživatel soubor odeslal nebo že EPO přijalo jeho obsah. |
| **Odesláno** | Uživatel doložil čas odeslání, případně identifikátor potvrzení. | Konečné přijetí bez chyb nebo vyměření daně. |
| **Přijato / odmítnuto** | Stav převzatý z potvrzení portálu či podatelny. | Věcnou správnost všech daňových údajů. |

Samostatná obrazovka `Daně → EPO podání a archív` zobrazuje vygenerované snapshoty,
výsledek lokální validace, historii předání do EPO, uložené důkazní dokumenty a
stav podání.

### 49.8.2 Filtr, hledání a stránkování

Seznam se **stránkuje po padesáti záznamech** a filtr stavu, výběr výkazu i hledání
pracují **nad celým archivem**, ne jen nad zobrazenou stránkou.

- **Filtr stavu** nabízí staženo, odesláno, přijato a zamítnuto, případně **Všechny stavy**.
- **Filtr formuláře** se plní ze skutečně existujících typů v archivu, ne z toho, co dorazilo na stránku.
- **Hledání** se páruje s podací značkou, otiskem, kódem výkazu a rokem období, tedy se skutečnými údaji záznamu, ne s přeloženými popisky na obrazovce. Píše se s krátkou prodlevou, takže se výsledek dohledá sám.

Změna filtru i hledání vás vždy vrátí na první stránku. Záznamy jsou řazené od
nejnovějšího.

> [!TIP]
> **Souhrnné dlaždice nahoře jsou vědomě jiné číslo než počet ve stránkování.** Počet u stránkování odpovídá tomu, co jste vyfiltrovali. Dlaždice se počítají nad celým viditelným archivem, protože slouží k navigaci. Kdyby se počítaly ze stránky, tvrdily by „2 problémy", i když jich je třicet o dvě stránky dál. Kdyby se vázaly na filtr, ztratily by smysl.

### 49.8.3 Kdy jde snapshot smazat

Mazání blokuje jen to, co prokazatelně odešlo:

<!-- cols: 44 56 -->
| Situace | Smazat lze? |
|---|---|
| Snapshot bez předání, případně jen s testem EPO | Ano. Test se z principu nepodává, EPO na něj odpovídá, že podání nebylo přijato, protože šlo o testovací režim. |
| Předání do EPO, jehož výsledek aplikace nezná (čeká na P7S, propadlý odkaz, nejednoznačná odpověď) | Až po vědomém uzavření. Ověřte v portálu EPO, jestli podání prošlo. Pokud ne, popište, jak jste to ověřili, a pokus se uzavře jako nepodaný. |
| Snapshot označený jako podaný, potvrzené podání, podací číslo nebo doručenka | Ne. Je to doklad o podání pro správce daně. |

Nad obdobím, na kterém běží zadržení podle § 32 ZoÚ (daňová kontrola, odvolání,
soudní spor), se snapshoty nemažou vůbec. Mazání není cesta, jak se zbavit
podkladu, který správce daně prověřuje.

Smazání zachytí auditní log včetně toho, co spolu se snapshotem zmizelo (pokusy,
vazby na důkazní dokumenty). Samotné dokumenty v modulu **Dokumenty** zůstávají.

### 49.8.4 Co se v archivu ukládá

Při ostrém exportu se uchovává přesný XML obsah, velikost, SHA-256 otisk, typ
formuláře, období, varianta podání a výsledek dostupné validace. Otisk umožňuje
později ověřit, že stažený soubor odpovídá archivované verzi.

Při prvním předání nebo nahrání dokumentu se stejný zdrojový XML snapshot uloží
také do modulu **Dokumenty**. Aplikace k němu připojí další nahrané artefakty,
například XML stažené z EPO, podepsané potvrzení P7S/P7M nebo PDF doručenku.
Každý soubor má vlastní SHA-256 otisk a výsledek dostupného ověření. Automaticky
vytvořené názvy obsahují formulář, období, ID archivního snapshotu, čas
s mikrosekundami a u EPO dokumentů také ID konkrétního pokusu. Opakované
vygenerování DPH za stejné čtvrtletí ani opakovaný stavový dokument proto
nepřepíše předchozí soubor.

Z dodejky P7S se, ať dorazila z API, nebo ji účetní nahrála ručně, ukládají
i její rozbalené části: čitelný přepis potvrzení, echo podání tak, jak si ho eviduje
finanční správa, certifikát pečeti a certifikát podepisující osoby. Díky tomu jde
podpis ověřit i za několik let, až vydávající autorita certifikát vymění.

Přímé rozhraní automaticky uloží podepsaný odchozí balíček, XML odpovědi a vrácené
potvrzení P7S. Dokumentované odesílací API neposkytuje ZFO ani renderovaný PDF
opis. PDF nebo P7S/P7M získané později z portálu lze přidat přetažením.

V nastavení archívu lze vybrat dva kořeny: jeden pro DPH, kontrolní a souhrnné
hlášení a OSS, druhý pro DPFO a DPPO. Pod kořenem vzniká automaticky strom
**rok → měsíc/čtvrtletí → druh formuláře**. Bez vlastního nastavení aplikace
založí výchozí kořeny **DPH a hlášení** a **Daň z příjmů**.

Finalizované DPFO i DPPO uchovávají neměnný výpočet a XML. Změna živých dokladů po
finalizaci proto nemění dříve vytvořené podání. Oprava se řeší novou revizí,
opravným nebo dodatečným přiznáním. Pracovní XML z náhledu není podáním a
nearchivuje se jako finální daňový výstup.

Starší finální DPPO bez uloženého XML lze exportovat rekonstrukcí. Použije uložený
výpočet a aktuální identifikační údaje a přílohy, chybí-li výpočet, aktuální
podklady. Na rekonstrukci i neúspěšnou kontrolu XML aplikace upozorní, export však
dokončí.

U DPHDP3, KH a souhrnného hlášení je archivovaný soubor technickým obrazem
vygenerovaného výkazu. Před odesláním vždy porovnejte období, typ podání,
identifikační údaje a součty s náhledem a související kontrolní sestavou.

### 49.8.5 Jak získat vhodný certifikát

Pro přímé EPO objednejte **kvalifikovaný certifikát pro elektronický podpis
fyzické osoby** (případně zaměstnanecký/OSVČ profil), jehož soukromý klíč lze
exportovat do souboru P12/PFX. Neobjednávejte certifikát pro elektronickou pečeť.
Pokud zvolíte čipovou kartu, token, eObčanku nebo jiný kvalifikovaný prostředek
QSCD, soukromý klíč obvykle exportovat nelze a do serverového trezoru MyÚčta ho
proto nepůjde vložit. Certifikát patří fyzické osobě, která podání podepisuje;
v MyÚčtu ho lze vědomě povolit pro jednu nebo více spravovaných firem.

Finanční správa požaduje pro přímé rozhraní třetích stran uznávaný elektronický
podpis ZAREP založený na kvalifikovaném certifikátu. V žádosti výslovně zvolte
vložení **identifikátoru klienta MPSV (IK MPSV)**; nevymýšlejte ani nezadávejte
vlastní identifikátor. Poskytovatel jej ověří nebo zajistí v rámci vydání.
Finanční správa uvádí, že IK MPSV není v certifikátu automaticky a jeho vložení je
bezplatné. Její novější podpora připouští i kvalifikovaný certifikát bez IK MPSV,
starší technické požadavky ZAREP ho ale výslovně žádají. Bezpečnější je proto
o něj požádat. Když ho MyÚčto v certifikátu nerozpozná, upozorní na to.

Praktický postup:

1. Nejrychlejší ověřená cesta je [PostSignum - certifikát online](https://www.postsignum.cz/certifikat_online.html). Při online identifikaci může být osobní kvalifikovaný certifikát hotový během několika minut; konkrétní doba závisí na úspěšném ověření a provozu služby. Alternativně vyberte kvalifikovaného poskytovatele a produkt pro podpis fyzické osoby: [eIdentity](https://www.eidentity.cz/), [PostSignum - fyzické osoby](https://www.postsignum.cz/fyzicke_osoby_.html?step=2) nebo [I.CA - kvalifikovaný certifikát pro ePodpis](https://www.ica.cz/kvalifikovany-certifikat-pro-ePodpis).
2. Žádost vytvářejte na počítači a v uživatelském profilu, ve kterém budete certifikát přebírat. Zvolte uložení klíče **v počítači / softwarovém úložišti**, nikoli na neexportovatelném QSCD.
3. V žádosti zaškrtněte vložení IK MPSV. U eIdentity je volba **Use IkMPSV in the certificate** přímo v [žádosti](https://www.eidentity.cz/registration/EasyRequest.html). Pokud portál vyžádá samostatnou žádost či potvrzení MPSV, postupujte podle pokynu eIdentity nebo registračního místa; do pole se nevkládá rodné číslo ani vlastní text. PostSignum má volbu **Vložit Identifikátor klienta MPSV** ve formuláři fyzické osoby. I.CA umožňuje IK MPSV zvolit při generování žádosti nebo o něj požádat při vydání na registrační autoritě.
4. Dokončete registraci a ověření totožnosti podle pokynů poskytovatele. U prvního certifikátu počítejte s kontrolou osobních dokladů na registračním místě.
5. Certifikát převezměte a nainstalujte ve stejném profilu, ve kterém vznikl soukromý klíč. Samotný veřejný soubor CER/CRT nestačí.
6. Ve Windows otevřete správu certifikátů aktuálního uživatele (`certmgr.msc`), najděte certifikát v **Osobní → Certifikáty** a zvolte **Všechny úkoly → Exportovat → Ano, exportovat soukromý klíč → PKCS #12 (.PFX)**. Zahrňte certifikační řetězec, nastavte silné jedinečné heslo a soubor uložte jen do zabezpečeného dočasného umístění.
7. Před importem zkontrolujte, že PFX obsahuje soukromý klíč, certifikát je platný a určený pro digitální podpis. Po importu do MyÚčta bezpečně uložte zálohu a údaje pro zneplatnění; pracovní kopii z běžné složky smažte.

Užitečné oficiální odkazy Finanční správy:

- [ePodatelna Finanční správy](https://financnisprava.gov.cz/cs/financni-sprava/kontakty/epodatelna)
- [Elektronická podání pro Finanční správu (EPO)](https://financnisprava.gov.cz/cs/dane/dane-elektronicky/danovy-portal/elektronicka-podani-pro-financni-spravu)
- [Technické požadavky na uznávaný elektronický podpis](https://adisspr.mfcr.cz/adis/jepo/info/zarep.htm)
- [Oficiální popis rozhraní EPO pro třetí strany](https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/PodatelnaEPO.pdf)
- [Zjištění stavu podání na portálu MOJE daně](https://mojedane.gov.cz/pmd/epo/stav/podani/info)

Certifikát prokazuje totožnost podepisující fyzické osoby, ne její oprávnění
jednat za každou firmu. Jednatel může podepisovat za společnost v rozsahu svého
oprávnění; účetní nebo daňový poradce musí mít odpovídající pověření či plnou
moc. Certifikát se u finančního úřadu předem samostatně neregistruje, rozhodující
je identita z podpisu a existující právní oprávnění zastupovat daňový subjekt.

Účetní, která podává za více firem, vystačí s jedním certifikátem. Plnou moc ale
musí mít uplatněnou u příslušného finančního úřadu **každé** zastupované firmy,
a to nejpozději v okamžiku podání. Plná moc uplatněná u jednoho finančního úřadu
se ostatním nepředává. V MyÚčtu certifikát nahrajete jednou a v každé firmě ho
povolíte. Pro podání mezd na ČSSZ platí jiná pravidla, včetně registrace
certifikátu, viz
[Podání přes VREP: zmocnění a registrace certifikátu](85_Podani_a_hlaseni.md#8521-podani-pres-vrep-zmocneni-a-registrace-certifikatu).

IK MPSV v kvalifikovaném certifikátu pomáhá EPO spojit podepisující osobu
s evidovanou identitou. MyÚčto na jeho nerozpoznání upozorní, ale samo nerozhoduje
o oprávnění jednat za konkrétní daňový subjekt. To musí odpovídat skutečnému
zastoupení, funkci nebo plné moci.

### 49.8.6 Zabezpečení certifikátu

Správce instalace musí před použitím nastavit samostatný
`app.secret_encryption_key`. Bez něj MyÚčto soukromý klíč nepřijme. P12/PFX i jeho
heslo jsou v databázi uložené pouze šifrovaně a API je nikdy nevrací. Uložení,
povolení pro firmu, odstranění, testovací podpis i ostré podání vyžaduje znovu
aktuální heslo uživatele a při zapnutém 2FA také TOTP kód. Máte-li přístupový klíč,
nahradí tlačítko **Ověřit přístupovým klíčem** heslo i TOTP naráz. Ověření je
jednorázové a vázané přímo na tuhle operaci, takže proof z jiné akce ani samotné
přihlášení certifikát neodemknou. Cesta přes heslo zůstává dostupná vždy.

Šifrované EPO údaje jsou navíc vázané na svůj účel, takže například ciphertext
hesla dodejky nelze zaměnit za ciphertext PFX. Při rotaci klíče správce nastaví
nový `app.secret_encryption_key` a původní klíč dočasně ponechá v
`app.secret_encryption_previous_keys`. Staré záznamy zůstanou čitelné a nové se už
zapisují novým klíčem. Starý klíč lze odebrat až po řízeném přešifrování nebo
odstranění všech dat, která jej používají.

Osobní certifikát uložený pro EPO může přihlášený uživatel připojit ke svému
profilu v `Systém → E-maily a certifikáty` (záložka **Certifikáty a elektronické
podpisy**) a použít ho také pro PDF nebo S/MIME. Nevzniká druhá kopie PFX ani
hesla, podpisový profil ukládá pouze vazbu na osobní trezor. Připojení vyžaduje
stejné ověření jako výše. Dokud certifikát používá aktivní podpisový profil, nelze
ho z trezoru smazat ani odebrat z příslušné firmy.

### 49.8.7 Ověřování dodejek

Dodejky se ověřují proti CA bundlu z `epo.ca_bundle_path`. Ve výchozí konfiguraci
míří na `api/resources/epo/epo-ca-bundle.pem`, který se nasazuje spolu s aplikací
a obsahuje kořeny i podřízené kvalifikované autority I.CA a PostSignum. Pečeť EPO
vydává I.CA (`CN=I.CA EU Qualified CA2/RSA`, `O=První certifikační autorita, a.s.`).

Systémový CA store se pro tohle použít nedá: bundly pro TLS (Mozilla) žádnou
českou kvalifikovanou autoritu neobsahují. Ostré podání přitom platný řetězec
potvrzenky vyžaduje, takže bez bundlu by reálně podané hlášení skončilo ve stavu
`uncertain` a v Dokumentech by chybělo potvrzení. Stav je z toho zotavitelný:
potvrzenka zůstává bezpečně uložená a **Obnovit stav EPO** ji po nápravě
konfigurace znovu ověří a pokus dotáhne do `confirmed`, bez opakovaného podání.

Volitelně lze doplnit allowlist SHA-256 otisků podpisových certifikátů EPO
v `epo.receipt_signer_fingerprints_sha256`. Je-li bundle nastaven, ale není
dostupný, ověření selže bezpečně; aplikace nespadne zpět na jiný trust store. Pro
zkušební prostředí je kvůli testovacímu certifikátu bez produkčního řetězce
povinný samostatný allowlist `epo.test_receipt_signer_fingerprints_sha256`. Lze jej
předat také jako čárkami oddělenou proměnnou
`MYINVOICE_EPO_TEST_RECEIPT_SIGNER_FINGERPRINTS_SHA256`. Bez přesné shody otisku
se dodejka pouze archivuje a pokus se automaticky nepotvrdí.

U kontrolního hlášení EPO z bezpečnostních důvodů vrací v dodejce redukovaný
obsah. Pokud proto nelze kryptograficky prokázat přesnou shodu s odeslaným CMS,
MyÚčto dodejku archivuje, ale pokus ponechá jako **Výsledek je nejistý** k ruční
kontrole; shodu nikdy nepředpokládá pouze podle typu formuláře. Pokud dodejka
obsahuje údaje pro stavový dotaz, následné potvrzení přijetí přes `epo_stav`
doplní čas, podací číslo, původního podepisujícího i daňový zámek období.

Automatické potvrzení vyžaduje platný certifikační řetězec, identitu **Společného
technického zařízení správců daně** v podpisovém certifikátu a přesnou vazbu na
odeslaný CMS balíček. Zkušební podatelna podepisuje dodejku certifikátem
s výslovnou identitou **Testovací zařízení - nelze učinit platné podání**. MyÚčto
v prostředí `epo_test` vyžaduje platný podpis, tuto přesnou identitu GFŘ, přesnou
shodu SHA-256 otisku s testovacím allowlistem a vazbu na odeslaný balíček.
Testovací dodejku přitom neprezentuje jako produkčně důvěryhodný řetězec ani jako
právně účinné podání.

### 49.8.8 Zkušební prostředí pro vývoj

Instalace může přímé podepsané EPO operace přepnout na veřejné zkušební prostředí
Finanční správy. V `cfg.php` nastavte:

```php
'epo_test' => true,
```

V Dockeru nebo jiné konfiguraci přes proměnné prostředí lze použít
`MYINVOICE_EPO_TEST=true`. Výchozí hodnota v aplikaci i v `cfg.sample.php` je
`false`.

Po zapnutí míří přímá kontrola s `test=1`, následné odeslání, vyzvednutí
rozsáhlého podání i dotaz na stav na [zkus.mojedane.gov.cz](https://zkus.mojedane.gov.cz).
Zkušební podání se na ostrém portálu neprojeví. MyÚčto přesto uloží pokus,
podepsaný balíček, odpovědi, dodejku, stavové události a dokumenty; v uživatelském
rozhraní je označí štítkem **Testovací prostředí**. Potvrzení ze zkušebního prostředí nikdy nezmění
stav archivovaného snapshotu na skutečně podaný.

Asistované otevření formuláře používá vždy ostrý interaktivní portál MOJE daně.
Předání pouze předvyplní formulář a samo jej neodešle; právní účinek vzniká až
vědomým odesláním uživatelem na portálu.

Každý pokus si ukládá prostředí, ve kterém vznikl. Když správce později `epo_test`
přepne, rozpracovaný pokus se na jiný server nepřesměruje. Odeslání i obnovení
jeho stavu se bezpečně odmítne, dokud aktuální konfigurace znovu neodpovídá
prostředí uloženému u pokusu. Zkušební prostředí může být dočasně nedostupné kvůli
servisu nebo aktualizaci. Technické struktury a rozhraní popisuje
[dokumentace MOJE daně](https://mojedane.gov.cz/pmd/dokumentace).

Otevřená asistovaná relace neblokuje neúčinný datový test, ale do svého vypršení
blokuje skutečné odeslání proti duplicitě. Testovací podepsaný balíček je uložen
jen šifrovaně a nelze jej stáhnout ani znovu použít mimo řízený tok. Kontrolní
hlášení DPH MyÚčto před podpisem automaticky vloží jako jediný XML soubor do ZIP
archivu, jak vyžaduje rozhraní Finanční správy; zdrojový XML snapshot i jeho
SHA-256 přitom zůstávají beze změny.

**Automatický dotaz na stav.** Stejné dotazy na stav bezpečně provádí
`cron-epo-status` s exponenciálním odstupem nejvýše jedné hodiny. Worker zná pouze
heslo konkrétního stavu a nikdy nepoužívá PFX ani znovu neodesílá XML. Wrapper
spusťte každou minutu. Jednotlivý pokus se i tak dotazuje jen podle svého
naplánovaného času a po každém neukončeném stavu prodlužuje odstup:

```text
Linux cron:       * * * * * /cesta/k/myucto/cmd/cron-epo-status.sh
Windows Scheduler: C:\cesta\k\myucto\cmd\cron-epo-status.cmd
Docker host cron: * * * * * docker compose exec --user www-data -T app php api/bin/cron-epo-status.php
```

Ve Windows nastavte opakování úlohy každou minutu. Výsledek každého běhu je
v evidenci cronů a wrapper zapisuje denní log do `log/cron`; při vlastním
`MYINVOICE_DATA_DIR` pod jeho `log/cron`.

### 49.8.9 Asistované podání: odkaz a omezení

**PDF opis podání se čte jen jako nápověda.** Pokud z portálu odnesete pouze tisk,
aplikace se z jeho textové vrstvy pokusí přečíst podací číslo a čas a předvyplní
jimi formulář. Panel u toho výslovně říká, že jde o odečtený text, ne o podepsaný
důkaz. Heslo pro dotaz na stav v tisku není, takže dotaz na stav odemkne až
dodejka. U skenovaného PDF bez textové vrstvy se soubor jen archivuje.

**Nahrané XML aplikace porovná s archivovaným snapshotem.** Když se otisky
neshodují, vypíše počet rozdílných údajů, případně upozorní, že jde o úplně jiný
formulář. Rozdíl v hodnotě znamená, že se podání v EPO upravovalo a archiv už
neodpovídá tomu, co bylo podáno.

Z dodejky si aplikace převezme **heslo pro dotaz na stav** a uloží ho zašifrované.
Díky tomu je i u asistovaného podání dostupné **Obnovit stav EPO** (dotaz na
`epo_stav`) a odhalení hesla pro opis na Daňovém portálu. To je samostatná akce se
silným ověřením a záznamem v auditu.

Odkaz vrácený EPO si ponechá jen aktuální prohlížeč, server ho neukládá do
databáze ani do logu. Odkaz je **jednorázový**: portál jej spotřebuje prvním
otevřením. MyÚčto jej proto hned po otevření označí jako použitý a tlačítko
**Pokračovat do EPO** znovu nenabídne. Pokud podání v otevřeném okně nedokončíte
nebo okno zavřete, vytvořte nový odkaz.

Dosud neotevřený odkaz je použitelný jen tehdy, když platí **obojí**:

- od vytvoření neuplynulo víc než **20 minut** (portál mluví o session zhruba 30 minut od poslední aktivity a tohle je rezerva pod tím),
- **XML se od vytvoření odkazu nezměnilo**. Jakmile se podklad přepočítá, mířil by starý odkaz na neaktuální písemnost. Shoda se pozná podle SHA-256 otisku, který archiv u snapshotu vede.

Skutečnou životnost odkazu portál neprozrazuje, takže dvacetiminutové okno je
bezpečnostní rezerva, ne zaručená platnost. Po otevření odkazu, uplynutí tohoto
okna nebo změně XML nabídne detail podání místo pokračování akci **Vytvořit nový
odkaz EPO**. Použijte ji vždy, když jste podání nedokončili: vytvoření nového
odkazu nic neodesílá, dosavadní aktivní pokus se v historii označí jako zrušený
a vznikne nový.

Parametr EPO `test=1` je v technické dokumentaci určen pro přímo odesílané
elektronicky podepsané podání ZAREP. Není zdokumentovaný pro asistované otevření
nepodepsaného formuláře, proto jej MyÚčto v tomto režimu neposílá. Před předáním
provede lokální validaci XSD a další obsahové problémy zobrazí interaktivní kontroly
formuláře EPO.

### 49.8.10 Rekonciliace proti skutečně podanému XML

**DPPO.** U DPPO lze importovat podané **DPPDP9 XML** a porovnat je s výpočtem
stejného roku. Systém nejprve tvrdě ověří rok a typ formuláře. Poté zobrazí rozdíly
po řádcích, včetně částek, které vznikly ruční úpravou nebo odlišným zaokrouhlením.
Importovaný soubor výpočet nepřepisuje, slouží jako nezávislý podklad k vysvětlení
rozdílu.

**OSS.** Záložka **Rekonciliace** v `Daně → OSS přiznání` porovnává archivované
podání s tím, co by se za totéž období podalo dnes. Nejde o import cizího XML:
srovnává se uložený podklad podání s aktuálním náhledem, takže se pozná doklad
opravený zpětně, doklad, který z období zmizel (storno, přesun data plnění), i
přesun daně do jiného státu spotřeby při nezměněném součtu.

Referencí není prostě poslední archivovaný snímek, ale ten s nejvyšší důkazní silou:
**doložené podání** (odeslané nebo přijaté finanční správou), a pokud žádné není,
tak **první stažení** období, tedy první podoba výkazu, která opustila systém.
Opakované stažení už referencí nepohne, takže rekonciliaci nelze omylem „srovnat"
tím, že si výkaz stáhnete znovu. Snímek pouze vygenerovaný k náhledu se za
referenci nebere nikdy. Archiv, který ještě nemá uložený podklad, se neporovnává
a hlásí to, nikdy se nevydává za shodu. Rozhodnutí o opravném podání zůstává na
účetní.

Tamtéž je i evidence podle § 110f ZDPH (struktura dle čl. 63c nařízení (EU)
č. 282/2011), která vzniká write-once při archivaci podání, uchovává se 10 let od
konce roku plnění a exportuje se do CSV nebo JSON. Celý režim OSS včetně podání
a evidence popisuje kapitola [OSS](45_OSS.md#451015-archiv-podani-rekonciliace-a-evidence-110f).

**DPH, KH, DPFO a pojistné.** MyÚčto provádí interní křížové kontroly DPHDP3 proti
KH, souhrnnému hlášení, knize DPH a účtu 343. To však není import a porovnání se
skutečně podaným XML. U DPFO, DPHDP3, KH, sociálního ani zdravotního přehledu
obecná rekonciliace proti podanému souboru v uživatelském rozhraní není. Pro tato
podání uchovejte finální XML i potvrzení a při opravě porovnejte podané hodnoty
s aktuálním náhledem ručně. Rozdíly po podání mohou znamenat pozdě doplněný doklad,
změněnou klasifikaci, kurz, datum přijetí nebo ruční zásah provedený přímo na
portálu.

### 49.8.11 Řádné, opravné a dodatečné podání

- **Řádné** je první tvrzení za období.
- **Opravné** nahrazuje předchozí podání před uplynutím lhůty.
- **Dodatečné** nebo u KH **následné** navazuje na poslední účinné podání po lhůtě.

Před vytvořením další varianty vždy určujte poslední účinné podání podle potvrzení
správce daně, ne podle nejnovějšího souboru v archivu. Vygenerovaný, ale
neodeslaný soubor se poslední známou daní nestává.

### 49.8.12 Co systém nenahrazuje

- podání na ePortál ČSSZ nebo portály zdravotních pojišťoven,
- právní oprávnění podepisující osoby jednat za konkrétní daňový subjekt,
- kontrolu stavů, které EPO rozhraní neposkytne nebo už na serveru neuchovává,
- odborné rozhodnutí, zda podat opravné nebo dodatečné tvrzení,
- univerzální import a diff všech typů podaných XML,
- kontrolu údajů, které uživatel po importu ručně změnil přímo na portálu.

## 49.9 Související kapitoly

- [Výkazy DPH](41_Vykazy_DPH.md)
- [Souhrnné hlášení](44_Souhrnne_hlaseni.md)
- [Daň z příjmů](43_Dan_z_prijmu.md)
- [OSS](45_OSS.md)
- [Uzávěrka a měsíční kontrola](72_Uzaverka.md)
- [Elektronické podpisy](99_Elektronicke_podpisy.md)
