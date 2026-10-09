import { ref } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import type { SupplierBrief } from '@/api/auth'

/** Normalizace pro hledání firmy: bez diakritiky, malá písmena. */
function fold(value: string): string {
  return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/**
 * Firmy, na které jde přepnout a které odpovídají dotazu (název nebo IČ, bez ohledu
 * na diakritiku; víc slov = všechna musí sedět). Aktuální firma se nenabízí,
 * u uzamčené domény ani u jediné firmy se nenabízí nic.
 */
export function matchSwitchableSuppliers(
  suppliers: readonly SupplierBrief[],
  currentId: number,
  query: string,
  canSwitch: boolean,
  limit = 8,
): SupplierBrief[] {
  const tokens = fold(query.trim()).split(/\s+/).filter(Boolean)
  if (!canSwitch || tokens.length === 0) return []
  return suppliers
    .filter(s => s.id !== currentId)
    .filter((s) => {
      const hay = fold(`${s.company_name} ${s.ic ?? ''}`)
      return tokens.every(tok => hay.includes(tok))
    })
    .slice(0, limit)
}

/**
 * Kam po přepnutí firmy. Záznam z detailu v jiné firmě neexistuje a odkaz na něj by
 * firmu přepnul zpátky (`router/supplierDeepLink.ts`), proto se z každé cesty
 * s číselným ID jde o úroveň výš na přehled; u deníku se zahodí odkaz na zápis.
 */
export function supplierSwitchDestination(path: string, search = ''): string | null {
  if (path === '/portfolio') return '/'
  const segments = path.split('/')
  const idIndex = segments.findIndex((segment, index) => index > 0 && /^\d+$/.test(segment))
  if (idIndex > 0) return segments.slice(0, idIndex).join('/') || '/'
  if (/(?:^\?|&)entry_id=/.test(search)) return path
  return null
}

/**
 * Přepnutí aktivní firmy — jediná cesta pro přepínač v hlavičce, hledání (Alt+Q)
 * i paletu příkazů (Ctrl+K).
 *
 * Po přepnutí se vyprázdní oprávnění, volba se uloží k účtu (ať se stejná firma
 * otevře i v jiném prohlížeči), znovu se načte /auth/me a stránka se přenačte —
 * z detailu záznamu, který v jiné firmě neexistuje, se přejde na jeho seznam.
 */
export function useSupplierSwitch() {
  const supplierStore = useSupplierStore()
  const auth = useAuthStore()
  const switching = ref(false)

  /**
   * `destination` = kam po přepnutí (odkaz na doklad jiné firmy, viz
   * `router/supplierDeepLink.ts`); bez něj se detail vrací na seznam.
   */
  async function switchTo(id: number, destination?: string): Promise<void> {
    if (id === supplierStore.currentSupplierId || switching.value) return
    switching.value = true
    auth.clearPermissions()
    supplierStore.setSupplier(id)

    // Selhání uložení přepnutí nebrání — localStorage drží volbu v tomhle prohlížeči.
    // Demo mutace odmítá globálně, uložení by jen vyvolalo hlášku.
    if (!auth.isDemo) await authApi.setDefaultSupplier(id).catch(() => undefined)

    const refreshed = await auth.refresh()
    if (!refreshed) {
      switching.value = false
      return
    }

    const target = destination ?? supplierSwitchDestination(window.location.pathname, window.location.search)
    if (target) {
      window.location.href = target
    } else {
      window.location.reload()
    }
  }

  return { switching, switchTo }
}
