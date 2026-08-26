# 18. Klienti

Klient = firma nebo osoba, které vystavuješ faktury. Hlavní e-mail je
volitelný; bez hlavního e-mailu nebo účelového kontaktu však nelze klientovi
odeslat fakturu ani upomínku. Pod klientem můžeš mít jednu nebo více
**zakázek** (viz [19. Zakázky](19_Zakazky.md)) — typicky 1 zakázka = 1 projekt
nebo dlouhodobá spolupráce.

## 18.1 Seznam klientů

V hlavním menu klikni **Klienti**.

![Seznam klientů](img/06_klienti_list.webp)

Tabulka ukazuje:

| Sloupec | Význam |
|---|---|
| Jméno | Název firmy nebo osoby (klikatelný — otevře detail) |
| IČO | České IČO, pokud je vyplněné |
| Stát | 2-písmenný kód (CZ, SK, DE, …) |
| Měna | Výchozí měna pro nové faktury |
| Hlavní e-mail | Kontakt pro odesílání faktur |
| Plátce DPH | Badge **Ano / Ne** — zda je subjekt plátce DPH. Užitečné hlavně u **dodavatelů**: u neplátce nemá přijatá faktura nárok na odpočet (viz [§ 23.2.4](23_Prijate_faktury.md#2324-danova-uznatelnost-a-narok-na-odpocet)). Příznak se plní z ARES (CZ) / VIES (EU). |
| Obrat letos | Suma vystavených faktur v aktuálním roce, v měně klienta |

Nad tabulkou je vyhledávač (jméno / IČO) a filtr stavu (`Aktivní` / `Archivovaní`).

### 18.1.1 Akce na řádku

- **Klik na jméno** → detail klienta
- **Tlačítko Smazat** se zobrazí jen pokud klient nemá žádné faktury ani zakázky.
  Pokud má, smazání vrátí 409 a UI tlačítko skryje.

## 18.2 Nový klient

Tlačítko **+ Nový klient** vpravo nahoře otevře modal.

![Nový klient — ARES lookup](img/06_klient_novy.webp)

Doporučený postup pro českého klienta:

1. Zadej **IČO** (8 cifer).
2. Klikni **Načíst z ARES** — server stáhne data z oficiálního registru a
   předvyplní: název firmy, DIČ, adresu, stát.
3. Volitelně doplň **Hlavní e-mail**. Pro odesílání dokladů musí mít klient
   alespoň hlavní e-mail nebo účelový kontakt.
4. (Volitelně) změň **Měnu** (CZK / EUR / …) a **Jazyk faktury** (CZ / EN).
5. Pokud je klient z EU s DIČ, klikni **Ověřit DIČ (VIES)** — pokud je platné,
   přidáme stříbrný badge „VIES OK".
6. (Volitelně) zaškrtni **Reverse charge** — faktura v této měně bude bez DPH
   s textem „Daň přiznává odběratel".
7. **Uložit**.

### 18.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Firma / jméno | Název na faktuře |
| Křestní jméno + Příjmení | Jen pro fyzické osoby (volitelné) |
| IČO | České IČO (8 cifer); slovenské také funguje s ARES SK |
| DIČ | Daňové ID s prefixem země; ČR „CZ12345678", SK „SK1234567890", EU různě. U slovenského klienta se pole jmenuje **IČ DPH** (viz § 18.2.1a) |
| Národní daňové číslo | Zobrazí se jen u zemí, kde existuje vedle VAT ID: SK **DIČ**, DE/AT **Steuernummer**, PL **NIP**, HU **Adószám**. Tiskne se na fakturu mezi IČO a DIČ/IČ DPH |
| Ulice / Město / PSČ / Stát | Adresa pro fakturu |
| Hlavní e-mail | Volitelný — výchozí adresa pro odesílání faktur a upomínek |
| Telefon | Volitelný |
| Jazyk | `cs` nebo `en` — určuje jazyk PDF, e-mailových šablon, currency formátu |
| Výchozí měna | Pro nové faktury (lze přepsat per faktura) |
| Výchozí DPH | Volitelný override (jinak se použije systémový default) |
| Reverse charge | Zatrhni pro EU B2B klienty s DIČ — DPH 0 % + text „Daň přiznává odběratel" |
| Splatnost | Preset **7 dnů / 14 dnů / Měsíc / Vlastní**, nebo **Použít výchozí** = dědit z dodavatele. „Měsíc" = kalendářní měsíc (1. 2. → 1. 3., 31. 1. → 28. 2.), ne fixních 30 dní |
| Poznámka | Interní text — nezobrazí se na faktuře |

### 18.2.2 18.2.1a Slovenský klient a národní daňová čísla

Slovenské subjekty mají **tři** identifikační čísla — IČO, **DIČ** (bez prefixu,
přiděluje ho finanční úřad každému podnikateli včetně neplátců) a **IČ DPH**
(`SK` + číslo, vzniká až registrací k DPH). Slovenská praxe vyžaduje na
faktuře všechna tři. Po výběru státu **Slovensko**:

- pole DIČ se přejmenuje na **IČ DPH** (patří sem hodnota s prefixem, např.
  `SK2022638992`) a tlačítko **VIES** po úspěšném ověření předvyplní DIČ
  automaticky (= totéž číslo bez prefixu),
- přibude samostatné pole **DIČ** (bez prefixu — vyplň i u neplátce).

Na faktuře se pak tiskne `IČO → DIČ → IČ DPH`; u neplátce (bez IČ DPH) jen
`IČO → DIČ`. Stejné pole funguje i pro Německo/Rakousko (**Steuernummer**),
Polsko (**NIP**) a Maďarsko (**Adószám**) — s odpovídajícím labelem. Pro
fakturu do jiné země EU je přitom legislativně povinné jen VAT ID
(čl. 226 směrnice 2006/112/ES); národní čísla jsou lokální konvence.

Tlačítko **Detaily plátce DPH** u zahraničního DIČ (s jiným prefixem než CZ)
ověřuje přes evropský **VIES** — zobrazí stav registrace k DPH, název
a adresu subjektu. Český registr plátců DPH (zveřejněné účty, nespolehlivý
plátce) se používá dál jen pro česká DIČ.

### 18.2.3 E-mailové kontakty podle účelu

U firemních odběratelů je běžné, že různé typy zpráv mají chodit na různé
adresy — faktury na účtárnu, upomínky na odpovědnou osobu, schvalování
výkazů na projektového manažera. Sekce **E-mailové kontakty podle účelu**
ve formuláři klienta to umožňuje nastavit.

U každého kontaktu vyplníš:

| Pole | Význam |
|---|---|
| E-mail | Adresa kontaktu |
| Jméno osoby | Volitelné |
| Popisek | Volitelný (např. „účtárna", „PM") |
| Účely | **Doklady** (faktury, dobropisy, poděkování za platbu) · **Upomínky** · **Schvalování** (výkazy víceprací) · **Komunikace** (jen evidence, nic se na ni automaticky neposílá) |
| Role | **Příjemce (to)** / **Kopie (cc)** / **Skrytá kopie (bcc)** |
| Aktivní | Neaktivní kontakt se při odesílání ignoruje |

**Jak se vybírají příjemci:**

- **Bez kontaktů** vše chodí na **hlavní e-mail** klienta
  (+ fakturační e-maily zakázky, viz [§ 19](19_Zakazky.md)). Pokud není vyplněný ani hlavní
  e-mail, odeslání skončí čitelnou validační chybou.
- **Jakmile má účel přiřazený aktivní kontakt**, použijí se kontakty s tímto
  účelem a hlavní e-mail se už automaticky **nepřidává** (zůstává jen
  záchranný fallback). Chceš-li hlavní e-mail zachovat mezi příjemci,
  přidej ho jako kontakt — tlačítko **Převzít hlavní e-mail**.
- **Upomínky bez vlastního kontaktu** spadnou na kontakty s účelem
  **Doklady**; teprve bez nich na hlavní e-mail.
- Duplicitní adresy se odstraní (priorita to > cc > bcc), neplatné se ignorují.

V modalu odeslání faktury vidíš u každého příjemce **odkud byl doplněn**
(kontakt: doklady / zakázka / hlavní e-mail) a celý seznam můžeš pro
konkrétní odeslání ručně upravit jako dosud.

Limit je 10 kontaktů na klienta. Kontakty jsou dostupné i přes API
(`email_contacts` v detailu klienta, replace-all při create/update).

## 18.3 Detail klienta

Klik na jméno v seznamu → detail.

![Detail klienta](img/06_klient_detail.webp)

### 18.3.1 Bankovní účty protistrany

Na detailu zákazníka nebo dodavatele je sekce **Bankovní účty protistrany**.
Účet můžeš přidat ručně jako české číslo účtu nebo IBAN. U českého dodavatele
s DIČ lze tlačítkem **Načíst z registru DPH** převzít všechny zveřejněné účty.

MyÚčto účet doplní také z bankovního výpisu, ale až po potvrzeném spárování
transakce s vydanou nebo přijatou fakturou. Samotná podobnost názvu či částky
nestačí. U každého účtu štítky ukazují, zda pochází z registru DPH, bankovního
výpisu nebo byl zadaný ručně; jeden účet může mít více zdrojů současně.

Detail má 4 záložky:

### 18.3.2 Přehled

Sumář: kontakt, výchozí nastavení, obraty (letos / loni), počet zakázek,
počet faktur podle stavu.

### 18.3.3 Zakázky

Seznam zakázek pod klientem. Tlačítko **+ Nová zakázka** otevře editor —
viz [19. Zakázky](19_Zakazky.md).

### 18.3.4 Faktury

Seznam faktur klienta (všechny zakázky + faktury bez zakázky). Filtr stavu
+ pagination.

### 18.3.5 Aktivita

Activity log — kdo a kdy klienta vytvořil / upravil / odeslal mu fakturu.

## 18.4 Editace klienta

Na detailu klikni **Upravit** (ikona tužky vpravo nahoře).

Změny se okamžitě projeví na nových fakturách. Faktury, které už jsou
**vystavené** (status `issued` a vyšší), mají vlastní **snapshot** údajů
klienta — tam se editace neprojeví. Tím se zajišťuje neměnnost vystavených
dokladů.

## 18.5 Archivace klienta

Klik na **Archivovat** — klient se schová z výchozího filtru, ale data zůstanou
zachována (faktury, statistiky). Archivovaného klienta najdeš ve filtru
„Archivovaní" v seznamu, kde ho můžeš obnovit (**Obnovit**).

## 18.6 Tipy

- **ARES** funguje jen pro česká IČO. Pro SK použij interní lookup `/api/clients/ares-lookup-sk?ic=...`.
- **VIES** je pomalý (~1–2 sekundy) a občas nedostupný — výsledek se cachuje
  na 24 hodin v `vies_cache` tabulce.
- Pokud klient nemá IČO (fyzická osoba), zadej alespoň jméno + adresu ručně.
- Reverse charge se nastavuje **per klient**, ale lze přepsat per faktura
  v editoru.
