import type { NumberSeriesKind } from '@/api/numberSeries'

type Translate = (key: string, params?: Record<string, unknown>) => string

/**
 * Popisek řady: vlastní název, jinak druh dokladu (převedená řada název nemá, popisek
 * se skládá z překladu druhu a případně pokladny, klienta, kategorie nebo účtu).
 */
export function numberSeriesLabel(
  t: Translate,
  s: { doc_kind: NumberSeriesKind; name: string; label: string; storage: 'legacy' | 'native'; scope_type?: string },
): string {
  if (s.storage === 'native' || s.name.trim() !== '') return s.name || s.label
  const kind = t(`number_series.kinds.${s.doc_kind}`)
  if (s.label === '') return kind
  const scope = s.scope_type ? t(`number_series.scopes.${s.scope_type}`) : ''
  return scope !== '' ? `${kind} - ${scope} ${s.label}` : `${kind} - ${s.label}`
}
