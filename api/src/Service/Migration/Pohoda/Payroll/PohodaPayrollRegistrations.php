<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationAttributeDocument;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;

/**
 * Přijaté registrace ČSSZ z PAMICA (`RegZAM`, `RegZAMitems`, {@see PohodaPayrollJmhzReports::registrations()})
 * převzaté PRODUKTOVÝM importem registrací (Mzdy → Importy), stejně jako u PREMIER
 * ({@see \MyInvoice\Service\Migration\Premier\PremierPayrollRegistrations}). Věta uložená po atributech
 * se složí zpátky do REGZEC25 ({@see RegistrationAttributeDocument}) a jde náhledem a zápisem importu,
 * takže z ní vznikne profil přihlášky A1, rezidence, stát a místo narození, pojišťovna, CZ-ISCO a další
 * údaje, které karty PAMICA nenesou.
 *
 * Karty PAMICA zůstávají pro převod jediným zdrojem osob a vztahů ({@see PohodaPayrollJmhzWriter}), věty
 * je jen doplňují:
 *  - věta, která by zakládala osobu nebo vztah, se nepřevezme (vztah převod nezná nebo ho PAMICA vede jinak),
 *  - věta, která by změnila údaj vyplněný z karty (adresa, pohlaví, CZ-ISCO…) nebo ukončila vztah, se nepřevezme
 *    a protokol ji ohlásí s rozdílem; profil přihlášky A1 se přepisuje vždy, nese ho poslední věta,
 *  - neodeslaná registrace a registrace bez přijetí ČSSZ se nepřebírají.
 *
 * Věty jdou po jedné v pořadí vyplnění, každá se plánuje nad stavem evidence po předchozí. Zapsaná věta
 * (i věta beze změny) se zapíše do mapy převodu, takže opakovaný převod nic nezdvojí.
 */
final class PohodaPayrollRegistrations
{
    private const ENVIRONMENT = 'production';
    /** Druh věty PAMICA (`RegZAMitems.RelTyp`) => akce REGZEC: přihláška a registrace trvajícího vztahu 1, odhláška 2. */
    private const ACTIONS = ['1' => '1', '3' => '1', '2' => '2'];
    /** Změny, které věta smí zapsat i přes vyplněný údaj: obsah přihlášky samotné. */
    private const ALWAYS = ['a1_profile'];

    public function __construct(
        private readonly RegistrationImportService $imports,
        private readonly PohodaImportRepository $map,
    ) {}

    /**
     * @param list<array<string,mixed>> $registrations {@see PohodaPayrollJmhzReports::registrations()}
     * @return array{
     *   counts:array<string,int>,
     *   problems:list<array{code:string,text:string,context:array<string,mixed>}>
     * }
     */
    public function import(int $supplierId, ?int $userId, array $registrations, ?int $runId): array
    {
        $counts = [
            'registrations_files' => 0,
            'registrations_files_unaccepted' => 0,
            'registrations_sentences' => 0,
            'registrations_done' => 0,
            'registrations_applied' => 0,
            'registrations_unchanged' => 0,
            'registrations_unmatched' => 0,
            'registrations_differs' => 0,
            'registrations_blocked' => 0,
            'registrations_failed' => 0,
        ];
        $problems = [];
        foreach ($registrations as $registration) {
            if ($registration['kind'] !== 'registration') {
                continue;
            }
            $counts['registrations_files']++;
            if (!$registration['sent'] || !PohodaPayrollJmhzReports::accepted($registration)) {
                $counts['registrations_files_unaccepted']++;
                continue;
            }
            foreach ($registration['items'] as $position => $item) {
                $counts['registrations_sentences']++;
                $key = $registration['source_key'] . ':' . $item['item_id'];
                if ($this->map->get($supplierId, PohodaImportRepository::KIND_PAYROLL_REGISTRATION, $key) !== null) {
                    $counts['registrations_done']++;
                    continue;
                }
                $sequence = (string) ($item['item']['Sqnr'] ?? '') !== '' ? (string) $item['item']['Sqnr'] : (string) ($position + 1);
                $reference = "registrace {$registration['id']} věta {$sequence}";
                $context = ['registration' => $registration['id'], 'sentence' => $sequence, 'personal_key' => $item['person_key']];
                $files = [['name' => "pamica-regzec-{$registration['id']}-{$sequence}.xml", 'content_base64' => base64_encode(self::xml($item, $sequence))]];
                try {
                    $preview = $this->imports->preview($supplierId, self::ENVIRONMENT, $files);
                    $record = $preview['records'][0] ?? null;
                    if ($record === null) {
                        $counts['registrations_failed']++;
                        $problems[] = ['code' => 'registration_unreadable', 'context' => $context,
                            'text' => "Podání ČSSZ z PAMICA, {$reference}: věta se nedala přečíst - " . (string) ($preview['files'][0]['error'] ?? 'bez popisu') . '.'];
                        continue;
                    }
                    if ($record['blocker'] !== null) {
                        $counts['registrations_blocked']++;
                        $problems[] = ['code' => 'registration_blocked', 'context' => $context,
                            'text' => "Podání ČSSZ z PAMICA, {$reference}: věta se nezapsala - " . (string) $record['blocker']];
                        continue;
                    }
                    if (in_array($record['operation'], ['create_person', 'create_employment'], true)) {
                        $counts['registrations_unmatched']++;
                        $problems[] = ['code' => 'registration_unmatched', 'context' => $context,
                            'text' => "Podání ČSSZ z PAMICA, {$reference}: věta neodpovídá žádnému převzatému vztahu (PAMICA ho eviduje s jiným "
                                . 'nástupem nebo druhem, nebo ho převod nezaložil), proto se nepřevzala a nevznikl druhý vztah téže osoby.'];
                        continue;
                    }
                    if ($record['selectable'] !== true) {
                        $counts['registrations_unchanged']++;
                        $this->remember($supplierId, $key, $record, $runId);
                        continue;
                    }
                    $differs = self::differences($record);
                    if ($differs !== []) {
                        $counts['registrations_differs']++;
                        $problems[] = ['code' => 'registration_differs', 'context' => $context + ['fields' => array_keys($differs)],
                            'text' => "Podání ČSSZ z PAMICA, {$reference}: věta se liší od karty PAMICA (" . implode('; ', $differs) . '). '
                                . 'Převod bere kartu, věta se nepřevzala. Pokud platí údaj z registrace, opravte ho na kartě zaměstnance.'];
                        continue;
                    }
                    $applied = $this->imports->apply(
                        $supplierId,
                        self::ENVIRONMENT,
                        $files,
                        [(string) $record['key']],
                        true,
                        null,
                        $userId,
                        null,
                        null,
                        autoApproveChanges: true,
                    );
                } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
                    $counts['registrations_failed']++;
                    $problems[] = ['code' => 'registration_failed', 'context' => $context,
                        'text' => "Podání ČSSZ z PAMICA, {$reference}: věta se nezapsala - {$e->getMessage()}"];
                    continue;
                }
                $result = $applied['results'][0] ?? null;
                $status = is_array($result) ? (string) $result['status'] : 'failed';
                if ($status === 'applied') {
                    $counts['registrations_applied']++;
                    $this->remember($supplierId, $key, $result, $runId);
                    continue;
                }
                $counts[$status === 'skipped' ? 'registrations_blocked' : 'registrations_failed']++;
                $problems[] = ['code' => $status === 'skipped' ? 'registration_blocked' : 'registration_failed', 'context' => $context,
                    'text' => "Podání ČSSZ z PAMICA, {$reference}: věta se nezapsala - " . (is_array($result) ? (string) ($result['message'] ?? '') : 'bez výsledku') . '.'];
            }
        }

        return ['counts' => $counts, 'problems' => $problems];
    }

    /** @param array<string,mixed> $item věta z {@see PohodaPayrollJmhzReports::registrations()} */
    public static function xml(array $item, string $sequence): string
    {
        $derived = [RegistrationAttributeDocument::SENTENCE => $sequence];
        if (isset(self::ACTIONS[(string) $item['type']])) {
            $derived[RegistrationAttributeDocument::ACTION] = self::ACTIONS[(string) $item['type']];
        }
        $created = (string) ($item['item']['DatCreate'] ?? '');
        if ($created !== '') {
            $derived[RegistrationAttributeDocument::PREPARED_ON] = substr($created, 0, 10);
        }

        return (string) RegistrationAttributeDocument::sentence($item['attributes'], $derived)->saveXML();
    }

    /**
     * Změny věty, které by přepsaly údaj z karty nebo ukončily vztah: pole => popis.
     *
     * @param array<string,mixed> $record
     * @return array<string,string>
     */
    private static function differences(array $record): array
    {
        $out = [];
        if ($record['operation'] === 'terminate') {
            $out['end_on'] = 'věta ukončuje vztah';
        }
        foreach ($record['changes'] ?? [] as $change) {
            $field = (string) ($change['field'] ?? '');
            $current = $change['current'] ?? null;
            if (in_array($field, self::ALWAYS, true) || $current === null || $current === '') {
                continue;
            }
            $out[$field] = (string) ($change['label'] ?? $field);
        }

        return $out;
    }

    /** @param array<string,mixed> $target */
    private function remember(int $supplierId, string $key, array $target, ?int $runId): void
    {
        $id = $target['employment_id'] ?? $target['match']['employment_id'] ?? $target['employee_id'] ?? $target['match']['employee_id'] ?? null;
        $this->map->put($supplierId, PohodaImportRepository::KIND_PAYROLL_REGISTRATION, $key, is_numeric($id) ? (int) $id : 1, $runId);
    }
}
