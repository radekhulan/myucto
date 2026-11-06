import { reactive } from 'vue'
import { propertyPaymentsApi, type BankProposalLink } from '@/api/propertyPayments'

/**
 * Vazby bankovních pohybů na úhrady jednotek SVJ pro řádky výpisu.
 *
 * Řádky se jen přihlásí svým id; dotaz jde jednou za dávku vykreslených řádků. Firma
 * bez profilu SVJ (nebo uživatel bez práva SVJ) odpoví jednou a další dotazy se už
 * nepošlou, takže běžná banka nemá kvůli SVJ žádnou režii navíc.
 */
interface SupplierState { enabled: boolean | null; links: Record<number, BankProposalLink | null>; pending: Set<number>; scheduled: boolean }

const state = reactive<Record<number, SupplierState>>({})

function supplierState(supplier: number): SupplierState {
  if (!state[supplier]) state[supplier] = { enabled: null, links: {}, pending: new Set(), scheduled: false }
  return state[supplier]!
}

async function flush(supplier: number) {
  const current = supplierState(supplier)
  current.scheduled = false
  if (current.enabled === false) { current.pending.clear(); return }
  const ids = [...current.pending].slice(0, 500)
  ids.forEach(id => current.pending.delete(id))
  if (!ids.length) return
  try {
    const result = await propertyPaymentsApi.bankLinks(supplier, ids)
    current.enabled = result.enabled
    for (const id of ids) current.links[id] = result.items[String(id)] ?? null
  } catch {
    current.enabled = false
  }
  if (current.pending.size) schedule(supplier)
}

function schedule(supplier: number) {
  const current = supplierState(supplier)
  if (current.scheduled) return
  current.scheduled = true
  queueMicrotask(() => { void flush(supplier) })
}

export function usePropertyBankLinks() {
  function track(supplier: number | null | undefined, transactionId: number) {
    if (!supplier || transactionId <= 0) return
    const current = supplierState(supplier)
    if (current.enabled === false || transactionId in current.links) return
    current.pending.add(transactionId)
    schedule(supplier)
  }
  function enabled(supplier: number | null | undefined): boolean {
    return !!supplier && state[supplier]?.enabled === true
  }
  function link(supplier: number | null | undefined, transactionId: number): BankProposalLink | null {
    return supplier ? state[supplier]?.links[transactionId] ?? null : null
  }
  /** Po změně párování (např. potvrzená úhrada) se vazba pohybu načte znovu. */
  function forget(supplier: number | null | undefined, transactionId: number) {
    if (supplier && state[supplier]) delete state[supplier]!.links[transactionId]
  }
  return { track, enabled, link, forget }
}

/** Jen pro testy: vyprázdní sdílený stav. */
export function resetPropertyBankLinks() {
  for (const key of Object.keys(state)) delete state[Number(key)]
}
