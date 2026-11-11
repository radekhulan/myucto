import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { payrollWorkingPeriod } from '@/pages/payroll/payrollComponentsUi'
import { readPayrollPeriod, writePayrollPeriod } from '@/utils/payrollPeriodSession'
import { getWindowSupplierScope } from '@/workspace/supplierContext'

/*
 * Výchozí období mzdových obrazovek u firmy, která mzdy převzala z předchozího
 * programu.
 *
 * Obrazovky bez `?period=` otevírají předchozí kalendářní měsíc (v září se
 * dělá srpen). U firmy, která v MyÚčtu počítá mzdy od září, je ale srpen měsíc
 * předchozího programu: Měsíční přehled, JMHZ, docházka i rychlý vstup se
 * otevřely na převzatém měsíci, ve kterém se nic dělat nedá, a účetní musela
 * každou stránku přepínat ručně. Výchozí je proto první měsíc vedení mezd,
 * pokud je pozdější než zpracovávaný měsíc.
 *
 * Přednost před obojím má období, které si účetní v tomhle sezení sama zvolila.
 * Stránky drží období v adrese (`?period=`), jenže položky menu ho nenesou:
 * po práci na lednu 2027 se běhy otevřely na prosinci 2026 a přehled podání
 * na říjnu. Strážce si proto pamatuje poslední výslovné období kterékoli
 * z těchto stránek a doplní ho tam, kde adresa žádné nemá. Odkaz s `remember=0`
 * zapamatované období nemění (jednorázový proklik do jiného měsíce).
 *
 * Dokud si uživatel období nezvolil, platí výchozí období podle toho, na čem
 * firma pracuje (viz `defaultPeriod`): rozpracovaný měsíc zná server.
 */
const ROUTES = new Set([
  'payroll-quick-inputs',
  'payroll-time',
  'payroll-documents',
  'payroll-runs',
  'payroll-payments',
  'payroll-submissions',
  'payroll-submissions-tab',
])

/** Stránky, kde se měsíc připravuje: výchozí je první měsíc bez schválené mzdy. */
const WORK_ROUTES = new Set(['payroll-quick-inputs', 'payroll-time', 'payroll-runs'])
/** Stránky nad výsledkem: výchozí je poslední zpracovaný měsíc. */
const RESULT_ROUTES = new Set(['payroll-documents', 'payroll-payments'])

const PERIOD = /^\d{4}-(0[1-9]|1[0-2])$/

export function startAwarePayrollPeriod(startPeriod: string | null, date = new Date()): string {
  const working = payrollWorkingPeriod(date)
  return startPeriod !== null && PERIOD.test(startPeriod) && startPeriod > working
    ? startPeriod
    : working
}

/**
 * Na kterém měsíci firma pracuje, jak ho zná server (`/payroll/capabilities`):
 * začátek vedení mezd, první měsíc bez schválené mzdy a poslední zpracovaný.
 */
interface PayrollPeriodDefaults {
  start: string | null
  firstOpen: string | null
  lastProcessed: string | null
}

/*
 * Rozpracovaný měsíc se mění schválením běhu, proto odpověď stárne rychle:
 * po půl minutě se strážce zeptá znovu. Do té doby drží jednu odpověď pro
 * sérii kliknutí v menu, aby každé nestálo vlastní požadavek.
 */
const CACHE_TTL_MS = 30_000
const cache = new Map<number, { at: number, pending: Promise<PayrollPeriodDefaults> }>()

function validPeriod(value: unknown): string | null {
  return typeof value === 'string' && PERIOD.test(value) ? value : null
}

function periodDefaults(supplierId: number): Promise<PayrollPeriodDefaults> {
  const cached = cache.get(supplierId)
  if (cached !== undefined && Date.now() - cached.at < CACHE_TTL_MS) return cached.pending
  const pending = api.get<{
    state?: { start_period?: string | null }
    periods?: { first_open?: string | null, last_processed?: string | null }
  }>('/payroll/capabilities')
    .then(response => ({
      start: response.data.state?.start_period ?? null,
      firstOpen: validPeriod(response.data.periods?.first_open),
      lastProcessed: validPeriod(response.data.periods?.last_processed),
    }))
    .catch(() => {
      cache.delete(supplierId)
      return { start: null, firstOpen: null, lastProcessed: null }
    })
  cache.set(supplierId, { at: Date.now(), pending })
  return pending
}

export function resetPayrollStartPeriodCache(): void {
  cache.clear()
  injected = null
}

/**
 * Výchozí období stránky, dokud si uživatel v sezení nezvolil vlastní.
 *
 * Kde se měsíc PŘIPRAVUJE (běhy, měsíční vstupy, docházka), je to první měsíc
 * bez schválené mzdy: firma, která v listopadu zpracovává únor, se dřív
 * otevírala na říjnu. Kde se pracuje s VÝSLEDKEM (dokumenty, platby), je to
 * poslední zpracovaný měsíc, a není-li žádný, rozpracovaný. Podání si první
 * nesplněný měsíc navrhují sama; těm zůstává jen začátek vedení mezd.
 */
function defaultPeriod(routeName: string, defaults: PayrollPeriodDefaults): string {
  if (WORK_ROUTES.has(routeName) && defaults.firstOpen !== null) return defaults.firstOpen
  if (RESULT_ROUTES.has(routeName)) {
    const period = defaults.lastProcessed ?? defaults.firstOpen
    if (period !== null) return period
  }
  return startAwarePayrollPeriod(defaults.start)
}

/*
 * Období, které strážce právě sám doplnil. Přesměrovaná navigace ho nese už
 * jako výslovné `?period=`, ale volba uživatele to není: nesmí se zapamatovat,
 * jinak by výchozí měsíc běhů přepsal i návrh období na stránkách podání.
 */
let injected: { name: string, period: string } | null = null

/** Uživatel a firma, pro které se období pamatuje; prázdné = nepamatovat. */
function sessionScope(supplierId: number): string {
  try {
    const userId = Number(useAuthStore().user?.id)
    const scopedSupplierId = Number(getWindowSupplierScope()?.supplierId ?? supplierId)
    return Number.isSafeInteger(userId) && userId > 0
      && Number.isSafeInteger(scopedSupplierId) && scopedSupplierId > 0
      ? `${userId}.${scopedSupplierId}`
      : ''
  } catch {
    return ''
  }
}

export async function payrollStartPeriodGuard(
  to: RouteLocationNormalized,
): Promise<true | RouteLocationRaw> {
  if (typeof to.name !== 'string' || !ROUTES.has(to.name)) return true
  let supplierId = 0
  try {
    supplierId = useSupplierStore().currentSupplierId
  } catch {
    return true
  }
  const scope = sessionScope(supplierId)
  if (to.query.period !== undefined) {
    const ownDefault = injected !== null && injected.name === to.name && injected.period === to.query.period
    injected = null
    if (!ownDefault
      && typeof to.query.period === 'string' && PERIOD.test(to.query.period) && to.query.remember !== '0'
    ) {
      writePayrollPeriod(scope, to.query.period)
    }
    return true
  }
  // Zdravotní agenda běží v osmidenních lhůtách od události a otevírá se
  // záměrně na dnešním měsíci.
  if (to.params.tab === 'health') return true
  const remembered = readPayrollPeriod(scope)
  if (remembered !== null) {
    return { ...to, query: { ...to.query, period: remembered } } as RouteLocationRaw
  }
  if (!supplierId) return true
  const period = defaultPeriod(to.name, await periodDefaults(supplierId))
  if (period === payrollWorkingPeriod()) return true

  injected = { name: to.name, period }
  return { ...to, query: { ...to.query, period } } as RouteLocationRaw
}
