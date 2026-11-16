import { computed, ref } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'

/** Seznam členů a samostatná stránka detailu člena (jako seznam a detail kontaktu). */
export const MEMBERSHIP_LIST_PATH = '/accounting/nonprofit-membership'
export const memberDetailPath = (id: number) => `${MEMBERSHIP_LIST_PATH}/members/${id}`

/**
 * Hláška pro seznam po akci v detailu, která detail zavře (smazání člena). Seznam se po návratu
 * připojí znovu, proto ji drží modul, ne komponenta; seznam ji při načtení přečte a vymaže.
 */
export const membershipListNotice = ref('')

/** Firma a práva stránek členské evidence; seznam i detail rozhodují stejně. */
export function useMembershipAccess() {
  const supplier = useSupplierStore(), auth = useAuthStore()
  const supplierId = computed(() => Number(supplier.currentSupplierId))
  const canManage = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.journal.post', 'write')))
  const canManageRates = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.templates', 'write')))
  return { supplierId, canManage, canManageRates }
}
