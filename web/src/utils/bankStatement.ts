import { bankApi, type BankStatement } from '@/api/bank'

export function statementClosingBalance(s: BankStatement): number | null {
  return s.curr_balance ?? s.balance_calculation?.confirmed_closing ?? s.balance_calculation?.closing ?? null
}

export function statementGpcUrl(s: BankStatement): string | undefined {
  if (s.source !== 'bank_api') return s.has_file ? bankApi.downloadUrl(s.id) : undefined
  if (s.balance_calculation?.bank_statement_id) return bankApi.downloadUrl(s.balance_calculation.bank_statement_id)
  return ['calculated', 'confirmed'].includes(s.balance_calculation?.status ?? '') ? bankApi.gpcExportUrl(s.id) : undefined
}

export function statementGpcTitle(s: BankStatement): string {
  if (s.source !== 'bank_api') return 'bank.download_gpc'
  if (!statementGpcUrl(s)) return `bank.balance_${s.balance_calculation?.status ?? 'unavailable'}`
  return s.balance_calculation?.bank_statement_id ? 'bank.download_gpc' : 'bank.gpc_calculated_hint'
}
