import { useRouter, type RouteLocationRaw } from 'vue-router'
import { inject } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'

/**
 * Navigace „klikatelných" řádků v seznamech tak, aby fungoval Ctrl/⌘+klik a klik
 * prostředním tlačítkem (otevření v novém panelu) — což čisté @click + router.push
 * neumí (není to skutečný odkaz).
 *
 * Pokrývá jen kliknutí na řádek jako celek; do <tr> se odkaz zabalit nedá, takže
 * kontextové menu a cíl ve stavovém řádku tudy nezískáš. Ty nese skutečný
 * <RouterLink class="row-link"> v identifikační buňce (číslo dokladu / název) —
 * viz `.row-link` ve styles/main.css. Obojí platí vedle sebe.
 *
 * Použití:
 *   const navigate = useRowLink()
 *   function openInvoice(inv, e) { navigate(`/invoices/${inv.id}`, e) }
 *   // v šabloně: @click="openInvoice(inv, $event)" @auxclick.prevent="openInvoice(inv, $event)"
 *
 * Chování dle eventu:
 *   - Ctrl/⌘ + klik nebo prostřední tlačítko (button 1) → nový panel (window.open)
 *   - běžný levý klik (button 0) → router.push
 *   - pravé/jiné tlačítko bez modifikátoru → ignorováno (nenaviguje)
 *
 * Volba `newPanel: true` otevře cíl v novém panelu i při běžném kliku (nebo bez události)
 * a má přednost před navigací v panelu pracovní plochy, např. když by odchod ze stránky zahodil
 * rozpracovanou práci. Pravé/jiné tlačítko bez modifikátoru ani s volbou nenaviguje.
 */
export function useRowLink() {
  const router = useRouter()
  const windowContext = inject(windowContextKey, null)

  // router.resolve respektuje base path routeru; otevře SPA na dané route v novém panelu
  const openNewPanel = (to: RouteLocationRaw) => { window.open(router.resolve(to).href, '_blank', 'noopener') }

  return function navigate(to: RouteLocationRaw, e?: MouseEvent, options?: { newPanel?: boolean }): void {
    if (e && (e.metaKey || e.ctrlKey || e.button === 1)) {
      openNewPanel(to)
      return
    }
    // Aux/pravé tlačítko (button != 0) bez modifikátoru — nenaviguj (nechováme se jako odkaz)
    if (e && e.button !== undefined && e.button !== 0) return
    if (options?.newPanel) {
      openNewPanel(to)
      return
    }
    if (windowContext?.navigate) void windowContext.navigate(to)
    else void router.push(to)
  }
}
