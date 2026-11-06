import { ref } from 'vue'
import type { PayrollJmhzFreezeBlocker } from '@/api/payroll'

/** Nálezy blokace zmrazení hlášení z odpovědi 409 (`error.details.blockers`). */
export function freezeBlockersFromError(error: unknown): PayrollJmhzFreezeBlocker[] {
  const blockers = (error as { response?: { data?: { error?: { details?: { blockers?: unknown } } } } })
    ?.response?.data?.error?.details?.blockers
  return Array.isArray(blockers) ? blockers as PayrollJmhzFreezeBlocker[] : []
}

/** Návratový odkaz smí vést jen na stránku téže aplikace. */
export function safeReturnPath(value: unknown): string | null {
  const raw = Array.isArray(value) ? value[0] : value
  if (typeof raw !== 'string' || !raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\')) {
    return null
  }
  return raw
}

/** Cíl opravy doplněný o návrat na přípravu JMHZ (`return`). */
export function pathWithReturn(path: string, returnTo: string, year?: number | null): string {
  const [base, hash = ''] = path.split('#', 2) as [string, string?]
  const [pathname, query = ''] = base.split('?', 2) as [string, string?]
  const params = new URLSearchParams(query)
  if (year != null && pathname === '/payroll/annual-settlement' && !params.has('year')) {
    params.set('year', String(year))
  }
  params.set('return', returnTo)
  return `${pathname}?${params.toString()}${hash ? `#${hash}` : ''}`
}

/**
 * Cesta zpět na přípravu JMHZ, když se stránka otevřela odkazem z blokace.
 * Hodnota se zachytí jednou při vzniku stránky, takže přežije, i když stránka
 * později přepíše vlastní query.
 *
 * Čte se z adresy, ne přes `useRoute()`: lišta je na stránkách, jejichž testy
 * router mockují bez téhle funkce, a mimo kontext routeru by stránka spadla.
 */
export function useJmhzReturn(search: string = typeof window === 'undefined' ? '' : window.location.search) {
  const target = ref<string | null>(safeReturnPath(new URLSearchParams(search).get('return')))
  return { returnTarget: target }
}
