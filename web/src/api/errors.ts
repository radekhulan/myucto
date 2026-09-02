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
