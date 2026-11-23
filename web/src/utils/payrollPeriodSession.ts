const PREFIX = 'myinvoice.payroll-period.'
const PERIOD = /^(20|21)\d{2}-(0[1-9]|1[0-2])$/

/**
 * Naposledy zvolené mzdové období v rámci sezení (`RRRR-MM`), zvlášť pro
 * uživatele a firmu. Drží ho `sessionStorage`, takže ho sdílí hlavní okno
 * i panely pracovní plochy a zavřením záložky prohlížeče zaniká.
 */
export function readPayrollPeriod(scope: string): string | null {
  if (!scope) return null
  try {
    const value = sessionStorage.getItem(`${PREFIX}${scope}`)
    return value !== null && PERIOD.test(value) ? value : null
  } catch {
    return null
  }
}

export function writePayrollPeriod(scope: string, period: string): void {
  if (!scope || !PERIOD.test(period)) return
  try {
    sessionStorage.setItem(`${PREFIX}${scope}`, period)
  } catch {
    // Blokované úložiště nesmí blokovat navigaci.
  }
}

/** Odhlášení ukončí i zapamatované mzdové období, ostatní data relace ponechá. */
export function clearPayrollPeriodSession(): void {
  try {
    for (let i = sessionStorage.length - 1; i >= 0; i--) {
      const key = sessionStorage.key(i)
      if (key?.startsWith(PREFIX)) sessionStorage.removeItem(key)
    }
  } catch {
    // Úložiště nemusí být dostupné ani při odhlášení.
  }
}
