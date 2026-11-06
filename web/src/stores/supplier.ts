import { domainLoginRequestContext, pendingDomainLoginRequestContext } from '@/security/domainLoginContext'
import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import type { SupplierBrief } from '@/api/auth'
import { getWindowSupplierScope, requestSupplierIdNumber } from '@/workspace/supplierContext'

const STORAGE_KEY = 'myinvoice.current_supplier_id'

/**
 * Multi-supplier scope na frontendu.
 * - `currentSupplierId` se persistuje v localStorage
 * - axios interceptor (api/client.ts) z něj plní hlavičku `X-Supplier-Id`
 * - po /me se naplní `availableSuppliers` (pro switcher v hlavičce)
 *
 * Přepínač po změně nejprve vyprázdní permission mapu a znovu načte `/auth/me`
 * s novým `X-Supplier-Id`; teprve potom pustí další navigaci.
 */
export const useSupplierStore = defineStore('supplier', () => {
  const initial = (() => {
    if (pendingDomainLoginRequestContext()) return 0
    const scoped = requestSupplierIdNumber()
    if (getWindowSupplierScope()) return scoped
    const v = localStorage.getItem(STORAGE_KEY)
    return v && /^\d+$/.test(v) ? parseInt(v, 10) : 0
  })()

  const currentSupplierId = ref<number>(initial)
  const availableSuppliers = ref<SupplierBrief[]>([])
  const domainLocked = ref(false)

  const hasMultiple = computed(() => !domainLocked.value && availableSuppliers.value.length > 1)
  // False = onboarding přeskočil dodavatele (žádný supplier v DB). Bez dodavatele je
  // celá aplikace nepoužitelná (data jsou supplier-scoped, currencies se sázejí per-supplier),
  // proto router + dashboard navigují uživatele na vytvoření prvního dodavatele.
  const hasSupplier = computed(() => availableSuppliers.value.length > 0)
  const currentSupplier = computed<SupplierBrief | null>(() =>
    availableSuppliers.value.find(s => s.id === currentSupplierId.value) ?? null,
  )

  watch(currentSupplierId, (v) => {
    if (getWindowSupplierScope() || domainLoginRequestContext()) return
    if (domainLocked.value) {
      localStorage.removeItem(STORAGE_KEY)
      return
    }
    if (v > 0) localStorage.setItem(STORAGE_KEY, String(v))
    else localStorage.removeItem(STORAGE_KEY)
  })

  function setSupplier(id: number) {
    const windowScope = getWindowSupplierScope()
    if (windowScope) {
      if (id === windowScope.supplierId) currentSupplierId.value = id
      return
    }
    if (domainLocked.value && id !== currentSupplierId.value) return
    currentSupplierId.value = id
    if (domainLoginRequestContext()) return
    if (id > 0) localStorage.setItem(STORAGE_KEY, String(id))
    else localStorage.removeItem(STORAGE_KEY)
  }

  function setAvailable(list: SupplierBrief[], serverCurrent: number, locked = false) {
    domainLocked.value = locked
    availableSuppliers.value = list
    const windowScope = getWindowSupplierScope()
    if (windowScope) {
      currentSupplierId.value = windowScope.supplierId
      return
    }
    if (locked) {
      if (!domainLoginRequestContext()) localStorage.removeItem(STORAGE_KEY)
      currentSupplierId.value = serverCurrent || (list[0]?.id ?? 0)
      return
    }
    // Pokud localStorage value není v dostupném listu, přejdi na server-side default
    if (!list.find(s => s.id === currentSupplierId.value)) {
      currentSupplierId.value = serverCurrent || (list[0]?.id ?? 0)
    }
  }

  /**
   * Propsání změn z uloženého nastavení dodavatele do brief listu (issue #94).
   * availableSuppliers se plní jen z /me při startu — bez patche by editor
   * faktur četl stale hodnoty (is_vat_payer, defaulty) až do hard refreshe.
   */
  function patchSupplier(id: number, partial: Partial<SupplierBrief>) {
    const idx = availableSuppliers.value.findIndex(s => s.id === id)
    if (idx === -1) return
    availableSuppliers.value[idx] = { ...availableSuppliers.value[idx], ...partial, id }
  }

  return {
    currentSupplierId,
    availableSuppliers,
    domainLocked,
    hasMultiple,
    hasSupplier,
    currentSupplier,
    setSupplier,
    setAvailable,
    patchSupplier,
  }
})
