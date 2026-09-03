/**
 * Sjednocené formátování chybové hlášky z axios error response.
 *
 * Backend formát:
 *   { error: { code, message, fields?: { "items.0.description": ["Popis je povinný"], ... } } }
 *
 * Funkce vrátí:
 *   - "message" pokud žádné field errors nejsou
 *   - "message: <field1>; <field2>; ..." s deduplicí
 *
 * Tím uživatel vidí konkrétní pole, ne jen generické "Validace selhala".
 */
/**
 * Strojový kód chyby z téže odpovědi.
 *
 * Hláška je pro člověka a mění se s překladem; kód je stabilní, takže jen podle
 * něj smí obrazovka rozhodnout, co uživateli nabídne dál (třeba odkaz tam, kde
 * se chybějící údaj doplňuje). Chyba bez odpovědi (síť, timeout) kód nemá.
 */
export function apiErrorCode(err: any): string {
  const code = err?.response?.data?.error?.code
  return typeof code === 'string' ? code : ''
}

/**
 * Uzavřený mzdový rok — kam se jde odemknout.
 *
 * Uzávěrka blokuje zápis napříč agendami: nepřítomnosti, exekuce, závazky,
 * podání i mzdové běhy. Hláška o tom uměla jen říct, který rok je uzavřený,
 * a poradit „rok nejprve znovu otevřete" — účetní pak hledala po záložkách,
 * kde se to dělá. Roční uzávěrka je přitom úkon jednou za rok, takže cestu
 * tam si nikdo nepamatuje.
 *
 * Vrací cíl prokliku na Roční uzávěrku mezd s předvyplněným rokem, nebo
 * `null`, když o uzavřený rok nejde. Rok posílá server v `error.year`;
 * starší odpověď bez něj proklik nezruší, jen nechá panel na jeho výchozím
 * roce.
 */
export function yearClosedTarget(err: any):
  | { name: 'payroll-dashboard', query: { yearCloseYear: string }, hash: '#payroll-year-close' }
  | { name: 'payroll-dashboard', hash: '#payroll-year-close' }
  | null {
  if (apiErrorCode(err) !== 'payroll_year_closed') return null
  const year = err?.response?.data?.error?.year
  return Number.isInteger(year)
    ? {
        name: 'payroll-dashboard',
        query: { yearCloseYear: String(year) },
        hash: '#payroll-year-close',
      }
    : { name: 'payroll-dashboard', hash: '#payroll-year-close' }
}

/**
 * Chybějící účetní období — kam se jde založit.
 *
 * Hláška uměla říct jen „pro datum X neexistuje účetní období", případně poslat
 * do sekce menu, která se tak nejmenuje („Účetnictví → Období"). Skutečné místo
 * je položka menu **Uzávěrka** (routa `/accounting/periods`) — tam to ale nikdo
 * nehledá, protože „uzávěrka" zní jako konec roku, ne jako jeho otevření.
 *
 * Vrací cíl prokliku s předvyplněným rokem (server ho posílá v `error.fiscal_year`,
 * viz `PostingService::noPeriodException`); odpověď bez roku proklik nezruší, jen
 * nechá stránku na jejím výchozím stavu. `period_missing` je táž věc hlášená
 * odpisy majetku u hospodářského roku.
 */
export const ACCOUNTING_PERIOD_MISSING_CODES = ['no_accounting_period', 'period_missing'] as const

/** Cíl prokliku na Uzávěrku, volitelně s předvyplněným rokem k založení. */
export function accountingPeriodRoute(fiscalYear?: number | null):
  | { name: 'accounting-periods', query: { fiscal_year: string } }
  | { name: 'accounting-periods' } {
  return Number.isInteger(fiscalYear)
    ? { name: 'accounting-periods', query: { fiscal_year: String(fiscalYear) } }
    : { name: 'accounting-periods' }
}

export function accountingPeriodTarget(err: any):
  | { name: 'accounting-periods', query: { fiscal_year: string } }
  | { name: 'accounting-periods' }
  | null {
  const code = apiErrorCode(err)
  if (!(ACCOUNTING_PERIOD_MISSING_CODES as readonly string[]).includes(code)) return null
  return accountingPeriodRoute(err?.response?.data?.error?.fiscal_year)
}

export function apiErrorMessage(err: any, fallback = 'Operace selhala'): string {
  const data = err?.response?.data?.error
  if (!data) return err?.message || fallback

  const base = (typeof data.message === 'string' && data.message) || fallback

  if (data.fields && typeof data.fields === 'object') {
    const seen = new Set<string>()
    for (const fieldErrs of Object.values(data.fields) as any[]) {
      if (Array.isArray(fieldErrs)) {
        for (const m of fieldErrs) {
          if (typeof m === 'string' && m.length > 0) seen.add(m)
        }
      } else if (typeof fieldErrs === 'string' && fieldErrs.length > 0) {
        seen.add(fieldErrs)
      }
    }
    if (seen.size > 0) return `${base}: ${[...seen].join('; ')}`
  }

  return base
}
