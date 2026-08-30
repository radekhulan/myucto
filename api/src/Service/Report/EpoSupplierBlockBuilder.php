<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DOMElement;

/**
 * Sdílený helper pro sestavení `<VetaP>` (identifikace daňového subjektu)
 * a normalizaci CZ-NACE / OKEČ kódu napříč EPO výkazy (DPHDP3, DPHKH1, DPHSHV).
 *
 * VetaP struktura je v DPH/KH/SHV identická per EPO XSD — sdílíme jeden
 * generátor, aby všechny výkazy odpovídaly konzistentně tomu, co posílá
 * skutečné EPO podání: opr_*, sest_*, c_orient, c_pop, c_telef atd.
 */
final class EpoSupplierBlockBuilder
{
    /**
     * Sloupce, které {@see fillVetaP} čte. SSOT seznamu — do fáze F1 existoval ve třech
     * téměř shodných kopiích (DPH, KH, SH) a lišily se drobnostmi, takže se nedalo říct,
     * který je ten správný.
     *
     * @var list<string>
     */
    public const REQUIRED_SUPPLIER_KEYS = [
        'company_name', 'street', 'city', 'zip', 'country_iso2',
        'dic', 'taxpayer_type', 'financial_office_code', 'workplace_code',
        'email', 'phone',
        'street_number_pop', 'street_number_orient',
        'opr_jmeno', 'opr_prijmeni', 'opr_postaveni',
        'sest_jmeno', 'sest_prijmeni', 'sest_telefon',
    ];

    /**
     * SELECT fragment pro načtení dodavatele do `fillVetaP()`. Vrací VŠECHNY sloupce,
     * které helper čte, plus ty, které si volající tradičně bere navíc (`ic`,
     * `is_vat_payer`, `data_box_id`, `vat_period`, …) — sjednocení je levnější než
     * tři subtilně odlišné seznamy.
     *
     * Používej s `FROM supplier s LEFT JOIN countries c ON c.id = s.country_id`.
     */
    public static function supplierSelect(): string
    {
        return "s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer, s.is_identified,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.data_box_id,
                    s.email, s.phone,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_prijmeni, s.sest_telefon, s.sest_email, s.sest_funkce";
    }

    /**
     * Načte dodavatele pro EPO podání. Jediná cesta, kterou mají DPH/KH/SH používat —
     * tři vlastní kopie SELECTu byly příčinou, ne důsledkem: helper níž totiž degradoval
     * TIŠE, když volající sloupec zapomněl.
     *
     * @param ?string $statusDate Rozhodné datum plátcovství (YYYY-MM-DD) — typicky
     *        POSLEDNÍ DEN období výkazu. Živé supplier.is_vat_payer/is_identified
     *        jsou jen cache stavu „dnes"; s datem se OBA flagy přepíší stavem
     *        k tomuto datu z historie (migrace 1181 historizuje i identifikovanou
     *        osobu — {@see \MyInvoice\Service\Vat\VatStatusService::flagsAt()}).
     *        Ostatní pole zůstávají živá.
     * @return array<string,mixed>
     */
    public static function loadSupplier(\PDO $pdo, int $supplierId, ?string $statusDate = null): array
    {
        $stmt = $pdo->prepare(
            'SELECT ' . self::supplierSelect() . '
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        if ($statusDate !== null) {
            $flags = \MyInvoice\Service\Vat\VatStatusService::flagsAt($pdo, $supplierId, $statusDate);
            $row['is_vat_payer'] = $flags['is_vat_payer'] ? 1 : 0;
            $row['is_identified'] = $flags['is_identified'] ? 1 : 0;
        }

        return $row;
    }

    /**
     * Vyplní VetaP atributy z `supplier` row.
     *
     * @param array<string,mixed> $supplier Načteno z `supplier` tabulky včetně
     *                                       cz_nace_code, opr_*, sest_*, street_number_*.
     * @param bool $includeContact Emitovat `email`/`c_telef`? DPHDP3 a DPHKH1 je znají,
     *                             DPHSHV (souhrnné hlášení) NE — tam by je EPO odmítlo
     *                             (VetaP XSD ty atributy nemá). SH volá s `false`.
     */
    public static function fillVetaP(DOMElement $vetaP, array $supplier, bool $includeContact = true): void
    {
        self::assertSupplierContract($supplier);

        // c_ufo (kód FÚ) je required. Fallback "451" (Praha 1) pokud chybí.
        $vetaP->setAttribute('c_ufo', (string) ($supplier['financial_office_code'] ?: '451'));
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        $vetaP->setAttribute('dic', self::normalizeDic($supplier['dic'] ?? null));
        // typ_ds = TYP DAŇOVÉHO SUBJEKTU (F = fyzická, P = právnická osoba), NIKOLI typ
        // datové schránky. Dřív se sem plnil sloupec `data_box_type` (OVM/PO/FO) — u s.r.o.
        // s prázdnou datovkou vypadl fallback "F" a EPO podání spadlo na kontrole
        // „U fyzické osoby musí být kmenová část DIČ tvořena RČ nebo vlastním číslem plátce".
        // Jediný autoritativní zdroj je `taxpayer_type` (fo/po).
        $isPravnickaOsoba = ($supplier['taxpayer_type'] ?? null) === 'po';
        $vetaP->setAttribute('typ_ds', $isPravnickaOsoba ? 'P' : 'F');

        if ($isPravnickaOsoba) {
            $vetaP->setAttribute('zkrobchjm', (string) $supplier['company_name']);
        } else {
            // Fyzická osoba (OSVČ) — jmeno/prijmeni = sám daňový subjekt.
            //   1) Preferuj strukturovaná pole jméno/příjmení, která už plníme jednateli
            //      u s.r.o. (opr_jmeno/opr_prijmeni) — u OSVČ = tatáž osoba, dá přesnou kontrolu.
            //   2) Fallback: rozdělení company_name s ODSTRANĚNÍM akademických titulů —
            //      jinak „MUDr. Josef Novák" → jmeno=„MUDr.", prijmeni=„Josef Novák" (#200).
            $jmeno = trim((string) ($supplier['opr_jmeno'] ?? ''));
            $prijmeni = trim((string) ($supplier['opr_prijmeni'] ?? ''));
            if ($jmeno === '' || $prijmeni === '') {
                [$jmeno, $prijmeni] = self::splitPersonName((string) ($supplier['company_name'] ?? ''));
            }
            $vetaP->setAttribute('jmeno', $jmeno);
            $vetaP->setAttribute('prijmeni', $prijmeni !== '' ? $prijmeni : $jmeno);
        }

        [$uliceText, $cpop, $corient] = self::parseStreet($supplier);
        $vetaP->setAttribute('ulice', $uliceText);
        if ($cpop !== '')    $vetaP->setAttribute('c_pop', $cpop);
        if ($corient !== '') $vetaP->setAttribute('c_orient', $corient);
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '');
        // `stat` = NÁZEV státu z číselníku Země (položka naz_zeme_c25), NE ISO2 kód (#201).
        // ISO2 kód patří do `k_stat` (u řádků protistran). Atribut je optional — pokud
        // zemi neumíme namapovat na číselníkový název, raději ho vynecháme než poslat
        // věcně neplatnou hodnotu.
        $statName = self::countryName((string) ($supplier['country_iso2'] ?? 'CZ'));
        if ($statName !== null) {
            $vetaP->setAttribute('stat', $statName);
        }

        if ($includeContact) {
            if (!empty($supplier['email'])) $vetaP->setAttribute('email', (string) $supplier['email']);
            if (!empty($supplier['phone'])) $vetaP->setAttribute('c_telef', self::normalizePhone((string) $supplier['phone']));
        }

        // Oprávněná osoba (POVINNÉ u PO — jednatel apod.)
        if (!empty($supplier['opr_jmeno']))     $vetaP->setAttribute('opr_jmeno', (string) $supplier['opr_jmeno']);
        if (!empty($supplier['opr_prijmeni']))  $vetaP->setAttribute('opr_prijmeni', (string) $supplier['opr_prijmeni']);
        if (!empty($supplier['opr_postaveni'])) $vetaP->setAttribute('opr_postaveni', (string) $supplier['opr_postaveni']);

        // Sestavitel přiznání (typicky účetní). Příjmení má vlastní sloupec
        // `sest_prijmeni` (sjednoceno s jednatelem opr_*). Když není vyplněno,
        // fallback: split `sest_jmeno` podle první mezery (BC pro stará data).
        if (!empty($supplier['sest_jmeno'])) {
            if (!empty($supplier['sest_prijmeni'])) {
                $vetaP->setAttribute('sest_jmeno', (string) $supplier['sest_jmeno']);
                $vetaP->setAttribute('sest_prijmeni', (string) $supplier['sest_prijmeni']);
            } else {
                $sestParts = explode(' ', trim((string) $supplier['sest_jmeno']), 2);
                $vetaP->setAttribute('sest_jmeno', $sestParts[0] ?? '');
                if (!empty($sestParts[1])) {
                    $vetaP->setAttribute('sest_prijmeni', $sestParts[1]);
                }
            }
        }
        if (!empty($supplier['sest_telefon'])) $vetaP->setAttribute('sest_telef', self::normalizePhone((string) $supplier['sest_telefon']));
        // Pozn.: sest_email a sest_funkce NEJSOU v EPO XSD (DPH/KH/SHV) — držíme je
        // jen v DB pro vnitřní použití (kontakt na účetní v UI).
    }

    /**
     * Kontrakt volajícího: řádek musí OBSAHOVAT všechny klíče, které helper čte.
     *
     * Rozlišuje se chybějící KLÍČ (chyba volajícího — zapomenutý sloupec v SELECTu)
     * od prázdné HODNOTY (legitimní stav — dodavatel prostě nemá vyplněný telefon).
     *
     * Proč to vůbec je: helper dřív u chybějícího sloupce degradoval **bez chyby** —
     * atribut se prostě neemitoval a podání odešlo neúplné. Registr SSOT to označil za
     * druhé nejrizikovější místo fáze F1 s poučením „kontrakt musí být vynutitelný,
     * ne slovní". Tohle je ta vynutitelnost: chyba volajícího spadne hlasitě a hned,
     * ne až na kontrole EPO u uživatele.
     *
     * @param array<string,mixed> $supplier
     */
    private static function assertSupplierContract(array $supplier): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_SUPPLIER_KEYS,
            static fn (string $key): bool => !array_key_exists($key, $supplier),
        ));

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'fillVetaP(): v řádku dodavatele chybí sloupce [%s]. '
                    . 'Načítej ho přes EpoSupplierBlockBuilder::loadSupplier(), '
                    . 'ne vlastním SELECTem — chybějící sloupec by se jinak projevil '
                    . 'až neúplným podáním.',
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Rozdělí adresu na ulici + číslo popisné + číslo orientační (konvence EPO).
     *
     *   1) Má-li uživatel vyplněná samostatná `street_number_pop` / `street_number_orient`,
     *      použijí se a z `street` se odřízne trailing číslo, aby se nezdvojovalo.
     *   2) Jinak fallback parsing z `street`:
     *      „Zkušební 123/4"           → ['Zkušební', '123', '4']
     *      „Hlavní 12"                → ['Hlavní', '12', '']
     *      „Hlavní 12a"               → ['Hlavní', '12a', '']   (alfa suffix ok)
     *      „17. listopadu 220"        → ['17. listopadu', '220', '']  (číslo v NÁZVU ulice)
     *      „Na Poříčí"                → ['Na Poříčí', '', '']   (bez čísla)
     *
     * **Proč veřejná metoda:** do fáze F1 byla tahle logika INLINE uvnitř `fillVetaP()`,
     * tedy nevolatelná — a existovala proto ve čtyřech kopiích (DPFO, DPPO, ČSSZ a tady).
     * To není nedbalost volajících, je to strukturální příčina: SSOT, který nejde zavolat,
     * se okopíruje rychleji, než kdyby žádný nebyl, protože vytváří dojem, že pravidlo
     * je vyřešené. Příští oprava adresy by zase dopadla jen na část podání.
     *
     * Ověřeno měřením, že sjednocení nic nemění: všechny čtyři kopie se na korpusu
     * 19 reálných českých adresních tvarů shodovaly (viz EpoStreetParsingTest).
     *
     * @param array<string,mixed> $supplier řádek `supplier` (street, street_number_pop/orient)
     * @return array{0:string, 1:string, 2:string} [ulice, c_pop, c_orient]
     */
    public static function parseStreet(array $supplier): array
    {
        $rawStreet = trim((string) ($supplier['street'] ?? ''));
        $cpop = trim((string) ($supplier['street_number_pop'] ?? ''));
        $corient = trim((string) ($supplier['street_number_orient'] ?? ''));

        if ($cpop !== '' || $corient !== '') {
            $ulice = trim(preg_replace('/\s+\d+[a-zA-Z]?(?:\s*\/\s*\d+[a-zA-Z]?)?\s*$/u', '', $rawStreet) ?? $rawStreet);

            return [$ulice, $cpop, $corient];
        }

        if ($rawStreet !== '' && preg_match('/^(.+?)\s+(\d+[a-zA-Z]?)(?:\s*\/\s*(\d+[a-zA-Z]?))?\s*$/u', $rawStreet, $m)) {
            return [trim($m[1]), $m[2], $m[3] ?? ''];
        }

        return [$rawStreet, $cpop, $corient];
    }

    /**
     * Číslo domu v jednom řetězci — tvar, který chce ČSSZ (`c_pop/c_orient`), na rozdíl
     * od EPO, kde jsou to dva samostatné atributy.
     *
     * Prázdné číslo popisné NEEMITUJE vedoucí lomítko: `['', '9']` dá `'9'`, ne `'/9'`.
     * Původní kopie v `CsszPrehledXmlBuilder` lomítko psala, takže dodavatel s vyplněným
     * jen číslem orientačním (stav, který ARES i ruční zadání umí vyrobit) dostal do
     * podání tvar `„/9"`.
     */
    public static function houseNumber(string $cpop, string $corient): string
    {
        if ($cpop === '') {
            return $corient;
        }

        return $corient === '' ? $cpop : $cpop . '/' . $corient;
    }

    /**
     * Rozdělí celé jméno fyzické osoby na [jmeno, prijmeni] pro EPO VetaP a odstraní
     * akademické tituly (vedoucí i koncové), aby nespadly do `jmeno`/`prijmeni` (#200).
     *
     *   „MUDr. Josef Novák"            → ['Josef', 'Novák']
     *   „prof. Ing. Jan Svoboda, CSc." → ['Jan', 'Svoboda']
     *   „Josef Novák"                  → ['Josef', 'Novák']
     *   „Josef Karel Novák"            → ['Josef', 'Karel Novák']  (víceslovné příjmení)
     *   „Novák"                        → ['Novák', 'Novák']        (BC — prijmeni je required)
     *
     * Titul = token s tečkou (MUDr., Ing., prof., Ph.D. …) nebo ze seznamu bez tečky
     * (CSc., DrSc., MBA, DiS, …). Koncové tituly bývají za čárkou — tu urveme celou.
     *
     * @return array{0:string, 1:string} [jmeno, prijmeni]
     */
    public static function splitPersonName(string $full): array
    {
        // Vše za první čárkou (typicky koncové tituly „, Ph.D.", „, CSc.", „, MBA") pryč.
        $full = preg_replace('/,.*$/us', '', trim($full)) ?? $full;
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($full)) ?: [], static fn ($t) => $t !== ''));

        $suffixTitles = ['csc', 'drsc', 'mba', 'dis', 'bsc', 'msc', 'ma', 'ba', 'llm', 'phd', 'dr'];
        $isTitle = static function (string $t) use ($suffixTitles): bool {
            if (str_contains($t, '.')) return true;                    // MUDr., Ing., prof., Ph.D.
            return in_array(mb_strtolower(rtrim($t, '.')), $suffixTitles, true);
        };
        // Urvi vedoucí i koncové tituly (nech aspoň 1 token = vlastní jméno).
        while (count($tokens) > 1 && $isTitle($tokens[0])) array_shift($tokens);
        while (count($tokens) > 1 && $isTitle($tokens[count($tokens) - 1])) array_pop($tokens);

        if ($tokens === []) return ['', ''];
        if (count($tokens) === 1) return [$tokens[0], $tokens[0]]; // jen jedno slovo → prijmeni=jmeno

        $jmeno = array_shift($tokens);
        return [$jmeno, implode(' ', $tokens)];
    }

    /**
     * Mapuje ISO2 kód země na NÁZEV státu podle číselníku EPO „Země" (položka
     * `naz_zeme_c25`, max. 25 znaků) pro atribut `VetaP/stat` (#201).
     *
     * Názvy jsou převzaty verbatim z autoritativní tabulky v EPO XSD (dokumentace
     * atributu `k_stat` — „Daňová identifikační čísla členských států EU"). Klíčem
     * je ISO2 (v DB `country_iso2`), takže Řecko je pod „GR" (číselník DPH kódů
     * používá „EL", ale to je jen `k_stat`, ne ISO).
     *
     * Vrací `null` pro zemi mimo mapu — atribut `stat` je v EPO optional, takže je
     * lepší ho vynechat než poslat neplatnou (číselníkově neexistující) hodnotu.
     */
    /**
     * Kmenová část tuzemského DIČ pro EPO — XSD pattern je `[0-9]{1,10}`.
     *
     * Nestačí utrhnout prefix `CZ`: DIČ zapsané s mezerami nebo pomlčkami
     * (`CZ 123 456 789`, běžný tvar z importu a z ruky) prošlo do XML nečíselné,
     * v rozporu s vlastním komentářem u kódu. EPO takové podání odmítne až na
     * kontrole schématu, tedy ve chvíli, kdy uživatel podává.
     *
     * SSOT pro všechny buildery; `KontrolniHlaseniBuilder::cleanDic()` sem deleguje.
     */
    public static function normalizeDic(?string $dic): string
    {
        if ($dic === null || $dic === '') {
            return '';
        }
        $clean = preg_replace('/^CZ/i', '', strtoupper(trim($dic))) ?? '';

        return preg_replace('/[^0-9]/', '', $clean) ?? '';
    }

    public static function countryName(string $iso2): ?string
    {
        static $map = [
            'AT' => 'RAKOUSKO',
            'BE' => 'BELGIE',
            'BG' => 'BULHARSKO',
            'CY' => 'KYPR',
            'CZ' => 'ČESKÁ REPUBLIKA',
            'DE' => 'NĚMECKO',
            'DK' => 'DÁNSKO',
            'EE' => 'ESTONSKO',
            'ES' => 'ŠPANĚLSKO',
            'FI' => 'FINSKO',
            'FR' => 'FRANCIE',
            'GB' => 'VELKÁ BRITÁNIE',
            'GR' => 'ŘECKO',
            'HR' => 'CHORVATSKO',
            'HU' => 'MAĎARSKO',
            'IE' => 'IRSKO',
            'IT' => 'ITÁLIE',
            'LT' => 'LITVA',
            'LU' => 'LUCEMBURSKO',
            'LV' => 'LOTYŠSKO',
            'MT' => 'MALTA',
            'NL' => 'NIZOZEMSKO',
            'PL' => 'POLSKO',
            'PT' => 'PORTUGALSKO',
            'RO' => 'RUMUNSKO',
            'SE' => 'ŠVÉDSKO',
            'SI' => 'SLOVINSKO',
            'SK' => 'SLOVENSKO',
        ];

        $key = strtoupper(trim($iso2));
        if ($key === '') {
            $key = 'CZ';
        }

        return $map[$key] ?? null;
    }

    /**
     * Normalizace telefonu pro EPO `c_telef` / `sest_telef`:
     *   - odstraní `+420` / `00420` prefix
     *   - odstraní mezery, pomlčky, závorky
     * Reálné EPO podání uvádí jen 9-místné číslo (např. "601002003"); naše DB
     * může mít formát "+420 601 002 003".
     */
    public static function normalizePhone(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/^(\+|00)420\s*/', '', $s) ?? $s;
        $s = preg_replace('/[\s\-()]+/', '', $s) ?? $s;
        return $s;
    }

    /**
     * Normalizace CZ-NACE / OKEČ hodnoty pro `c_okec`. Hodnoty z UI/ARES mohou
     * být "62.02", "62020", "620200", ale i pouhý oddíl "74".
     *
     * Deleguje na {@see EpoOkecCodebook::normalize()} — kanonizaci proti snapshotu
     * číselníku ČINNOSTI z Daňového portálu MFČR. Dřívější varianta tady jen
     * strippovala nečíslice a záměrně NEpadovala, s odůvodněním, že číselník je
     * proměnné šířky a většina položek je 5místná. Snapshot to vyvrací: z 1952
     * položek je 1775 šestimístných a jen 177 pětimístných, a ty pětimístné jsou
     * kódy sekcí 01–09 uložené bez vodicí nuly (sloupec je číselný, „14800" =
     * 01.48.00). Ani slepé padování ani žádné padování proto nesedí — jediné
     * správné je dohledání v číselníku, které obojí rozliší.
     *
     * Kód mimo číselník se NEBLOKUJE (snapshot může zestárnout). Pozor na
     * platnost: k 1. 1. 2026 se číselník překlopil na NACE rev. 2.1, takže
     * i správně široký kód může být odmítnut proto, že expiroval.
     *
     * A pozor na váhu té chyby: dřív tu stálo, že EPO hlásí jen propustnou
     * chybu 30. Neplatí to. Zkušební EPO 30. 8. 2026 i protokol ke skutečně
     * archivovanému přiznání vrátily KRITICKOU chybu 126 „Číslo hlavní
     * (převažující) činnosti není v číselníku" — s tou se podání nedá odevzdat
     * vůbec. Proto {@see describeOkec()}, kterou stavitelé používají k varování;
     * expirovaný kód se pozná dřív, než ho odmítne úřad.
     *
     * KRATŠÍ NEŽ 4 číslice → null = atribut se VYNECHÁ (c_okec je optional;
     * oddíl z ARES v číselníku není a vyvolal by chybu 30).
     */
    public static function normalizeOkec(string $raw): ?string
    {
        $resolved = EpoOkecCodebook::normalize($raw);
        return $resolved === null ? null : $resolved['code'];
    }

    /**
     * Varování k číslu činnosti, nebo null, když je kód v pořádku.
     *
     * Vrací hotovou větu pro `warnings` stavitele: účetní se o expirovaném kódu
     * jinak dozví až z odmítnutého podání, a to je pozdě.
     */
    public static function okecWarning(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $resolved = EpoOkecCodebook::describe($trimmed);
        if ($resolved === null) {
            return 'Číslo hlavní (převažující) činnosti „' . $trimmed . '" není v číselníku CZ-NACE. '
                . 'EPO podání s neplatným kódem odmítne kritickou chybou 126 — doplňte platný kód v nastavení firmy.';
        }
        if (($resolved['status'] ?? '') !== EpoOkecCodebook::STATUS_EXPIRED) {
            return null;
        }

        return 'Číslo hlavní (převažující) činnosti ' . $resolved['display'] . ' skončilo platnost '
            . ($resolved['valid_to'] ?? '?') . ' (číselník se k 1. 1. 2026 překlopil na CZ-NACE rev. 2.1). '
            . 'EPO takové podání odmítne kritickou chybou 126 — vyberte v nastavení firmy nástupnický kód.';
    }
}
