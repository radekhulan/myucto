import type { BulkDocumentDimensionsResult } from '@/api/dimensions'

export type BulkDimensionMode = 'keep' | 'set' | 'remove'

export interface BulkDimensionChoice {
  mode: BulkDimensionMode
  valueId: number | null
}

/**
 * Tělo hromadné změny: nastavovaný typ nese hodnotu, odebíraný `null`, ponechaný
 * v mapě chybí. `null` znamená, že volba není kompletní (Nastavit bez hodnoty) nebo
 * se nic nemění.
 */
export function bulkDimensionsPayload(choices: Record<number, BulkDimensionChoice>): Record<number, number | null> | null {
  const out: Record<number, number | null> = {}
  for (const [typeId, choice] of Object.entries(choices)) {
    if (choice.mode === 'set') {
      if (!choice.valueId) return null
      out[Number(typeId)] = choice.valueId
    } else if (choice.mode === 'remove') {
      out[Number(typeId)] = null
    }
  }
  return Object.keys(out).length > 0 ? out : null
}

/** Souhrn „X upraveno, Y přeskočeno" s důvody přeskočených dokladů. */
export function bulkDimensionsSummary(
  result: BulkDocumentDimensionsResult,
  t: (key: string, params?: Record<string, unknown>) => string,
): string {
  const lines = [t('dimensions.bulk_summary', {
    updated: result.updated.length,
    unchanged: result.unchanged.length,
    skipped: result.skipped.length,
  })]
  if (result.needs_repost.length > 0) {
    lines.push(t('dimensions.bulk_needs_repost', { n: result.needs_repost.length }))
  }
  for (const s of result.skipped) {
    lines.push(`${s.varsymbol || `#${s.id}`}: ${s.message}`)
  }
  return lines.join('\n')
}
