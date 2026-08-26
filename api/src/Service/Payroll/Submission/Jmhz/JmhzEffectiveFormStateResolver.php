<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Skládá autoritativní stav formulářů jen z neměnného XML a podepsaných
 * protokolů. Neúplný nebo rozporný důkaz je blocker, nikdy odhad.
 */
final readonly class JmhzEffectiveFormStateResolver
{
    /**
     * @param list<array<string,mixed>> $chain
     * @param list<string> $currentEmploymentExternalIdentifiers
     */
    public function resolve(array $chain, array $currentEmploymentExternalIdentifiers): JmhzEffectiveFormSet
    {
        if ($chain === []) {
            $this->invalid('chain_missing', 'Chybí řádné podání, ze kterého se má stav odvodit.');
        }
        /** @var array<string,JmhzEffectiveFormState> $effective */
        $effective = [];
        $lastSubmissionId = 0;
        foreach ($chain as $index => $submission) {
            $id = $submission['submission_id'] ?? null;
            $type = $submission['submission_type'] ?? null;
            $trusted = $submission['trusted'] ?? null;
            $remoteStatus = $submission['remote_status'] ?? null;
            if (!is_int($id) || $id <= $lastSubmissionId || !is_string($type)) {
                $this->invalid('chain_invalid', 'Řetězec podání JMHZ nemá jednoznačné pořadí.');
            }
            if ($index === 0 && $type !== 'R') {
                $this->invalid('root_invalid', 'Řetězec efektivního stavu musí začínat řádným podáním.');
            }
            if ($index > 0 && !in_array($type, ['O', 'S'], true)) {
                $this->invalid('chain_invalid', 'Návazné podání má nepovolený typ.');
            }
            if ($trusted !== true || !in_array($remoteStatus, ['accepted', 'partially_accepted', 'rejected'], true)) {
                $this->invalid('protocol_untrusted', 'Výsledek podání není doložený úplným podepsaným protokolem ČSSZ.');
            }
            $lastSubmissionId = $id;
            if ($type === 'S') {
                if ($remoteStatus === 'rejected') {
                    continue;
                }
                if ($remoteStatus !== 'accepted') {
                    $this->invalid(
                        'protocol_incomplete',
                        'Částečný výsledek storna celého podání neurčuje, které formuláře zůstaly platné.',
                    );
                }
                foreach ($effective as $employment => $state) {
                    $effective[$employment] = new JmhzEffectiveFormState(
                        $employment,
                        $state->personExternalIdentifier,
                        'cancelled',
                        null,
                        $id,
                    );
                }
                continue;
            }
            $forms = $submission['forms'] ?? null;
            if (!is_array($forms) || !array_is_list($forms) || $forms === []) {
                $this->invalid('forms_missing', 'Podepsaný protokol nedokládá výsledek všech formulářů podání.');
            }
            $seen = [];
            $seenEmployments = [];
            foreach ($forms as $form) {
                if (!is_array($form)) {
                    $this->invalid('form_invalid', 'Výsledek formuláře má neplatný tvar.');
                }
                $guid = strtoupper(trim((string) ($form['form_guid'] ?? '')));
                $formType = $form['form_type'] ?? null;
                $employment = trim((string) ($form['employment_external_identifier'] ?? ''));
                $person = trim((string) ($form['person_external_identifier'] ?? ''));
                $status = $form['remote_status'] ?? null;
                if ($employment === '' && in_array($formType, ['O', 'S'], true)) {
                    foreach ($effective as $candidateEmployment => $candidate) {
                        if ($candidate->formGuid !== null && hash_equals($candidate->formGuid, $guid)) {
                            $employment = $candidateEmployment;
                            break;
                        }
                    }
                }
                if ($guid === '' || $employment === '' || !in_array($formType, ['R', 'O', 'S'], true)) {
                    $this->invalid('form_invalid', 'Zmrazený formulář nemá úplnou identitu nebo typ.');
                }
                if (isset($seen[$guid])) {
                    $this->invalid('protocol_conflict', 'Podepsaný protokol uvádí pro tentýž GUID rozporný výsledek.');
                }
                $seen[$guid] = true;
                if (isset($seenEmployments[$employment])) {
                    $this->invalid('protocol_conflict', 'Podepsaný protokol uvádí více výsledků pro tentýž pracovní vztah.');
                }
                $seenEmployments[$employment] = true;
                if (!in_array($status, ['accepted', 'rejected'], true)) {
                    $this->invalid('protocol_incomplete', 'Podepsaný protokol neurčuje přijetí nebo odmítnutí formuláře.');
                }
                $previous = $effective[$employment] ?? null;
                if ($formType === 'O') {
                    if ($previous === null || $previous->state !== 'accepted'
                        || $previous->formGuid === null || !hash_equals($previous->formGuid, $guid)
                    ) {
                        $this->invalid('guid_conflict', 'Oprava přijatého formuláře nenavazuje na jeho poslední platný GUID.');
                    }
                    if ($status === 'accepted') {
                        $effective[$employment] = new JmhzEffectiveFormState(
                            $employment,
                            $person !== '' ? $person : $previous->personExternalIdentifier,
                            'accepted',
                            $guid,
                            $id,
                        );
                    }
                    continue;
                }
                if ($formType === 'S') {
                    if ($previous === null || $previous->state !== 'accepted'
                        || $previous->formGuid === null || !hash_equals($previous->formGuid, $guid)
                    ) {
                        $this->invalid('guid_conflict', 'Storno formuláře nenavazuje na jeho poslední platný GUID.');
                    }
                    if ($status === 'accepted') {
                        $effective[$employment] = new JmhzEffectiveFormState(
                            $employment,
                            $person !== '' ? $person : $previous->personExternalIdentifier,
                            'cancelled',
                            null,
                            $id,
                        );
                    }
                    continue;
                }
                if ($status === 'accepted') {
                    if ($index > 0 && $previous?->state === 'accepted') {
                        $this->invalid('replacement_conflict', 'Novým řádným formulářem nelze nahradit již přijatý formulář.');
                    }
                    foreach ($effective as $candidateEmployment => $candidate) {
                        if ($candidateEmployment !== $employment && $candidate->formGuid !== null
                            && hash_equals($candidate->formGuid, $guid)
                        ) {
                            $this->invalid('guid_conflict', 'Nový řádný formulář nesmí znovu použít GUID jiného formuláře.');
                        }
                    }
                    $effective[$employment] = new JmhzEffectiveFormState(
                        $employment,
                        $person !== '' ? $person : null,
                        'accepted',
                        $guid,
                        $id,
                    );
                } elseif ($previous === null || $previous->state !== 'accepted') {
                    $effective[$employment] = new JmhzEffectiveFormState(
                        $employment,
                        $person !== '' ? $person : null,
                        'rejected',
                        null,
                        $id,
                    );
                }
            }
        }

        foreach ($currentEmploymentExternalIdentifiers as $employment) {
            $employment = trim($employment);
            if ($employment === '') {
                $this->invalid('current_set_invalid', 'Aktuální příprava obsahuje vztah bez identifikátoru JMHZ.');
            }
            $effective[$employment] ??= new JmhzEffectiveFormState(
                $employment,
                null,
                'missing',
                null,
                null,
            );
        }
        ksort($effective, SORT_STRING);

        return new JmhzEffectiveFormSet(array_values($effective), $effective);
    }

    private function invalid(string $suffix, string $message): never
    {
        throw new JmhzXmlException('jmhz_effective_state_' . $suffix, $message);
    }
}
