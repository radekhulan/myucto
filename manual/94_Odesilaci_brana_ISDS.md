# Odesílací brána ISDS

Odesílací brána ISDS je globální nastavení provozovatele MyÚčta. Otevřete ji přes **Systém → Odesílací brána ISDS**. Neslouží jako datová schránka jedné firmy a běžný uživatel firmy její certifikát ani tajné údaje nevidí.

Brána umožní předat připravené podání do oficiálního rozhraní ISDS jako koncept. Uživatel se přihlásí a odeslání potvrdí až na stránce ISDS.

## 94.1 Rozsah registrace

Produkční a testovací prostředí mají samostatnou registraci. Každá obsahuje zejména:

- identifikátor aplikace přidělený ISDS,
- adresu portálu a příslušné služby ISDS,
- dobu platnosti krátkodobého konceptu,
- klientský certifikát **PFX/P12** a heslo k jeho soukromému klíči,
- provozní stav registrace.

Přihlašovací politika zobrazená v nastavení je informativní. Konkrétní metody, které se uživateli při odesílání skutečně nabídnou, určuje oficiální stránka ISDS podle účtu, prostředí a aktuálních pravidel služby.

## 94.2 Registrace v ISDS

Nejprve zaregistrujte externí aplikaci pro službu vytváření konceptu v portálu ISDS. Do registrace opište přesnou návratovou adresu, kterou MyÚčto zobrazuje:

```text
/isds-gateway/callback
```

V reálném provozu musí jít o úplnou veřejnou HTTPS adresu této cesty. Volitelnou chybovou návratovou adresu nastavte podle údajů, které zobrazuje administrace. Identifikátor aplikace a klientský certifikát v MyÚčtu musí odpovídat stejné registraci v ISDS.

Certifikát musí obsahovat soukromý klíč. MyÚčto jej při uložení parsuje a odmítne neúplný nebo nečitelný balíček. Citlivý obsah a heslo ukládá šifrovaně; API a uživatelské rozhraní zpět vracejí jen provozní údaje, například otisk a konec platnosti. Při změně registrace nahrajte certifikát znovu.

## 94.3 Uložení, ověření a aktivace

Uložení registrace ji samo neaktivuje. Doporučený postup je:

1. Vyberte testovací nebo produkční prostředí.
2. Vyplňte identifikátor, adresy služeb, dobu platnosti a certifikát.
3. Uložte registraci. Po uložení zůstane neaktivní.
4. Porovnejte identifikátor, návratové adresy, dobu platnosti a otisk certifikátu s registrací v ISDS a proveďte provozovatelem požadovaný test.
5. Registraci aktivujte samostatně až po této kontrole.

MyÚčto při aktivaci hlídá existenci registrace a platnost uloženého certifikátu, samo však nepotvrzuje správnost externí registrace v ISDS. Registraci s prošlým certifikátem nelze aktivovat. Deaktivace odebere firmám možnost použít bránu v daném prostředí, ale neodebere jejich připravená podání ani možnost ručního odeslání.

## 94.4 Průběh jednoho odeslání

1. Uživatel otevře připravené podání firmy a zvolí odeslání přes ISDS.
2. Server ověří oprávnění, firmu, příjemce, přílohu a aktivní registraci prostředí.
3. Brána založí krátkodobý koncept a vrátí adresu oficiální stránky ISDS.
4. Uživatel se na této stránce přihlásí, zprávu zkontroluje a odeslání výslovně potvrdí. MyÚčto jeho přihlašovací údaje nepřijímá ani neukládá.
5. ISDS vrátí prohlížeč na `/isds-gateway/callback`. MyÚčto výsledek spojí s původní jednorázovou relací a aktualizuje podání.

Callback je součástí autentizovaného toku a nelze jej použít jako obecné potvrzení libovolného podání. Úspěšný návrat musí odpovídat platné, nevypršené relaci správné firmy, uživatele a prostředí.

Pokud ISDS výsledek nepotvrdí jednoznačně, podání zůstane v neurčitém stavu. Nezakládejte automaticky druhou zprávu; nejprve ověřte skutečný stav v datové schránce.

## 94.5 Co brána neřeší

- Nezajišťuje automatické načítání doručené pošty.
- Neobchází přihlášení ani potvrzení uživatele na oficiální stránce ISDS.
- Nezaručuje, že cílová instituce obsah formuláře věcně přijala.
- Nenahrazuje evidenci doručenky a následných výzev.
- Nesdílí přístupové údaje firmy mezi různými účetními.

Ruční načtení doručených zpráv, firemní přístupy a náhradní ruční odeslání popisuje kapitola [Datová schránka](93_Datova_schranka.md). Mzdové formuláře a jejich věcný stav popisuje kapitola [Podání a hlášení](68_Podani_a_hlaseni.md).

## 94.6 Řešení potíží

- **Brána není nabízena:** zkontrolujte prostředí, aktivaci registrace a platnost certifikátu.
- **ISDS odmítne certifikát:** ověřte, že PFX/P12 obsahuje soukromý klíč a patří ke stejné registraci aplikace.
- **Návrat skončí chybou:** zkontrolujte přesnou HTTPS callback adresu v ISDS a platnost krátkodobé relace.
- **Uživatel nevidí očekávanou přihlašovací metodu:** nabídku metod řídí ISDS; MyÚčto ji nemůže garantovat ani vynutit.
- **Výsledek je neurčitý:** nepokoušejte se odeslat stejný formulář znovu, dokud neověříte stav přímo v datové schránce.
