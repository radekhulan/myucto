export interface StereoTargetAccount {
  code: string
  name: string
  is_active?: boolean
}

export function normalizeStereoAccountCode(value: string): string {
  const code = value.trim()
  return /^\d{4,}$/.test(code) ? `${code.slice(0, 3)}.${code.slice(3)}` : code
}

/** Kanonický účet má přednost; starý číselný bankovní účet není aliasem nové analytiky. */
export function stereoTargetAccounts(accounts: StereoTargetAccount[]): StereoTargetAccount[] {
  const targets = new Map<string, StereoTargetAccount>()
  for (const account of accounts) {
    const code = normalizeStereoAccountCode(account.code)
    if (code.startsWith('221') && code !== account.code.trim()) continue
    if (!targets.has(code) || account.code.trim() === code) targets.set(code, account)
  }
  return [...targets.values()]
}
