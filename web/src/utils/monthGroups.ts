interface MonthGroupLike {
  month: string
  count: number
  totals_per_currency: unknown[]
  invoices: Array<{ id: number }>
}

/**
 * Připojí další stránku seznamu seskupeného po měsících. Počet a součty hlavičky
 * měsíce posílá server vždy za CELÝ měsíc, ne za načtené řádky (#392), takže se
 * nesčítají: nejnovější odpověď je přepíše. Řádky se připojí bez duplicit.
 */
export function mergeMonthGroups<G extends MonthGroupLike>(existing: G[], incoming: G[]): G[] {
  const byMonth = new Map<string, G>()
  for (const g of existing) byMonth.set(g.month, { ...g, invoices: [...g.invoices] })
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, { ...g, invoices: [...g.invoices] })
      continue
    }
    const seenIds = new Set(cur.invoices.map(i => i.id))
    for (const inv of g.invoices) if (!seenIds.has(inv.id)) cur.invoices.push(inv)
    cur.count = g.count
    cur.totals_per_currency = g.totals_per_currency
  }
  return Array.from(byMonth.values())
}
