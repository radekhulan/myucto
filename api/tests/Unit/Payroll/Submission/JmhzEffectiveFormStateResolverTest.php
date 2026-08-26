<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEffectiveFormStateResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzEffectiveFormStateResolverTest extends TestCase
{
    private const A = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEF';
    private const B = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEF0';
    private const C = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEF1';

    public function testAcceptedAmendmentKeepsGuidAndBuildsWholeCompanySet(): void
    {
        $set = (new JmhzEffectiveFormStateResolver())->resolve(
            [
                $this->submission(10, 'R', [
                    $this->form(self::A, 'R', 'PPV-1', 'accepted'),
                    $this->form(self::B, 'R', 'PPV-2', 'accepted'),
                ]),
                $this->submission(11, 'O', [
                    $this->form(self::A, 'O', 'PPV-1', 'accepted'),
                ]),
            ],
            ['PPV-1', 'PPV-2', 'PPV-3'],
        );

        self::assertSame('accepted', $set->forEmployment('PPV-1')->state);
        self::assertSame(self::A, $set->forEmployment('PPV-1')->formGuid);
        self::assertSame('accepted', $set->forEmployment('PPV-2')->state);
        self::assertSame('missing', $set->forEmployment('PPV-3')->state);
        self::assertCount(3, $set->forms);
    }

    public function testRejectedAmendmentPreservesLastAcceptedGuid(): void
    {
        $set = (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'accepted'),
            ]),
            $this->submission(11, 'O', [
                $this->form(self::A, 'O', 'PPV-1', 'rejected'),
            ]),
        ], ['PPV-1']);

        self::assertSame('accepted', $set->forEmployment('PPV-1')->state);
        self::assertSame(self::A, $set->forEmployment('PPV-1')->formGuid);
    }

    public function testRejectedReplacementAndAcceptedStornoRequireNewRegularForm(): void
    {
        $rejected = (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'rejected'),
            ]),
        ], ['PPV-1']);
        self::assertSame('rejected', $rejected->forEmployment('PPV-1')->state);
        self::assertNull($rejected->forEmployment('PPV-1')->formGuid);

        $cancelled = (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'accepted'),
            ]),
            $this->submission(11, 'O', [
                $this->form(self::A, 'S', 'PPV-1', 'accepted'),
            ]),
        ], ['PPV-1']);
        self::assertSame('cancelled', $cancelled->forEmployment('PPV-1')->state);
        self::assertNull($cancelled->forEmployment('PPV-1')->formGuid);
    }

    public function testAcceptedReplacementUsesItsNewGuid(): void
    {
        $set = (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'rejected'),
            ]),
            $this->submission(11, 'O', [
                $this->form(self::C, 'R', 'PPV-1', 'accepted'),
            ]),
        ], ['PPV-1']);

        self::assertSame('accepted', $set->forEmployment('PPV-1')->state);
        self::assertSame(self::C, $set->forEmployment('PPV-1')->formGuid);
    }

    public function testWholeSubmissionCancellationRequiresAnAcceptedTerminalResult(): void
    {
        $root = $this->submission(10, 'R', [
            $this->form(self::A, 'R', 'PPV-1', 'accepted'),
            $this->form(self::B, 'R', 'PPV-2', 'accepted'),
        ]);
        $accepted = (new JmhzEffectiveFormStateResolver())->resolve([
            $root,
            $this->submission(11, 'S', [], remoteStatus: 'accepted'),
        ], ['PPV-1', 'PPV-2']);
        self::assertSame('cancelled', $accepted->forEmployment('PPV-1')->state);
        self::assertSame('cancelled', $accepted->forEmployment('PPV-2')->state);

        $rejected = (new JmhzEffectiveFormStateResolver())->resolve([
            $root,
            $this->submission(11, 'S', [], remoteStatus: 'rejected'),
        ], ['PPV-1', 'PPV-2']);
        self::assertSame('accepted', $rejected->forEmployment('PPV-1')->state);
        self::assertSame(self::A, $rejected->forEmployment('PPV-1')->formGuid);

        try {
            (new JmhzEffectiveFormStateResolver())->resolve([
                $root,
                $this->submission(11, 'S', [], remoteStatus: 'partially_accepted'),
            ], ['PPV-1', 'PPV-2']);
            self::fail('Částečný výsledek storna celého podání nesmí zrušit celý efektivní set.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_effective_state_protocol_incomplete', $exception->validationCode);
        }
    }

    public function testAcceptedRegularCannotReplaceAnAlreadyAcceptedForm(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('přijatý formulář');
        (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'accepted'),
            ]),
            $this->submission(11, 'O', [
                $this->form(self::C, 'R', 'PPV-1', 'accepted'),
            ]),
        ], ['PPV-1']);
    }

    public function testAcceptedReplacementCannotReuseAnotherFormsGuid(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('GUID');
        (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'accepted'),
                $this->form(self::B, 'R', 'PPV-2', 'rejected'),
            ]),
            $this->submission(11, 'O', [
                $this->form(self::A, 'R', 'PPV-2', 'accepted'),
            ]),
        ], ['PPV-1', 'PPV-2']);
    }

    public function testUntrustedIncompleteOrConflictingEvidenceFailsClosed(): void
    {
        foreach ([
            [$this->submission(10, 'R', [$this->form(self::A, 'R', 'PPV-1', 'accepted')], false)],
            [$this->submission(10, 'R', [$this->form(self::A, 'R', 'PPV-1', null)])],
            [$this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'accepted'),
                $this->form(self::A, 'R', 'PPV-1', 'rejected'),
            ])],
        ] as $chain) {
            try {
                (new JmhzEffectiveFormStateResolver())->resolve($chain, ['PPV-1']);
                self::fail('Neautoritativní evidence musí zůstat blokující.');
            } catch (JmhzXmlException $exception) {
                self::assertStringStartsWith('jmhz_effective_state_', $exception->validationCode);
            }
        }
    }

    public function testAmendmentCannotChangeAcceptedFormGuid(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('GUID');
        (new JmhzEffectiveFormStateResolver())->resolve([
            $this->submission(10, 'R', [
                $this->form(self::A, 'R', 'PPV-1', 'accepted'),
            ]),
            $this->submission(11, 'O', [
                $this->form(self::B, 'O', 'PPV-1', 'accepted'),
            ]),
        ], ['PPV-1']);
    }

    /** @param list<array<string,mixed>> $forms @return array<string,mixed> */
    private function submission(
        int $id,
        string $type,
        array $forms,
        bool $trusted = true,
        string $remoteStatus = 'accepted',
    ): array {
        return [
            'submission_id' => $id,
            'submission_type' => $type,
            'trusted' => $trusted,
            'remote_status' => $remoteStatus,
            'forms' => $forms,
        ];
    }

    /** @return array<string,mixed> */
    private function form(
        string $guid,
        string $type,
        string $employment,
        ?string $status,
    ): array {
        return [
            'form_guid' => $guid,
            'form_type' => $type,
            'person_external_identifier' => 'PERSON-' . $employment,
            'employment_external_identifier' => $employment,
            'remote_status' => $status,
        ];
    }
}
