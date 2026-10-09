import type { InboxCategory, InboxCategoryCode } from '@/api/dataBox'

type Translate = (key: string) => string

/** Název kategorie: vlastní název, jinak překlad systémového kódu. */
export function inboxCategoryLabel(
  category: Pick<InboxCategory, 'code' | 'name'> | null | undefined,
  t: Translate,
): string {
  if (!category) return '—'
  if (category.name) return category.name
  return category.code ? t(`databox.inboxBrowse.system.${category.code}`) : '—'
}

/**
 * Barva odznaku podle druhu odesílatele. Úřady, u kterých běží lhůty, jsou
 * výraznější než obchodní a systémová pošta; vlastní kategorie mají neutrální
 * tón, protože o jejich významu aplikace nic neví.
 */
export function inboxCategoryTone(code: InboxCategoryCode | null | undefined): string {
  switch (code) {
    case 'tax_office':
      return 'bg-warning-50 text-warning-800 dark:bg-warning-900/30 dark:text-warning-200'
    case 'courts_enforcement':
      return 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200'
    case 'social_security':
    case 'health_insurance':
    case 'public_authority':
      return 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
    case 'business_partners':
      return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    default:
      return 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300'
  }
}
