<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Jediná odpověď na otázku „dostal už to úřad?".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to musí být na jednom místě
 * ═══════════════════════════════════════════════════════════════════════════
 * Na tuhle otázku se ptají čtyři různá místa a každé z ní vyvozuje něco jiného:
 *
 *   * fronta odchozích podání — smí řádek vůbec ukázat jako „k odeslání"?
 *   * zahození rozdělaného odeslání — smí se podat znovu?
 *   * ruční uzavření — smí účetní prohlásit povinnost za splněnou?
 *   * přehled podání — má u řádku svítit tlačítko?
 *
 * Kdyby si každé z nich sestavilo vlastní podmínku, rozešly by se: fronta by
 * nabízela „zahodit a podat znovu" u zprávy, kterou úřad prokazatelně dostal,
 * a druhé podání téhož by u něj založilo duplicitu, kterou nejde vzít zpět.
 * Přesně to se stalo u přehledu VZP za 08/2026.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co je důkaz a co ne
 * ═══════════════════════════════════════════════════════════════════════════
 * Důkazem je řádek odchozí fronty datové schránky, ne stav podání. Stav
 * `submitted` říká jen tolik, že aplikace odeslání ZAPSALA — může to být
 * i okamžik, kdy zpráva teprve čeká na potvrzení v ISDS.
 *
 * Za doložené doručení se bere:
 *   * `dispatch_state = 'delivered'` — ISDS potvrdila dodání do schránky,
 *   * připnutá doručenka (`receipt_document_id`) — totéž listinně,
 *   * `acceptance_state = 'accepted'` — úřad se už vyjádřil k obsahu.
 *
 * Samotné `sent` důkaz NENÍ: zpráva odešla, ale o dodání se zatím neví. Právě
 * v tom okamžiku ještě dává smysl pokus zahodit a poslat znovu.
 */
final readonly class PayrollSubmissionDeliveryProof
{
    /**
     * Stavy odchozí fronty, které dokládají, že zpráva došla adresátovi.
     *
     * @var list<string>
     */
    public const DELIVERED_DISPATCH_STATES = ['delivered'];

    /**
     * Je doručení DOLOŽENÉ? `null` (podání bez řádku odchozí fronty, typicky
     * VREP) znamená „nevíme", tedy `false` — nikoli „ano".
     *
     * @param array<string,mixed>|null $outbox
     *        řádek z {@see \MyInvoice\Repository\Payroll\PayrollSubmissionRepository::findDispatchOutboxForSubmission()}
     */
    public static function isProven(?array $outbox): bool
    {
        return self::reason($outbox) !== null;
    }

    /**
     * Čím je doručení doložené — kód pro klienta a pro chybovou hlášku.
     *
     * @param array<string,mixed>|null $outbox
     * @return 'delivered'|'receipt'|'accepted'|null
     */
    public static function reason(?array $outbox): ?string
    {
        if ($outbox === null) {
            return null;
        }
        if (in_array(
            (string) ($outbox['dispatch_state'] ?? ''),
            self::DELIVERED_DISPATCH_STATES,
            true,
        )) {
            return 'delivered';
        }
        if (($outbox['receipt_document_id'] ?? null) !== null) {
            return 'receipt';
        }
        if ((string) ($outbox['acceptance_state'] ?? '') === 'accepted') {
            return 'accepted';
        }

        return null;
    }

    /**
     * Opustila zpráva aplikaci? Slabší tvrzení než {@see self::isProven()} —
     * `sent` sem patří, protože ISDS zprávu převzala, i když o jejím dodání
     * ještě nepřišla zpráva.
     *
     * @param array<string,mixed>|null $outbox
     */
    public static function hasLeftApplication(?array $outbox): bool
    {
        return $outbox !== null && in_array(
            (string) ($outbox['dispatch_state'] ?? ''),
            ['sent', 'delivered'],
            true,
        );
    }

    /**
     * Věta pro účetní, proč se odeslání nedá zahodit.
     *
     * @param array<string,mixed>|null $outbox
     */
    public static function abandonBlockedReason(?array $outbox): ?string
    {
        return match (self::reason($outbox)) {
            'delivered' => 'Tuhle zprávu už datová schránka doručila adresátovi,'
                . ' takže zahodit a podat znovu ji nelze — druhé podání by'
                . ' u úřadu založilo duplicitu. Pokud je obsah špatně, řeší se'
                . ' to opravným nebo stornovacím podáním.',
            'receipt' => 'K podání je připnutá doručenka, takže je doložené, že'
                . ' zprávu adresát dostal. Zahodit a podat znovu už nejde —'
                . ' druhé podání by u úřadu založilo duplicitu.',
            'accepted' => 'Úřad se k tomuhle podání už vyjádřil, takže ho nelze'
                . ' zahodit a podat znovu. Opravu řešte opravným podáním.',
            default => null,
        };
    }
}
