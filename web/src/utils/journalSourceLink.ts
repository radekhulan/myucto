import type { RouteLocationRaw } from 'vue-router'

/**
 * Cíl drill-down odkazu na PRVOTNÍ DOKLAD zaúčtovaného zápisu.
 *
 * Sdílený SSOT pro deník (Journal.vue), opis účtu (AccountStatement.vue) i rozpad
 * měsíce v hlavní knize — mapování source_type → routa žilo předtím jen v deníku,
 * takže z opisu účtu se dalo prokliknout na fakturu, ale banka, pokladna, majetek
 * ani zápočet nikam nevedly.
 *
 * Banka: `source_id` je bank_transactions.id, ale detail-stránka (`bank-detail`) čeká
 * ID VÝPISU — proto BE-obohacené `source_statement_id`. Pokladna: nemá samostatný
 * detail, jen seznam (`accounting-cash`) filtrovatelný přes `q` (prefix na doc_number).
 * Majetek: 'asset'/'asset_disposal' mají source_id = ID karty, 'depreciation' má ID
 * řádku depreciation_entries → BE dopočítá `source_asset_id`. Zápočet: source_id je
 * ID zápočtu, proklik vede na vyrovnaný doklad (`source_settlement_doc_*`).
 */
export interface JournalSourceRef {
  source_type: string
  source_id?: number | null
  source_statement_id?: number | null
  source_doc_number?: string | null
  source_register_id?: number | null
  source_asset_id?: number | null
  source_settlement_doc_type?: string | null
  source_settlement_doc_id?: number | null
}

export function journalSourceLink(entry: JournalSourceRef): RouteLocationRaw | null {
  if (entry.source_type === 'invoice' && entry.source_id) {
    return { name: 'invoice-detail', params: { id: entry.source_id } }
  }
  if (entry.source_type === 'purchase_invoice' && entry.source_id) {
    return { name: 'purchase-invoice-detail', params: { id: entry.source_id } }
  }
  if (entry.source_type === 'bank' && entry.source_statement_id) {
    return { name: 'bank-detail', params: { id: entry.source_statement_id } }
  }
  if (entry.source_type === 'gopay') {
    return { name: 'gopay' }
  }
  if (entry.source_type === 'cash' && entry.source_doc_number) {
    return {
      name: 'accounting-cash',
      query: {
        ...(entry.source_register_id ? { register_id: String(entry.source_register_id) } : {}),
        q: entry.source_doc_number,
      },
    }
  }
  if ((entry.source_type === 'asset' || entry.source_type === 'asset_disposal') && entry.source_id) {
    return { name: 'accounting-asset-detail', params: { id: entry.source_id } }
  }
  if (entry.source_type === 'depreciation' && entry.source_asset_id) {
    return { name: 'accounting-asset-detail', params: { id: entry.source_asset_id } }
  }
  if (entry.source_type === 'settlement' && entry.source_settlement_doc_id) {
    return entry.source_settlement_doc_type === 'invoice'
      ? { name: 'invoice-detail', params: { id: entry.source_settlement_doc_id } }
      : { name: 'purchase-invoice-detail', params: { id: entry.source_settlement_doc_id } }
  }
  return null
}

/** Odkaz do deníku filtrovaného na konkrétní zápis (fallback, když prvotní doklad nemá stránku). */
export function journalEntryLink(entryId: number): RouteLocationRaw {
  return { name: 'accounting-journal', query: { entry_id: String(entryId) } }
}
