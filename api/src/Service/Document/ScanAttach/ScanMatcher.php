<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

/**
 * Párování skenů na doklady, které už v systému jsou. Čistá logika bez DB —
 * vstupem jsou soubory dávky (název + uložené vytěžení) a kandidátní doklady.
 *
 * Tři klíče podle jistoty:
 *   1. ČÁROVÝ KÓD — číslo na začátku názvu souboru nebo vytěžené z nálepky
 *      = `external_barcode` dokladu. Jistý: nálepka patří k papírovému dokladu.
 *   2. ČÍSLO DOKLADU v názvu souboru (`PF260268 foto1.jpg`). Samo jisté není —
 *      složka skenů může obsahovat doklady jiných firem se stejnou řadou — potvrdí
 *      ho až vytěžený obsah (strana dokladu nebo částka), jinak jde k potvrzení.
 *   3. VYTĚŽENÝ OBSAH — firma na správné straně dokladu, částka a k tomu číslo
 *      dokladu / VS, nebo protistrana s datem ±5 dní.
 *
 * Páruje se ve DVOU KOLECH: nejdřív klíče 1 a 2 pro všechny doklady, teprve pak
 * obsah pro zbytek bez už použitých souborů. Jinak si starší doklad téhož
 * dodavatele na stejnou částku (pravidelná faktura, každý rok stejná) vezme podle
 * obsahu sken, který čárovým kódem patří novějšímu dokladu.
 *
 * Obsahové kolo přiřazuje globálně od nejlepší shody, ne doklad po dokladu:
 * pořadí dokladů tak nerozhoduje o tom, kdo dostane sken. Dvě stejně dobré shody
 * (dva skeny pro doklad, nebo jeden sken pro dva doklady) jsou nerozhodnutelné
 * a jdou jako kandidáti k ručnímu potvrzení.
 *
 * Doklad se čte ze strany firmy: u přijatého dokladu je firma odběratel a
 * protistrana dodavatel, u vydaného naopak. Odběratele účtenky, která ho neuvádí,
 * doplní SPZ vozidla nebo koncovka platební karty firmy.
 */
final class ScanMatcher
{
    public const LEVEL_CERTAIN = 'certain';
    public const LEVEL_LIKELY = 'likely';
    public const LEVEL_CANDIDATE = 'candidate';
    public const LEVEL_NONE = 'none';

    public const DIRECTION_RECEIVED = 'received';
    public const DIRECTION_ISSUED = 'issued';

    public const OUTCOME_ATTACHED = 'attached';
    public const OUTCOME_PROPOSED = 'proposed';
    public const OUTCOME_ORPHAN = 'orphan';
    public const OUTCOME_FOREIGN = 'foreign';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_UNREADABLE = 'unreadable';

    /** Poznámky jsou kódy — překládá je UI. */
    public const NOTE_AMOUNT_MISMATCH = 'amount_mismatch';
    public const NOTE_DOC_NO_CONFIRMED = 'doc_no_confirmed';
    public const NOTE_DOC_NO_UNCONFIRMED = 'doc_no_unconfirmed';
    public const NOTE_AMBIGUOUS = 'ambiguous';
    /** Klíč sedí, ale obsah skenu uvádí na straně firmy jinou firmu. */
    public const NOTE_SIDE_MISMATCH = 'side_mismatch';

    private const AMOUNT_TOLERANCE = 1.0;
    private const DATE_TOLERANCE_DAYS = 5;

    private const SIDE_STRONG = 'strong';
    private const SIDE_WEAK = 'weak';
    private const SIDE_NO = 'no';

    private string $ownIco = '';
    private string $ownName = '';
    /** @var array<string,true> */
    private array $ownPlates = [];
    /** @var array<string, list<array{from:?string,to:?string}>> koncovka → období platnosti karet firmy */
    private array $ownCards = [];

    /**
     * @param list<array{key:string,name:string,extraction:?array<string,mixed>}> $files
     * @param list<array{key:string,direction:string,doc_numbers?:list<string>,barcode?:?string,counterparty_ico?:?string,counterparty_doc_no?:?string,vs?:?string,total:float,date?:?string,tax_date?:?string}> $targets
     * @param array{own_ico?:?string,own_name?:?string,own_plates?:list<string>,own_cards?:list<string>,trust_doc_no?:bool,accept_likely?:bool,rejected?:list<string>,claimed_files?:list<string>,attached_targets?:list<string>} $context
     *        `rejected` = páry „souborKlíč|dokladKlíč", které uživatel odmítl;
     *        `claimed_files` = soubory už připojené dřív (dávka běží znovu);
     *        `attached_targets` = doklady, které už sken z téže dávky mají (obsahem se jim další nepřidá)
     * @return array{
     *   targets: array<string, array{files:list<string>,method:string,level:string,note:string,score:int,attach:bool,per_file:array<string,array{attach:bool,level:string,note:string}>}>,
     *   files: array<string, array{outcome:string,ownership:?string}>
     * }
     *        `per_file` = rozhodnutí pro každý soubor dokladu zvlášť (skupina skenů
     *        jednoho čísla může mít část souborů jistou a část k potvrzení).
     */
    public function match(array $files, array $targets, array $context): array
    {
        $this->ownIco = self::ico((string) ($context['own_ico'] ?? ''));
        $this->ownName = trim((string) ($context['own_name'] ?? ''));
        $this->ownPlates = [];
        foreach ($context['own_plates'] ?? [] as $p) {
            $p = self::normPlate((string) $p);
            if ($p !== '') {
                $this->ownPlates[$p] = true;
            }
        }
        $this->ownCards = [];
        foreach ($context['own_cards'] ?? [] as $c) {
            // Karta je buď jen koncovka, nebo koncovka s obdobím platnosti — stejná
            // koncovka mohla mezi lety patřit jiné kartě (výměna karty).
            $card = is_array($c) ? $c : ['last4' => $c];
            $last4 = substr((string) preg_replace('/\D/', '', (string) ($card['last4'] ?? '')), -4);
            if (strlen($last4) === 4) {
                $this->ownCards[$last4][] = [
                    'from' => isset($card['valid_from']) && $card['valid_from'] !== '' ? (string) $card['valid_from'] : null,
                    'to' => isset($card['valid_to']) && $card['valid_to'] !== '' ? (string) $card['valid_to'] : null,
                ];
            }
        }
        $trustDocNo = !empty($context['trust_doc_no']);
        $acceptLikely = !empty($context['accept_likely']);
        $rejected = array_fill_keys(array_map('strval', $context['rejected'] ?? []), true);
        $claimedBefore = array_fill_keys(array_map('strval', $context['claimed_files'] ?? []), true);
        $attachedTargets = array_fill_keys(array_map('strval', $context['attached_targets'] ?? []), true);

        // ── Příprava souborů a indexů ────────────────────────────────────────────
        $info = [];
        $byBarcode = [];
        $byToken = [];
        $buckets = [];
        foreach ($files as $f) {
            $key = (string) $f['key'];
            $x = is_array($f['extraction'] ?? null) ? $f['extraction'] : null;
            $base = pathinfo((string) $f['name'], PATHINFO_FILENAME);

            $barcodes = [];
            if (preg_match('/^(\d{6,32})(?!\d)/', $base, $m) === 1) {
                $barcodes[] = $m[1];
            }
            $xb = self::normCode((string) ($x['barcode'] ?? ''));
            if ($xb !== '' && !in_array($xb, $barcodes, true)) {
                $barcodes[] = $xb;
            }
            $token = self::docToken($base);
            $plate = $this->detectPlate($base, $x);
            $card = substr((string) preg_replace('/\D/', '', (string) ($x['card_last4'] ?? '')), -4);
            $card = strlen($card) === 4 ? $card : null;

            $amounts = [];
            foreach (['total_with_vat', 'amount_due'] as $k) {
                if (is_numeric($x[$k] ?? null)) {
                    $amounts[] = abs((float) $x[$k]);
                }
            }

            $buyer = $this->buyerSide($x, $plate, $card);
            $vendor = $this->vendorSide($x);
            $info[$key] = [
                'x' => $x, 'barcodes' => $barcodes, 'amounts' => $amounts,
                'buyer' => $buyer, 'vendor' => $vendor,
                'ownership' => $x === null && $buyer === null ? null : self::ownership($buyer, $vendor),
            ];

            if (isset($claimedBefore[$key])) {
                continue;
            }
            foreach ($barcodes as $bc) {
                $byBarcode[$bc][] = $key;
            }
            if ($token !== null) {
                $byToken[$token][] = $key;
            }
            foreach ($amounts as $a) {
                $b = (int) floor($a);
                $buckets[$b][$key] = true;
            }
        }

        $taken = $claimedBefore;       // soubor už nemůže dostat jiný doklad
        $attachedNow = [];             // soubor se připojí automaticky
        $proposed = [];                // soubor čeká na potvrzení
        $results = [];
        $free = static function (array $keys, string $targetKey) use (&$taken, $rejected): array {
            return array_values(array_filter(
                array_unique($keys),
                static fn (string $k): bool => !isset($taken[$k]) && !isset($rejected[$k . '|' . $targetKey]),
            ));
        };

        // ── Kolo 1: čárový kód a číslo dokladu pro VŠECHNY doklady ────────────────
        // Jistota se určuje PO SOUBORECH, ne za celou skupinu: složka může vedle
        // vlastního skenu obsahovat sken sesterské firmy se stejným číslem, a ten
        // se nesmí svézt s potvrzením cizího souboru.
        foreach ($targets as $t) {
            $tk = (string) $t['key'];
            $results[$tk] = self::empty();
            $total = abs((float) $t['total']);
            $direction = (string) $t['direction'];

            $bc = self::normCode((string) ($t['barcode'] ?? ''));
            if ($bc !== '' && isset($byBarcode[$bc])) {
                $fs = $free($byBarcode[$bc], $tk);
                if ($fs !== []) {
                    $perFile = [];
                    foreach ($fs as $fk) {
                        // Obsah skenu uvádí na straně firmy jinou firmu → kód sám
                        // nestačí, rozhodne uživatel.
                        if ($this->ownSide($info[$fk], $direction) === self::SIDE_NO) {
                            $perFile[$fk] = ['attach' => false, 'level' => self::LEVEL_CANDIDATE, 'note' => self::NOTE_SIDE_MISMATCH];
                            $proposed[$fk] = true;
                            continue;
                        }
                        $note = $info[$fk]['amounts'] !== [] && !$this->amountMatches($info[$fk]['amounts'], $total)
                            ? self::NOTE_AMOUNT_MISMATCH : '';
                        $perFile[$fk] = ['attach' => true, 'level' => self::LEVEL_CERTAIN, 'note' => $note];
                        $taken[$fk] = true;
                        $attachedNow[$fk] = true;
                    }
                    $results[$tk] = self::grouped($fs, $perFile, 'barcode', 10);
                    continue;
                }
            }

            foreach (array_unique(array_filter(array_map(self::normNumber(...), $t['doc_numbers'] ?? []))) as $n) {
                if (!isset($byToken[$n])) {
                    continue;
                }
                // Sken, jehož obsah na straně firmy uvádí jinou firmu, k dokladu
                // nepatří ani se stejným číslem (sesterská firma se stejnou řadou).
                $fs = array_values(array_filter(
                    $free($byToken[$n], $tk),
                    fn (string $fk): bool => $this->ownSide($info[$fk], $direction) !== self::SIDE_NO,
                ));
                if ($fs === []) {
                    continue;
                }
                // Potvrzený soubor = firma na správné straně (IČO, SPZ, karta), nebo
                // aspoň nevyloučená strana a sedící částka.
                $confirmed = [];
                foreach ($fs as $fk) {
                    if ($info[$fk]['x'] !== null
                        && ($this->ownSide($info[$fk], $direction) === self::SIDE_STRONG || $this->amountMatches($info[$fk]['amounts'], $total))) {
                        $confirmed[$fk] = true;
                    }
                }
                $perFile = [];
                foreach ($fs as $fk) {
                    // Nevytěžená fotka další strany téhož dokladu převezme potvrzení
                    // vytěženého souboru; vytěžený sken, který nesedí, jistý není.
                    $certain = isset($confirmed[$fk]) || $trustDocNo || ($info[$fk]['x'] === null && $confirmed !== []);
                    $perFile[$fk] = [
                        'attach' => $certain,
                        'level' => $certain ? self::LEVEL_CERTAIN : self::LEVEL_CANDIDATE,
                        'note' => isset($confirmed[$fk]) || ($certain && $confirmed !== []) ? self::NOTE_DOC_NO_CONFIRMED : self::NOTE_DOC_NO_UNCONFIRMED,
                    ];
                    if ($certain) {
                        $taken[$fk] = true;
                        $attachedNow[$fk] = true;
                    } else {
                        $proposed[$fk] = true;
                    }
                }
                $results[$tk] = self::grouped($fs, $perFile, 'doc_no', 8);
                continue 2;
            }
        }

        // ── Kolo 2: obsah pro doklady bez souboru, jen z nepoužitých souborů ──────
        $pairs = [];
        foreach ($targets as $t) {
            $tk = (string) $t['key'];
            if ($results[$tk]['files'] !== [] || isset($attachedTargets[$tk])) {
                continue;
            }
            $direction = (string) $t['direction'];
            $total = abs((float) $t['total']);
            $tBarcode = self::normCode((string) ($t['barcode'] ?? ''));
            $numbers = array_values(array_unique(array_filter([
                self::normNumber((string) ($t['counterparty_doc_no'] ?? '')),
                self::normNumber((string) ($t['vs'] ?? '')),
            ])));
            $counterIco = self::ico((string) ($t['counterparty_ico'] ?? ''));
            $b = (int) floor($total);
            $candidates = ($buckets[$b - 1] ?? []) + ($buckets[$b] ?? []) + ($buckets[$b + 1] ?? []);

            foreach (array_keys($candidates) as $fk) {
                $fk = (string) $fk;
                $f = $info[$fk];
                $x = $f['x'];
                if ($x === null || isset($taken[$fk]) || isset($rejected[$fk . '|' . $tk])) {
                    continue;
                }
                // Doklad s vlastním čárovým kódem má sken pojmenovaný tím kódem —
                // sken s jiným kódem je jiný doklad, i když sedí protistrana a částka.
                if ($tBarcode !== '' && $f['barcodes'] !== [] && !in_array($tBarcode, $f['barcodes'], true)) {
                    continue;
                }
                $side = $this->ownSide($f, $direction);
                if ($side === self::SIDE_NO || !$this->amountMatches($f['amounts'], $total)) {
                    continue;
                }
                $counterValue = self::ico((string) ($direction === self::DIRECTION_ISSUED ? ($x['buyer_ico'] ?? '') : ($x['vendor_ico'] ?? '')));
                $counterOk = $counterIco !== '' && $counterValue === $counterIco;
                $numberOk = $numbers !== [] && array_intersect($numbers, array_filter([
                    self::normNumber((string) ($x['document_number'] ?? '')),
                    self::normNumber((string) ($x['variable_symbol'] ?? '')),
                ])) !== [];
                $dist = self::dateDistance($x, $t);
                $dateOk = $dist !== null && $dist <= self::DATE_TOLERANCE_DAYS;
                $strong = $side === self::SIDE_STRONG;

                // Protistrana a částka samy nestačí: pravidelné faktury téhož dodavatele
                // mají stejnou částku každý rok. Jistota chce firmu na správné straně
                // a k tomu číslo dokladu, nebo protistranu s datem.
                $level = match (true) {
                    $strong && ($numberOk || ($counterOk && $dateOk)) => self::LEVEL_CERTAIN,
                    $counterOk || $numberOk || $dateOk => self::LEVEL_LIKELY,
                    default => null,
                };
                if ($level === null) {
                    continue;
                }
                $pairs[] = [
                    't' => $tk, 'f' => $fk, 'level' => $level,
                    'score' => ($strong ? 4 : ($side === self::SIDE_WEAK ? 1 : 0)) + ($numberOk ? 2 : 0) + ($counterOk ? 2 : 0) + ($dateOk ? 1 : 0),
                    'dist' => $dist ?? PHP_INT_MAX,
                ];
            }
        }

        usort($pairs, static fn (array $a, array $b): int =>
            [$b['score'], $a['dist'], $a['t'], $a['f']] <=> [$a['score'], $b['dist'], $b['t'], $b['f']]);
        $byTarget = [];
        $byFile = [];
        foreach ($pairs as $i => $p) {
            $byTarget[$p['t']][] = $i;
            $byFile[$p['f']][] = $i;
        }

        $done = [];
        foreach ($pairs as $i => $p) {
            if (isset($done[$p['t']]) || isset($taken[$p['f']])) {
                continue;
            }
            $tiedFiles = [$p['f']];
            foreach ($byTarget[$p['t']] as $j) {
                $q = $pairs[$j];
                if ($j !== $i && !isset($taken[$q['f']]) && $q['score'] === $p['score'] && $q['dist'] === $p['dist']) {
                    $tiedFiles[] = $q['f'];
                }
            }
            $tiedTargets = [$p['t']];
            foreach ($byFile[$p['f']] as $j) {
                $q = $pairs[$j];
                if ($j !== $i && !isset($done[$q['t']]) && $q['score'] === $p['score'] && $q['dist'] === $p['dist']) {
                    $tiedTargets[] = $q['t'];
                }
            }

            if (count($tiedFiles) > 1 || count($tiedTargets) > 1) {
                foreach ($tiedTargets as $tt) {
                    $results[$tt] = self::uniform(
                        $tt === $p['t'] ? $tiedFiles : [$p['f']],
                        'content', self::LEVEL_CANDIDATE, self::NOTE_AMBIGUOUS, $p['score'], false,
                    );
                    $done[$tt] = true;
                }
                foreach ($tiedFiles as $ff) {
                    $proposed[$ff] = true;
                }
                continue;
            }

            $done[$p['t']] = true;
            $taken[$p['f']] = true;
            $attach = $p['level'] === self::LEVEL_CERTAIN || ($p['level'] === self::LEVEL_LIKELY && $acceptLikely);
            if ($attach) {
                $attachedNow[$p['f']] = true;
            } else {
                $proposed[$p['f']] = true;
            }
            $results[$p['t']] = self::uniform([$p['f']], 'content', $p['level'], '', $p['score'], $attach);
        }

        // ── Výsledek po souborech ────────────────────────────────────────────────
        $outFiles = [];
        foreach ($info as $fk => $f) {
            $fk = (string) $fk;
            $outcome = match (true) {
                isset($claimedBefore[$fk]), isset($attachedNow[$fk]) => self::OUTCOME_ATTACHED,
                isset($proposed[$fk]) => self::OUTCOME_PROPOSED,
                $f['ownership'] === null => self::OUTCOME_UNREADABLE,
                $f['ownership'] === 'own' => self::OUTCOME_ORPHAN,
                $f['ownership'] === 'foreign' => self::OUTCOME_FOREIGN,
                default => self::OUTCOME_UNKNOWN,
            };
            $outFiles[$fk] = ['outcome' => $outcome, 'ownership' => $f['ownership']];
        }

        return ['targets' => $results, 'files' => $outFiles];
    }

    /** SPZ pro porovnání: jen písmena a číslice, velkými („1AB 23 45" = „1AB2345"). */
    public static function normPlate(string $s): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $s));
    }

    /** @return array{files:list<string>,method:string,level:string,note:string,score:int,attach:bool,per_file:array<string,array{attach:bool,level:string,note:string}>} */
    private static function empty(): array
    {
        return ['files' => [], 'method' => 'none', 'level' => self::LEVEL_NONE, 'note' => '', 'score' => 0, 'attach' => false, 'per_file' => []];
    }

    /**
     * Výsledek, kde všechny soubory dokladu mají stejné rozhodnutí.
     *
     * @param list<string> $files
     * @return array{files:list<string>,method:string,level:string,note:string,score:int,attach:bool,per_file:array<string,array{attach:bool,level:string,note:string}>}
     */
    private static function uniform(array $files, string $method, string $level, string $note, int $score, bool $attach): array
    {
        $perFile = [];
        foreach ($files as $fk) {
            $perFile[$fk] = ['attach' => $attach, 'level' => $level, 'note' => $note];
        }
        return ['files' => $files, 'method' => $method, 'level' => $level, 'note' => $note, 'score' => $score, 'attach' => $attach, 'per_file' => $perFile];
    }

    /**
     * Výsledek skupiny souborů s rozhodnutím po souborech. Doklad je „jistý",
     * když se k němu aspoň jeden soubor připojí; poznámka dokladu je poznámka
     * připojeného souboru (jinak prvního).
     *
     * @param list<string> $files
     * @param array<string,array{attach:bool,level:string,note:string}> $perFile
     * @return array{files:list<string>,method:string,level:string,note:string,score:int,attach:bool,per_file:array<string,array{attach:bool,level:string,note:string}>}
     */
    private static function grouped(array $files, array $perFile, string $method, int $score): array
    {
        $attached = array_filter($perFile, static fn (array $d): bool => $d['attach']);
        $note = '';
        foreach ([$attached, $perFile] as $pool) {
            foreach ($pool as $d) {
                if ($d['note'] !== '') {
                    $note = $d['note'];
                    break 2;
                }
            }
        }
        return [
            'files' => $files, 'method' => $method,
            'level' => $attached !== [] ? self::LEVEL_CERTAIN : self::LEVEL_CANDIDATE,
            'note' => $note, 'score' => $score, 'attach' => $attached !== [], 'per_file' => $perFile,
        ];
    }

    /**
     * Firma na straně dokladu, která jí podle směru patří. `no` = sken je jiné
     * firmy nebo opačného směru; null = nelze určit.
     *
     * @param array{buyer:?string,vendor:?string} $f
     */
    private function ownSide(array $f, string $direction): ?string
    {
        $own = $direction === self::DIRECTION_ISSUED ? $f['vendor'] : $f['buyer'];
        $other = $direction === self::DIRECTION_ISSUED ? $f['buyer'] : $f['vendor'];
        // Firma na OPAČNÉ straně a na správné ne = doklad opačného směru (vlastní
        // vydaná faktura se nesmí připojit k přijaté se stejnou částkou).
        if ($other === self::SIDE_STRONG && $own !== self::SIDE_STRONG && $own !== self::SIDE_WEAK) {
            return self::SIDE_NO;
        }
        return $own;
    }

    /**
     * Je firma odběratelem? IČO (nebo DIČ „CZ"+IČO) je silný důkaz; když chybí,
     * rozhoduje SPZ vozidla firmy, koncovka její karty, pak jméno a nakonec role,
     * kterou určil model.
     *
     * @param array<string,mixed>|null $x
     */
    private function buyerSide(?array $x, ?string $plate, ?string $card): ?string
    {
        if ($plate !== null && isset($this->ownPlates[$plate])) {
            return self::SIDE_STRONG;
        }
        if ($x === null) {
            return null;
        }
        $ico = self::ico((string) ($x['buyer_ico'] ?? ''));
        if ($ico === '' && preg_match('/^CZ(\d{8})$/i', (string) ($x['buyer_dic'] ?? ''), $m) === 1) {
            $ico = $m[1];
        }
        if ($ico !== '' && $this->ownIco !== '') {
            return $ico === $this->ownIco ? self::SIDE_STRONG : self::SIDE_NO;
        }
        if ($card !== null && $this->ownCardOnDate($card, $x)) {
            return self::SIDE_STRONG;
        }
        $name = trim((string) ($x['buyer_name'] ?? ''));
        if ($name !== '' && $this->ownName !== '') {
            return self::sameName($name, $this->ownName) ? self::SIDE_WEAK : self::SIDE_NO;
        }
        $role = (string) ($x['company_role'] ?? '');
        return $role === 'buyer' || $role === 'both' ? self::SIDE_WEAK : null;
    }

    /**
     * Platila karta firmy s touto koncovkou k datu dokladu? Bez data na skenu
     * stačí, že firma kartu s touto koncovkou někdy měla.
     *
     * @param array<string,mixed> $x
     */
    private function ownCardOnDate(string $last4, array $x): bool
    {
        $date = (string) ($x['tax_date'] ?? $x['issue_date'] ?? '');
        foreach ($this->ownCards[$last4] ?? [] as $window) {
            if ($date === ''
                || (($window['from'] === null || $window['from'] <= $date) && ($window['to'] === null || $window['to'] >= $date))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed>|null $x */
    private function vendorSide(?array $x): ?string
    {
        if ($x === null) {
            return null;
        }
        $ico = self::ico((string) ($x['vendor_ico'] ?? ''));
        if ($ico === '' && preg_match('/^CZ(\d{8})$/i', (string) ($x['vendor_dic'] ?? ''), $m) === 1) {
            $ico = $m[1];
        }
        if ($ico !== '' && $this->ownIco !== '') {
            return $ico === $this->ownIco ? self::SIDE_STRONG : self::SIDE_NO;
        }
        $name = trim((string) ($x['vendor_name'] ?? ''));
        if ($name !== '' && $this->ownName !== '') {
            return self::sameName($name, $this->ownName) ? self::SIDE_WEAK : self::SIDE_NO;
        }
        $role = (string) ($x['company_role'] ?? '');
        return $role === 'vendor' || $role === 'both' ? self::SIDE_WEAK : null;
    }

    /**
     * Komu sken patří (pro přehled „skeny firmy bez dokladu"). Cizí jen tehdy,
     * když je jako odběratel uvedený někdo jiný — účtenka bez odběratele cizí není.
     */
    private static function ownership(?string $buyer, ?string $vendor): string
    {
        if (in_array($buyer, [self::SIDE_STRONG, self::SIDE_WEAK], true) || in_array($vendor, [self::SIDE_STRONG, self::SIDE_WEAK], true)) {
            return 'own';
        }
        return $buyer === self::SIDE_NO ? 'foreign' : 'unknown';
    }

    /**
     * SPZ vozidla firmy: z vytěžení, jinak hledáním známých SPZ v názvu souboru
     * (`Tankování 1AB 2345.pdf`). Neznámá SPZ z vytěžení se vrátí taky.
     *
     * @param array<string,mixed>|null $x
     */
    private function detectPlate(string $base, ?array $x): ?string
    {
        $fromX = self::normPlate((string) ($x['license_plate'] ?? ''));
        if ($fromX !== '' && isset($this->ownPlates[$fromX])) {
            return $fromX;
        }
        if ($this->ownPlates !== []) {
            $haystack = self::normPlate($base);
            foreach (array_keys($this->ownPlates) as $plate) {
                if (str_contains($haystack, (string) $plate)) {
                    return (string) $plate;
                }
            }
        }
        return $fromX !== '' ? $fromX : null;
    }

    /** @param list<float> $amounts */
    private function amountMatches(array $amounts, float $total): bool
    {
        foreach ($amounts as $a) {
            if (abs($a - $total) <= self::AMOUNT_TOLERANCE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nejmenší vzdálenost ve dnech mezi daty skenu (vystavení, DUZP) a dokladu.
     *
     * @param array<string,mixed> $x
     * @param array<string,mixed> $t
     */
    private static function dateDistance(array $x, array $t): ?int
    {
        $best = null;
        foreach ([$x['issue_date'] ?? null, $x['tax_date'] ?? null] as $a) {
            $ta = is_string($a) && $a !== '' ? strtotime($a . ' 00:00:00 UTC') : false;
            if ($ta === false) {
                continue;
            }
            foreach ([$t['date'] ?? null, $t['tax_date'] ?? null] as $b) {
                $tb = is_string($b) && $b !== '' ? strtotime($b . ' 00:00:00 UTC') : false;
                if ($tb === false) {
                    continue;
                }
                $d = (int) round(abs($ta - $tb) / 86400);
                $best = $best === null ? $d : min($best, $d);
            }
        }
        return $best;
    }

    /**
     * Číslo dokladu ze začátku názvu souboru (`PF260268 foto1` → `PF260268`,
     * `PPD-2026-0001 sken` → `PPD20260001`). Musí obsahovat číslici.
     */
    private static function docToken(string $base): ?string
    {
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\/.]*[0-9])/', $base, $m) !== 1) {
            return null;
        }
        $n = self::normNumber($m[1]);
        return strlen($n) >= 3 ? $n : null;
    }

    private static function ico(string $s): string
    {
        return ltrim((string) preg_replace('/\D/', '', $s), '0');
    }

    private static function normCode(string $s): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $s));
    }

    private static function normNumber(string $s): string
    {
        return ltrim(strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $s)), '0');
    }

    /** Název firmy bez právní formy a interpunkce („Alfa Beta s.r.o." = `alfabeta`). */
    private static function normName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = (string) preg_replace('/\b(s\.?\s*r\.?\s*o\.?|a\.?\s*s\.?|spol\.?|v\.?\s*o\.?\s*s\.?|k\.?\s*s\.?)(?=\s|$|,)/u', ' ', $s);
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    private static function sameName(string $a, string $b): bool
    {
        $a = self::normName($a);
        $b = self::normName($b);
        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }
}
