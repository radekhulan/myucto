import type { InvoiceOriginalPdfMatch } from '@/api/invoices'

/** Stav řádku hromadného nahrání originálů: výsledek párování, pak výsledek nahrání. */
export type OriginalPdfRowState = InvoiceOriginalPdfMatch['status'] | 'not_pdf' | 'uploaded' | 'failed'

export interface OriginalPdfRow {
  file: File
  match: InvoiceOriginalPdfMatch | null
  state: OriginalPdfRowState
  error?: string
}

/** Strop souborů v jednom náhledu (stejný jako na serveru). */
export const ORIGINAL_PDF_BULK_MAX = 500

export function isPdfFile(file: Pick<File, 'name' | 'type'>): boolean {
  return file.type === 'application/pdf' || /\.pdf$/i.test(file.name)
}

/**
 * Řádky z výběru souborů a odpovědi párování. Párování dostává jen PDF soubory
 * a vrací výsledky ve stejném pořadí, takže se spojují podle pořadí, ne podle
 * názvu (dva soubory mohou mít stejný název z různých složek).
 */
export function buildRows(files: File[], matches: InvoiceOriginalPdfMatch[]): OriginalPdfRow[] {
  let next = 0
  return files.map((file) => {
    if (!isPdfFile(file)) return { file, match: null, state: 'not_pdf' as const }
    const match = matches[next++] ?? null
    return { file, match, state: match?.status ?? 'unmatched' }
  })
}

export function countStates(rows: OriginalPdfRow[]): Record<OriginalPdfRowState, number> {
  const out: Record<OriginalPdfRowState, number> = {
    matched: 0, unmatched: 0, ambiguous: 0, not_pdf: 0, uploaded: 0, failed: 0,
  }
  for (const r of rows) out[r.state]++
  return out
}

export const ORIGINAL_PDF_STATE_BADGE: Record<OriginalPdfRowState, string> = {
  matched: 'bg-primary-100 text-primary-700',
  uploaded: 'bg-success-50 text-success-600',
  unmatched: 'bg-neutral-100 text-neutral-600',
  not_pdf: 'bg-neutral-100 text-neutral-600',
  ambiguous: 'bg-warning-50 text-warning-600',
  failed: 'bg-danger-50 text-danger-600',
}
