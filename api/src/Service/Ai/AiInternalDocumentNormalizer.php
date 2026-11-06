<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Service\Accounting\InternalDocumentService;

/**
 * Serverová kontrola návrhů interních dokladů od AI (Účetnictví → Interní doklady).
 * Návrh nic nezakládá; koncept založí až uživatel běžným
 * `POST /api/accounting/internal-documents` a zaúčtuje `POST …/{id}/post`, tedy stejnou
 * službou ({@see InternalDocumentService}) a stejnými pojistkami jako ruční doklad.
 *
 * Tady jen tvar: druh z číselníku agendy, platná data, řádky s kladnou částkou na haléře,
 * vyrovnanost MD = D v celých haléřích a kódy, které ve firmě neexistují. Každý nález
 * se vrátí v `problems`; návrh s nálezem rozhraní nedovolí založit. Kontroly proti
 * stavu firmy (aktivní účet k datu, otevřené období, zámek, dimenze) doplní
 * {@see AiInternalDocumentValidator}.
 */
final class AiInternalDocumentNormalizer
{
    public const MAX_DOCUMENTS = 10;
    public const MAX_LINES = 50;
    private const MAX_AMOUNT = 999999999999.99;

    /**
     * @param mixed                                    $raw        pole dokladů tak, jak je vrátil model
     * @param array<string,string>                     $chartNames kód účtu => název (všechny účty firmy)
     * @param array<string,array{id:int,name:string}> $partners   IČO => klient firmy
     * @return list<array<string,mixed>>
     */
    public static function normalize(mixed $raw, array $chartNames, string $today, array $partners = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_DOCUMENTS) as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $lines = [];
            $debit = 0;
            $credit = 0;
            $unknown = [];
            foreach (array_slice(array_values(is_array($doc['lines'] ?? null) ? $doc['lines'] : []), 0, self::MAX_LINES) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $code = preg_replace('/\s+/u', '', (string) ($line['account_code'] ?? '')) ?? '';
                $side = in_array($line['side'] ?? null, ['debit', 'credit'], true) ? (string) $line['side'] : null;
                $amount = is_numeric($line['amount'] ?? null) ? round((float) $line['amount'], 2) : 0.0;
                if ($code === '' || strlen($code) > 20 || $side === null || $amount <= 0 || $amount > self::MAX_AMOUNT) {
                    continue;
                }
                $cents = (int) round($amount * 100);
                if ($side === 'debit') {
                    $debit += $cents;
                } else {
                    $credit += $cents;
                }
                $known = array_key_exists($code, $chartNames);
                if (!$known && !in_array($code, $unknown, true)) {
                    $unknown[] = $code;
                }
                $dims = [];
                foreach (is_array($line['dimension_value_ids'] ?? null) ? $line['dimension_value_ids'] : [] as $id) {
                    $id = filter_var($id, FILTER_VALIDATE_INT);
                    if ($id !== false && $id > 0) {
                        $dims[$id] = $id;
                    }
                }
                $lines[] = [
                    'account_code' => $code,
                    'account_name' => $known ? $chartNames[$code] : null,
                    'side' => $side,
                    'amount' => (float) ($cents / 100),
                    'dimension_value_ids' => array_values($dims),
                ];
            }
            $title = trim((string) ($doc['title'] ?? ''));
            if ($lines === [] || $title === '') {
                continue;
            }
            $kind = in_array($doc['kind'] ?? null, InternalDocumentService::KINDS, true) ? (string) $doc['kind'] : 'other';
            $entryDate = self::date($doc['entry_date'] ?? null, $today);
            $documentDate = self::date($doc['document_date'] ?? null, $entryDate);
            $ico = preg_replace('/\D+/', '', (string) ($doc['partner_ico'] ?? '')) ?? '';
            $partner = $ico !== '' ? ($partners[$ico] ?? null) : null;
            $partnerName = trim((string) ($doc['partner_name'] ?? ''));
            $note = trim((string) ($doc['note'] ?? ''));

            $problems = [];
            if ($debit === 0 || $credit === 0) {
                $problems[] = 'Doklad musí mít řádky na straně MD i D.';
            } elseif ($debit !== $credit) {
                $problems[] = 'Strany MD a D se neshodují (MD ' . self::money($debit) . ', D ' . self::money($credit) . ').';
            }
            foreach ($unknown as $code) {
                $problems[] = 'Účet ' . $code . ' není v účtové osnově firmy.';
            }

            $out[] = [
                'kind' => $kind,
                'title' => mb_substr($title, 0, 255),
                'document_date' => $documentDate,
                'entry_date' => $entryDate,
                'partner_id' => $partner['id'] ?? null,
                'partner_name' => $partner['name'] ?? ($partnerName !== '' ? mb_substr($partnerName, 0, 190) : null),
                'partner_ico' => $ico !== '' ? $ico : null,
                'note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
                'lines' => $lines,
                'total_debit' => (float) ($debit / 100),
                'total_credit' => (float) ($credit / 100),
                'balanced' => $debit > 0 && $debit === $credit,
                'unknown_accounts' => $unknown,
                'problems' => $problems,
            ];
        }
        return $out;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ');
    }

    private static function date(mixed $value, string $fallback): string
    {
        $v = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }
        return $fallback;
    }
}
