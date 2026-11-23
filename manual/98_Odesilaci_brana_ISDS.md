# 98. Odesílací brána ISDS

> Návod pro provozovatele MyÚčta, jak zaregistrovat, ověřit a zapnout globální odesílací bránu ISDS. Brána předá
> připravené podání do oficiálního rozhraní ISDS jako koncept; uživatel se přihlásí a odeslání potvrdí až na stránce
> ISDS. Běžný uživatel firmy s ní nepracuje, odeslání popisuje kapitola [Datová schránka](97_Datova_schranka.md).

## 98.1 Kdy to potřebujete

Kapitolu otevřete, když:

- zavádíte odesílání mzdových podání datovou schránkou a potřebujete bránu zaregistrovat a zapnout,
- obnovujete prošlý klientský certifikát brány,
- uživatelé hlásí, že se brána v odchozích podáních nenabízí nebo že návrat z ISDS končí chybou,
- potřebujete bránu pro určité prostředí vypnout.

Odesílací brána ISDS je globální nastavení provozovatele MyÚčta. Neslouží jako datová schránka jedné firmy a běžný
uživatel firmy její certifikát ani tajné údaje nevidí.

## 98.2 Než začnete

1. **Oprávnění provozovatele.** Bránu spravuje jen **Superadmin instalace**. Otevřete ji přes `Mzdy → Odesílací brána ISDS`.
2. **Registrace externí aplikace v ISDS.** Musíte mít v portálu ISDS zaregistrovanou externí aplikaci pro službu vytváření konceptu (viz [§ 98.3](#983-krok-za-krokem-zaregistrovat-aplikaci-v-isds)).
3. **Klientský certifikát** ve formátu **PFX/P12** s heslem k jeho soukromému klíči. Musí obsahovat soukromý klíč.
4. **Veřejná HTTPS adresa** instalace, aby šla vyplnit návratová adresa.
5. **Rozhodnutí o prostředí.** Produkční a testovací prostředí mají samostatnou registraci.

## 98.3 Krok za krokem: zaregistrovat aplikaci v ISDS

1. V portálu ISDS zaregistrujte externí aplikaci pro službu vytváření konceptu.
2. Do registrace opište přesnou návratovou adresu, kterou MyÚčto zobrazuje (tlačítko **Zkopírovat**). Cesta je `/isds-gateway/callback`; v reálném provozu musí jít o úplnou veřejnou HTTPS adresu této cesty.
3. Volitelnou chybovou návratovou adresu nastavte podle údajů, které zobrazuje administrace.
4. Zapamatujte si **ID brány (atsId)**. Najdete ho v Portálu datových schránek v `Nastavení → Externí aplikace → Odesílací brána`.

**Jak poznáte, že je hotovo:** V Portálu datových schránek je aplikace zaregistrovaná s návratovou adresou a máte její ID.

Identifikátor aplikace a klientský certifikát v MyÚčtu musí odpovídat stejné registraci v ISDS.

## 98.4 Krok za krokem: uložit a aktivovat registraci v MyÚčtu

Uložení registrace ji samo neaktivuje.

1. Otevřete `Mzdy → Odesílací brána ISDS` a klikněte na **Přidat registraci** (nebo u existující na **Upravit**).
2. Vyberte **Prostředí** (testovací, nebo produkční).
3. Vyplňte **Označení**, **ID brány (atsId)**, **Platnost konceptu (s)** (mezi 60 a 7200 sekundami, stejnou hodnotu nastavte i v registraci v Portálu datových schránek), **Návratovou adresu**, volitelně **Chybovou adresu**, **Adresu portálu** a **Adresu služeb**.
4. Nahrajte **Komerční certifikát (PFX nebo P12)** a vyplňte **Heslo k certifikátu**. Při úpravě je potřeba certifikát nahrát znovu.
5. Uložte registraci. Po uložení zůstane vypnutá (**Vypnutá**).
6. Porovnejte identifikátor, návratové adresy, dobu platnosti a **Otisk certifikátu** s registrací v ISDS a proveďte provozovatelem požadovaný test.
7. Až po této kontrole klikněte na **Zapnout** a potvrďte dialog **Zapnout odesílací bránu?**

**Jak poznáte, že je hotovo:** Registrace má stav **Zapnutá** (aplikace ohlásí **Odesílací brána zapnutá**) a uživatelům se u
připravených podání začne nabízet příprava zprávy v datové schránce.

**Vypnutí:** tlačítkem **Vypnout** (dialog **Vypnout odesílací bránu?**). Příprava zprávy v datové schránce se přestane
nabízet, ruční cesta zůstává dostupná. Deaktivace odebere firmám možnost použít bránu v daném prostředí, ale neodebere
jejich připravená podání ani možnost ručního odeslání.

**Smazání registrace:** smaže se i uložený certifikát a registraci bude potřeba založit znovu včetně nahrání souboru.

> [!WARNING]
> Registraci s prošlým certifikátem nelze aktivovat. MyÚčto při aktivaci hlídá existenci registrace a platnost uloženého
> certifikátu, samo však nepotvrzuje správnost externí registrace v ISDS.

## 98.5 Co se při jednom odeslání děje

Pro srozumění uživatelským hláškám a při řešení potíží:

1. Uživatel otevře připravené podání firmy a zvolí odeslání přes ISDS.
2. Server ověří oprávnění, firmu, příjemce, přílohu a aktivní registraci prostředí.
3. Brána založí krátkodobý koncept a vrátí adresu oficiální stránky ISDS.
4. Uživatel se na této stránce přihlásí, zprávu zkontroluje a odeslání výslovně potvrdí. MyÚčto jeho přihlašovací údaje nepřijímá ani neukládá.
5. ISDS vrátí prohlížeč na `/isds-gateway/callback`. MyÚčto výsledek spojí s původní jednorázovou relací a aktualizuje podání.

Callback je součástí autentizovaného toku a nelze jej použít jako obecné potvrzení libovolného podání. Úspěšný návrat musí
odpovídat platné, nevypršené relaci správné firmy, uživatele a prostředí.

Pokud ISDS výsledek nepotvrdí jednoznačně, podání zůstane v neurčitém stavu. Nezakládejte automaticky druhou zprávu;
nejprve ověřte skutečný stav v datové schránce.

## 98.6 Když něco nejde

<!-- cols: 34 32 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Brána není nabízena | Registrace pro prostředí není zapnutá, certifikát prošel, nebo je zvoleno jiné prostředí | Zkontrolujte prostředí, aktivaci registrace a platnost certifikátu. |
| ISDS odmítne certifikát | PFX/P12 neobsahuje soukromý klíč, nebo nepatří ke stejné registraci aplikace | Ověřte, že balíček obsahuje soukromý klíč a odpovídá stejné registraci. |
| Registraci nejde zapnout | Certifikát vypršel | Nahrajte nový certifikát. |
| Návrat z ISDS skončí chybou | Špatná callback adresa, nebo vypršela krátkodobá relace | Zkontrolujte přesnou HTTPS callback adresu v ISDS a platnost krátkodobé relace (**Platnost konceptu**). |
| Uživatel nevidí očekávanou přihlašovací metodu | Nabídku metod řídí ISDS | MyÚčto ji nemůže garantovat ani vynutit. |
| Výsledek je neurčitý | ISDS výsledek nepotvrdilo | Nepokoušejte se odeslat stejný formulář znovu, dokud neověříte stav přímo v datové schránce. |

## 98.7 Podrobnosti a pravidla

### 98.7.1 Rozsah registrace

Produkční a testovací prostředí mají samostatnou registraci. Každá obsahuje zejména:

- identifikátor aplikace přidělený ISDS,
- adresu portálu a příslušné služby ISDS,
- dobu platnosti krátkodobého konceptu,
- klientský certifikát **PFX/P12** a heslo k jeho soukromému klíči,
- provozní stav registrace.

Přihlašovací politika zobrazená v nastavení je informativní. Konkrétní metody, které se uživateli při odesílání
skutečně nabídnou, určuje oficiální stránka ISDS podle účtu, prostředí a aktuálních pravidel služby.

### 98.7.2 Certifikát

Certifikát musí obsahovat soukromý klíč. MyÚčto jej při uložení parsuje a odmítne neúplný nebo nečitelný balíček.
Citlivý obsah a heslo ukládá šifrovaně; rozhraní zpět vrací jen provozní údaje, například otisk a konec platnosti.
Při změně registrace nahrajte certifikát znovu.

### 98.7.3 Co brána neřeší

- **Neumí číst schránku, ani ručně.** Rozhraní brány vkládá pouze koncept a jeho odeslání schvaluje člověk; ke stažení zpráv by bylo potřeba přihlášení, které vzniká jen přesměrováním prohlížeče uživatele. Doručenka odeslaného podání proto zůstává neověřená, dokud ji uživatel nenačte nebo nenahraje z datové schránky.
- Nezajišťuje automatické načítání doručené pošty.
- Neobchází přihlášení ani potvrzení uživatele na oficiální stránce ISDS.
- Nezaručuje, že cílová instituce obsah formuláře věcně přijala.
- Nenahrazuje evidenci doručenky a následných výzev.
- Nesdílí přístupové údaje firmy mezi různými účetními.

Ruční načtení doručených zpráv, firemní přístupy a náhradní ruční odeslání popisuje kapitola
[Datová schránka](97_Datova_schranka.md). Mzdové formuláře a jejich věcný stav popisuje kapitola
[Podání a hlášení](85_Podani_a_hlaseni.md).

## 98.8 Související kapitoly

- [Datová schránka](97_Datova_schranka.md)
- [Podání a hlášení](85_Podani_a_hlaseni.md)
- [Nastavení](96_Nastaveni.md)
