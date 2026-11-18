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
 *   - pravé/jiné tlačítko → ignorováno (nenaviguje), i s Ctrl/⌘
 *
 * Volba `newPanel: true` otevře cíl v novém panelu i při běžném kliku (nebo bez události)
 * a má přednost před navigací v panelu pracovní plochy, např. když by odchod ze stránky zahodil
 * rozpracovanou práci. Pravé/jiné tlačítko nenaviguje ani s modifikátorem, ani s volbou.
 *
 * Vrací false, když prohlížeč nový panel zablokoval (otevření mimo akci uživatele, typicky
 * až po pomalé odpovědi serveru); volající pak může nabídnout otevření tlačítkem.
 */
export function useRowLink() {
  const router = useRouter()
  const windowContext = inject(windowContextKey, null)

  // router.resolve respektuje base path routeru; otevře SPA na dané route v novém panelu.
  // S `noopener` vrací window.open null i při úspěchu, proto se okno otevře bez něj
  // a odkaz na opener se zruší ručně; null pak spolehlivě znamená zablokované okno.
  const openNewPanel = (to: RouteLocationRaw): boolean => {
    const opened = window.open(router.resolve(to).href, '_blank')
    if (opened) opened.opener = null
    return opened !== null
  }

  return function navigate(to: RouteLocationRaw, e?: MouseEvent, options?: { newPanel?: boolean }): boolean {
    // Pravé (a jiné než levé a prostřední) tlačítko nenaviguje nikdy, ani s Ctrl/⌘:
    // `auxclick` chodí i pro pravé tlačítko, takže Ctrl + pravé by jinak otevřelo panel (#405).
    if (e && e.button !== undefined && e.button !== 0 && e.button !== 1) return true
    if (e && (e.metaKey || e.ctrlKey || e.button === 1)) return openNewPanel(to)
    if (options?.newPanel) return openNewPanel(to)
    if (windowContext?.navigate) void windowContext.navigate(to)
    else void router.push(to)
    return true
  }
}
