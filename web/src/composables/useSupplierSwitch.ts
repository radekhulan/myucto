import { ref } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import type { SupplierBrief } from '@/api/auth'
import { LOCATABLE, abortManualSupplierSwitch, beginManualSupplierSwitch, isLocatableRoute } from '@/router/locatableRoutes'

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
/**
 * Kam po ručním přepnutí z aktuální routy. Z routy dokladu (`LOCATABLE`) se odchází
 * vždy, i když cesta nemá číselný segment: zůstat na ní by znamenalo, že ji guard
 * odkazu otevře znovu a firmu vrátí vlastníkovi dokladu.
 */
export function manualSwitchDestination(route: { name?: unknown, path: string, fullPath: string }): string | null {
  const queryIndex = route.fullPath.indexOf('?')
  const search = queryIndex >= 0 ? route.fullPath.slice(queryIndex) : ''
  const parent = supplierSwitchDestination(route.path, search)
  if (!isLocatableRoute(route.name)) return parent
  if (parent) return parent
  return LOCATABLE[route.name as string].query ? route.path : '/'
}

async function currentRoute(): Promise<{ name?: unknown, path: string, fullPath: string }> {
  try {
    const { router } = await import('@/router')
    return router.currentRoute.value
  } catch {
    return { path: window.location.pathname, fullPath: window.location.pathname + window.location.search }
  }
}

/** Sdílené napříč přepínačem, hledáním i paletou: druhé přepnutí během prvního se ignoruje. */
const switching = ref(false)

/** Úspěšné přepnutí příznak nevrací (stránka se přenačte), testy ho proto nulují samy. */
export function resetSupplierSwitchForTests(): void {
  switching.value = false
}

export function useSupplierSwitch() {
  const supplierStore = useSupplierStore()
  const auth = useAuthStore()

  /**
   * `destination` = kam po přepnutí (odkaz na doklad jiné firmy, viz
   * `router/supplierDeepLink.ts`); bez něj jde o ruční přepnutí a detail se vrací na seznam.
   */
  async function switchTo(id: number, destination?: string): Promise<void> {
    if (id === supplierStore.currentSupplierId || switching.value) return
    switching.value = true
    const manual = destination === undefined
    if (manual) beginManualSupplierSwitch()
    const from = manual ? await currentRoute() : null
    auth.clearPermissions()
    supplierStore.setSupplier(id)

    // Selhání uložení přepnutí nebrání — localStorage drží volbu v tomhle prohlížeči.
    // Demo mutace odmítá globálně, uložení by jen vyvolalo hlášku.
    if (!auth.isDemo) await authApi.setDefaultSupplier(id).catch(() => undefined)

    const refreshed = await auth.refresh()
    if (!refreshed) {
      if (manual) abortManualSupplierSwitch()
      switching.value = false
      return
    }

    const target = from ? manualSwitchDestination(from) : destination ?? null
    if (target) {
      window.location.href = target
    } else {
      window.location.reload()
    }
  }

  return { switching, switchTo }
}
