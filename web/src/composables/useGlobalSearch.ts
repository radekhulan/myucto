import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { searchApi, type SearchResults } from '@/api/search'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useSupplierStore } from '@/stores/supplier'
import { matchSwitchableSuppliers, useSupplierSwitch } from '@/composables/useSupplierSwitch'

/**
 * Jádro globálního hledání. Pole v postranním panelu (Alt+Q), paleta příkazů
 * (Ctrl+K) i hledání na nové záložce z něj berou stejné výsledky; liší se jen
 * vzhledem a tím, že paleta bez dotazu nabízí rychlé akce.
 *
 * Klienty a doklady, které aktivní firma nemá, vrací server z ostatních firem
 * uživatele (stejně jako zaměstnance). Takový výsledek nese `supplier_id`
 * a otevře se až po přepnutí firmy.
 */

export interface SearchMenuItem {
  to: string
  label: string
  icon: string
  external?: boolean
  /** Nadpis sekce menu: popisek pod názvem a další slova pro hledání. */
  section?: string
  /** Accent sekce; barví ikonu. */
  accent?: string
}

export interface SearchQuickAction {
  to: string
  label: string
  icon: string
}

export type SearchGroup = 'action' | 'nav' | 'company' | 'client' | 'employee' | 'invoice' | 'purchase'

export interface SearchOption {
  group: SearchGroup
  label: string
  sub: string
  icon: string
  /** Klíč accentu pro barvu ikony (viz [data-accent] v styles/main.css). */
  accent: string
  run: () => void
}

const GROUP_LABEL: Record<SearchGroup, string> = {
  action: 'command.group_actions',
  nav: 'search.group_menu',
  company: 'search.group_companies',
  client: 'search.group_clients',
  employee: 'search.group_employees',
  invoice: 'search.group_invoices',
  purchase: 'search.group_purchase',
}

const ICON_PLUS = 'M12 6v6m0 0v6m0-6h6m-6 0H6'
const ICON_USER = 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z'
const ICON_EMPLOYEE = 'M10 6H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-5m-4 0V5a2 2 0 1 1 4 0v1m-4 0a2 2 0 1 0 4 0m-5 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 0 0-2.83 2M15 11h3m-3 4h2'
const ICON_DOC = 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z'
const ICON_BUILDING = 'M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4'
const ICON_CART = 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 1-4 0 2 2 0 0 1 4 0z'

const MAX_MENU_RESULTS = 12
const DEBOUNCE_MS = 220

/**
 * Fuzzy shoda: nejdřív souvislý podřetězec (nejsilnější signál), pak písmena
 * v pořadí kdekoli v textu („vyfa" najde „Vydané faktury"). Vrací skóre, nebo
 * -1 když neodpovídá.
 */
export function fuzzyScore(text: string, needle: string): number {
  const hay = text.toLowerCase()
  const idx = hay.indexOf(needle)
  if (idx === 0) return 1000
  if (idx > 0) return 700 - idx
  let score = 0
  let pos = 0
  for (const ch of needle) {
    const found = hay.indexOf(ch, pos)
    if (found === -1) return -1
    // Shoda na začátku slova váží víc než uprostřed.
    score += found === 0 || hay[found - 1] === ' ' ? 10 : 3
    pos = found + 1
  }
  return score
}

/**
 * Skóre víceslovného dotazu: KAŽDÉ slovo musí někde v textu sedět, výsledek je
 * průměr dílčích skóre. „nová faktura" tak trefí akci „Vydaná faktura" přes
 * klíčové slovo zakládání a pořadí slov nehraje roli.
 */
export function matchScore(haystack: string, query: string): number {
  const tokens = query.split(/\s+/).filter(Boolean)
  if (tokens.length === 0) return -1
  let total = 0
  for (const token of tokens) {
    const score = fuzzyScore(haystack, token)
    if (score < 0) return -1
    total += score
  }
  return total / tokens.length
}

function emptyResults(q: string): SearchResults {
  return { q, clients: [], invoices: [], purchase_invoices: [], employees: [] }
}

export function useGlobalSearch(source: {
  menuItems: () => readonly SearchMenuItem[]
  quickActions?: () => readonly SearchQuickAction[]
  /** Bez dotazu nabídnout rychlé akce (paleta). */
  quickActionsWhenEmpty?: boolean
}) {
  const { t } = useI18n()
  const workspaceNavigation = useWorkspaceNavigation()
  const supplierStore = useSupplierStore()
  const supplierSwitch = useSupplierSwitch()

  const q = ref('')
  const loading = ref(false)
  const results = ref<SearchResults>(emptyResults(''))
  let debounceTimer: ReturnType<typeof setTimeout> | undefined
  let seq = 0

  const needle = computed(() => q.value.trim().toLowerCase())

  /** Výsledek z jiné firmy se otevře až v ní; přepnutí ho pak zobrazí. */
  function open(to: string, supplierId?: number): void {
    if (supplierId && supplierId !== supplierStore.currentSupplierId) void supplierSwitch.switchTo(supplierId, to)
    else void workspaceNavigation.navigate(to)
  }

  function otherCompany(supplierId: number | undefined, supplierName: string | undefined): string {
    return supplierId && supplierId !== supplierStore.currentSupplierId ? supplierName ?? '' : ''
  }

  function joinSub(...parts: Array<string | null | undefined>): string {
    return parts.filter(Boolean).join(' · ')
  }

  function actionOption(action: SearchQuickAction): SearchOption {
    return {
      group: 'action', label: action.label, sub: t('command.action_hint'), icon: action.icon || ICON_PLUS, accent: 'primary',
      run: () => void workspaceNavigation.navigate(action.to, { fresh: true }),
    }
  }

  const options = computed<SearchOption[]>(() => {
    const out: SearchOption[] = []
    const n = needle.value
    const quickActions = source.quickActions?.() ?? []

    if (!n) {
      return source.quickActionsWhenEmpty ? quickActions.map(actionOption) : out
    }

    const scored: Array<{ opt: SearchOption, score: number }> = []
    // Klíčová slova zakládání („nová", „vytvořit"…) patří do prohledávaného textu
    // akce, aby „nová faktura" našla akci „Vydaná faktura".
    const createKeywords = t('command.kw_create')
    for (const action of quickActions) {
      const score = matchScore(`${createKeywords} ${action.label}`, n)
      if (score >= 0) scored.push({ score: score + 50, opt: actionOption(action) })
    }
    for (const item of source.menuItems()) {
      const score = matchScore(item.section ? `${item.section} ${item.label}` : item.label, n)
      if (score < 0) continue
      scored.push({
        score,
        opt: {
          group: 'nav', label: item.label, sub: item.section ?? '', icon: item.icon, accent: item.accent ?? 'primary',
          run: () => { item.external ? workspaceNavigation.openExternal(item.to) : void workspaceNavigation.navigate(item.to) },
        },
      })
    }
    scored.sort((a, b) => b.score - a.score)
    out.push(...scored.slice(0, MAX_MENU_RESULTS).map(s => s.opt))

    // Víc firem: přepnutí na firmu podle názvu nebo IČ (aktuální se nenabízí).
    for (const s of matchSwitchableSuppliers(supplierStore.availableSuppliers, supplierStore.currentSupplierId, n, supplierStore.hasMultiple)) {
      out.push({
        group: 'company', label: s.company_name, sub: s.ic ? t('search.company_ic', { ic: s.ic }) : t('search.company_switch'),
        icon: ICON_BUILDING, accent: 'teal', run: () => void supplierSwitch.switchTo(s.id),
      })
    }
    for (const c of results.value.clients) {
      out.push({
        group: 'client', label: c.company_name, sub: joinSub(c.main_email, otherCompany(c.supplier_id, c.supplier_name)),
        icon: ICON_USER, accent: 'success', run: () => open(`/clients/${c.id}`, c.supplier_id),
      })
    }
    for (const e of results.value.employees ?? []) {
      out.push({
        group: 'employee', label: e.full_name,
        sub: joinSub(
          e.code ? t('search.employee_code', { code: e.code }) : '',
          e.is_active ? '' : t('search.employee_inactive'),
          otherCompany(e.supplier_id, e.supplier_name),
        ),
        icon: ICON_EMPLOYEE, accent: 'payroll', run: () => open(`/payroll/people?person=${e.id}`, e.supplier_id),
      })
    }
    for (const i of results.value.invoices) {
      out.push({
        group: 'invoice', label: i.varsymbol || `#${i.id}`, sub: joinSub(i.company_name, otherCompany(i.supplier_id, i.supplier_name)),
        icon: ICON_DOC, accent: 'primary', run: () => open(`/invoices/${i.id}`, i.supplier_id),
      })
    }
    for (const p of results.value.purchase_invoices) {
      out.push({
        group: 'purchase', label: p.varsymbol || p.vendor_invoice_number || `#${p.id}`,
        sub: joinSub(p.company_name, otherCompany(p.supplier_id, p.supplier_name)),
        icon: ICON_CART, accent: 'warning', run: () => open(`/purchase-invoices/${p.id}`, p.supplier_id),
      })
    }
    return out
  })

  function groupHeaderFor(i: number): string | null {
    const opt = options.value[i]
    if (!opt) return null
    if (i === 0 || options.value[i - 1].group !== opt.group) return t(GROUP_LABEL[opt.group])
    return null
  }

  watch(q, (val) => {
    clearTimeout(debounceTimer)
    const text = val.trim()
    if (text.length < 2) {
      seq++
      results.value = emptyResults(text)
      loading.value = false
      return
    }
    loading.value = true
    debounceTimer = setTimeout(async () => {
      const mySeq = ++seq
      try {
        const r = await searchApi.query(text)
        if (mySeq === seq) results.value = r
      } catch {
        if (mySeq === seq) results.value = emptyResults(text)
      } finally {
        if (mySeq === seq) loading.value = false
      }
    }, DEBOUNCE_MS)
  })

  function clear(): void {
    clearTimeout(debounceTimer)
    q.value = ''
    results.value = emptyResults('')
    loading.value = false
  }

  onBeforeUnmount(() => clearTimeout(debounceTimer))

  return { q, loading, options, groupHeaderFor, clear }
}
