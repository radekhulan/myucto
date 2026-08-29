# 89. EPO podání, archív a daňová rekonciliace

Tato kapitola vysvětluje rozdíl mezi **výpočtem**, **vygenerovaným XML**, skutečně
**odeslaným podáním** a pozdějším potvrzením správce daně. Tyto stavy se nesmějí
zaměňovat: soubor uložený v MyÚčto není sám o sobě důkazem, že byl přijat finanční
správou.

Související výpočty jsou popsány v kapitolách [Výkazy DPH](36_Vykazy_DPH.md),
[Souhrnné hlášení](39_Souhrnne_hlaseni.md) a [Daň z příjmů](38_Dan_z_prijmu.md).

## 89.1 Stavy daňového výstupu

| Stav | Co prokazuje | Co neprokazuje |
|---|---|---|
| **Náhled** | Aktuální výpočet z dat firmy. | Neměnnost dat, podání ani přijetí správcem daně. |
| **Vygenerováno / staženo** | XML vzniklo, prošlo dostupnou technickou validací a bylo archivováno se svým otiskem. | Že uživatel soubor odeslal nebo že EPO přijalo jeho obsah. |
| **Odesláno** | Uživatel doložil čas odeslání, případně identifikátor potvrzení. | Konečné přijetí bez chyb nebo vyměření daně. |
| **Přijato / odmítnuto** | Stav převzatý z potvrzení portálu či podatelny. | Věcnou správnost všech daňových údajů. |

Samostatnou obrazovku otevřeš v **Nástroje → EPO podání a archív**. Zobrazuje
vygenerované snapshoty, výsledek lokální validace, historii předání do EPO,
uložené důkazní dokumenty a stav podání.

### 89.1.1 Filtr, hledání a stránkování

Seznam se **stránkuje po padesáti záznamech** a filtr stavu, výběr výkazu
i hledání pracují **nad celým archivem**, ne jen nad zobrazenou stránkou. Dřív
obrazovka natáhla prvních sto záznamů a filtrovala jen mezi nimi, takže kdo si
vybral „zamítnuté", viděl prázdno, i když zamítnuté podání měl - jen bylo starší
než těch sto. Obrazovka takhle uživateli lhala, a proto se to změnilo.

- **Stav** nabízí staženo, odesláno, přijato a zamítnuto, případně vše.
- **Výkaz** se plní ze skutečně existujících typů v archivu, ne z toho, co
  dorazilo na stránku.
- **Hledání** se párá s podací značkou, otiskem, kódem výkazu a rokem období,
  tedy se skutečnými údaji záznamu, ne s přeloženými popisky na obrazovce.
  Píše se s krátkou prodlevou, takže se výsledek dohledá sám.

Změna filtru i hledání tě vždy vrátí na první stránku. Záznamy jsou řazené od
nejnovějšího.

> 🛈 Pozn: **Souhrnné dlaždice nahoře jsou vědomě jiné číslo než počet ve
> stránkování.** Počet u stránkování odpovídá tomu, co jsi vyfiltroval; dlaždice
> se počítají nad celým viditelným archivem, protože slouží k navigaci. Kdyby se
> počítaly ze stránky, tvrdily by „2 problémy", i když jich je třicet o dvě
> stránky dál; kdyby se vázaly na filtr, ztratily by smysl.

### 89.1.2 Kdy jde snapshot smazat

Mazání blokuje jen to, co prokazatelně odešlo:

| Situace | Smazat lze? |
|---|---|
| Snapshot bez předání, případně jen s testem EPO (`test=1`) | Ano. Test se z principu nepodává — EPO na něj odpovídá, že podání nebylo přijato, protože šlo o testovací režim. |
| Předání do EPO, jehož výsledek aplikace nezná (čeká na P7S, propadlý odkaz, nejednoznačná odpověď) | Až po vědomém uzavření. Ověř v portálu EPO, jestli podání prošlo; pokud ne, popiš, jak jsi to ověřil, a pokus se uzavře jako nepodaný. |
| Snapshot označený jako podaný, potvrzené podání, podací číslo nebo doručenka | Ne. Je to doklad o podání pro správce daně. |

Nad obdobím, na kterém běží zadržení podle § 32 ZoÚ (daňová kontrola, odvolání,
soudní spor), se snapshoty nemažou vůbec — mazání není cesta, jak se zbavit
podkladu, který správce daně prověřuje.

Smazání zachytí auditní log včetně toho, co spolu se snapshotem zmizelo
(pokusy, vazby na důkazní dokumenty). Samotné dokumenty v modulu **Dokumenty**
zůstávají.

## 89.2 Co se v archivu ukládá

Při ostrém exportu se uchovává přesný XML obsah, velikost, SHA-256 otisk, typ
formuláře, období, varianta podání a výsledek dostupné validace. Otisk umožňuje
později ověřit, že stažený soubor odpovídá archivované verzi.

Při prvním předání nebo nahrání dokumentu se stejný zdrojový XML snapshot uloží
také do modulu **Dokumenty**. Aplikace k němu připojí další nahrané artefakty,
například XML stažené z EPO, podepsané potvrzení P7S/P7M nebo PDF doručenku.
Každý soubor má vlastní SHA-256 otisk a výsledek dostupného ověření.
Automaticky vytvořené názvy obsahují formulář, období, ID archivního snapshotu,
čas s mikrosekundami a u EPO dokumentů také ID konkrétního pokusu. Opakované
vygenerování DPH za stejné čtvrtletí ani opakovaný stavový dokument proto
nepřepíše předchozí soubor.

Z dodejky P7S se — bez ohledu na to, jestli dorazila z API, nebo ji účetní nahrála
ručně — ukládají i její rozbalené části: čitelný přepis potvrzení, echo podání tak,
jak si ho eviduje finanční správa, certifikát pečeti a certifikát podepisující osoby.
Díky tomu jde podpis ověřit i za několik let, až vydávající autorita certifikát vymění.

Přímé rozhraní automaticky uloží podepsaný odchozí balíček, XML odpovědi a
vrácené potvrzení P7S. Dokumentované odesílací API neposkytuje ZFO ani
renderovaný PDF opis. PDF nebo P7S/P7M získané později z portálu lze přidat
přetažením; ZFO není podporovaný typ ručního uploadu.

V nastavení archívu lze vybrat dva kořeny: jeden pro DPH, kontrolní a souhrnné
hlášení a OSS, druhý pro DPFO a DPPO. Pod kořenem vzniká automaticky strom
**rok → měsíc/čtvrtletí → druh formuláře**. Bez vlastního nastavení aplikace
založí výchozí kořeny **DPH a hlášení** a **Daň z příjmů**.

U DPFO vzniká ostré XML jen z finalizovaného neměnného snapshotu. Změna živých
dokladů po finalizaci proto nesmí tiše změnit dříve vytvořené podání; oprava se řeší
novou revizí, opravným nebo dodatečným přiznáním. Pracovní XML z náhledu není
podáním a nearchivuje se jako finální daňový výstup.

U DPHDP3, KH a souhrnného hlášení je archivovaný soubor technickým obrazem
vygenerovaného výkazu. Před odesláním vždy porovnej období, typ podání, identifikační
údaje a součty s náhledem a související kontrolní sestavou.

## 89.3 Přímé podání přes EPO API

Přímý režim vytvoří uznávaný elektronický podpis ZAREP, provede test oficiální
podatelny a po samostatném potvrzení odešle skutečné podání. Potřebuješ osobní
**kvalifikovaný certifikát pro elektronický podpis** ve formátu P12/PFX se
soukromým klíčem. Certifikát patří fyzické osobě, která podání podepisuje; v
MyÚčtu jej lze vědomě povolit pro jednu nebo více spravovaných firem.

### 89.3.1 Jak získat vhodný certifikát

Pro přímé EPO objednej **kvalifikovaný certifikát pro elektronický podpis
fyzické osoby** (případně zaměstnanecký/OSVČ profil), jehož soukromý klíč lze
exportovat do souboru P12/PFX. Neobjednávej certifikát pro elektronickou pečeť.
Pokud zvolíš čipovou kartu, token, eObčanku nebo jiný kvalifikovaný prostředek
QSCD, soukromý klíč obvykle exportovat nelze a do serverového trezoru MyÚčta jej
proto nepůjde vložit.

Finanční správa požaduje pro přímé rozhraní třetích stran uznávaný elektronický
podpis ZAREP založený na kvalifikovaném certifikátu. V žádosti výslovně zvol
vložení **identifikátoru klienta MPSV (IK MPSV)**; nevymýšlej ani nezadávej
vlastní identifikátor. Poskytovatel jej ověří nebo zajistí v rámci vydání.
Finanční správa uvádí, že IK MPSV není v certifikátu automaticky a jeho vložení
je bezplatné.

Praktický postup:

1. Nejrychlejší ověřená cesta je
   [PostSignum – certifikát online](https://www.postsignum.cz/certifikat_online.html).
   Při online identifikaci může být osobní kvalifikovaný certifikát hotový během
   několika minut; konkrétní doba závisí na úspěšném ověření a provozu služby.
   Alternativně vyber kvalifikovaného poskytovatele a produkt pro podpis fyzické osoby:
   [eIdentity](https://www.eidentity.cz/),
   [PostSignum – fyzické osoby](https://www.postsignum.cz/fyzicke_osoby_.html?step=2)
   nebo [I.CA – kvalifikovaný certifikát pro ePodpis](https://www.ica.cz/kvalifikovany-certifikat-pro-ePodpis).
2. Žádost vytvářej na počítači a v uživatelském profilu, ve kterém budeš
   certifikát přebírat. Zvol uložení klíče **v počítači / softwarovém úložišti**,
   nikoli na neexportovatelném QSCD.
3. V žádosti zaškrtni vložení IK MPSV. U eIdentity je volba **Use IkMPSV in
   the certificate** přímo v
   [žádosti](https://www.eidentity.cz/registration/EasyRequest.html). Pokud
   portál vyžádá samostatnou žádost či potvrzení MPSV, postupuj podle pokynu
   eIdentity nebo registračního místa; do pole se nevkládá rodné číslo ani
   vlastní text. PostSignum má volbu **Vložit Identifikátor klienta MPSV** ve
   formuláři fyzické osoby. I.CA umožňuje IK MPSV zvolit při generování žádosti
   nebo o něj požádat při vydání na registrační autoritě.
4. Dokonči registraci a ověření totožnosti podle pokynů poskytovatele. U prvního
   certifikátu počítej s kontrolou osobních dokladů na registračním místě.
5. Certifikát převezmi a nainstaluj ve stejném profilu, ve kterém vznikl
   soukromý klíč. Samotný veřejný soubor CER/CRT nestačí.
6. Ve Windows otevři správu certifikátů aktuálního uživatele
   (`certmgr.msc`), najdi certifikát v **Osobní → Certifikáty** a zvol
   **Všechny úkoly → Exportovat → Ano, exportovat soukromý klíč → PKCS #12
   (.PFX)**. Zahrň certifikační řetězec, nastav silné jedinečné heslo a soubor
   ulož jen do zabezpečeného dočasného umístění.
7. Před importem zkontroluj, že PFX obsahuje soukromý klíč, certifikát je platný
   a určený pro digitální podpis. Po importu do MyÚčta bezpečně ulož zálohu a
   údaje pro zneplatnění; pracovní kopii z běžné složky smaž.

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

Správce instalace musí před použitím nastavit samostatný
`app.secret_encryption_key`. Bez něj MyÚčto soukromý klíč nepřijme. P12/PFX i
jeho heslo jsou v databázi uložené pouze šifrovaně a API je nikdy nevrací.
Uložení, povolení pro firmu, odstranění, testovací podpis i ostré podání
vyžaduje znovu aktuální heslo uživatele a při zapnutém 2FA také TOTP kód.
Máš-li přístupový klíč, nahradí tlačítko **Ověřit přístupovým klíčem** heslo
i TOTP naráz — ověření je jednorázové a vázané přímo na tuhle operaci, takže
proof z jiné akce ani samotné přihlášení certifikát neodemknou. Cesta přes
heslo zůstává dostupná vždy.
Šifrované EPO údaje jsou navíc vázané na svůj účel, takže například ciphertext
hesla dodejky nelze zaměnit za ciphertext PFX. Při rotaci klíče správce nastaví
nový `app.secret_encryption_key` a původní klíč dočasně ponechá v
`app.secret_encryption_previous_keys`; staré záznamy zůstanou čitelné a nové se
už zapisují novým klíčem. Starý klíč lze odebrat až po řízeném přešifrování nebo
odstranění všech dat, která jej používají.

Osobní certifikát uložený pro EPO může přihlášený uživatel připojit ke svému
profilu v **Systém -> Elektronické podpisy** a použít ho také pro PDF nebo
S/MIME. Nevzniká druhá kopie PFX ani hesla; podpisový profil ukládá pouze vazbu
na osobní trezor. Připojení vyžaduje stejné ověření jako výše. Dokud
certifikát používá aktivní podpisový profil, nelze ho z trezoru smazat ani
odebrat z příslušné firmy.

Dodejky se ověřují proti CA bundlu z `epo.ca_bundle_path`. Ve výchozí konfiguraci
míří na `api/resources/epo/epo-ca-bundle.pem`, který se nasazuje spolu s aplikací
a obsahuje kořeny i podřízené kvalifikované autority I.CA a PostSignum. Pečeť EPO
vydává I.CA (`CN=I.CA EU Qualified CA2/RSA`, `O=První certifikační autorita, a.s.`).

Systémový CA store se pro tohle použít nedá: bundly pro TLS (Mozilla) žádnou
českou kvalifikovanou autoritu neobsahují. Ostré podání přitom platný řetězec
potvrzenky vyžaduje, takže bez bundlu by reálně podané hlášení skončilo ve stavu
`uncertain` a v Dokumentech by chybělo potvrzení. Stav je z toho zotavitelný —
potvrzenka zůstává bezpečně uložená a *Obnovit stav* ji po nápravě konfigurace
znovu ověří a pokus dotáhne do `confirmed`, bez opakovaného podání.

Volitelně lze doplnit allowlist SHA-256 otisků podpisových certifikátů EPO
v `epo.receipt_signer_fingerprints_sha256`. Je-li bundle nastaven, ale
není dostupný, ověření selže bezpečně; aplikace nespadne zpět na jiný trust store.
Pro zkušební prostředí je kvůli testovacímu certifikátu bez produkčního řetězce
povinný samostatný allowlist
`epo.test_receipt_signer_fingerprints_sha256`. Lze jej předat také jako
čárkami oddělenou proměnnou
`MYINVOICE_EPO_TEST_RECEIPT_SIGNER_FINGERPRINTS_SHA256`. Bez přesné shody
otisku se dodejka pouze archivuje a pokus se automaticky nepotvrdí.

### 89.3.2 Zkušební prostředí pro vývoj

Instalace může přímé podepsané EPO operace přepnout na veřejné zkušební
prostředí Finanční správy. V `cfg.php` nastav:

```php
'epo_test' => true,
```

V Dockeru nebo jiné konfiguraci přes proměnné prostředí lze použít
`MYINVOICE_EPO_TEST=true`. Výchozí hodnota v aplikaci i v `cfg.sample.php` je
`false`.

Po zapnutí míří přímá kontrola s `test=1`, následné odeslání, vyzvednutí
rozsáhlého podání i dotaz na stav na
[zkus.mojedane.gov.cz](https://zkus.mojedane.gov.cz). Zkušební podání se na
ostrém portálu neprojeví. MyÚčto přesto uloží pokus, podepsaný balíček,
odpovědi, dodejku, stavové události a dokumenty; v uživatelském rozhraní je
označí jako **TEST**. Potvrzení ze zkušebního prostředí nikdy nezmění stav
archivovaného snapshotu na skutečně podaný.

Asistované otevření formuláře používá vždy ostrý interaktivní portál MOJE daně.
Předání pouze předvyplní formulář a samo jej neodešle; právní účinek vzniká až
vědomým odesláním uživatelem na portálu.

Každý pokus si ukládá prostředí, ve kterém vznikl. Když správce později
`epo_test` přepne, rozpracovaný pokus se na jiný server nepřesměruje. Odeslání
i obnovení jeho stavu se bezpečně odmítne, dokud aktuální konfigurace znovu
neodpovídá prostředí uloženému u pokusu. Zkušební prostředí může být dočasně
nedostupné kvůli servisu nebo aktualizaci.
Technické struktury a rozhraní popisuje
[dokumentace MOJE daně](https://mojedane.gov.cz/pmd/dokumentace).

Postup:

1. Otevři **Certifikáty EPO**, nahraj P12/PFX, zadej jeho heslo a potvrď se —
   buď přístupovým klíčem, nebo heslem do MyÚčta a případným TOTP.
   Zkontroluj vlastníka, vydavatele a platnost certifikátu.
2. Pokud certifikát používáš pro další spravovanou firmu, přepni se na ni a
   certifikát pro ni výslovně povol.
3. Pro vizuální kontrolu můžeš nejdřív otevřít předvyplněný formulář EPO a
   zavřít jej bez odeslání. Otevřená asistovaná relace neblokuje neúčinný datový
   test, ale do svého vypršení blokuje skutečné odeslání proti duplicitě.
4. V detailu validního XML snapshotu vyber certifikát a klikni
   **Otestovat v EPO**.
5. MyÚčto vytvoří připojený podpis PKCS#7 v DER, odešle jej s `test=1` a
   zobrazí všechny zprávy EPO. Test kontroluje podpis, strukturu i věcná
   pravidla, ale daňové podání nevytvoří. Úspěšný test lze pro ostré podání
   použít jednou a nejdéle 30 minut. Testovací podepsaný balíček je uložen jen
   šifrovaně a nelze jej stáhnout ani znovu použít mimo řízený tok.
   Kontrolní hlášení DPH MyÚčto před podpisem automaticky vloží jako jediný
   XML soubor do ZIP archivu, jak vyžaduje rozhraní Finanční správy; zdrojový
   XML snapshot i jeho SHA-256 přitom zůstávají beze změny.
   Do tohoto okamžiku aplikace certifikát označuje jako **Dosud neověřen EPO**;
   samotné načtení PFX neprokazuje jeho kvalifikovanost ani přijatelnost pro EPO.
6. Chyby typu struktura, kritická chyba nebo systémová výjimka musíš odstranit
   v datech a vygenerovat nový snapshot. Propustná upozornění jsou zobrazena,
   ale úspěšný test neblokují.
7. Akce **Podepsat a podat** je při dostupném certifikátu stále viditelná, ale
   odemkne se až po úspěšném testu a po skončení případné asistované relace. Znovu se ověř
   (přístupový klíč, nebo heslo a případný TOTP) a potvrď právně účinné odeslání.
8. MyÚčto automaticky uloží zdrojové XML, ostrý odchozí podepsaný balíček,
   testovací protokol a P7S potvrzení podatelny. Podací číslo a čas převezme z
   kryptograficky ověřeného potvrzení. Automatické potvrzení vyžaduje platný
   certifikační řetězec, identitu **Společného technického zařízení správců
   daně** v podpisovém certifikátu a přesnou vazbu na odeslaný CMS balíček.
9. Tlačítkem **Obnovit stav EPO** lze stáhnout aktuální stav zpracování. U
   rozsáhlého podání nejprve EPO vrátí identifikátor předání; potvrzení se
   vyzvedne později stejným tlačítkem. Stejné dotazy bezpečně provádí
   `cron-epo-status` s exponenciálním odstupem nejvýše jedné hodiny. Worker zná
   pouze heslo konkrétního stavu a nikdy nepoužívá PFX ani znovu neodesílá XML.

Pro automatickou kontrolu spusť wrapper každou minutu. Jednotlivý pokus se i
tak dotazuje jen podle svého naplánovaného času a po každém neukončeném stavu
prodlužuje odstup:

```text
Linux cron:       * * * * * /cesta/k/myucto/cmd/cron-epo-status.sh
Windows Scheduler: C:\cesta\k\myucto\cmd\cron-epo-status.cmd
Docker host cron: * * * * * docker compose exec -T app php api/bin/cron-epo-status.php
```

Ve Windows nastav opakování úlohy každou minutu. Výsledek každého běhu je v
evidenci cronů a wrapper zapisuje denní log do `log/cron`; při vlastním
`MYINVOICE_DATA_DIR` pod jeho `log/cron`.

> [!WARNING]
> Pokud se při ostrém POST přeruší spojení bez jednoznačné odpovědi, pokus se
> označí jako **Výsledek je nejistý**. MyÚčto jej automaticky neopakuje.
> Nejdřív ověř stav u Finanční správy, protože slepé opakování může vytvořit
> duplicitní podání. Pokud dohledáš původní P7S/P7M, použij **Ověřit nalezené
> P7S**; systém je kryptograficky sváže s uloženým odchozím balíčkem. Jen když
> pokus nemá podací číslo ani stavové heslo a přímo v EPO ověříš, že podání
> nevzniklo, lze po 15 minutách použít **Uvolnit nepřijatý pokus**. Akce vyžaduje
> nové ověření identity, výslovné potvrzení a auditní poznámku.

U kontrolního hlášení EPO z bezpečnostních důvodů vrací v dodejce redukovaný
obsah. Pokud proto nelze kryptograficky prokázat přesnou shodu s odeslaným CMS,
MyÚčto dodejku archivuje, ale pokus ponechá jako **Výsledek je nejistý** k ruční
kontrole; shodu nikdy nepředpokládá pouze podle typu formuláře. Pokud dodejka
obsahuje údaje pro stavový dotaz, následné potvrzení přijetí přes `epo_stav`
doplní čas, podací číslo, původního podepisujícího i daňový zámek období.

Zkušební podatelna podepisuje dodejku certifikátem s výslovnou identitou
**Testovací zařízení – nelze učinit platné podání**. MyÚčto v prostředí
`epo_test` vyžaduje platný podpis, tuto přesnou identitu GFŘ, přesnou shodu
SHA-256 otisku s testovacím allowlistem a vazbu na odeslaný balíček. Testovací
dodejku přitom neprezentuje jako produkčně důvěryhodný řetězec ani jako právně
účinné podání.

IK MPSV v kvalifikovaném certifikátu pomáhá EPO spojit podepisující osobu s
evidovanou identitou. MyÚčto na jeho nerozpoznání upozorní, ale samo nerozhoduje
o oprávnění jednat za konkrétní daňový subjekt. To musí odpovídat skutečnému
zastoupení, funkci nebo plné moci.

## 89.4 Asistované podání přes EPO

1. Dokonči účetní nebo evidenční kontrolu daného období.
2. Vygeneruj XML a ověř, že interní kontrola nehlásí blokující chybu.
3. V **Nástroje → EPO podání a archív** najdi snapshot a zkontroluj období, formulář,
   variantu, otisk a stav **Validní**.
4. Klikni **Otevřít a podat v EPO**. MyÚčto odešle přesný snapshot na oficiální
   endpoint finanční správy a otevře předvyplněný formulář. Toto předání samo
   ještě není podáním.
5. V EPO spusť kontroly, porovnej zobrazené částky a typ podání s MyÚčto a až
   poté podání odešli.
6. Z EPO stáhni odeslané XML a potvrzení. Přetáhni je do vyznačené plochy
   detailu podání; složka v Dokumentech se vytvoří automaticky.
7. Z nahrané dodejky (`.p7s`/`.p7m`) aplikace ověří podpis, vazbu na archivovaný
   formulář a přečte její obsah — stejně jako u přímého podání. Dodejku rozbalí
   na čitelné části (přepis potvrzení, echo podání, certifikát pečeti i podepisující
   osoby) a v detailu podání ukáže shrnutí: podací číslo, rozhodný čas, příznak
   ZAREP, kontrolní součet odeslaného souboru, kód finančního úřadu a identitu
   pečeti. Podací číslo a čas zároveň předvyplní do formuláře **Označit jako
   podané** — potvrzení zůstává tvým úkonem, aplikace ho jen neuhádne za tebe.
8. Spolu s tím si aplikace z dodejky převezme **heslo pro dotaz na stav** a uloží
   ho zašifrované k předání. Díky tomu je i u asistovaného podání dostupné
   **Obnovit stav** (dotaz na `epo_stav`) a odhalení hesla pro opis na Daňovém
   portálu — to je samostatná akce se silným ověřením a záznamem v auditu.
9. Teprve potvrzené podání používej jako poslední známý stav pro další opravné
   nebo dodatečné tvrzení.

> [!NOTE]
> **PDF opis podání se čte jen jako nápověda.** Pokud z portálu odneseš pouze tisk,
> aplikace se z jeho textové vrstvy pokusí přečíst podací číslo a čas a předvyplní
> jimi formulář — panel u toho výslovně říká, že jde o odečtený text, ne o podepsaný
> důkaz. Heslo pro dotaz na stav v tisku není, takže dotaz na stav odemkne až dodejka.
> U skenovaného PDF bez textové vrstvy se soubor jen archivuje.

> [!TIP]
> **Nahrané XML aplikace porovná s archivovaným snapshotem.** Když se otisky
> neshodují, nezůstane u konstatování „neodpovídá": vypíše počet rozdílných údajů,
> případně upozorní, že jde o úplně jiný formulář. Rozdíl v hodnotě znamená, že se
> podání v EPO upravovalo a archiv už neodpovídá tomu, co bylo podáno.

Odkaz vrácený EPO si ponechá jen aktuální prohlížeč — server ho neukládá do
databáze ani do logu. Odkaz je **jednorázový**: portál jej spotřebuje prvním
otevřením. MyÚčto jej proto hned po otevření označí jako použitý a tlačítko
**Pokračovat do EPO** znovu nenabídne. Pokud podání v otevřeném okně
nedokončíš nebo okno zavřeš, vytvoř nový odkaz.

Dosud neotevřený odkaz je použitelný jen tehdy, když platí **obojí**:

- od vytvoření neuplynulo víc než **20 minut** — portál mluví o session zhruba
  30 minut od poslední aktivity a tohle je rezerva pod tím;
- **XML se od vytvoření odkazu nezměnilo** — jakmile se podklad přepočítá,
  mířil by starý odkaz na neaktuální písemnost. Shoda se pozná podle SHA-256
  otisku, který archiv u snapshotu vede.

Skutečnou životnost odkazu portál neprozrazuje, takže dvacetiminutové okno je
bezpečnostní rezerva, ne zaručená platnost. Po otevření odkazu, uplynutí tohoto
okna nebo změně XML nabídne detail podání místo pokračování akci **Vytvořit
nový odkaz EPO**. Použij ji vždy, když jsi podání nedokončil: vytvoření nového
odkazu nic neodesílá, dosavadní aktivní pokus se v historii označí jako zrušený
a vznikne nový.

> [!IMPORTANT]
> **OSS přiznání (OSSEI1) se přes EPO podat nedá — žádnou z obou cest.** Portál
> písemnost rozpozná, ale odmítne ji s tím, že musíš být přihlášený v samostatné
> aplikaci **MOSS/OSS**. Přímé podepsané podání míří na týž endpoint jako
> asistované předání, takže dopadne stejně, jen po zbytečném odemčení klíče.
> MyÚčto proto u OSS snapshotu nenabízí ani asistované předání, ani panel
> přímého podání (a odmítá obě cesty i přes API). Stáhni XML a nahraj ho
> v aplikaci MOSS/OSS na Daňovém portálu — podrobně
> [§ 40.8.5](40_OSS.md#4095-kde-se-oss-priznani-podava).

> [!NOTE]
> Parametr EPO `test=1` je v technické dokumentaci určen pro přímo odesílané
> elektronicky podepsané podání ZAREP. Není zdokumentovaný pro asistované
> otevření nepodepsaného formuláře pomocí `otevriFormular=1`, proto jej MyÚčto
> v tomto režimu neposílá. Před předáním provede lokální XSD validaci a další
> obsahové problémy zobrazí interaktivní kontroly formuláře EPO.

> [!WARNING]
> Zavření okna EPO, úspěšné otevření formuláře ani zelená lokální XSD validace
> neprokazují odeslání. Rozhodující je potvrzení podatelny.

Samotné stažení XML neposouvá stav na odesláno a nemá nahrazovat ruční zámek období.
Po úspěšném podání uzamkni příslušné daňové období k datu přes
[Měsíční kontrolu](87_Uzaverka.md#877-mesicni-kontrola), pokud to již neprovedlo
řízené workflow firmy.

## 89.5 Rekonciliace proti skutečně podanému XML

Rekonciliace odpovídá na otázku: „Shoduje se dnešní výpočet MyÚčto s tím, co bylo
skutečně odesláno?“ Není to totéž jako kontrola, že dvě interní sestavy čerpají ze
stejných dokladů.

### 89.5.1 DPPO

U DPPO lze importovat podané **DPPDP9 XML** a porovnat je s výpočtem stejného roku.
Systém nejprve tvrdě ověří rok a typ formuláře. Poté zobrazí rozdíly po řádcích,
včetně částek, které vznikly ruční úpravou nebo odlišným zaokrouhlením. Importovaný
soubor výpočet nepřepisuje; slouží jako nezávislý podklad k vysvětlení rozdílu.

Praktický postup:

1. Vyber stejné období jako v podaném formuláři.
2. Nahraj XML, které bylo skutečně odesláno, nikoli nově vygenerovanou kopii.
3. Projdi všechny rozdíly, zejména výsledek hospodaření, nedaňové náklady,
   odpisové rozdíly, dary, ztráty a zálohy.
4. Rozdíl vysvětli opravou zdroje, doloženou ruční úpravou nebo identifikací změny,
   která nastala až po podání.
5. Neshodu nikdy neodstraňuj mechanickým dorovnávacím zápisem bez účetního případu.

### 89.5.2 OSS

U OSS porovnává **Daně → OSS přiznání → Rekonciliace** archivované podání s tím, co
by se za totéž období podalo dnes. Nejde o import cizího XML: srovnává se uložený
podklad podání s aktuálním náhledem, takže se pozná doklad opravený zpětně, doklad,
který z období zmizel (storno, přesun data plnění), i přesun daně do jiného státu
spotřeby při nezměněném součtu.

Referencí není prostě poslední archivovaný snímek, ale ten s nejvyšší důkazní silou:
**doložené podání** (odeslané nebo přijaté finanční správou), a pokud žádné není, tak
**první stažení** období — tedy první podoba výkazu, která opustila systém. Opakované
stažení už referencí nepohne, takže rekonciliaci nelze omylem „srovnat" tím, že si
výkaz stáhnete znovu. Snímek pouze vygenerovaný k náhledu se za referenci nebere nikdy.
Archiv, který ještě nemá uložený podklad, se neporovnává a hlásí to — nikdy se nevydává
za shodu. Rozhodnutí o opravném podání zůstává na účetní.

Tamtéž je i evidence podle § 110f ZDPH (struktura dle čl. 63c nařízení (EU)
č. 282/2011), která vzniká write-once při archivaci podání, uchovává se 10 let od
konce roku plnění a exportuje se do CSV nebo JSON. Celý režim OSS včetně podání
a evidence popisuje kapitola [40. Režim OSS](40_OSS.md#409-priznani-a-podani).

### 89.5.3 DPH, KH, DPFO a pojistné

MyÚčto provádí interní křížové kontroly DPHDP3 proti KH, souhrnnému hlášení, knize
DPH a účtu 343. To však není import a porovnání se skutečně podaným XML. U DPFO,
DPHDP3, KH, sociálního ani zdravotního přehledu obecná rekonciliace proti
podanému souboru v uživatelském rozhraní není.

Pro tato podání uchovej finální XML i potvrzení a při opravě porovnej podané hodnoty
s aktuálním náhledem ručně. Rozdíly po podání mohou znamenat pozdě doplněný doklad,
změněnou klasifikaci, kurz, datum přijetí nebo ruční zásah provedený přímo na portálu.

## 89.6 Řádné, opravné a dodatečné podání

- **Řádné** je první tvrzení za období.
- **Opravné** nahrazuje předchozí podání před uplynutím lhůty.
- **Dodatečné** nebo u KH **následné** navazuje na poslední účinné podání po lhůtě.

Před vytvořením další varianty vždy určuj poslední účinné podání podle potvrzení
správce daně, ne podle nejnovějšího souboru v archivu. Vygenerovaný, ale neodeslaný
soubor se poslední známou daní nestává.

## 89.7 Co systém nenahrazuje

- podání na ePortál ČSSZ nebo portály zdravotních pojišťoven,
- právní oprávnění podepisující osoby jednat za konkrétní daňový subjekt,
- kontrolu stavů, které EPO rozhraní neposkytne nebo už na serveru neuchovává,
- odborné rozhodnutí, zda podat opravné nebo dodatečné tvrzení,
- univerzální import a diff všech typů podaných XML,
- kontrolu údajů, které uživatel po importu ručně změnil přímo na portálu.

> [!TIP]
> Pro každý formulář archivuj trojici **odeslané XML + potvrzení + stručné vysvětlení
> ručních změn**. Při kontrole nebo dalším dodatečném podání pak lze přesně doložit,
> z jakého stavu se vycházelo.
