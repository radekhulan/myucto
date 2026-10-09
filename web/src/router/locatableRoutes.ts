/**
 * Detailové routy, jejichž záznam patří jedné firmě. Klíč = jméno routy, hodnota =
 * typ pro `GET /api/locate/{type}/{id}` a odkud vzít id (parametr cesty, u deníku query).
 */
export const LOCATABLE: Record<string, { type: string, query?: string }> = {
  'invoice-detail': { type: 'invoice' },
  'invoice-edit': { type: 'invoice' },
  'purchase-invoice-detail': { type: 'purchase_invoice' },
  'purchase-invoice-edit': { type: 'purchase_invoice' },
  'document-detail': { type: 'document' },
  'other-item-detail': { type: 'other_item' },
  'other-item-edit': { type: 'other_item' },
  'accounting-cash-edit': { type: 'cash_document' },
  'stock-document-detail': { type: 'stock_document' },
  'stock-sales-order-detail': { type: 'sales_order' },
  'stock-purchase-order-detail': { type: 'purchase_order' },
  'bank-detail': { type: 'bank_statement' },
  'accounting-journal': { type: 'journal_entry', query: 'entry_id' },
}

export function isLocatableRoute(name: unknown): boolean {
  return typeof name === 'string' && Object.prototype.hasOwnProperty.call(LOCATABLE, name)
}

const MANUAL_SWITCH_KEY = 'myinvoice.manual_supplier_switch'
let manualSwitchInProgress = false

/**
 * Ruční přepnutí firmy musí vyhrát nad odkazem na doklad. Během přepnutí (změna
 * firmy přehodnotí aktuální routu v `WorkspaceHost`) a v první navigaci po
 * přenačtení stránky se proto firma podle dokladu nepřepíná.
 */
export function beginManualSupplierSwitch(): void {
  manualSwitchInProgress = true
  try { sessionStorage.setItem(MANUAL_SWITCH_KEY, '1') } catch { /* bez úložiště stačí příznak v paměti */ }
}

export function abortManualSupplierSwitch(): void {
  manualSwitchInProgress = false
  try { sessionStorage.removeItem(MANUAL_SWITCH_KEY) } catch { /* ignore */ }
}

/** `true` = automatické přepnutí z odkazu se teď nesmí spustit; příznak po přenačtení spotřebuje. */
export function consumeManualSupplierSwitch(): boolean {
  if (manualSwitchInProgress) return true
  try {
    if (sessionStorage.getItem(MANUAL_SWITCH_KEY) === null) return false
    sessionStorage.removeItem(MANUAL_SWITCH_KEY)
    return true
  } catch {
    return false
  }
}
