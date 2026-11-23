import { bankApi, type BankStatement } from '@/api/bank'

export function statementDownloadsWithMovements<T extends { transaction_count: number }>(
  s: { evidence_statements?: T[] } | null | undefined,
): T[] {
  return (s?.evidence_statements ?? []).filter(source => source.transaction_count > 0)
}

export function statementClosingBalance(s: BankStatement): number | null {
  return s.curr_balance ?? s.balance_calculation?.confirmed_closing ?? s.balance_calculation?.closing ?? null
}

/**
 * Výpis bez souboru GPC, jehož GPC se skládá ze zůstatků a pohybů (bankovní API,
 * převod z jiného programu, PDF výpis).
 *
 * PDF výpis sem patří ze stejného důvodu jako ostatní: originál je PDF, soubor GPC
 * k němu neexistuje, ale zůstatky i pohyby ano — a účetní GPC chce. Je to navíc ten
 * případ, pro který se import PDF výpisů zaváděl: banky, které GPC vůbec nedávají.
 * Backend export umí (`GpcExportAction`), chybělo jen to, aby UI nabídlo tlačítko.
 */
function isGeneratedGpc(s: BankStatement): boolean {
  return s.source === 'bank_api' || s.source === 'pdf' || (s.source === 'import' && !s.has_file)
}

export function statementHasGpc(s: BankStatement): boolean {
  return s.has_file || isGeneratedGpc(s)
}

export function statementGpcUrl(s: BankStatement): string | undefined {
  if (!isGeneratedGpc(s)) return s.has_file ? bankApi.downloadUrl(s.id) : undefined
  if (s.balance_calculation?.bank_statement_id) return bankApi.downloadUrl(s.balance_calculation.bank_statement_id)
  if (s.balance_calculation?.opening == null || s.balance_calculation?.closing == null) return undefined
  return ['calculated', 'confirmed'].includes(s.balance_calculation?.status ?? '') ? bankApi.gpcExportUrl(s.id) : undefined
}

export function statementGpcTitle(s: BankStatement): string {
  if (!isGeneratedGpc(s)) return 'bank.download_gpc'
  if (!s.balance_calculation?.bank_statement_id && s.balance_calculation?.opening == null && s.balance_calculation?.closing != null) return 'bank.balance_missing_anchor'
  if (!statementGpcUrl(s)) return `bank.balance_${s.balance_calculation?.status ?? 'unavailable'}`
  return s.balance_calculation?.bank_statement_id ? 'bank.download_gpc' : 'bank.gpc_calculated_hint'
}
