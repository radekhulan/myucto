# 99. Elektronické podpisy

> Návod, jak nahrát certifikát, založit podpisový profil a zapnout elektronické podpisy PDF faktur, výkazů práce,
> ISDOC a odchozích e-mailů (S/MIME). Pro administrátory a účetní, kteří spravují podpisy firmy.

## 99.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete, aby se faktury, výkazy práce nebo odchozí e-maily podepisovaly elektronicky,
- dostáváte z POHODY nebo jiného programu hlášku „Chybí podpis dokumentu" u importu faktury z PDF,
- máte nový certifikát po obnově a potřebujete ho nahrát, nebo starý odstranit,
- potřebujete certifikát použít ve víc firmách,
- účetní má podepisovat vlastním certifikátem,
- chcete ověřit, že se doklad podepsal správně.

Aktuálně se podepisují PDF výstupy, ISDOC faktur a vybrané odchozí e-maily:

- **PDF výstupy:** vydaná faktura, samostatný výkaz práce, volitelně sloučené PDF v exportu vydaných faktur.
- **ISDOC faktury:** stejným profilem jako PDF výstup Vydaná faktura (viz [§ 99.9](#999-krok-za-krokem-podpis-isdoc)).
- **S/MIME e-mailové výstupy:** e-mail s fakturou, s upomínkou, s připomínkou zálohy, s poděkováním za úhradu, se schválením výkazu a s připomínkou pravidelné faktury.

PDF používá podpis PAdES. Bez časového razítka jde o úroveň **PAdES-B**, s nastaveným TSA serverem o **PAdES-T**.
Odchozí e-mail se podepisuje jako **S/MIME** zpráva. S/MIME podpis potvrzuje odesílatele a integritu zprávy, ale
e-mail nešifruje.

> [!TIP]
> Podpis zachovává archivní formát. Faktury se generují jako PDF/A-3b a elektronický podpis tuto archivní konformitu
> zachová: podepsaný dokument je stále validní PDF/A-3b (ověřeno nástrojem veraPDF).

## 99.2 Než začnete

Kde funkci najdete, závisí na roli:

- **admin**: správu podpisů najdete jako záložku **Certifikáty a elektronické podpisy** uvnitř stránky `Systém → E-maily a certifikáty` (vedle záložek **Odeslané e-maily**, **E-mail šablony**, **Odesílací profily** a **SMTP log analýza**).
- **accountant** (účetní): pokud admin zapnul přepínač **Povolit uživatelům správu vlastních podpisových profilů**, objeví se účetnímu v postranním menu samostatná položka `Systém → Certifikáty a elektronické podpisy`. Bez zapnutého přepínače účetní tuto položku v menu nevidí a přímý přístup na adresu ho přesměruje na úvodní stránku.
- **Klientský správce**: uživatel s klientskou rolí, který má pro danou firmu **Firemní podpisové profily a PDF podpisy – Zápis**, spravuje firemní podpisové profily v nastavení firmy na klientském portálu. Tato cesta je určená pro provozní správu podpisu dané firmy; osobní profily ani certifikáty v osobním trezoru zde nejsou dostupné.

<!-- cols: 22 78 -->
| Role | Co může |
|---|---|
| **admin** | Spravuje profily dodavatele, může spravovat i profily uživatelů, nastavuje konfiguraci podpisů a povoluje uživatelské profily. |
| **accountant** | Po povolení adminem může spravovat pouze vlastní podpisové profily a vlastní výchozí mapování, na samostatné stránce. V detailu dokladu může změnit výběr profilu, ale konkrétní profil dodavatele může vybrat jen admin. |
| **Klientský správce** | Se zápisem do nastavení firmy může spravovat firemní profily, jejich certifikáty a použití pro PDF; podpis e-mailu zapojí přes firemní odesílací profil. Nemůže spravovat osobní profily, trezor ani jiné backendy. |
| **readonly** | Může číst a stahovat doklady podle běžných oprávnění, ale nemůže měnit podpisové profily, mapování ani výběr podpisu na dokladu. Přímý přístup na stránku elektronických podpisů ho přesměruje pryč. |

Admin povolí uživatelské profily přepínačem **Povolit uživatelům správu vlastních podpisových profilů** (na záložce
Certifikáty a elektronické podpisy v `Systém → E-maily a certifikáty`). Tím zpřístupní účetním jejich vlastní profily.
Klientský správce toto povolení nepotřebuje; rozhoduje jeho klientská role a přístup **Firemní podpisové profily a PDF podpisy – Zápis** pro
aktuální firmu.

Základní pojmy:

<!-- cols: 28 72 -->
| Pojem | Význam |
|---|---|
| Podpisový profil | Pojmenované nastavení podpisu. Obsahuje vlastnictví, použití, volitelnou konfiguraci PDF a časového razítka a jeden společný certifikát profilu. |
| Profil dodavatele | Profil vlastněný dodavatelem. Spravuje ho admin a může se použít jako centrální firemní podpis. |
| Můj profil | Profil vlastněný konkrétním uživatelem. Použije se jen tam, kde konfigurace výstupu počítá s přihlášeným uživatelem. |
| Konfigurace podpisů | Nastavení admina, které určuje, zda se PDF nebo e-mailový výstup podepisuje a odkud se bere podpisový profil. |
| Mapování podpisových profilů | Uživatelské výchozí profily pro výstupy, kde admin zvolil strategii **Přihlášený uživatel**. |

Před prvním podpisem potřebujete **certifikát ve formátu P12/PFX se soukromým klíčem**. Pro S/MIME je prakticky
důležité, aby certifikát obsahoval e-mailovou adresu používanou jako odesílatel.

## 99.3 Krok za krokem: nahrát certifikát do trezoru

Sekce **Certifikáty** na stránce Certifikáty a elektronické podpisy je jediné místo, kam se osobní certifikát P12/PFX
nahrává. Certifikát patří uživateli, ne firmě: v trezoru je uložený jednou a šifrovaně, firma k němu dostává jen
povolení. Odtud si ho berou podpisové profily, podání EPO i mzdová podání.

1. Otevřete `Systém → E-maily a certifikáty`, záložku **Certifikáty a elektronické podpisy**, sekci **Certifikáty**.
2. V části **Nahrát certifikát** klikněte na **Vybrat soubor** a vyberte soubor P12/PFX (se soukromým klíčem).
3. Zadejte **Heslo k certifikátu**. To je heslo, kterým je chráněný samotný soubor, ne heslo do MyÚčta.
4. Ověřte se: tlačítkem **Ověřit passkey** (po ověření se zobrazí **Passkey ověřeno**), nebo vyplněním pole **Heslo do MyÚčta** a při zapnutém 2FA také **Kódem z autentikátoru**. Ověření platí jen pro toto jedno nahrání a nikam se neukládá.
5. Podle potřeby zaškrtněte **Uložit i do dalších firem** a **Jen do firem bez platného certifikátu** (viz níže).
6. Klikněte na **Uložit certifikát**.
7. V každé firmě, kde ho chcete používat pro EPO a podpisy, ho povolte v `Daně → EPO podání a archív → Certifikáty EPO` (**Povolit pro tuto firmu**).

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Certifikát byl uložen** a v seznamu **Uložené certifikáty** je vidět
vlastník, vydavatel, sériové číslo a platnost.

**Dvě různá hesla.** Při nahrání se zadává **Heslo k certifikátu** a zvlášť ověření uživatele (passkey, nebo
**Heslo do MyÚčta** případně **Kód z autentikátoru**). Obě se posílají odděleně a nezávisle. Heslo do MyÚčta tedy
nikdy nepatří do pole s heslem k certifikátu.

**Povolení v dalších firmách.** Při nahrání lze zaškrtnout dvě volby:

<!-- cols: 36 64 -->
| Volba | Co udělá |
|---|---|
| **Uložit i do dalších firem** | Certifikát se povolí i ve všech dalších firmách, kde jste členem a smíte spravovat elektronické podpisy. Výchozí stav je vypnuto. |
| **Jen do firem bez platného certifikátu** | Přeskočí firmy, kde už máte povolený jiný platný certifikát. |

U každého platného certifikátu v trezoru je navíc tlačítko **Povolit v dalších firmách**, které udělá totéž pro
certifikát nahraný dříve. Obě akce vyžadují stejné ověření jako nahrání certifikátu. Po dokončení se zobrazí přehled
po firmách: **Povoleno**, **Už bylo povoleno**, **Přeskočeno, má platný certifikát** a **Přeskočeno, chybí oprávnění**.
Firmy, kde nejste členem, se akce nedotkne. Výjimkou je správce systému, který není členem žádné firmy a vidí proto
všechny: u něj se akce týká všech firem. Povolení certifikát ve firmě jen zpřístupní. Který certifikát se v dané
firmě skutečně použije pro podpisový profil, EPO nebo mzdová podání, se dál volí v příslušném nastavení té firmy.

**Nahradit certifikát ve všech firmách.** Když certifikát obnovíte, vyberte v poli **Nahradit certifikát** ten starý
a nový nahrajte, nebo u už nahraného nového klikněte na **Povolit v dalších firmách**. Aplikace se jednou zeptá na
potvrzení a pak:

- nový certifikát povolí ve všech firmách, kam akce dosáhne, i v aktuální firmě a i tam, kde už platný certifikát je
  (volba **Jen do firem bez platného certifikátu** se při náhradě nepoužije),
- přepojí na něj přístup k datové schránce, osobní podpisové profily a volbu certifikátu pro mzdová podání, které
  používaly starý certifikát.

V přehledu po firmách je u takové firmy **Nahrazeno** a počet přepojených míst. Starý certifikát zůstane v trezoru
a když ho už nic nepoužívá, smažete ho běžně. Nemění se odesílací brána ISDS (je společná pro celou instalaci
a registrovaná přímo v ISDS) ani registrace certifikátu u ČSSZ: nový certifikát je potřeba u ČSSZ zaregistrovat
a obrazovka mezd to do té doby hlásí. Přístup k datové schránce bude po náhradě potřeba znovu ověřit přihlášením.
Dříve podepsaná PDF zůstávají podepsaná starým certifikátem.

**Soubor se zastaralým šifrováním (RC2).** Starší exporty certifikátů, zejména z Windows a od některých autorit, jsou
chráněné zastaralou šifrou RC2, kterou server nepodporuje. Aplikace to ohlásí výslovnou hláškou, že soubor používá
zastaralé šifrování (RC2) a že za tím není heslo. Řešení jsou dvě:

- certifikát znovu vyexportujte s moderním šifrováním (ve Windows při exportu vyberte šifrování **AES256-SHA256**),
- nebo soubor převeďte nástrojem OpenSSL na své pracovní stanici. Starý soubor nejdřív rozbalte do dočasného souboru PEM pomocí `openssl pkcs12 -in stary.pfx -legacy -nodes -out docasny.pem`, z něj vytvořte nový soubor příkazem `openssl pkcs12 -export -in docasny.pem -out novy.pfx` (OpenSSL se zeptá na nové heslo a použije moderní šifrování) a dočasný soubor PEM hned smažte, protože obsahuje soukromý klíč bez ochrany heslem.

Nový soubor PFX pak nahrajte do trezoru stejným postupem.

> [!WARNING]
> Certifikát bez soukromého klíče nestačí. Pokud nahrání selže s chybou špatného hesla nebo neplatného PKCS#12,
> zkontrolujte, že P12/PFX opravdu obsahuje soukromý klíč a že zadáváte správnou passphrase.

## 99.4 Krok za krokem: založit podpisový profil

1. Admin otevře `Systém → E-maily a certifikáty` a záložku **Certifikáty a elektronické podpisy**. Účetní (má-li povoleno) otevře rovnou `Systém → Certifikáty a elektronické podpisy`.
2. V sekci **Podpisové profily** klikněte na **Nový profil**.
3. Vyplňte **Název** a **Kód**. Kód je technický identifikátor profilu a musí být unikátní v rámci dodavatele.
4. Vyberte **Vlastník profilu**:
   - **Profil dodavatele** pro centrální firemní podpis,
   - **Můj profil** pro podpis konkrétního přihlášeného uživatele,
   - **Jiný uživatel** jen pro admina, pokud profil zakládá za konkrétního uživatele.
5. V části **Použití** vyberte, k čemu se profil smí použít:
   - **PDF** pro podpis faktur a výkazů práce,
   - **S/MIME e-mail** pro podpis odchozích e-mailů,
   - obě volby, pokud stejný certifikát používáte pro PDF i e-mail.
6. Backend profilu ponechte jako předvolený. E-mailové výstupy používají S/MIME interně podle typu výstupu.
7. Ponechte **Aktivní profil** zapnutý, pokud se má dát použít při podepisování.
8. Připojte certifikát ([§ 99.5](#995-krok-za-krokem-pripojit-certifikat-k-profilu)).
9. Klikněte na **Vytvořit profil** (u existujícího **Uložit profil**).

**Jak poznáte, že je hotovo:** Profil je v sekci Podpisové profily s metadaty certifikátu.

### 99.4.1 Firemní podpisový profil v klientském portálu

Tento postup je pro klientského správce s přístupem **Firemní podpisové profily a PDF podpisy – Zápis**. Otevřete `Firma → Nastavení firmy`
a záložku **Podpisové profily**.

1. Zvolte **Vytvořit profil**, vyplňte **Název** a **Kód**, vyberte použití **PDF** a/nebo **S/MIME e-mail** a profil uložte.
2. U nového profilu připojte certifikát PFX/P12 včetně soukromého klíče a zadejte heslo certifikátu. Klikněte na
   **Nahrát certifikát**. Certifikát musí být chráněný heslem, které se ukládá šifrovaně.
3. V části **Konfigurace podpisů** vyberte pro PDF výstup profil, zapněte **Podepisovat** a nastavte **Při chybě**.
   Změny uložte a pak spusťte test PDF. PDF výstup může mít také důvod podpisu a TSA časového razítka.
4. Pro e-mail otevřete záložku **E-mailové profily** a u příslušného firemního profilu vyberte **Podpisový profil**.
   Ověřte, že certifikát obsahuje adresu odpovídající odesílateli; test S/MIME proveďte testovacím e-mailem z profilu.

Klientský portál nabízí pouze firemní profily s nativním backendem a politikou šifrovaně uloženého hesla. Použít lze
také existující firemní profil vytvořený interním administrátorem, pokud splňuje tyto podmínky. Osobní profily,
certifikáty z osobního trezoru, jiné backendy ani `sendmail` tato správa nenabízí.

Před smazáním podpisového profilu jej nejprve odpojte od e-mailových profilů, které jej používají.
Pokud podpisový profil deaktivujete nebo mu odeberete použití pro e-mail, můžete jej v e-mailovém profilu
odpojit a vybrat jiný dostupný podpis.

## 99.5 Krok za krokem: připojit certifikát k profilu

Každý profil používá jeden certifikát. Stejný certifikát se může použít pro PDF podpis i pro S/MIME podpis e-mailu,
pokud profil povoluje obě použití. U vlastního profilu jsou dostupné dva zdroje v poli **Zdroj certifikátu**:

<!-- cols: 36 64 -->
| Zdroj | Kdy použít |
|---|---|
| **Vybrat z Certifikátů** | Certifikát už máte uložený v osobním šifrovaném trezoru (sekce **Certifikáty**). PFX ani heslo se neukládají podruhé. |
| **Nahrát samostatný certifikát** | Profil dodavatele, profil jiného uživatele nebo certifikát, který nechcete používat pro EPO. |

**Certifikát z trezoru (osobní):**

1. Nejdřív nahrajte certifikát podle [§ 99.3](#993-krok-za-krokem-nahrat-certifikat-do-trezoru) a povolte ho pro aktuální firmu v `Daně → EPO podání a archív → Certifikáty EPO`.
2. Založte nebo upravte profil s vlastníkem **Můj profil**.
3. V části **Certifikát profilu** zvolte **Vybrat z Certifikátů** a vyberte certifikát (**Vyberte certifikát**).
4. Potvrďte se stejně jako u trezoru certifikátů: buď tlačítkem **Ověřit passkey** (po ověření se zobrazí **Passkey ověřeno** a pole pro heslo zmizí), nebo aktuálním heslem do MyÚčta a při zapnutém 2FA také kódem z autentikátoru. Ověření platí jen pro toto jedno uložení. Tím vědomě povolíte použití soukromého klíče v podpisovém profilu.
5. Uložte profil.

Profil ukládá jen vazbu a veřejná metadata certifikátu. PFX a jeho heslo zůstávají v původním šifrovaném trezoru, při
PDF nebo S/MIME podpisu se dešifrují pouze v paměti serveru. Certifikát nelze z trezoru smazat ani odebrat z firmy,
dokud ho používá její aktivní podpisový profil. Nejprve v profilu certifikát odeberte nebo ho nahraďte jiným.

**Samostatný certifikát profilu:**

1. Otevřete editaci profilu.
2. V části **Certifikát profilu** zvolte **Nahrát samostatný certifikát** a klikněte na **Vybrat soubor**.
3. Vyberte soubor ve formátu **P12/PFX** s privátním klíčem.
4. Zadejte **Heslo k certifikátu**. Aplikace ho použije pro kontrolu souboru a podle zvolené politiky ho buď uloží šifrovaně, nebo jen ověří.
5. Vyberte **Politiku hesla** ([§ 99.12.3](#99123-politika-hesla-k-certifikatu)).
6. Klikněte na **Uložit profil** nebo **Nahrát certifikát** podle toho, jestli profil teprve vytváříte, nebo upravujete.

Po nahrání se zobrazí metadata certifikátu: subject, e-mail v certifikátu, platnost, politika hesla a otisk SHA-256.
Soubor certifikátu se ukládá do interního úložiště aplikace. V produkci je vhodné mít datový adresář
(`MYINVOICE_DATA_DIR`) nastavený mimo webový root (MyÚčto je fork MyInvoice a tento název proměnné prostředí sdílí
beze změny). Certifikát z profilu odeberete s potvrzením dialogu.

**Jak poznáte, že je hotovo:** U profilu vidíte subject, e-mail a platnost certifikátu.

> [!WARNING]
> Pro S/MIME podpis je prakticky důležité, aby certifikát, včetně sdíleného osobního certifikátu, obsahoval
> e-mailovou adresu používanou jako odesílatel nebo aby ho příjemcův klient uměl přiřadit k odesílateli. Aplikace
> podpis vytvoří, ale důvěryhodnost a shoda identity se vyhodnocuje až v e-mailovém klientovi příjemce.

## 99.6 Krok za krokem: zapnout podepisování výstupů

Sekci **Konfigurace podpisů** vidí admin na záložce Certifikáty a elektronické podpisy. Každý řádek nastavuje jeden
typ výstupu: buď PDF, nebo S/MIME e-mail.

1. Otevřete `Systém → E-maily a certifikáty`, záložku **Certifikáty a elektronické podpisy**, sekci **Konfigurace podpisů**.
2. U výstupu (například **Vydaná faktura**, **Výkaz práce**, **E-mail s fakturou**) zapněte **Podepisovat**.
3. V poli **Výběr profilu** zvolte **Profil dodavatele**, nebo **Přihlášený uživatel**. Při profilu dodavatele vyberte konkrétní aktivní **Profil**.
4. Při volbě **Přihlášený uživatel** nastavte **Fallback uživatele** (co se stane, když uživatel nemá použitelný vlastní profil).
5. Nastavte **Při chybě** (co se stane, když podpis selže nebo není nakonfigurovaný).
6. U e-mailových výstupů zvolte **S/MIME identitu** ([§ 99.12.6](#99126-podepisovani-odchozich-e-mailu-smime)).
7. U PDF výstupů klikněte na **Otestovat**. Vytvoří dočasné PDF a zkusí ho podepsat podle stejného mapování. Výsledek zobrazí stav, použitý profil a vlastníka certifikátu (CN), pokud ho aplikace zjistí.
8. Klikněte na **Uložit konfiguraci podpisů** pod tabulkou (uloží změny všech řádků společně).

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Konfigurace podpisů byla uložena** a test PDF proběhne.

S/MIME podpis otestujete odesláním testovacího e-mailu pro příslušný typ zprávy a ověřením podpisu v e-mailovém
klientovi.

Když je výkaz práce vložený jako další stránka PDF faktury, podpis výstupu **Vydaná faktura** pokrývá celé výsledné
PDF včetně této stránky. Výstup **Výkaz práce** se používá pro samostatně generované PDF výkazu. Sloučený export se
podepisuje až jako hotový celek a používá stejnou konfiguraci profilu jako výstup **Vydaná faktura**. Podpis se
provede jen po zaškrtnutí volby v exportu; bez ní zůstane sloučené PDF nepodepsané.

## 99.7 Krok za krokem: smazat certifikát z trezoru

Certifikát, který už nepotřebujete (například po obnově), smažete takto:

1. Otevřete `Systém → E-maily a certifikáty`, záložku **Certifikáty a elektronické podpisy**.
2. V sekci **Certifikáty** se ověřte stejně jako při nahrání: **Ověřit passkey**, nebo **Heslo do MyÚčta** a případně **Kód z autentikátoru**.
3. U certifikátu klikněte na **Smazat** a smazání potvrďte v dialogu s názvem certifikátu.

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Certifikát byl smazán z trezoru** a certifikát zmizí ze seznamu.

Smazat lze jen vlastní certifikát. Soukromý klíč se z trezoru odstraní nevratně a pro všechny firmy; historické pokusy
o podání a otisk certifikátu zůstávají zachované.

Certifikát, který se ještě používá, smazat nejde a tlačítko **Smazat** je neaktivní. Pod tlačítkem uvidíte, kde ho
nejdřív odpojit:

<!-- cols: 36 64 -->
| Kde se certifikát používá | Kde ho odpojit |
|---|---|
| Podpisový profil (PDF, S/MIME) | Sekce **Podpisové profily** na téže stránce: v profilu certifikát odeberte nebo nahraďte jiným. |
| Přístup k datové schránce | `Mzdy → Datová schránka`: přístup smažte nebo vyberte jiný certifikát. |
| Odesílací brána ISDS | **Nastavení odesílací brány**: registraci změňte na jiný certifikát. |

Volba certifikátu pro mzdová podání smazání nebrání. Dialog na ni upozorní a se smazáním certifikátu se zruší; před
dalším mzdovým podáním pak vyberte jiný certifikát v `Mzdy → Podání a hlášení` (**Další ▾ → Certifikát**). Smazání
i povolení certifikátu v dalších firmách se zapisuje do auditního logu (události viz [§ 99.12.7](#99127-audit)).

## 99.8 Krok za krokem: vlastní profil účetní a výběr podpisu na dokladu

**Mapování vlastních profilů uživatele.** Sekce **Mapování podpisových profilů** slouží pro osobní výchozí profily
přihlášeného uživatele. Admin ji vidí na záložce Certifikáty a elektronické podpisy, účetní (má-li povoleno) na stránce
`Systém → Certifikáty a elektronické podpisy`. Použije se jen tehdy, když admin v **Konfiguraci podpisů** nastavil
daný výstup na **Přihlášený uživatel**. Pokud je výstup nastavený na **Profil dodavatele**, uživatelský profil se
ignoruje a aplikace na to upozorní.

1. Otevřete sekci **Mapování podpisových profilů**.
2. Pro každý výstup vyberte vlastní aktivní profil, který podporuje stejné použití jako výstup. Pro PDF výstupy musí profil podporovat použití **PDF**, pro e-mailové výstupy použití **S/MIME e-mail**.
3. Výchozí profil se uloží (**Výchozí podpisový profil uložen**).

**Výběr podpisu na konkrétním dokladu.** Na detailu faktury je pro uživatele s právem zápisu sekce **Elektronický podpis
dokumentu**, která umožňuje přepsat výchozí konfiguraci pro konkrétní doklad:

<!-- cols: 28 72 -->
| Hodnota | Chování |
|---|---|
| **Dědit** | Použije se globální konfigurace z **Konfigurace podpisů**. |
| **Přihlášený uživatel** | Pro tento doklad se použije výchozí podpisový profil uživatele, který PDF generuje nebo odesílá. |
| **Profil dodavatele** | Pro tento doklad se použije profil dodavatele. Admin může vybrat konkrétní profil, účetní nechává profil zdědit z konfigurace. |

Změna výběru u faktury invaliduje cache PDF, aby se další stažení nebo odeslání vygenerovalo s aktuálním podpisem.
U uživatelských profilů cache závisí na tom, který uživatel PDF generuje, takže stejný doklad může být podepsaný
jiným profilem podle přihlášeného uživatele. Výběr na dokladu se týká PDF dokladů. Odchozí e-maily se řídí mapováním
e-mailových výstupů v **Konfiguraci podpisů**.

**Jak poznáte, že je hotovo:** Při příštím stažení nebo odeslání je doklad podepsaný zvoleným profilem.

## 99.9 Krok za krokem: podpis ISDOC

**Proč se podepisuje i ISDOC.** POHODA a další účetní programy při importu faktury z PDF čtou vložený soubor
`invoice.isdoc` a ověřují podpis přímo v něm. Podpis PDF se na vložený ISDOC nevztahuje, takže bez vlastního podpisu
ISDOC by POHODA hlásila „Chybí podpis dokumentu", i když je PDF podepsané. Proto aplikace při zapnutém podpisu
výstupu **Vydaná faktura** podepíše nejdřív ISDOC a teprve potom celé PDF. Podpis PDF tak kryje už podepsaný ISDOC.
Když máte zapnutý podpis výstupu **Vydaná faktura**, podepíše se stejným profilem i ISDOC faktury: soubor
`invoice.isdoc` vložený do PDF, ISDOC stažený z detailu faktury i ISDOC v exportech.

Co pro to musíte udělat:

1. Otevřete `Systém → E-maily a certifikáty`, záložku **Certifikáty a elektronické podpisy**.
2. V **Konfiguraci podpisů** zapněte u výstupu **Vydaná faktura** volbu **Podepisovat** a vyberte profil s certifikátem.
3. Klikněte na **Uložit konfiguraci podpisů**.
4. V detailu faktury vygenerujte PDF znovu (starší PDF zůstávají tak, jak byla vydána).

Samostatné nastavení pro ISDOC neexistuje. Platí stejný profil, stejný výběr profilu i stejná volba **Při chybě** jako
u PDF faktury:

- při **Vrátit nepodepsané** se ISDOC vydá bez podpisu a v logu uvidíte událost `signing.isdoc_failed`,
- při **Zastavit s chybou** se PDF ani ISDOC nevydá a zobrazí se chyba „Podpis ISDOC selhal."

Podepisuje se:

<!-- cols: 46 54 -->
| Kde | Co se podepíše |
|---|---|
| PDF faktury | Vložený soubor `invoice.isdoc` (a potom celé PDF). |
| Detail faktury, stažení ISDOC, REST API `/api/v1/invoices/{id}/isdoc` | Stažený soubor `.isdoc`. |
| **Exporty → ISDOC** | Každý `.isdoc` v exportu. |
| **Hromadný export** (část Vystavené faktury, ISDOC) | Každý `.isdoc` v ZIPu. |

**Jak poznáte, že je hotovo:** otevřete stažený `.isdoc` v textovém editoru. Na konci souboru, těsně před `</Invoice>`,
je element `<Signature … Id="Signature-1">` s vaším certifikátem v `X509Certificate`. V POHODĚ se po importu faktury
z PDF hláška o chybějícím podpisu neobjeví. Jestli POHODA podpisu důvěřuje, závisí na tom, zda jde o kvalifikovaný
certifikát vydaný důvěryhodnou autoritou (například PostSignum, I.CA, eIdentity).

Podpis odpovídá standardu ISDOC 6.0.2, kapitole 5 „Digitální podpisy": XML Signature s povinnou transformací
Enveloped Signature, kanonizace Canonical XML 1.0, otisk SHA-256 a podpis RSA-SHA256. Doporučený filtr XPath
aplikace nepřidává, protože ho řada ověřovačů na Windows ve výchozím nastavení odmítá a podpis by se
příjemci (například v POHODĚ) ukázal jako neplatný. Podepsat lze certifikátem s klíčem RSA, což jsou
běžné kvalifikované certifikáty českých autorit.

## 99.10 Krok za krokem: ověřit podepsaný PDF

1. Stáhněte podepsané PDF.
2. Otevřete ho v běžné PDF čtečce, nebo na serveru například přes `pdfsig`:

```bash
pdfsig Faktura-2606009.pdf
```

**Jak poznáte, že je hotovo:** U platného podpisu uvidíte stav podpisu jako validní. Pokud výstup hlásí, že vydavatel
certifikátu je neznámý, znamená to obvykle chybějící důvěryhodný certifikační řetězec v prostředí ověřovatele.
Samotný kryptografický podpis může být přesto validní.

## 99.11 Když něco nejde

<!-- cols: 34 66 -->
| Problém | Co zkontrolovat |
|---|---|
| PDF se vygenerovalo bez podpisu | Zkontrolujte **Konfiguraci podpisů**, aktivní profil, nahraný certifikát a politiku **Při chybě**. Při volbě Vrátit nepodepsané se nepodepsané PDF vydá záměrně. |
| Export skončil chybou „PDF podpis není nakonfigurovaný" | Výstup je nastavený na tvrdé selhání a chybí použitelný profil nebo certifikát. |
| POHODA hlásí u importu z PDF „Chybí podpis dokumentu" | PDF bylo vygenerované dřív, než byl podpis zapnutý, nebo podpis ISDOC selhal. Vygenerujte PDF znovu a v logu hledejte `signing.isdoc_failed`. Viz [§ 99.9](#999-krok-za-krokem-podpis-isdoc). |
| Certifikát nejde nahrát | Ověřte P12/PFX, heslo, soukromý klíč a expiraci certifikátu. Při hlášce o RC2 viz [§ 99.3](#993-krok-za-krokem-nahrat-certifikat-do-trezoru). |
| Nelze smazat certifikát | Tlačítko **Smazat** je neaktivní, certifikát se ještě používá. Odpojte ho tam, kam odkazuje text pod tlačítkem ([§ 99.7](#997-krok-za-krokem-smazat-certifikat-z-trezoru)). |
| Background job nepodepisuje uživatelským profilem | Background job nemá přihlášeného uživatele. Pro tyto scénáře použijte fallback na profil dodavatele nebo passphrase file. |
| Po změně konfigurace se stále vrací staré PDF | Zkontrolujte historii PDF a cache. Změna konfigurace podpisů faktury cache invaliduje, ale starší archivované verze zůstávají jako auditní záznam. |
| E-mail odešel bez S/MIME podpisu | Zkontrolujte, že je zapnutý konkrétní e-mailový výstup, profil podporuje **S/MIME e-mail** a politika chyby není nastavená na tichý fallback. |
| E-mailový klient podpisu nevěří | Zkontrolujte e-mail v certifikátu, důvěryhodnost certifikační autority a to, zda po podpisu zprávu neupravuje SMTP brána nebo antispam. |
| Účetní nevidí položku Certifikáty a elektronické podpisy v menu | Zkontrolujte v `Systém → E-maily a certifikáty`, jestli je zapnutý přepínač **Povolit uživatelům správu vlastních podpisových profilů**. |
| Správa profilů není povolená | Admin musí nejdřív povolit uživatelům správu vlastních podpisových profilů. |
| Vybraný profil nepodporuje použití výstupu | Profil nemá zapnuté PDF, resp. S/MIME e-mail. Upravte použití profilu. |

## 99.12 Podrobnosti a pravidla

### 99.12.1 Typy výstupů a úrovně podpisu

PDF výstupy: vydaná faktura, samostatný výkaz práce, volitelně sloučené PDF v exportu vydaných faktur. Přehled
e-mailových výstupů a jejich interních šablon je v [§ 99.12.6](#99126-podepisovani-odchozich-e-mailu-smime).

### 99.12.2 Oprávnění rolí

Viz tabulka v [§ 99.2](#992-nez-zacnete). Podpisové endpointy jsou interní administrační endpointy používané webovou
aplikací (`/api/settings/...` a `/api/documents/.../signature-selection`). Nejsou součástí veřejného `/api/v1`
subsetu a nejsou popsané v `api/openapi.yaml`. Veřejné endpointy pro stažení nebo odeslání PDF vrací dokument podle
aktuální konfigurace podpisů, ale samotná správa podpisových profilů není veřejné API pro externí integrace.

### 99.12.3 Politika hesla k certifikátu

<!-- cols: 24 28 48 -->
| Politika | Kdy použít | Chování |
|---|---|---|
| **Uložit šifrovaně** (`encrypted_store`) | Běžný produkční režim a background joby. | Heslo se uloží v databázi šifrovaně pomocí aplikačního klíče. Běžný uživatel ho nevidí a API ho nikdy nevrací. |
| **Passphrase file** (`passphrase_file`) | Když nechcete heslo ukládat do databáze, ale aplikace musí podepisovat i bez interaktivního vstupu. | V profilu se uloží jen ID hesla. Skutečné heslo se čte ze serverového souboru nastaveného v konfiguraci. |
| **Ptát se při použití** (`prompt_on_use`) | Interaktivní podpis na vyžádání. | Pro runtime podpisy není podporováno. Pro PDF i S/MIME zvolte šifrované uložení nebo passphrase file. |

**Passphrase file.** Cestu k souboru nastaví správce v konfiguraci:

```php
'signing' => [
    'passphrase_file' => '/var/lib/myucto/signing-passphrases.json',
],
```

Kvůli zpětné kompatibilitě se bere i `pdf_signing.passphrase_file`. Soubor může být JSON:

```json
{
  "profiles": {
    "owner_john": { "passphrase": "heslo-k-p12" },
    "owner_novak": { "passphrase": "jine-heslo" }
  }
}
```

Nebo INI:

```ini
[owner_john]
passphrase=heslo-k-p12

[owner_novak]
passphrase=jine-heslo
```

Do pole **ID hesla v passphrase file** v profilu zadejte například `owner_john`. Soubor musí být čitelný procesem
aplikace a neměl by být součástí webového rootu ani gitu.

### 99.12.4 Časové razítko a důvod podpisu

V profilu je volitelná část **PDF nastavení profilu**. Tato nastavení platí jen pro PDF podpisy:

<!-- cols: 30 70 -->
| Pole | Význam |
|---|---|
| **Použít časové razítko** | Zapne PAdES-T. Po zapnutí je povinná TSA URL. |
| **TSA URL** | RFC 3161 endpoint časové autority, například URL služby poskytovatele časových razítek. |
| **TSA jméno / heslo** | HTTP Basic auth, pokud ho TSA server vyžaduje. |
| **Důvod podpisu** | Textový důvod v PDF podpisu. Když zůstane prázdný, použije se výchozí text podle typu dokumentu: Faktura, Výkaz práce nebo Hromadný export faktur. |

Bez TSA se dokument podepíše jako PAdES-B. Pokud je TSA nastavená a dostupná, přidá se důvěryhodné časové razítko
a výsledkem je PAdES-T. S/MIME podpis odchozího e-mailu v této implementaci TSA nepoužívá.

### 99.12.5 Konfigurace podpisů: sloupce a volby

<!-- cols: 26 74 -->
| Sloupec | Význam |
|---|---|
| **Výstup** | Například **Vydaná faktura**, **Výkaz práce** nebo **E-mail s fakturou**. Štítek **PDF** nebo **S/MIME** říká, jaký typ podpisu se použije. |
| **Podepisovat** | Zapne nebo vypne podpis pro daný výstup aktuálního dodavatele. |
| **Výběr profilu** | Určuje, odkud se vezme podpisový profil. |
| **Profil** | Konkrétní profil dodavatele, pokud výstup používá strategii **Profil dodavatele**. |
| **Fallback uživatele** | Co se má stát, když je zvolen **Přihlášený uživatel**, ale uživatel nemá použitelný vlastní profil. |
| **Při chybě** | Co se má stát, když podpis selže nebo není nakonfigurovaný. |

**Výběr profilu:**

<!-- cols: 30 70 -->
| Hodnota | Chování |
|---|---|
| **Profil dodavatele** | Použije se konkrétní aktivní profil dodavatele z pole **Profil**. Uživatelské profily se pro tento výstup nepoužijí. |
| **Přihlášený uživatel** | Aplikace použije výchozí profil přihlášeného uživatele pro daný výstup. Pokud ho nenajde, použije se **Fallback uživatele**. |

U automatických a background operací nemusí existovat přihlášený uživatel. Pokud je výstup nastavený na
**Přihlášený uživatel**, je proto důležité nastavit rozumný fallback.

**Fallback uživatele:**

<!-- cols: 30 70 -->
| Hodnota | Chování |
|---|---|
| **Profil dodavatele** | Pokud uživatel nemá vlastní profil, použije se profil dodavatele z řádku konfigurace. |
| **Vrátit nepodepsané** | Výstup pokračuje bez podpisu a událost se zapíše do logu. U PDF se vydá nepodepsané PDF, u e-mailu odejde nepodepsaná zpráva. |
| **Zastavit s chybou** | Export nebo odeslání selže s chybou. |

**Při chybě:**

<!-- cols: 36 64 -->
| Hodnota | Chování |
|---|---|
| **Vrátit nepodepsané** (`fallback_unsigned`) | Při chybě podpisu výstup pokračuje bez podpisu a zapíše se auditní událost. Hodí se tam, kde je důležitější dostupnost dokladu nebo e-mailu než tvrdé vynucení podpisu. |
| **Zastavit s chybou** (`fail_closed`) | Při chybě podpisu export nebo odeslání selže. Hodí se tam, kde podpis musí být povinný. |
| **Přeskočit bez konfigurace** (`skip_when_unconfigured`) | Pokud chybí použitelný profil, podpis se přeskočí. |

### 99.12.6 Podepisování odchozích e-mailů (S/MIME)

S/MIME podpis se aplikuje při sestavení e-mailu těsně před odesláním přes SMTP. Podepisuje se výsledná MIME zpráva
včetně HTML nebo textového těla a příloh, takže příjemce může v běžném e-mailovém klientovi ověřit, že zpráva nebyla
cestou změněna.

Podporované e-mailové výstupy:

<!-- cols: 56 44 -->
| Výstup v UI | Interní šablona |
|---|---|
| E-mail s fakturou | `invoice_send` |
| E-mail s upomínkou | `invoice_reminder` |
| E-mail s připomínkou zálohy | `proforma_reminder` |
| E-mail s poděkováním za úhradu | `invoice_payment_thanks` |
| E-mail se schválením výkazu | `invoice_approval` |
| E-mail s připomínkou pravidelné faktury | `recurring_draft_reminder` |

Nastavení funguje stejně jako u PDF výstupů:

- admin v **Konfiguraci podpisů** zapne podpis pro konkrétní e-mailový výstup,
- zvolí **Profil dodavatele** nebo **Přihlášený uživatel**,
- při strategii **Přihlášený uživatel** si uživatel nastaví vlastní výchozí profil v **Mapování podpisových profilů**,
- u **S/MIME identity** zvolí pravidlo pro vztah mezi e-mailem v certifikátu a hlavičkou **From**,
- při chybě se použije politika **Vrátit nepodepsané** nebo **Zastavit s chybou**.

Výchozí politika S/MIME identity je **Podepsat a varovat**. Pokud certifikát neobsahuje e-mailovou identitu nebo se
liší od skutečného odesílatele ve **From**, e-mail se podepíše a neshoda se zapíše do activity logu. Striktní kontrolu
lze zapnout volbou **Vyžadovat shodu From**.

Režimy S/MIME identity:

<!-- cols: 40 60 -->
| Režim | Chování |
|---|---|
| Vyžadovat shodu From | From se musí přesně shodovat s e-mailem v certifikátu. |
| Podepsat a varovat | E-mail se podepíše i při neshodě a do activity logu se zapíše varování. |
| Přepsat From při stejné doméně | Při neshodě se From přepíše na e-mail certifikátu jen tehdy, když původní From používá stejnou doménu. |
| Přepsat From podle allowlistu | Při neshodě se From přepíše na e-mail certifikátu jen tehdy, když původní From odpovídá allowlistu e-mailů nebo domén (jedna položka na řádek: přesný e-mail nebo doména). |

Režimy s přepsáním From podepisují až výslednou zprávu po úpravě hlavičky. Activity log obsahuje původní From, nové
From, e-mail certifikátu a použitý podpisový profil. Pokud podmínka pro přepsání neplatí, chová se režim jako
striktní chyba a použije se nastavená politika chyby.

Pokud je v **Odesílacím e-mailovém profilu** vybraný konkrétní S/MIME profil, má pro daný e-mail přednost před obecným
mapováním profilu ve výstupu. Díky tomu může jedna odesílací identita držet pohromadě From, DKIM identitu
a certifikát.

S/MIME podpis e-mail nešifruje. Obsah zprávy zůstává čitelný stejně jako u běžného e-mailu, jen je opatřen
elektronickým podpisem.

### 99.12.7 Audit

Správa i použití podpisů se zapisuje do activity logu. Typické události:

<!-- cols: 56 44 -->
| Událost | Význam |
|---|---|
| `signing.profile_created` / `signing.profile_updated` / `signing.profile_deleted` | Změna podpisového profilu. |
| `signing.credential_uploaded` / `signing.credential_deleted` / `signing.credential_passphrase_updated` | Změna certifikátu nebo politiky hesla. |
| `signing.output_settings_updated` | Změna konfigurace podpisů pro výstup. |
| `signing.user_default_updated` | Změna osobního mapování profilu. |
| `signing.document_selection_updated` | Změna výběru podpisu na konkrétním dokladu. |
| `signing.pdf_signed` | PDF bylo úspěšně podepsáno. |
| `signing.failed` | Podepisování selhalo. Podle politiky se buď vrátilo nepodepsané PDF, nebo operace skončila chybou. |
| `signing.skipped` | Podepisování bylo přeskočeno, například kvůli vypnutému výstupu nebo chybějící konfiguraci. |
| `signing.isdoc_signed` | ISDOC faktury bylo podepsáno (samostatně, v exportu nebo před vložením do PDF). |
| `signing.isdoc_failed` | Podpis ISDOC selhal. Podle politiky se vydalo nepodepsané ISDOC, nebo operace skončila chybou. |
| `signing.email_signed` | Odchozí e-mail byl úspěšně podepsán S/MIME. |
| `signing.email_identity_warning` | S/MIME podpis proběhl v režimu varování, přestože e-mail v certifikátu neodpovídá hlavičce From. |
| `signing.email_failed` | S/MIME podpis e-mailu selhal. |
| `signing.email_skipped` | S/MIME podpis e-mailu byl přeskočen, například kvůli chybějící konfiguraci. |
| `certificate_vault_delete` | Certifikát byl smazán z trezoru. |
| `report.epo_credential_deleted` | Smazáním certifikátu z trezoru zanikla jeho volba pro EPO. |
| `certificate_vault_supplier_enabled` | Certifikát byl povolen v další firmě (zapisuje se do logu dotčené firmy, při nahrání i při **Povolit v dalších firmách**). |
| `certificate_vault_replaced` | Nový certifikát nahradil starý v dotčené firmě; v záznamu jsou otisky obou certifikátů a počty přepojených míst. |

## 99.13 Související kapitoly

- [Datová schránka](97_Datova_schranka.md)
- [Odesílací brána ISDS](98_Odesilaci_brana_ISDS.md)
- [Nastavení](96_Nastaveni.md)
