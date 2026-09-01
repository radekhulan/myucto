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
