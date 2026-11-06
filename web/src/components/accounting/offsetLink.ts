/**
 * Předvyplnění stránky Zápočty (route `accounting-offsets`) z jiných obrazovek: saldokonto,
 * dialog Vyrovnat, detail vydané a přijaté faktury. Query nese partnera a doklady
 * s částkami: `?partner_id=12&docs=invoice:123:1000,purchase_invoice:456:1000`.
 * Doklad bez částky (`invoice:123`) se předvyplní celým zbytkem.
 */

export type OffsetDocType = 'invoice' | 'purchase_invoice'

export interface OffsetPrefillDoc {
  doc_type: OffsetDocType
  doc_id: number
  /** null = celý zbytek dokladu. */
  amount: number | null
}

/** Strop dokladů v jednom odkazu (délka URL). */
export const MAX_PREFILL_DOCS = 50

export function offsetRoute(partnerId: number, docs: OffsetPrefillDoc[]) {
  const query: Record<string, string> = { partner_id: String(partnerId) }
  const list = docs.slice(0, MAX_PREFILL_DOCS)
    .map(d => d.amount === null ? `${d.doc_type}:${d.doc_id}` : `${d.doc_type}:${d.doc_id}:${(Math.round(d.amount * 100) / 100).toFixed(2)}`)
  if (list.length > 0) query.docs = list.join(',')
  return { name: 'accounting-offsets', query }
}

/** Rozebere `docs` z query; neplatné položky přeskočí. */
export function parseOffsetDocs(raw: unknown): OffsetPrefillDoc[] {
  if (typeof raw !== 'string' || raw === '') return []
  const out: OffsetPrefillDoc[] = []
  for (const part of raw.split(',').slice(0, MAX_PREFILL_DOCS)) {
    const [type, id, amount] = part.split(':')
    const docId = Number(id)
    if ((type !== 'invoice' && type !== 'purchase_invoice') || !Number.isInteger(docId) || docId <= 0) continue
    const value = amount === undefined || amount === '' ? null : Number(amount)
    if (value !== null && !(Number.isFinite(value) && value > 0)) continue
    out.push({ doc_type: type, doc_id: docId, amount: value === null ? null : Math.round(value * 100) / 100 })
  }
  return out
}

export function parsePartnerId(raw: unknown): number | null {
  const id = Number(typeof raw === 'string' ? raw : NaN)
  return Number.isInteger(id) && id > 0 ? id : null
}

/**
 * Zápočet musí být vyrovnaný: součet pohledávek = součet závazků (Offsets ho jinak
 * nepotvrdí). Obě strany se proto předvyplní menší z obou sum, rozdělenou mezi doklady
 * strany v pořadí, každému nejvýš jeho zbytek.
 */
export function balancedOffsetDocs(
  receivables: { doc_id: number; remaining: number }[],
  payables: { doc_id: number; remaining: number }[],
): OffsetPrefillDoc[] {
  const cents = (v: number) => Math.round(v * 100)
  const total = Math.min(
    receivables.reduce((s, r) => s + cents(r.remaining), 0),
    payables.reduce((s, r) => s + cents(r.remaining), 0),
  )
  const side = (rows: { doc_id: number; remaining: number }[], type: OffsetDocType): OffsetPrefillDoc[] => {
    let left = total
    const out: OffsetPrefillDoc[] = []
    for (const r of rows) {
      const take = Math.max(0, Math.min(left, cents(r.remaining)))
      left -= take
      if (take > 0) out.push({ doc_type: type, doc_id: r.doc_id, amount: take / 100 })
    }
    return out
  }
  return total > 0 ? [...side(receivables, 'invoice'), ...side(payables, 'purchase_invoice')] : []
}
