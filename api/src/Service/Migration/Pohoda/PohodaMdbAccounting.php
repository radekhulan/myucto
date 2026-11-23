<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Support\CompanyIdNormalizer;

final class PohodaMdbAccounting
{
    public const FILE = '89_ucetnictvi_mdb.xml';

    private const INVOICES = [
        1 => 'issued',
        4 => 'issued_advance',
        5 => 'receivable',
        6 => 'issued_proforma',
        8 => 'issued_corrective',
        11 => 'received',
        14 => 'received_advance',
        15 => 'commitment',
        16 => 'received_proforma',
        18 => 'received_corrective',
    ];
    /**
     * Druh objednávky `OBJ.RelTpObj`. Ověřeno na číselné řadě reálné agendy: objednávka typu 2
     * má řadu agendy 10 (vydané objednávky); typ 1 je druhá z dvojice, přijaté objednávky
     * (agenda 21). Jiný typ převod nezná a objednávku přeskočí.
     */
    private const ORDERS = [
        1 => 'received',
        2 => 'issued',
    ];
    private const SOURCES = [
        2 => PohodaJournal::ISSUED,
        3 => PohodaJournal::RECEIVED,
        5 => PohodaJournal::ASSETS,
        18 => PohodaJournal::RECEIVABLE,
        19 => PohodaJournal::COMMITMENT,
        27 => PohodaJournal::CASH,
        28 => PohodaJournal::BANK,
        29 => PohodaJournal::INTERNAL,
        63 => PohodaJournal::OPENING,
        174 => PohodaJournal::ACCRUALS,
    ];
    private const LINKS = [
        2 => 'issuedInvoice',
        3 => 'receivedInvoice',
        18 => 'receivable',
        19 => 'commitment',
        27 => 'voucher',
        28 => 'bank',
        29 => 'internalDocuments',
        43 => 'issuedAdvanceInvoice',
        44 => 'receivedAdvanceInvoice',
    ];
    private const TABLES = [
        'journal' => 'pUD',
        'chart' => 'pOS',
        'posting_rules' => 'pPK',
        'vat_classes' => 'sDPH',
        'addressbook' => 'AD',
        'bank_accounts' => 'sUcet',
        'cash_registers' => 'sUcet',
        'bank' => 'BV',
        'cash' => 'HO',
        'internal' => 'pINT',
        'contracts' => 'sZAK',
        'activities' => 'sCIN',
    ];

    /** Tabulky, jejichž řádky se kontrolují při otevření, v pořadí hlášení chyb. */
    private const VALIDATED = ['pUD', 'FA', 'BV', 'HO', 'pINT'];
    /** Číselníky a doklady, na které se odkazuje přes `ID` ({@see lookup()}). */
    private const LOOKUPS = ['sDPHTp', 'sFormUh', 'sCMeny', 'sZeme', 'pPK', 'sDPH', 'sUcet', 'FA', 'BV'];
    /** Položky a úhrady dokladů podle sloupce odkazu na doklad ({@see group()}). */
    private const GROUPS = [['FApol', 'RefAg'], ['pINTpol', 'RefAg'], ['HOpol', 'RefAg'], ['BVpol', 'RefAg'], ['BVpol', 'RelIDUhrady'], ['Uhrady', 'RelIDH']];

    private array $indexes = [];
    private array $groups = [];
    private array $warnings = [];

    /**
     * Otevře XML převodu: úvodní průchod souborem ověří strukturu a zároveň sebere řádky
     * ke kontrole firmy a roku a dokladů ({@see checked()}), bez dalšího průchodu.
     *
     * @return array{0:PohodaMdbTables,1:array{config:list<array<string,string>>,errors:array<string,PohodaException>}}
     */
    public static function scan(string $path): array
    {
        $checked = ['config' => [], 'errors' => []];
        $tables = new PohodaMdbTables($path, ['sKonfig', ...self::VALIDATED], static function (string $table, array $r) use (&$checked): void {
            self::check($checked, $table, $r);
        });
        return [$tables, $checked];
    }

    /**
     * @param array{config:list<array<string,string>>,errors:array<string,PohodaException>}|null $checked
     *        řádky sebrané při otevření ({@see scan()}); bez nich se přečtou z tabulek
     */
    public function __construct(public readonly PohodaMdbTables $tables, ?array $checked = null)
    {
        foreach (['sKonfig', 'pUD', 'pOS', 'sDPH', 'sDPHTp', 'pPK', 'FA', 'FApol', 'AD', 'sUcet', 'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT', 'pINTpol', 'Uhrady'] as $table) {
            if (!$tables->has($table)) {
                throw new PohodaException('mdb_incomplete', 'MDB export neobsahuje všechny povinné účetní tabulky. Vytvořte nový ZIP převodníkem.');
            }
        }
        if ($checked === null) {
            $checked = ['config' => [], 'errors' => []];
            foreach ($tables->each(['sKonfig', ...self::VALIDATED]) as $table => $r) {
                self::check($checked, $table, $r);
            }
        }
        // Chyba se u každé tabulky zapamatuje a hlásí se v pevném pořadí: nejdřív firma
        // a rok, pak deník, faktury, banka, pokladna, interní doklady.
        $config = $checked['config'];
        if (count($config) !== 1 || CompanyIdNormalizer::ic($config[0]['ICO'] ?? '') !== CompanyIdNormalizer::ic($tables->ico) || (int) ($config[0]['Rok'] ?? 0) !== $tables->year) {
            throw new PohodaException('mdb_source_mismatch', 'Firma nebo rok neodpovídá údajům zdrojové MDB. Vytvořte nový export.');
        }
        foreach (self::VALIDATED as $table) {
            if (isset($checked['errors'][$table]) && !self::skippable($checked['errors'][$table])) {
                throw $checked['errors'][$table];
            }
        }
    }

    /**
     * @param array{config:list<array<string,string>>,errors:array<string,PohodaException>} $checked MĚNÍ SE
     * @param array<string,string> $r
     */
    private static function check(array &$checked, string $table, array $r): void
    {
        if ($table === 'sKonfig') {
            $checked['config'][] = $r;
            return;
        }
        if (isset($checked['errors'][$table])) {
            return;
        }
        try {
            self::validate($table, $r);
        } catch (PohodaException $e) {
            $checked['errors'][$table] = $e;
        }
    }

    /** @param array<string,string> $r */
    private static function validate(string $table, array $r): void
    {
        if ($table === 'pUD') {
            return;
        }
        self::assertRatesKnown($r);
        if (self::historicalRates($r)) {
            self::rates($r);
        }
        if ($table === 'FA' && (($r['Cislo'] ?? '') !== '' || ($r['Datum'] ?? '') !== '') && !isset(self::INVOICES[(int) ($r['RelTpFak'] ?? 0)])) {
            throw new PohodaException('mdb_invoice_type', 'Nepodporovaný typ faktury ' . ($r['RelTpFak'] ?? '0') . '.');
        }
        if ($table === 'BV' || $table === 'HO') {
            self::direction($r['RelTp' . $table] ?? '');
        }
    }

    public function has(string $key): bool
    {
        return isset(self::TABLES[$key]) || in_array($key, array_values(self::INVOICES), true);
    }

    public function warnings(): array
    {
        return array_values($this->warnings);
    }

    /** Export nese objednávky (`OBJ` a `OBJpol`); starší převodník je nevytahoval. */
    public function hasOrders(): bool
    {
        return $this->tables->has('OBJ') && $this->tables->has('OBJpol');
    }

    /**
     * Objednávky z tabulky `OBJ` s položkami z `OBJpol`, v pořadí ze souboru. Typ podle
     * `RelTpObj` ({@see self::ORDERS}, neznámý = `null`). Vyřízení nese příznak `Vyrizeno`
     * (ručně nebo po přenesení všech položek) a u položky dodané množství `Dodano`, storno
     * datum `DatStorn`. Položka odkazuje na kartu zásoby `RefSKz` (`SKz.ID` ze skladu).
     *
     * @return \Generator<int,array{id:string,type:?string,source_type:string,number:string,date:string,delivery:string,
     *     fulfilled:bool,cancelled:bool,text:string,partner_reference:string,note:string,
     *     partner:array<string,mixed>,
     *     currency:string,rate:float,units:float,items:list<array<string,mixed>>}>
     */
    public function orders(): \Generator
    {
        if (!$this->hasOrders()) {
            return;
        }
        $items = $this->group('OBJpol', 'RefAg');
        foreach ($this->tables->each(['OBJ']) as $r) {
            $currency = $this->lookup('sCMeny', $r['RefCM'] ?? '');
            $lines = [];
            foreach ($items[$r['ID'] ?? ''] ?? [] as $it) {
                $lines[] = [
                    'id' => (string) ($it['ID'] ?? ''),
                    'card' => ($it['RefSKz'] ?? '') === '0' ? '' : (string) ($it['RefSKz'] ?? ''),
                    'code' => trim($it['Kod'] ?? ''),
                    'text' => trim($it['SText'] ?? ''),
                    'quantity' => (float) ($it['Mnozstvi'] ?? 0),
                    'delivered' => (float) ($it['Dodano'] ?? 0),
                    'unit' => trim($it['MJ'] ?? ''),
                    'unit_ratio' => is_numeric($it['MJKoef'] ?? null) && (float) $it['MJKoef'] > 0 ? (float) $it['MJKoef'] : 1.0,
                    'unit_price' => (float) ($it['KcJedn'] ?? 0),
                    'price' => (float) ($it['Kc'] ?? 0),
                    'vat' => (float) ($it['KcDPH'] ?? 0),
                    'vat_slot' => (int) ($it['RelSzDPH'] ?? 0),
                    'vat_percent' => is_numeric($it['ProcentoDPH'] ?? null) ? (float) $it['ProcentoDPH'] : null,
                    'prices_include_vat' => self::flag($it['SDph'] ?? ''),
                    'foreign_unit_price' => is_numeric($it['CmJedn'] ?? null) ? (float) $it['CmJedn'] : null,
                    'foreign_price' => is_numeric($it['Cm'] ?? null) ? (float) $it['Cm'] : null,
                ];
            }
            yield [
                'id' => (string) ($r['ID'] ?? ''),
                'type' => self::ORDERS[(int) ($r['RelTpObj'] ?? 0)] ?? null,
                'source_type' => (string) ($r['RelTpObj'] ?? ''),
                'number' => trim($r['Cislo'] ?? ''),
                'date' => $this->date($r, 'Datum'),
                'delivery' => $this->date($r, 'DatDod'),
                'fulfilled' => self::flag($r['Vyrizeno'] ?? ''),
                'cancelled' => $this->date($r, 'DatStorn') !== '',
                'text' => trim($r['SText'] ?? ''),
                'partner_reference' => trim($r['PDoklad'] ?? ''),
                'note' => trim($r['Pozn'] ?? ''),
                // Partner ve tvaru `typ:partnerIdentity` jako u dokladů ({@see PartnerImporter::snapshot()}).
                'partner' => ['id' => ($r['RefAD'] ?? '') === '0' ? '' : (string) ($r['RefAD'] ?? '')] + $this->identity($r),
                'currency' => strtoupper(trim($currency['Kod'] ?? '')),
                'rate' => (float) ($r['CmKurs'] ?? 0),
                'units' => (float) ($r['CmMnoz'] ?? 0),
                'items' => $lines,
            ];
        }
    }

    private static function flag(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '1', '-1'], true);
    }

    private static function skippable(PohodaException $error): bool
    {
        return in_array($error->errorCode, ['mdb_invoice_type', 'mdb_direction', 'mdb_payment_source'], true);
    }

    public function records(string $key): \Generator
    {
        foreach ($this->recordsOf([$key]) as $record) {
            yield $record;
        }
    }

    /**
     * Záznamy několika agend jedním průchodem souborem, v pořadí ze souboru; klíč
     * generátoru je agenda (opakuje se). Všechny druhy faktur leží v jedné tabulce `FA`,
     * takže přehled vydaných a přijatých dokladů ji tudy čte jednou, ne po druzích.
     *
     * @param list<string> $keys
     * @return \Generator<string,array<string,mixed>>
     */
    public function recordsOf(array $keys): \Generator
    {
        $invoiceKinds = array_values(self::INVOICES);
        $byTable = [];
        foreach ($keys as $key) {
            $table = in_array($key, $invoiceKinds, true) ? 'FA' : (self::TABLES[$key] ?? null);
            if ($table !== null && !in_array($key, $byTable[$table] ?? [], true)) {
                $byTable[$table][] = $key;
            }
        }
        if ($byTable === []) {
            return;
        }
        foreach ($this->tables->each(array_keys($byTable)) as $table => $r) {
            try {
                if (in_array($table, self::VALIDATED, true)) {
                    self::validate($table, $r);
                }
                if ($table === 'FA') {
                    if ($this->isPlaceholder($r)) {
                        continue;
                    }
                    $kind = self::INVOICES[(int) ($r['RelTpFak'] ?? 0)] ?? null;
                    if ($kind === null) {
                        throw new PohodaException('mdb_invoice_type', 'Nepodporovaný typ faktury ' . ($r['RelTpFak'] ?? '0') . '.');
                    }
                    if (in_array($kind, $byTable['FA'], true)) {
                        yield $kind => $this->document($r, 'invoice', 'FApol');
                    }
                    continue;
                }
                foreach ($byTable[$table] as $key) {
                    $record = $this->record($key, $r);
                    if ($record !== null) {
                        yield $key => $record;
                    }
                }
            } catch (PohodaException $error) {
                if (!self::skippable($error)) throw $error;
                $number = (string) ($r['Cislo'] ?? $r['ID'] ?? '');
                $this->warnings[$table . '|' . ($r['ID'] ?? $number) . '|' . $error->errorCode] = [
                    'level' => 'warning', 'code' => $error->errorCode,
                    'message' => "Doklad {$number} se nepřevede: " . $error->getMessage()
                        . ' Ostatní doklady se převedou; případný zápis tohoto dokladu v účetním deníku zůstává zachovaný.',
                    'context' => ['table' => $table, 'document_no' => $number,
                        'source_id' => (string) ($r['ID'] ?? ''),
                        'source_type' => (string) ($r['RelTpFak'] ?? $r['RelTpBV'] ?? $r['RelTpHO'] ?? '')],
                ];
            }
        }
    }

    /**
     * Záznam agendy (mimo faktury) z řádku její tabulky; null = řádek do agendy nepatří.
     *
     * @param array<string,string> $r
     * @return array<string,mixed>|null
     */
    private function record(string $key, array $r): ?array
    {
        if ($key === 'journal') {
            // Neznámá agenda nezastaví převod: řádek deníku je vyvážený zápis jako každý jiný,
            // převede se jako ruční zápis a krok deníku to ohlásí upozorněním.
            $source = self::SOURCES[(int) ($r['RelUdAg'] ?? 0)] ?? PohodaJournal::UNKNOWN_AGENDA_PREFIX . (int) ($r['RelUdAg'] ?? 0);
            return [
                'id' => $r['ID'],
                'source' => $source,
                'number' => ['numberRequested' => $r['Cislo'] ?? ''],
                'date' => $this->date($r, 'Datum'),
                'dateTax' => $this->date($r, 'DatZdPln'),
                'text' => $r['SText'] ?? '',
                'homeCurrency' => ['priceSum' => $r['Kc'] ?? '0'],
                'accounting' => ['credit' => $r['UMD'] ?? '', 'debit' => $r['UD'] ?? ''],
                'centre' => $this->centre($r['RefStr'] ?? ''),
                'contract' => self::contract($r),
                'activity' => $this->activity($r['RefCin'] ?? ''),
                // Partner řádku v tvaru `act:address` z XML. Vazbu na adresář XML nenese, starší
                // převodník tyto sloupce neexportoval.
                'address' => ['company' => $r['Firma'] ?? '', 'name' => $r['Jmeno'] ?? '', 'ico' => $r['ParICO'] ?? ''],
                'addressbookId' => ($r['RefAD'] ?? '') === '0' ? '' : ($r['RefAD'] ?? ''),
                // Id zdrojového dokladu řádku (u banky id bankovního dokladu). XML ho nenese,
                // rozlišuje se jím pohyb mezi pohyby se stejným číslem ({@see PohodaBankJournal}).
                'sourceId' => ($r['RelAgID'] ?? '') === '0' ? '' : ($r['RelAgID'] ?? ''),
                'pairing' => ['symbol' => $r['ParSym'] ?? '', 'advance' => $r['ParSym2'] ?? ''],
            ];
        } elseif ($key === 'contracts') {
            if (trim($r['Cislo'] ?? '') === '') {
                return null;
            }
            // Zakázka ve tvaru `con:contractDesc` z XML (agenda Zakázky). Partner je vazba na
            // adresář (`RefAD`), údaje z hlavičky zakázky jsou jen opis.
            return ['contractDesc' => [
                'id' => $r['ID'] ?? '',
                'number' => ['numberRequested' => trim($r['Cislo'])],
                'text' => $r['SText'] ?? '',
                'partnerIdentity' => ['id' => ($r['RefAD'] ?? '') === '0' ? '' : ($r['RefAD'] ?? ''), 'address' => ['company' => $r['Firma'] ?? '', 'ico' => $r['ICO'] ?? '']],
                'datePlanStart' => $this->date($r, 'PlZahaj'),
                'datePlanDelivery' => $this->date($r, 'PlPredani'),
                'dateStart' => $this->date($r, 'Zahajeni'),
                'dateDelivery' => $this->date($r, 'Predani'),
            ]];
        } elseif ($key === 'activities') {
            if (trim($r['IDS'] ?? '') === '') {
                return null;
            }
            // Činnost ve tvaru `acv:activityHeader` z XML (agenda Činnosti).
            return ['activityHeader' => ['id' => $r['ID'] ?? '', 'code' => trim($r['IDS']), 'name' => $r['SText'] ?? '']];
        } elseif ($key === 'chart') {
            return ['@code' => $r['Ucet'] ?? '', '@name' => $r['Nazev'] ?? ''];
        } elseif ($key === 'posting_rules') {
            if (($r['RelPkAg'] ?? '') === '0') {
                return null;
            }
            return ['@code' => $r['IDS'] ?? '', '@accounting' => $r['SText'] ?? '', '@debit' => $r['UMD'] ?? '', '@credit' => $r['UD'] ?? ''];
        } elseif ($key === 'vat_classes') {
            $type = $this->lookup('sDPHTp', $r['RefTpDph'] ?? '');
            $section = [
                1 => 'Nezahrnovat',
                2 => 'A.1.',
                3 => 'A.2.',
                4 => 'A.3.',
                5 => 'A.4.',
                6 => 'A.4., A.5.',
                7 => 'A.5.',
                8 => 'B.1.',
                9 => 'B.2.',
                10 => 'B.2., B.3.',
                11 => 'B.3.',
            ][(int) ($r['RelVlivKHDPH'] ?? 0)] ?? null;
            if ($section === null || $type === []) {
                throw new PohodaException('mdb_vat_type', 'Členění DPH v MDB exportu nelze bezpečně určit. Použijte standardní XML export.');
            }
            return ['classificationVATHeader' => ['id' => $r['ID'], 'code' => $r['IDS'] ?? '', 'name' => $r['SText'] ?? '', 'lineInVATReturn' => $type['Radky'] ?? '', 'sectionInVATLedgerStatement' => $section]];
        } elseif ($key === 'addressbook') {
            return ['addressbookHeader' => ['id' => $r['ID'], 'identity' => $this->identity($r), 'email' => $r['Email'] ?? '', 'mobil' => $r['GSM'] ?? '', 'phone' => $r['Tel'] ?? '']];
        } elseif ($key === 'bank_accounts' && (int) ($r['RelJeUcet'] ?? 0) === 2) {
            // Měna účtu (`RefCM` do číselníku měn); korunový účet ji nemá. XML export ji nenese,
            // převod ji tam určí z dokladů účtu ({@see CashBankImporter::importBank()}).
            return ['bankAccountHeader' => ['id' => $r['ID'], 'ids' => $r['IDS'] ?? '', 'numberAccount' => $r['SText'] ?? '', 'codeBank' => $r['KodBanky'] ?? '', 'nameBank' => $r['Banka'] ?? '', 'IBAN' => $r['IBAN'] ?? '', 'analyticAccount' => ['ids' => $r['AUcet'] ?? ''],
                'currency' => ['ids' => $this->lookup('sCMeny', $r['RefCM'] ?? '')['Kod'] ?? '']]];
        } elseif ($key === 'cash_registers' && (int) ($r['RelJeUcet'] ?? 0) === 1) {
            return ['cashRegisterHeader' => ['id' => $r['ID'], 'ids' => $r['IDS'] ?? '', 'name' => $r['SText'] ?? '', 'account' => ['ids' => $r['AUcet'] ?? '']]];
        } elseif ($key === 'internal') {
            return $this->document($r, 'intDoc', 'pINTpol');
        } elseif ($key === 'cash') {
            $d = $this->document($r, 'voucher', 'HOpol');
            $d['voucherHeader']['voucherType'] = $this->direction($r['RelTpHO'] ?? '');
            $d['voucherHeader']['cashAccount'] = $this->reference('sUcet', $r['RefUcet'] ?? '');
            $d['voucherHeader']['datePayment'] = $this->date($r, 'DatPlat');
            return $d;
        } elseif ($key === 'bank') {
            $d = $this->document($r, 'bank', 'BVpol');
            $d['bankHeader']['bankType'] = $this->direction($r['RelTpBV'] ?? '');
            $d['bankHeader']['number'] = $r['Cislo'] ?? '';
            $d['bankHeader']['symPar'] = $r['ParSym'] ?? '';
            $d['bankHeader']['account'] = $this->reference('sUcet', $r['RefUcet'] ?? '');
            $d['bankHeader']['dateStatement'] = $this->date($r, 'Datum');
            $d['bankHeader']['datePayment'] = $this->date($r, 'DatPlat');
            $parts = explode('/', $r['Vypis'] ?? '');
            $d['bankHeader']['statementNumber'] = ['statementNumber' => $parts[0], 'numberMovement' => $parts[1] ?? ''];
            return $d;
        }
        return null;
    }

    private function document(array $r, string $prefix, string $itemsTable): array
    {
        $payment = $this->lookup('sFormUh', $r['RelForUh'] ?? '');
        $h = [
            'id' => $r['ID'],
            'number' => ['numberRequested' => $r['Cislo'] ?? ''],
            'date' => $this->date($r, 'Datum'),
            'dateTax' => $this->date($r, 'DatZdPln'),
            'dateAccounting' => $this->date($r, 'DatUcP'),
            'dateDue' => $this->date($r, 'DatSplat'),
            'dateKHDPH' => $this->date($r, 'DatKHDPH'),
            'text' => $r['SText'] ?? '',
            'symVar' => $r['VarSym'] ?? '',
            'symConst' => $r['KonstSym'] ?? '',
            'symSpec' => $r['SpecSym'] ?? '',
            'originalDocument' => $r['PDoklad'] ?? '',
            'numberKHDPH' => $r['CisloKHDPH'] ?? '',
            'accounting' => $this->accounting($r['RelPk'] ?? ''),
            'classificationVAT' => $this->reference('sDPH', $r['RelTpDPH'] ?? ''),
            'partnerIdentity' => $this->identity($r),
            'paymentAccount' => ['accountNo' => $r['Ucet'] ?? '', 'bankCode' => $r['KodBanky'] ?? ''],
            'paymentType' => ['paymentType' => [1 => 'cash', 2 => 'creditcard', 3 => 'draft', 4 => 'advance', 5 => 'other', 6 => 'compensation', 7 => 'other', 8 => 'compensation', 9 => 'delivery'][(int) ($payment['RelTyp'] ?? 0)] ?? ''],
            'liquidation' => ['amountHome' => $r['KcLikv'] ?? '0', 'date' => $this->date($r, 'DatLikv')],
            'note' => $r['Pozn'] ?? '',
            'contract' => self::contract($r),
            'activity' => $this->activity($r['RefCin'] ?? ''),
        ];
        // Režim OSS dokladu (FA.MOSS = stát spotřeby, FA.MOSSDukaz = doklady prokazující
        // stát) ve stejných elementech jako XML POHODY. Prázdné se vynechává stejně jako
        // v XML - převod podle toho pozná doklad, který POHODA v OSS nevede.
        if ($prefix === 'invoice' && trim($r['MOSS'] ?? '') !== '') {
            $h['MOSS'] = ['ids' => trim($r['MOSS'])];
            if (trim($r['MOSSDukaz'] ?? '') !== '') {
                $h['evidentiaryResourcesMOSS'] = ['ids' => trim($r['MOSSDukaz'])];
            }
        }
        $d = [$prefix . 'Header' => $h, $prefix . 'Summary' => ['homeCurrency' => $this->summary($r)]];
        if ($prefix !== 'invoice') {
            unset($d[$prefix . 'Header']['liquidation']);
        }
        $currency = $this->lookup('sCMeny', $r['RefCM'] ?? '');
        if ($currency !== []) {
            $d[$prefix . 'Summary']['foreignCurrency'] = [
                'currency' => ['ids' => $currency['Kod'] ?? ''],
                'rate' => $r['CmKurs'] ?? '',
                'amount' => $r['CmMnoz'] ?? '',
                'priceSum' => $r['CmCelkem'] ?? '0',
            ];
        }
        foreach ($this->group($itemsTable, 'RefAg')[$r['ID']] ?? [] as $it) {
            $advance = $prefix === 'invoice' && in_array((int) ($it['RelAgID'] ?? 0), [-1, 43, 44], true);
            if ($prefix === 'invoice' && (int) ($it['RelAgID'] ?? 0) === -1001) {
                continue;
            }
            $item = $this->item($it, $r);
            if ($prefix === 'bank' && ($it['ParSym'] ?? '') !== '') {
                $item['symPar'] = $it['ParSym'];
            }
            if ($advance) {
                $linked = $this->lookup('FA', $it['RefPol'] ?? '');
                $item['note'] = $linked['Cislo'] ?? ($it['SText'] ?? '');
                $item['sourceDocument'] = ['id' => $linked['ID'] ?? '', 'number' => $linked['Cislo'] ?? ''];
            }
            $tag = $advance ? 'invoiceAdvancePaymentItem' : $prefix . 'Item';
            $d[$prefix . 'Detail'][$tag][] = $item;
            if ($prefix === 'intDoc' && in_array((int) ($it['RelAgID'] ?? 0), [2, 3, 18, 19, 43, 44], true)) {
                $linked = $this->lookup('FA', $it['RefPol'] ?? '');
                if ($linked !== []) {
                    $d['linkedDocuments']['link'][] = [
                        'sourceAgenda' => self::LINKS[(int) $it['RelAgID']],
                        'sourceDocument' => ['id' => $linked['ID'], 'number' => $linked['Cislo'] ?? ''],
                    ];
                }
            }
        }
        if ($prefix === 'invoice') {
            $source = match ((int) $r['RelTpFak']) { 1, 8 => 2, 4, 6 => 43, 5 => 18, 11, 18 => 3, 14, 16 => 44, 15 => 19 };
            foreach ($this->group('Uhrady', 'RelIDH')[$r['ID']] ?? [] as $u) {
                if ((int) ($u['RelAgH'] ?? 0) !== $source) {
                    continue;
                }
                $agenda = (int) ($u['RelAgU'] ?? 0);
                if ($agenda !== 0 && !isset(self::LINKS[$agenda])) {
                    throw new PohodaException('mdb_payment_source', 'MDB obsahuje nepodporovaný zdroj úhrady ' . $agenda . '.');
                }
                $liquidation = [
                    'id' => $u['ID'],
                    'date' => $this->date($u, 'DatumU'),
                    'sourceAgenda' => self::LINKS[$agenda] ?? '',
                    'sourceDocument' => $this->paymentSource($u, $agenda),
                    'amount' => $u['KcU'] ?? '0',
                ];
                if (in_array($source, [43, 44], true) && (int) ($u['RefDD'] ?? 0) > 0) {
                    $liquidation['taxDocumentRef'] = $u['RefDD'];
                }
                $d['liquidations']['liquidation'][] = $liquidation;
            }
        }
        if (isset($d['linkedDocuments']['link'])) {
            $unique = [];
            foreach ($d['linkedDocuments']['link'] as $link) {
                $unique[$link['sourceAgenda'] . ':' . $link['sourceDocument']['id']] = $link;
            }
            $d['linkedDocuments']['link'] = array_values($unique);
        }
        return $d;
    }

    /**
     * Doklad, kterým byla úhrada zaplacena. Bankovní úhradu váže POHODA oběma směry
     * (`Uhrady.RelIDU` na bankovní doklad a `BVpol.RelIDUhrady` z položky pohybu na úhradu);
     * číslo dokladu v úhradě (`CisloU`) je jen opis a číselná řada banky se po letech opakuje.
     * Rozhoduje proto vazba na id, číslo se bere z nalezeného bankovního dokladu. Export bez
     * sloupce `RelIDUhrady` (starší převodník) se čte jako dřív.
     *
     * @return array{id:string,number:string}
     */
    private function paymentSource(array $u, int $agenda): array
    {
        $id = (string) ($u['RelIDU'] ?? '');
        $number = (string) ($u['CisloU'] ?? '');
        if ($agenda !== 28) {
            return ['id' => $id, 'number' => $number];
        }
        $bank = $this->lookup('BV', $id);
        if ($bank === []) {
            foreach ($this->group('BVpol', 'RelIDUhrady')[(string) ($u['ID'] ?? '')] ?? [] as $item) {
                $bank = $this->lookup('BV', (string) ($item['RefAg'] ?? ''));
                if ($bank !== []) {
                    break;
                }
            }
        }
        return $bank === [] ? ['id' => $id, 'number' => $number] : ['id' => (string) $bank['ID'], 'number' => (string) ($bank['Cislo'] ?? $number)];
    }

    private function summary(array $r): array
    {
        $out = ['priceNone' => $r['Kc0'] ?? '0', 'round' => ['priceRound' => $r['KcZaokr'] ?? '0']];
        $rates = self::rates($r);
        foreach ([1 => ['Low', $rates[1]], 2 => ['High', $rates[2]], 3 => ['3', $rates[3]]] as $slot => [$name, $rate]) {
            $out['price' . $name] = $r['Kc' . $slot] ?? '0';
            $out['price' . $name . 'VAT'] = ['#' => $r['KcDPH' . $slot] ?? '0', '@rate' => (string) $rate];
            $out['price' . $name . 'Sum'] = (string) ((float) ($r['Kc' . $slot] ?? 0) + (float) ($r['KcDPH' . $slot] ?? 0));
        }
        return $out;
    }

    private function item(array $r, array $header): array
    {
        $slot = (int) ($r['RelSzDPH'] ?? 0);
        $rates = self::rates($header);
        $rate = $r['ProcentoDPH'] ?? ([0 => '0', 1 => (string) $rates[1], 2 => (string) $rates[2], 3 => (string) $rates[3]][$slot] ?? null);
        if ($rate === null) {
            throw new PohodaException('mdb_item_vat', 'Sazbu položky z MDB nelze bezpečně určit. Použijte standardní XML export.');
        }
        $item = [
            'id' => $r['ID'],
            'text' => $r['SText'] ?? '',
            'quantity' => $r['Mnozstvi'] ?? '1',
            'unit' => $r['MJ'] ?? '',
            'rateVAT' => ['#' => [0 => 'none', 1 => 'low', 2 => 'high', 3 => 'third'][$slot] ?? '', '@value' => $rate],
            'homeCurrency' => ['unitPrice' => $r['KcJedn'] ?? '0', 'price' => $r['Kc'] ?? '0', 'priceVAT' => $r['KcDPH'] ?? '0', 'priceSum' => (string) ((float) ($r['Kc'] ?? 0) + (float) ($r['KcDPH'] ?? 0))],
            'classificationVAT' => $this->reference('sDPH', $r['RelTpDPH'] ?? ''),
            'contract' => self::contract($r),
            'activity' => $this->activity($r['RefCin'] ?? ''),
        ];
        // Stejné elementy jako XML POHODY, ať převod z obou zdrojů skončí v téže logice:
        // skutečné procento sazby, typ plnění OSS a částky položky v cizí měně dokladu.
        if (($r['ProcentoDPH'] ?? '') !== '') {
            $item['percentVAT'] = $r['ProcentoDPH'];
        }
        if (trim($r['MOSSDruh'] ?? '') !== '') {
            $item['typeServiceMOSS'] = ['ids' => trim($r['MOSSDruh'])];
        }
        if ($this->lookup('sCMeny', $header['RefCM'] ?? '') !== [] && is_numeric($r['Cm'] ?? '') && is_numeric($r['CmDPH'] ?? '')) {
            $item['foreignCurrency'] = [
                'unitPrice' => $r['CmJedn'] ?? '0',
                'price' => $r['Cm'],
                'priceVAT' => $r['CmDPH'],
                'priceSum' => (string) ((float) $r['Cm'] + (float) $r['CmDPH']),
            ];
        }
        return $item;
    }

    private function identity(array $r): array
    {
        $country = $this->lookup('sZeme', $r['RefZeme'] ?? '');
        return [
            'id' => $r['RefAD'] ?? '',
            'address' => ['company' => $r['Firma'] ?? '', 'name' => $r['Jmeno'] ?? '', 'division' => $r['Utvar'] ?? '', 'street' => $r['Ulice'] ?? '', 'city' => $r['Obec'] ?? '', 'zip' => $r['PSC'] ?? '', 'ico' => $r['ICO'] ?? '', 'dic' => $r['DIC'] ?? '', 'country' => ['ids' => $country['IDS'] ?? '']],
        ];
    }

    private function accounting(string $id): array
    {
        $out = $this->reference('pPK', $id);
        if ($id === '3') {
            $out['accountingType'] = 'withoutAccounting';
        }
        return $out;
    }

    /** Středisko řádku deníku. Starší export středisko nenese a číselník v něm být nemusí. */
    private function centre(string $id): array
    {
        if ($id === '' || $id === '0' || !$this->tables->has('sSTR')) {
            return [];
        }
        $r = $this->lookup('sSTR', $id);
        return ['ids' => $r['IDS'] ?? '', 'name' => $r['SText'] ?? ''];
    }

    /**
     * Činnost řádku deníku, dokladu nebo položky (`RefCin` do `sCIN`) jako `typ:activity` z XML.
     * Starší převodník činnost ani číselník neexportoval; odkaz na činnost, kterou číselník
     * nemá, se čte jako bez činnosti.
     *
     * @return array{ids?:string,name?:string}
     */
    private function activity(string $id): array
    {
        if ($id === '' || $id === '0' || !$this->tables->has('sCIN')) {
            return [];
        }
        $r = $this->lookup('sCIN', $id);
        $code = trim($r['IDS'] ?? '');
        return $code === '' ? [] : ['ids' => $code, 'name' => $r['SText'] ?? ''];
    }

    /**
     * Zakázka řádku nebo dokladu (`CisloZAK`) jako `typ:contract` z XML. POHODA ji na doklad
     * zapisuje číslem zakázky, ne vazbou na `sZAK`; starší převodník sloupec neexportoval.
     *
     * @param array<string,string> $r
     * @return array{ids?:string}
     */
    private static function contract(array $r): array
    {
        $code = trim($r['CisloZAK'] ?? '');
        return $code === '' ? [] : ['ids' => $code];
    }

    private function reference(string $table, string $id): array
    {
        $r = $this->lookup($table, $id);
        return ['id' => $id, 'ids' => $r['IDS'] ?? ''];
    }

    private function lookup(string $table, string $id): array
    {
        if ($id === '' || $id === '0') {
            return [];
        }
        if (!isset($this->indexes[$table])) {
            in_array($table, self::LOOKUPS, true) ? $this->index(self::LOOKUPS, self::GROUPS) : $this->index([$table], []);
        }
        return $this->indexes[$table][$id] ?? [];
    }

    private function group(string $table, string $column): array
    {
        $key = $table . ':' . $column;
        if (!isset($this->groups[$key])) {
            in_array([$table, $column], self::GROUPS, true) ? $this->index(self::LOOKUPS, self::GROUPS) : $this->index([], [[$table, $column]]);
        }
        return $this->groups[$key];
    }

    /**
     * Rejstříky číselníků a položek dokladů. Převod doklad po dokladu potřebuje všechny,
     * proto se při prvním dotazu naplní všechny naráz jedním průchodem souborem, ne každý
     * zvlášť dalším průchodem.
     *
     * @param list<string> $lookups
     * @param list<array{0:string,1:string}> $groups
     */
    private function index(array $lookups, array $groups): void
    {
        $lookups = array_values(array_filter($lookups, fn (string $table): bool => !isset($this->indexes[$table])));
        $groups = array_values(array_filter($groups, fn (array $g): bool => !isset($this->groups[$g[0] . ':' . $g[1]])));
        $byTable = [];
        foreach ($lookups as $table) {
            $this->indexes[$table] = [];
            $byTable[$table] = [];
        }
        foreach ($groups as [$table, $column]) {
            $this->groups[$table . ':' . $column] = [];
            $byTable[$table][] = $column;
        }
        if ($byTable === []) {
            return;
        }
        $indexed = array_fill_keys($lookups, true);
        foreach ($this->tables->each(array_keys($byTable)) as $table => $r) {
            if (isset($indexed[$table])) {
                $this->indexes[$table][$r['ID']] = $r;
            }
            foreach ($byTable[$table] as $column) {
                $this->groups[$table . ':' . $column][$r[$column] ?? ''][] = $r;
            }
        }
        foreach ($groups as [$table, $column]) {
            if (str_ends_with($table, 'pol')) {
                foreach ($this->groups[$table . ':' . $column] as &$rows) {
                    usort($rows, static fn (array $a, array $b): int => ((float) ($a['OrderFld'] ?? 0) <=> (float) ($b['OrderFld'] ?? 0)) ?: ((int) ($a['ID'] ?? 0) <=> (int) ($b['ID'] ?? 0)));
                }
                unset($rows);
            }
        }
    }

    private function date(array $r, string $key): string
    {
        $date = substr($r[$key] ?? '', 0, 10);
        // Prázdné datum Accessu (30. 12. 1899) není datum dokladu.
        return $date !== '' && $date <= '1900-12-31' ? '' : $date;
    }

    private static function direction(string $type): string
    {
        return match ($type) { '1' => 'receipt', '2' => 'expense', default => throw new PohodaException('mdb_direction', 'Neznámý směr dokladu ' . $type . ' v MDB exportu.') };
    }

    /**
     * Sazby DPH slotů dokladu (1 snížená, 2 základní, 3 druhá snížená) podle data
     * zdanitelného plnění, jinak data dokladu. MDB export sazbu u hlavičky nenese, jen
     * slot; POHODA přitom převádí do nového roku i neuhrazené doklady s původním datem,
     * takže v záloze roku 2026 může být faktura z roku 2012 se sazbami 14 a 20 %.
     * Slot 3 před rokem 2015 neexistoval; doklad s částkou v něm odmítne
     * {@see assertRatesKnown()}.
     *
     * @param array<string,string> $r
     * @return array{1:int,2:int,3:int}
     */
    private static function rates(array $r): array
    {
        $date = self::taxDate($r);
        $index = 0;
        while ($date !== '' && $index < count(self::RATE_SETS) - 1 && $date < self::RATE_SETS[$index][0]) {
            $index++;
        }
        if (!self::historicalRates($r)) {
            return self::RATE_SETS[$index][1];
        }
        return self::ratesFromAmounts($r, array_column(array_slice(self::RATE_SETS, $index, 2), 1));
    }

    /** Sazby DPH (1 snížená, 2 základní, 3 druhá snížená) platné od data, od nejnovějších. */
    private const RATE_SETS = [
        ['2024-01-01', [1 => 12, 2 => 21, 3 => 10]],
        ['2013-01-01', [1 => 15, 2 => 21, 3 => 10]],
        ['2012-01-01', [1 => 14, 2 => 20, 3 => 10]],
        ['2010-01-01', [1 => 10, 2 => 20, 3 => 10]],
        ['2008-01-01', [1 => 9, 2 => 19, 3 => 10]],
        ['', [1 => 5, 2 => 19, 3 => 10]],
    ];

    /** @param array<string,string> $r */
    private static function historicalRates(array $r): bool
    {
        return in_array(strtolower($r['HistSzDPH'] ?? ''), ['true', '1', '-1'], true);
    }

    /**
     * Doklad se zaškrtnutými historickými sazbami: POHODA počítá DPH sazbami platnými před
     * poslední změnou, MDB export ale sazbu u hlavičky nenese. Sazby se proto určí z částek
     * slotů: platí ta sada (aktuální ke dni plnění, nebo předchozí), se kterou souhlasí DPH
     * všech slotů s částkou, při shodě obou ta bližší. Typicky jde o vyúčtování zálohy
     * přijaté před změnou sazeb: odpočet zálohy je v historické sazbě (slot 101-103,
     * procento nese `FApol.ProcentoDPH`) a hlavička v aktuálních sazbách. Doklad bez DPH
     * dostane aktuální sazby. Doklad, jehož DPH neodpovídá žádné sadě, převod odmítne.
     *
     * @param array<string,string> $r
     * @param list<array{1:int,2:int,3:int}> $candidates
     * @return array{1:int,2:int,3:int}
     */
    private static function ratesFromAmounts(array $r, array $candidates): array
    {
        $best = null;
        $bestDeviation = INF;
        foreach ($candidates as $rates) {
            $deviation = 0.0;
            foreach ($rates as $slot => $rate) {
                $base = (float) ($r['Kc' . $slot] ?? 0);
                $vat = (float) ($r['KcDPH' . $slot] ?? 0);
                $expected = $base * $rate / 100;
                $diff = abs($vat - $expected);
                if ($diff > max(1.0, abs($expected) * 0.01)) {
                    continue 2;
                }
                $deviation += $diff;
            }
            if ($deviation < $bestDeviation) {
                [$best, $bestDeviation] = [$rates, $deviation];
            }
        }
        if ($best === null) {
            throw new PohodaException('mdb_historical_vat', 'Doklad ' . self::label($r) . ' má zaškrtnuté historické sazby DPH a jeho DPH neodpovídá sazbám platným ke dni plnění ani předchozím. Použijte standardní XML export.');
        }
        return $best;
    }

    /**
     * Odmítne doklad, jehož sazby z data nejde bezpečně určit: částky DPH před 1. 5. 2004
     * (sazby 5 a 22 % číselník nezná) a částka ve slotu druhé snížené sazby před rokem
     * 2015. Doklad bez DPH (nebo s prázdným datem) projde, sazba se u něj neuplatní.
     *
     * @param array<string,string> $r
     */
    private static function assertRatesKnown(array $r): void
    {
        $date = self::taxDate($r);
        if ($date === '' || $date >= '2015-01-01') {
            return;
        }
        $nonZero = static fn (string ...$keys): bool => array_filter($keys, static fn (string $k): bool => (float) ($r[$k] ?? 0) != 0.0) !== [];
        if ($date < '2004-05-01' && $nonZero('Kc1', 'Kc2', 'Kc3', 'KcDPH1', 'KcDPH2', 'KcDPH3')) {
            throw new PohodaException('mdb_historical_vat', 'Doklad ' . self::label($r) . ' má DPH ze sazeb platných před 1. 5. 2004, které převod nezná. Použijte standardní XML export.');
        }
        if ($nonZero('Kc3', 'KcDPH3')) {
            throw new PohodaException('mdb_historical_vat', 'Doklad ' . self::label($r) . ' má částku v druhé snížené sazbě DPH, která před rokem 2015 neexistovala. Opravte doklad v POHODĚ, nebo použijte standardní XML export.');
        }
    }

    /**
     * Datum zdanitelného plnění, jinak datum dokladu. U přijatého dokladu je DUZP z dokladu
     * dodavatele „Datum KH DPH" (`DatKHDPH`), `DatZdPln` je tam datum odpočtu
     * ({@see PohodaPurchaseVatDates}); sazby platí ke dni plnění. Vydaný doklad (faktura,
     * příjmový pokladní doklad) má DUZP v `DatZdPln`. Access ukládá prázdné datum jako
     * 30. 12. 1899, takové datum se bere jako nevyplněné.
     *
     * @param array<string,string> $r
     */
    private static function taxDate(array $r): string
    {
        $keys = self::isIssued($r) ? ['DatZdPln', 'Datum'] : ['DatKHDPH', 'DatZdPln', 'Datum'];
        foreach ($keys as $key) {
            $date = substr($r[$key] ?? '', 0, 10);
            if ($date !== '' && $date > '1900-12-31') {
                return $date;
            }
        }
        return '';
    }

    /**
     * Vydaný doklad: faktura vydané strany (`FA.RelTpFak` mimo přijaté druhy) nebo příjmový
     * pokladní doklad. Interní, bankovní a výdajový pokladní doklad nese DUZP dodavatele
     * v `DatKHDPH` stejně jako přijatá faktura.
     *
     * @param array<string,string> $r
     */
    private static function isIssued(array $r): bool
    {
        if (isset($r['RelTpFak'])) {
            return !str_starts_with(self::INVOICES[(int) $r['RelTpFak']] ?? '', 'received') && (self::INVOICES[(int) $r['RelTpFak']] ?? '') !== 'commitment';
        }
        return ($r['RelTpHO'] ?? '') === '1';
    }

    /** @param array<string,string> $r */
    private static function label(array $r): string
    {
        $date = self::taxDate($r);
        return trim(($r['Cislo'] ?? '') !== '' ? $r['Cislo'] : 'ID ' . ($r['ID'] ?? '?'))
            . ($date !== '' ? ' (' . (int) substr($date, 8, 2) . '. ' . (int) substr($date, 5, 2) . '. ' . substr($date, 0, 4) . ')' : '');
    }

    private function isPlaceholder(array $r): bool
    {
        if (($r['Cislo'] ?? '') !== '') {
            return false;
        }
        foreach (['Kc0', 'Kc1', 'Kc2', 'Kc3', 'KcDPH1', 'KcDPH2', 'KcDPH3', 'KcZaokr'] as $key) {
            if ((float) ($r[$key] ?? 0) !== 0.0) {
                return false;
            }
        }
        return ($this->group('FApol', 'RefAg')[$r['ID']] ?? []) === [];
    }
}
