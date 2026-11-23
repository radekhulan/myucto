/** Chyba z API (`error` v odpovědi): kód, pole formuláře, hláška serveru a případné neznámé znaky. */
export interface ScheduleTitleError {
  code?: string
  field?: string
  message?: string
  tokens?: string[]
}

type Translate = (key: string, params?: Record<string, unknown>) => string

/**
 * Hláška chyby šablony názvu opakování v jazyce UI: známé kódy překládá klient
 * (server píše česky), neznámé ukáže, jak přišly.
 */
export function scheduleTitleErrorText(e: ScheduleTitleError, t: Translate, te: (key: string) => boolean): string {
  const key = `other_items.plans.title_errors.${(e.code || '').replace(/^other_items\.error\./, '')}`
  if (e.code && te(key)) return t(key, { tokens: (e.tokens || []).join(', ') })
  return e.message || t('common.error')
}
