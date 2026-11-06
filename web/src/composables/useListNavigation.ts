import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { useRouter, type RouteLocationRaw } from 'vue-router'
import { usePaneActivity } from '@/workspace/paneActivity'

/**
 * Listování doklady z detailu (předchozí / další) v pořadí seznamu, ze kterého
 * uživatel přišel.
 *
 * Seznam při otevření detailu uloží do sessionStorage kontext: ID řádků v pořadí,
 * jak je zobrazuje, parametry dotazu (filtry, hledání, řazení) a kolik stránek už
 * načetl. sessionStorage sdílí hlavní okno i okna pracovní plochy (včetně iframe),
 * takže kontext najde i detail otevřený v jiném panelu. Detail ukáže šipky jen
 * tehdy, když ho seznam „označil“ (`openedId`) nebo když na něj už dřív listoval
 * (`currentId`, např. po obnovení stránky). Přímý odkaz kontext nemá a šipky
 * se neukážou.
 *
 * Na hranici načtených dat se další dávka načte stejným API se stejnými
 * parametry (`fetchPage`), celý seznam se nikdy nestahuje.
 */

export interface ListNavSnapshot {
  ids: number[]
  /** Poslední načtená stránka; seznam načítá stránky 1…page za sebou. */
  page: number
  pages: number
  params: Record<string, unknown>
}

export interface ListNavContext {
  ids: number[]
  firstPage: number
  lastPage: number
  pages: number
  params: Record<string, unknown>
  listPath: string
  scope: string
  openedId: number | null
  currentId: number | null
}

export interface ListNavPage {
  ids: number[]
  pages: number
}

const STORAGE_PREFIX = 'myinvoice.list-nav.'
const DIALOG_SELECTOR = '[role="dialog"], [aria-modal="true"]'

export const LIST_NAV_KEYS = { previous: '[', next: ']' } as const

function storageKey(kind: string): string {
  return `${STORAGE_PREFIX}${kind}`
}

export function readListNavContext(kind: string): ListNavContext | null {
  try {
    const raw = sessionStorage.getItem(storageKey(kind))
    if (!raw) return null
    const parsed = JSON.parse(raw) as ListNavContext
    if (!parsed || !Array.isArray(parsed.ids)) return null
    return parsed
  } catch {
    return null
  }
}

export function writeListNavContext(kind: string, context: ListNavContext): void {
  try { sessionStorage.setItem(storageKey(kind), JSON.stringify(context)) } catch {}
}

function normalizeScope(scope: unknown): string {
  return scope === null || scope === undefined ? '' : String(scope)
}

/**
 * Strana seznamu: `markOpen(id)` zavolej při otevření detailu z řádku.
 */
export function useListNavigationSource(
  kind: string,
  snapshot: () => ListNavSnapshot,
  listPath: () => string,
  scope: () => unknown = () => '',
) {
  function markOpen(id: number): void {
    const current = snapshot()
    if (!current.ids.includes(id)) return
    writeListNavContext(kind, {
      ids: [...current.ids],
      firstPage: 1,
      lastPage: Math.max(1, current.page),
      pages: Math.max(1, current.pages),
      params: JSON.parse(JSON.stringify(current.params ?? {})),
      listPath: listPath(),
      scope: normalizeScope(scope()),
      openedId: id,
      currentId: null,
    })
  }
  return { markOpen }
}

export interface ListNavigationOptions {
  kind: string
  currentId: () => number
  detailRoute: (id: number) => RouteLocationRaw
  fetchPage: (params: Record<string, unknown>, page: number) => Promise<ListNavPage>
  scope?: () => unknown
  /** Vypne klávesové zkratky (např. při otevřeném formuláři mimo modál). */
  hotkeysEnabled?: () => boolean
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.isContentEditable) return true
  return !!target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]')
}

export function listNavDirection(event: KeyboardEvent): -1 | 1 | 0 {
  if (event.defaultPrevented) return 0
  const altGraph = event.getModifierState?.('AltGraph') === true
  if (!altGraph && (event.ctrlKey || event.metaKey || event.altKey)) return 0
  if (event.key === LIST_NAV_KEYS.previous || (!altGraph && !event.shiftKey && event.code === 'BracketLeft')) return -1
  if (event.key === LIST_NAV_KEYS.next || (!altGraph && !event.shiftKey && event.code === 'BracketRight')) return 1
  return 0
}

/**
 * Strana detailu: stav šipek, přechod na sousední doklad a klávesové zkratky.
 */
export function useListNavigation(options: ListNavigationOptions) {
  const router = useRouter()
  const paneActive = usePaneActivity()
  const context = ref<ListNavContext | null>(null)
  const busy = ref(false)
  let pendingTarget: number | null = null
  let listening = false

  const scope = () => normalizeScope(options.scope?.())

  function sync(): void {
    const id = options.currentId()
    if (!id) { context.value = null; return }
    const local = context.value
    if (local && local.scope === scope() && local.ids.includes(id) && (local.currentId === id || pendingTarget === id)) {
      pendingTarget = null
      if (local.currentId !== id) {
        local.currentId = id
        writeListNavContext(options.kind, local)
      }
      return
    }
    pendingTarget = null
    const stored = readListNavContext(options.kind)
    if (stored && stored.scope === scope() && stored.ids.includes(id)
      && (stored.openedId === id || stored.currentId === id)) {
      stored.openedId = null
      stored.currentId = id
      writeListNavContext(options.kind, stored)
      context.value = stored
      return
    }
    context.value = null
  }

  const index = computed(() => {
    const ctx = context.value
    return ctx ? ctx.ids.indexOf(options.currentId()) : -1
  })
  const active = computed(() => !!context.value && index.value >= 0)
  const hasPrevious = computed(() => {
    const ctx = context.value
    return !!ctx && index.value >= 0 && (index.value > 0 || ctx.firstPage > 1)
  })
  const hasNext = computed(() => {
    const ctx = context.value
    return !!ctx && index.value >= 0 && (index.value < ctx.ids.length - 1 || ctx.lastPage < ctx.pages)
  })
  const listPath = computed(() => context.value?.listPath ?? null)

  async function loadAdjacentPage(direction: -1 | 1): Promise<number | null> {
    const ctx = context.value
    if (!ctx) return null
    const page = direction > 0 ? ctx.lastPage + 1 : ctx.firstPage - 1
    if (page < 1 || page > ctx.pages) return null
    const result = await options.fetchPage(ctx.params, page)
    if (context.value !== ctx) return null
    const known = new Set(ctx.ids)
    const fresh = result.ids.filter(id => !known.has(id))
    ctx.pages = Math.max(1, result.pages)
    if (direction > 0) {
      ctx.lastPage = page
      ctx.ids.push(...fresh)
      if (!fresh.length && ctx.lastPage >= ctx.pages) ctx.pages = ctx.lastPage
    } else {
      ctx.firstPage = page
      ctx.ids.unshift(...fresh)
    }
    writeListNavContext(options.kind, ctx)
    if (!fresh.length) return null
    return direction > 0 ? fresh[0] : fresh[fresh.length - 1]
  }

  async function go(direction: -1 | 1): Promise<void> {
    const ctx = context.value
    if (!ctx || busy.value) return
    const i = index.value
    if (i < 0) return
    let target: number | null = ctx.ids[i + direction] ?? null
    if (target === null) {
      busy.value = true
      try {
        target = await loadAdjacentPage(direction)
      } catch {
        target = null
      } finally {
        busy.value = false
      }
    }
    if (target === null || context.value !== ctx) return
    pendingTarget = target
    ctx.openedId = target
    writeListNavContext(options.kind, ctx)
    await router.replace(options.detailRoute(target))
  }

  const previous = () => go(-1)
  const next = () => go(1)

  function onKey(event: KeyboardEvent): void {
    if (!paneActive.value || !active.value) return
    if (options.hotkeysEnabled && !options.hotkeysEnabled()) return
    const direction = listNavDirection(event)
    if (!direction) return
    if (isEditableTarget(event.target) || isEditableTarget(document.activeElement)) return
    if (document.querySelector(DIALOG_SELECTOR)) return
    if (direction < 0 ? !hasPrevious.value : !hasNext.value) return
    event.preventDefault()
    void go(direction)
  }

  function listen(): void {
    if (listening) return
    window.addEventListener('keydown', onKey)
    listening = true
  }
  function unlisten(): void {
    if (!listening) return
    window.removeEventListener('keydown', onKey)
    listening = false
  }

  watch(() => [options.currentId(), scope()], sync, { immediate: true })
  onMounted(listen)
  onActivated(() => { sync(); listen() })
  onDeactivated(unlisten)
  onBeforeUnmount(unlisten)

  return { active, hasPrevious, hasNext, busy, listPath, previous, next, context }
}
