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

const PERIOD = /^\d{4}-(0[1-9]|1[0-2])$/

export function startAwarePayrollPeriod(startPeriod: string | null, date = new Date()): string {
  const working = payrollWorkingPeriod(date)
  return startPeriod !== null && PERIOD.test(startPeriod) && startPeriod > working
    ? startPeriod
    : working
}

const cache = new Map<number, Promise<string | null>>()

function startPeriod(supplierId: number): Promise<string | null> {
  let pending = cache.get(supplierId)
  if (pending === undefined) {
    pending = api.get<{ state?: { start_period?: string | null } }>('/payroll/capabilities')
      .then(response => response.data.state?.start_period ?? null)
      .catch(() => {
        cache.delete(supplierId)
        return null
      })
    cache.set(supplierId, pending)
  }
  return pending
}

export function resetPayrollStartPeriodCache(): void {
  cache.clear()
}

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
    if (typeof to.query.period === 'string' && PERIOD.test(to.query.period) && to.query.remember !== '0') {
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
  const period = startAwarePayrollPeriod(await startPeriod(supplierId))
  if (period === payrollWorkingPeriod()) return true

  return { ...to, query: { ...to.query, period } } as RouteLocationRaw
}
