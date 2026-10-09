import type { RouteLocationNormalized } from 'vue-router'
import { api } from '@/api/client'
import { useSupplierStore } from '@/stores/supplier'
import { useSupplierSwitch } from '@/composables/useSupplierSwitch'
import { LOCATABLE, consumeManualSupplierSwitch, isLocatableRoute } from './locatableRoutes'

function entityId(to: RouteLocationNormalized, query?: string): number {
  const raw = query ? to.query[query] : to.params.id
  const value = Array.isArray(raw) ? raw[0] : raw
  const id = Number(value)
  return Number.isInteger(id) && id > 0 ? id : 0
}

/**
 * Odkaz na doklad jiné firmy, do které uživatel smí: přepne firmu a otevře tentýž
 * doklad (celé přenačtení stránky). Vrací `true`, když přepnutí začalo — navigace se
 * pak zastaví a pokračuje po přenačtení. Selhání dotazu navigaci nebrání: detail pak
 * ukáže obvyklé „nenalezeno". Při ručním přepnutí firmy se nepřepíná nic.
 */
export async function switchSupplierForDeepLink(to: RouteLocationNormalized): Promise<boolean> {
  if (consumeManualSupplierSwitch()) return false
  if (!isLocatableRoute(to.name)) return false
  const target = LOCATABLE[to.name as string]
  const supplierStore = useSupplierStore()
  if (!supplierStore.hasMultiple) return false
  const id = entityId(to, target.query)
  if (id === 0) return false

  let owner = 0
  try {
    const { data } = await api.get<{ supplier_id: number }>(`/locate/${target.type}/${id}`)
    owner = Number(data?.supplier_id ?? 0)
  } catch {
    return false
  }
  if (!owner || owner === supplierStore.currentSupplierId) return false
  if (!supplierStore.availableSuppliers.some(s => s.id === owner)) return false

  await useSupplierSwitch().switchTo(owner, to.fullPath)
  return true
}
