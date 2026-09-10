export interface InvoiceStockAvailabilityRow {
  stock_item_id?: number | null
  warehouse_id?: number | null
}

export interface InvoiceStockAvailabilityGroup {
  warehouseId: number | null
  itemIds: number[]
}

export function invoiceStockAvailabilityKey(stockItemId: number, warehouseId: number | null): string {
  return `${warehouseId ?? 'all'}:${stockItemId}`
}

export function groupInvoiceStockAvailability(
  rows: InvoiceStockAvailabilityRow[],
  defaultWarehouseId: number | null,
): InvoiceStockAvailabilityGroup[] {
  const groups = new Map<number | null, Set<number>>()
  for (const row of rows) {
    if (!row.stock_item_id) continue
    const warehouseId = row.warehouse_id ?? defaultWarehouseId
    const itemIds = groups.get(warehouseId) ?? new Set<number>()
    itemIds.add(row.stock_item_id)
    groups.set(warehouseId, itemIds)
  }
  return [...groups.entries()].map(([warehouseId, itemIds]) => ({
    warehouseId,
    itemIds: [...itemIds],
  }))
}
