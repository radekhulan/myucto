<script setup lang="ts">
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { BTN_SM_BASE, OUTLINE, FILLED, MENU_ICON, ICONS, type ActionVariant, type ActionIcon } from './buttonStyles'

/**
 * Kompaktní řádková lišta akcí pro husté tabulky (výpis banky, seznam zaměstnanců).
 *
 * Drží akce na jednom řádku: prvních `inlineCount` (default 2) jako malá tlačítka
 * (btnSm), zbytek spadne do „…" popupu teleportovaného do <body> — stejný princip
 * jako ActionBar na detailových stránkách, jen menší. Řeší přetékání/scrollování
 * úzkého sloupce akcí, když je tlačítek víc.
 *
 * Nabídka je ovladatelná z klávesnice: spouštěč ji otevře Enter/mezerou i šipkou
 * dolů, uvnitř se chodí šipkami a Esc ji zavře a vrátí ohnisko na spouštěč. Bez
 * toho by položky sice byly ve stránce, ale teleport je odsune na konec <body>,
 * takže by na ně Tab z řádku nikdy nedošel.
 */

export interface RowAction {
  key: string
  label: string
  icon?: ActionIcon
  variant?: ActionVariant   // default 'neutral'
  filled?: boolean          // inline: plné tlačítko místo outline (hlavní akce)
  disabled?: boolean
  /**
   * Věta, PROČ akce nejde — typicky chybějící oprávnění. Vypíše se v nabídce
   * jako druhý řádek položky, ne jen do `title`: tooltip se na dotykovém
   * displeji nedá vyvolat vůbec a u zašedlé položky ho přeskočí i čtečka.
   */
  disabledReason?: string
  title?: string
  to?: RouteLocationRaw
  href?: string
  download?: boolean
  run?: () => void
  show?: unknown            // default true; falsy = skrýt
}

const props = withDefaults(defineProps<{
  actions: RowAction[]
  inlineCount?: number
  /**
   * Inline tlačítka jen jako ikona. Popisek zůstává v `aria-label` i v `title`,
   * takže se čtečka ani myš o nic nepřipraví — jen řádek tabulky nezhoustne na
   * šířku, kterou by musel scrollovat.
   */
  iconOnly?: boolean
  /** Popisek spouštěče „…" — přebíjí obecné „Další akce". */
  menuLabel?: string
}>(), {
  inlineCount: 2,
  iconOnly: false,
  menuLabel: '',
})

const { t } = useI18n()

const DOTS = 'M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0z'

const visible = computed(() => props.actions.filter(a => a.show === undefined || !!a.show))
const inline = computed(() => visible.value.slice(0, props.inlineCount))
const menu = computed(() => visible.value.slice(props.inlineCount))
const hasMenu = computed(() => menu.value.length > 0)
const triggerLabel = computed(() => props.menuLabel || t('common.more_actions'))

function tagOf(a: RowAction) {
  if (a.to) return RouterLink
  if (a.href) return 'a'
  return 'button'
}
/**
 * Zašedlá položka nabídky NENÍ odkaz. `pointer-events-none` zastaví myš, ale
 * klávesnice by odkaz otevřela navzdory blokaci — a to je u práv díra, ne kosmetika.
 */
function menuTagOf(a: RowAction) {
  return a.disabled ? 'span' : tagOf(a)
}
function attrsOf(a: RowAction): Record<string, unknown> {
  if (a.to) return { to: a.to }
  if (a.href) return { href: a.href, target: '_blank', rel: 'noopener', ...(a.download ? { download: '' } : {}) }
  return { type: 'button' }
}
function inlineClass(a: RowAction): string {
  const v = a.variant ?? 'neutral'
  return `${BTN_SM_BASE} ${a.filled ? FILLED[v] : OUTLINE[v]} ${props.iconOnly ? 'px-1.5' : ''}`
}
/** Tooltip nese popisek; u blokované akce důvod, protože ten je to nové. */
function titleOf(a: RowAction): string {
  if (a.disabled && a.disabledReason) return a.disabledReason
  return a.title || a.label
}

// ─── dropdown ───
const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function reposition() {
  const tr = triggerRef.value?.getBoundingClientRect()
  if (!tr) return
  const mw = menuRef.value?.offsetWidth ?? 208
  const mh = menuRef.value?.offsetHeight ?? 240
  let left = tr.right - mw
  let top = tr.bottom + 4
  if (left < 8) left = 8
  if (top + mh > window.innerHeight - 8) top = Math.max(8, tr.top - mh - 4)
  pos.value = { top, left }
}

/** Položky, na které se dá stoupnout klávesnicí — zašedlé se přeskakují. */
function focusables(): HTMLElement[] {
  const root = menuRef.value
  if (!root) return []
  return Array.from(root.querySelectorAll<HTMLElement>('[data-menu-item]:not([aria-disabled="true"])'))
}

function focusItem(index: number) {
  const items = focusables()
  if (items.length === 0) return
  const bounded = (index + items.length) % items.length
  items[bounded]?.focus()
}

async function openMenu(focusIndex: number | null = null) {
  reposition()
  open.value = true
  await nextTick()
  reposition()
  if (focusIndex !== null) focusItem(focusIndex)
}

async function toggle() {
  if (open.value) { close(); return }
  await openMenu()
}

function close(returnFocus = false) {
  if (!open.value) return
  open.value = false
  if (returnFocus) triggerRef.value?.focus()
}

function runItem(a: RowAction) {
  if (a.disabled) return
  close()
  if (a.run) a.run()
}

/** Spouštěč: šipka dolů/nahoru otevře nabídku rovnou na první/poslední položce. */
function onTriggerKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') { e.preventDefault(); void openMenu(0) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); void openMenu(-1) }
}

function onMenuKey(e: KeyboardEvent) {
  const items = focusables()
  const current = items.indexOf(document.activeElement as HTMLElement)
  if (e.key === 'ArrowDown') { e.preventDefault(); focusItem(current + 1) }
  else if (e.key === 'ArrowUp') { e.preventDefault(); focusItem(current - 1) }
  else if (e.key === 'Home') { e.preventDefault(); focusItem(0) }
  else if (e.key === 'End') { e.preventDefault(); focusItem(-1) }
  // Tab z nabídky ji zavírá: teleportované položky sedí na konci <body>, takže
  // by ohnisko odskočilo mimo řádek, ze kterého uživatel vyšel.
  else if (e.key === 'Tab') close(true)
}

function onKey(e: KeyboardEvent) { if (e.key === 'Escape') close(true) }
// Posluchač musí být TÁŽ funkce při přidání i odebrání; `close` sama bere
// argument, takže by jí prohlížeč jako „vrať ohnisko" podstrčil Event.
function closeOnViewportChange() { close() }
onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('scroll', closeOnViewportChange, true)
  window.addEventListener('resize', closeOnViewportChange)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', closeOnViewportChange, true)
  window.removeEventListener('resize', closeOnViewportChange)
})
</script>

<template>
  <div class="inline-flex items-center gap-1 justify-end">
    <component :is="tagOf(a)" v-for="a in inline" :key="a.key" v-bind="attrsOf(a)"
      :class="inlineClass(a)" :disabled="a.disabled" :title="titleOf(a)" :aria-label="iconOnly ? a.label : undefined"
      @click="runItem(a)">
      <svg v-if="a.icon" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      <span :class="iconOnly ? 'sr-only' : ''">{{ a.label }}</span>
    </component>

    <button v-if="hasMenu" ref="triggerRef" type="button" @click.stop="toggle" @keydown="onTriggerKey"
      :class="['cursor-pointer w-7 h-7 shrink-0 inline-flex items-center justify-center rounded-md ring-1 ring-inset transition-colors',
               open ? 'bg-neutral-100 text-neutral-700 ring-neutral-400'
                    : 'text-neutral-500 ring-neutral-300 hover:bg-neutral-100 hover:text-neutral-700']"
      aria-haspopup="menu" :aria-expanded="open" :title="triggerLabel" :aria-label="triggerLabel">
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path :d="DOTS" /></svg>
    </button>

    <Teleport to="body">
      <template v-if="open">
        <div class="fixed inset-0 z-[60]" @click="close()" @contextmenu.prevent="close()" aria-hidden="true"></div>
        <div ref="menuRef" role="menu" :aria-label="triggerLabel" @keydown="onMenuKey"
          class="fixed z-[61] w-60 max-w-[calc(100vw-16px)] bg-surface border border-neutral-200 rounded-lg shadow-xl py-1 text-sm"
          :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
          <component :is="menuTagOf(a)" v-for="a in menu" :key="a.key" v-bind="a.disabled ? { 'aria-disabled': 'true' } : attrsOf(a)"
            data-menu-item role="menuitem" :tabindex="a.disabled ? -1 : 0"
            :class="['w-full flex items-start gap-2.5 px-3 py-2 text-left',
                     a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                     a.disabled ? 'opacity-70 cursor-default' : 'cursor-pointer']"
            :title="a.title || undefined" @click="runItem(a)">
            <svg v-if="a.icon" :class="['w-4 h-4 mt-0.5 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
            </svg>
            <span class="min-w-0">
              <span class="block">{{ a.label }}</span>
              <!--
                Viditelná věta, PROČ to nejde. Bez ní zůstane zašedlá položka bez
                vysvětlení — `title` na dotyku neexistuje a čtečka ho u zablokované
                položky přeskočí.
              -->
              <span v-if="a.disabled && a.disabledReason" class="mt-0.5 block text-xs leading-snug text-warning-700">
                {{ a.disabledReason }}
              </span>
            </span>
          </component>
        </div>
      </template>
    </Teleport>
  </div>
</template>
