import type { JournalDescriptionParts } from '@/api/accounting'

const SEPARATOR = ' — '

/**
 * Popis zápisu v seznamu deníku bez údajů, které už ukazuje zapnutý sloupec.
 *
 * Uložený popis skládá JournalDescriptionBuilder jako „doklad — firma (VS) — text".
 * Číslo dokladu je vidět ve sloupcích Doklad a Zdroj, firma a VS ve svých sloupcích.
 * Vynechává se jen to, co je na řádku jinde, takže se nic neztratí. Celý popis zůstává
 * v tooltipu a v detailu zápisu. Bez rozkladu nebo bez sloupce s číslem dokladu se
 * ukáže popis beze změny.
 */
export function compactJournalDescription(
  description: string | null | undefined,
  parts: JournalDescriptionParts | null | undefined,
  isVisible: (column: string) => boolean,
): string {
  const full = description ?? ''
  if (!parts || !(isVisible('document_no') || isVisible('source'))) return full

  const out: string[] = []
  if (parts.kind) out.push(parts.kind)
  const showVs = parts.vs && !isVisible('variable_symbol')
  if (parts.partner && !isVisible('partner_name')) {
    out.push(showVs ? `${parts.partner} (VS ${parts.vs})` : parts.partner)
  } else if (showVs) {
    out.push(`VS ${parts.vs}`)
  }
  if (parts.text) out.push(parts.text)
  return out.join(SEPARATOR)
}
