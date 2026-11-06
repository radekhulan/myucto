/**
 * Saldokontní, daňové a peněžní účty, které nesmí stát na položce dokladu jako protiúčet
 * výnosu nebo nákladu. Zrcadlo backendového SSOT `SettlementAccounts::PREFIXES`.
 */
export const SETTLEMENT_ACCOUNT_PREFIXES = ['311', '321', '314', '324', '325', '33', '34', '221', '211'] as const

export function isSettlementAccount(code: string): boolean {
  const trimmed = code.trim()
  return SETTLEMENT_ACCOUNT_PREFIXES.some(prefix => trimmed.startsWith(prefix))
}
