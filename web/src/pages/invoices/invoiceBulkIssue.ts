/**
 * Potvrzení hromadného vystavení konceptů. Vystavení ručně vyplněné číslo konceptu
 * ponechá a z číselné řady čísluje jen koncepty bez čísla (IssueInvoiceAction),
 * takže hláška musí rozlišit obě skupiny.
 */
export function bulkIssueConfirmMessage(
  drafts: ReadonlyArray<{ varsymbol?: string | number | null }>,
  t: (key: string, params?: Record<string, unknown>) => string,
): string {
  const n = drafts.length
  const withNumber = drafts.filter(d => String(d.varsymbol ?? '').trim() !== '').length
  const withoutNumber = n - withNumber
  if (withoutNumber === 0) return t('invoice.bulk_issue_confirm_all_manual', { n })
  if (withNumber === 0) return t('invoice.bulk_issue_confirm_all_series', { n })
  return t('invoice.bulk_issue_confirm', { n, withNumber, withoutNumber })
}
