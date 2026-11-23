<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;

/**
 * Dvojice převzatého deníku ze zdroje, který účtuje po řádcích „účet MD, účet Dal,
 * částka" (Money S3, POHODA, Premier): každý řádek zdroje je v jednoduché formě zápisu
 * jedna dvojice s textem řádku. Párovač se tu nepoužívá - dvojice nese už zdroj a obraty
 * po účtech zůstávají přesně jako ve složené formě.
 *
 * Řádky převodu jdou po řádcích zdroje vždy jako dvojice MD, Dal se stejnou kladnou
 * částkou (zápornou částku převod už přenesl s prohozenými stranami). Platnou dvojicí
 * není jen řádek, jehož obě strany vede MyÚčto na tomtéž účtu (např. syntetický účet
 * a jeho analytika `000`): na zůstatek účtu nemá vliv, a proto se vynechá a převod ho
 * vykáže. Řádky ostatních dvojic si čísla řádků (`line_no`) nechávají, takže na ně dál
 * sedí dimenze, měny a další vazby po řádcích.
 *
 * Firma bez historie formy (složený zápis) tudy jde beze změny.
 */
final class SourceJournalPairs
{
    /** @param list<array{valid_from:string,entry_form:string}> $history */
    private function __construct(private readonly array $history) {}

    /** Historie formy firmy načtená znovu z DB (převod ji mohl právě zapsat). */
    public static function load(EntryFormService $forms, int $supplierId): self
    {
        $forms->forget($supplierId);
        return new self($forms->history($supplierId));
    }

    /** Účtuje firma k datu zápisu jednoduchou formou? */
    public function singleAt(string $date): bool
    {
        return $this->history !== [] && EntryFormService::formFromHistory($this->history, $date)->isSingle();
    }

    /**
     * Řádky zápisu po dvojicích ze zdroje.
     *
     * @param list<array<string,mixed>> $lines řádky po dvou na řádek zdroje (MD, Dal)
     * @param list<?string> $texts text řádku zdroje, indexem řádek zdroje (0, 1, …)
     * @return array{lines:list<array<string,mixed>>,same_account:int}
     */
    public static function pair(array $lines, array $texts): array
    {
        $lines = array_values($lines);
        if (count($lines) % 2 !== 0) {
            throw new \LogicException('Řádky převzatého zápisu nejdou po dvou na řádek zdroje.');
        }
        $out = [];
        $pairNo = 0;
        $sameAccount = 0;
        for ($i = 0; $i < count($lines); $i += 2) {
            [$debit, $credit] = [$lines[$i], $lines[$i + 1]];
            if ((int) $debit['account_id'] === (int) $credit['account_id']) {
                $sameAccount++;
                continue;
            }
            $text = trim((string) ($texts[intdiv($i, 2)] ?? ''));
            $pair = ['pair_no' => ++$pairNo, 'line_text' => $text !== '' ? mb_substr($text, 0, 255) : null];
            $out[] = $debit + $pair;
            $out[] = $credit + $pair;
            $violation = JournalPairInvariant::pairViolation($debit, $credit);
            if ($violation !== null) {
                throw new \LogicException('Řádek převzatého zápisu není platná dvojice: ' . $violation);
            }
        }
        return ['lines' => $out, 'same_account' => $sameAccount];
    }
}
