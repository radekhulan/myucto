<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Vydá bajty artefaktu, na který fronta jen ODKAZUJE.
 *
 * Fronta si obsah nekopíruje schválně: kopie by mohla žít vlastním životem
 * a odeslalo by se něco jiného, než co uživatel v aplikaci vidí. Otisk
 * (`artifact_sha256`) uložený při zařazení proti tomu slouží jako kontrola —
 * když se zdroj mezitím změnil, odeslání se zastaví.
 */
interface SubmissionArtifactResolver
{
    /**
     * @return array{
     *   filename:string,mime:string,bytes:string,
     *   authority?:array{
     *     kind:string,environment:string,agenda_code:string,status:string,
     *     channel:string,artifact_kind:string,direction:string
     *   }
     * }|null
     *         null = artefakt už neexistuje
     */
    public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array;
}
