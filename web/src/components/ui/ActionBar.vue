<script setup lang="ts">
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import { usePaneActivity } from '@/workspace/paneActivity'

/**
 * Sdílená lišta akcí pro detailní stránky (faktura / přijatá faktura / klient …).
 *
 * 3 úrovně (tier) řeší „action overload":
 *  - primary    → plné tlačítko (1 akce dle stavu = další logický krok)
 *  - secondary  → outline tlačítko (podpůrné akce, na mobilu spadnou do „…")
 *  - overflow   → vždy jen v „…" dropdownu (utility, destruktivní akce)
 *
 * Dropdown je teleportovaný do <body> (neořezává overflow), zavírá se na Esc / scroll /
 * resize / klik mimo. Položky jsou data-driven (pole `actions`), takže přidání akce =
 * jeden řádek v poli, ne další tlačítko do tří různých toolbarů.
 *
 * Mobil: inline zůstanou jen primary; secondary + overflow jsou v „…" menu.
 */

import { BTN_BASE, FILLED, OUTLINE, MENU_ICON, ICONS, type ActionVariant } from './buttonStyles'

export type { ActionVariant }
// 'advanced' = méně časté / admin / destruktivní akce — v dropdownu schované pod rozbalovacím „Pokročilé"
export type ActionTier = 'primary' | 'secondary' | 'overflow' | 'advanced'

export interface ActionItem {
  /** unikátní klíč pro v-for */
  key: string
  /** popisek (už přeložený přes t()) */
  label: string
  /** název ikony z ICONS mapy níže (volitelné) */
  icon?: keyof typeof ICONS
  tier?: ActionTier          // default 'secondary'
  variant?: ActionVariant    // default 'neutral'
  show?: unknown             // default true; truthy = zobrazit, falsy (false/null/0/'') = skrýt
  disabled?: boolean
  /**
   * Věta, PROČ je akce zašedlá — např. „Závazky vzniknou až ze zaúčtované
   * revize. Nejdřív zaúčtujte mzdovou revizi za toto období."
   *
   * Zobrazuje se dvakrát a to schválně: jako `title` (tooltip pro myš) a jako
   * viditelný řádek pod lištou. Samotný tooltip nestačí — na dotykovém displeji
   * se nedá vyvolat vůbec a čtečka obrazovky ho u `disabled` prvku přeskočí,
   * takže by uživatel mačkal mrtvé tlačítko bez jediné nápovědy.
   *
   * Uplatní se jen když `disabled === true`. Formulovat jako větu, podle které
   * se dá jednat („Nejdřív …"), ne jako „Akce není dostupná".
   */
  disabledReason?: string
  loading?: boolean          // nahradí popisek „…"
  title?: string             // tooltip
  to?: RouteLocationRaw      // RouterLink cíl
  href?: string              // externí odkaz (target=_blank)
  download?: boolean
  run?: () => void           // click handler
}

const props = defineProps<{
  actions: ActionItem[]
  // Výjimka z desktopového stropu pro stránky s krátkými popisky (detail skladové karty).
  desktopInline?: number
  // Popisek místo samotných teček, když jde o samostatný seznam funkcí stránky.
  moreLabel?: string
}>()

const { t } = useI18n()
const paneActive = usePaneActivity()

// Ikony a barevné varianty (FILLED/OUTLINE/MENU_ICON) žijí v buttonStyles.ts —
// sdílené s tlačítky mimo ActionBar (jednotný koncept UI, AGENTS.md §Frontend).
const DOTS = 'M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0zM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0z'

const visible = computed(() => props.actions.filter(a => a.show === undefined || !!a.show))
const primary = computed(() => visible.value.filter(a => (a.tier ?? 'secondary') === 'primary'))
const secondary = computed(() => visible.value.filter(a => (a.tier ?? 'secondary') === 'secondary'))
const overflow = computed(() => visible.value.filter(a => a.tier === 'overflow'))
const advanced = computed(() => visible.value.filter(a => a.tier === 'advanced'))

// Kolik akcí (primary + secondary) zůstane inline v hlavičce; zbytek spadne do „…".
// Desktop = max 3 (AGENTS.md: max 3 tlačítka v hlavičce), mobil = 2.
const MOBILE_INLINE = 2
const DESKTOP_INLINE = 3
const inlineOrder = computed(() => [...primary.value, ...secondary.value])
const mobileInlineKeys = computed(() => new Set(
  inlineOrder.value.slice(0, MOBILE_INLINE).map(a => a.key),
))
const desktopInlineKeys = computed(() => new Set(
  inlineOrder.value.slice(0, props.desktopInline ?? DESKTOP_INLINE).map(a => a.key),
))
// sekundární, které zůstávají inline i na mobilu (jsou mezi prvními 2)
const secondaryMobile = computed(() => secondary.value.filter(a => mobileInlineKeys.value.has(a.key)))
// sekundární inline jen na desktopu (v prvních 3, ale ne v prvních 2) → na mobilu jdou do menu
const secondaryDesktop = computed(() => secondary.value.filter(a => desktopInlineKeys.value.has(a.key) && !mobileInlineKeys.value.has(a.key)))
// sekundární nad rámec desktop capu → vždy v „…" (i na desktopu)
const secondaryOverflow = computed(() => secondary.value.filter(a => !desktopInlineKeys.value.has(a.key)))

const hasSecondaryDesktop = computed(() => secondaryDesktop.value.length > 0)
const hasSecondaryOverflow = computed(() => secondaryOverflow.value.length > 0)
const hasOverflow = computed(() => overflow.value.length > 0)
const hasAdvanced = computed(() => advanced.value.length > 0)
// „…" trigger: vždy když je overflow/advanced/secondaryOverflow; jinak (jen collapsnuté secondary) jen na mobilu
const showTrigger = computed(() => hasOverflow.value || hasAdvanced.value || hasSecondaryOverflow.value || hasSecondaryDesktop.value)
// na desktopu má „…" smysl pro overflow/advanced/secondaryOverflow (secondaryDesktop jsou inline)
const triggerDesktop = computed(() => hasOverflow.value || hasAdvanced.value || hasSecondaryOverflow.value)
const advancedOpen = ref(false)

/** Důvod se uplatní jen u skutečně zašedlé akce — u aktivní by mátl. */
function reasonOf(a: ActionItem): string | undefined {
  return a.disabled && a.disabledReason ? a.disabledReason : undefined
}
/** Explicitní `title` má přednost; jinak tooltip nese důvod blokace. */
function titleOf(a: ActionItem): string | undefined {
  return a.title || reasonOf(a) || undefined
}

/*
 * Viditelné vysvětlení pod lištou. Jen za akce, které jsou na obrazovce vidět
 * jako tlačítko (primary + secondary) — u položek schovaných v „…" by uživatel
 * četl důvod k něčemu, co nevidí. Duplicitní texty se slučují, protože dvě
 * akce blokované touž podmínkou by jinak napsaly totéž dvakrát.
 */
const blockedNotes = computed(() => [...new Set(
  [...primary.value, ...secondary.value].map(reasonOf).filter((r): r is string => !!r),
)])

function tagOf(a: ActionItem) {
  if (a.to) return RouterLink
  if (a.href) return 'a'
  return 'button'
}
function attrsOf(a: ActionItem): Record<string, unknown> {
  if (a.to) return { to: a.to }
  if (a.href) return { href: a.href, target: '_blank', rel: 'noopener', ...(a.download ? { download: '' } : {}) }
  return { type: 'button' }
}

const inlineBase = BTN_BASE

// ─── dropdown ───
const open = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

function reposition() {
  const tr = triggerRef.value?.getBoundingClientRect()
  if (!tr) return
  const mw = menuRef.value?.offsetWidth ?? 224
  const mh = menuRef.value?.offsetHeight ?? 320
  let left = tr.right - mw
  let top = tr.bottom + 4
  if (left < 8) left = 8
  if (top + mh > window.innerHeight - 8) top = Math.max(8, tr.top - mh - 4)
  pos.value = { top, left }
}
async function toggle() {
  if (open.value) { open.value = false; return }
  reposition(); open.value = true; await nextTick(); reposition()
}
function close() { open.value = false; advancedOpen.value = false }
function runItem(a: ActionItem) {
  close()
  if (a.run) a.run()
}

function onKey(e: KeyboardEvent) { if (paneActive.value && e.key === 'Escape') close() }
onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})
</script>

<template>
  <div class="flex flex-col gap-1.5 md:shrink-0 md:items-end">
  <div class="flex flex-wrap md:flex-nowrap md:shrink-0 gap-2 md:justify-end items-center">
    <!-- PRIMARY: vždy inline, plné -->
    <component :is="tagOf(a)" v-for="a in primary" :key="a.key" v-bind="attrsOf(a)"
      :class="[inlineBase, FILLED[a.variant ?? 'primary']]"
      :disabled="a.disabled" :title="titleOf(a)"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.loading ? '…' : a.label }}
    </component>

    <!-- SECONDARY (mezi prvními 2): inline i na mobilu -->
    <component :is="tagOf(a)" v-for="a in secondaryMobile" :key="a.key" v-bind="attrsOf(a)"
      :class="[inlineBase, OUTLINE[a.variant ?? 'neutral']]"
      :disabled="a.disabled" :title="titleOf(a)"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.loading ? '…' : a.label }}
    </component>

    <!-- SECONDARY (zbytek): inline jen na sm+; na mobilu jsou v menu -->
    <component :is="tagOf(a)" v-for="a in secondaryDesktop" :key="a.key" v-bind="attrsOf(a)"
      :class="['hidden sm:inline-flex', inlineBase, OUTLINE[a.variant ?? 'neutral']]"
      :disabled="a.disabled" :title="titleOf(a)"
      @click="a.run && a.run()">
      <svg v-if="a.icon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
      </svg>
      {{ a.loading ? '…' : a.label }}
    </component>

    <!-- „…" trigger — light: decentní neutral ghost; dark: vyplněný accent „pill" (drží kontrast na tmavém pozadí) -->
    <button v-if="showTrigger" ref="triggerRef" type="button" @click.stop="toggle"
      :class="['cursor-pointer h-9 shrink-0 inline-flex items-center justify-center rounded-md ring-1 ring-inset transition-colors',
               moreLabel ? 'gap-1.5 whitespace-nowrap px-3 text-sm font-medium' : 'w-9',
               open ? 'bg-neutral-100 text-neutral-700 ring-neutral-400 dark:bg-primary-600/40 dark:text-primary-900 dark:ring-primary-500/60'
                    : 'text-neutral-500 ring-neutral-300 hover:bg-neutral-100 hover:text-neutral-700 dark:text-primary-700 dark:bg-primary-100 dark:ring-primary-500/30 dark:hover:bg-primary-600/30 dark:hover:text-primary-900 dark:hover:ring-primary-500/50',
               triggerDesktop ? '' : 'sm:hidden']"
      :aria-expanded="open" :title="moreLabel ?? t('common.more_actions')">
      <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path :d="DOTS" /></svg>
      <template v-if="moreLabel">{{ moreLabel }}</template>
    </button>

    <WorkspaceTeleport>
      <template v-if="open">
        <div class="fixed inset-0 z-[60]" @click="close" @contextmenu.prevent="close" aria-hidden="true"></div>
        <div ref="menuRef" class="fixed z-[61] w-60 max-w-[calc(100vw-16px)] bg-surface border border-neutral-200 rounded-lg shadow-xl py-1 text-sm"
          :style="{ top: pos.top + 'px', left: pos.left + 'px' }">
          <div class="px-3 py-2 text-xs font-semibold text-neutral-500 text-center border-b border-neutral-100 truncate">
            {{ moreLabel ?? t('common.more_actions') }}
          </div>

          <!-- sekundární, které se nevešly inline: secondaryOverflow vždy, secondaryDesktop jen na mobilu -->
          <template v-if="hasSecondaryOverflow || hasSecondaryDesktop">
            <component :is="tagOf(a)" v-for="a in secondaryOverflow" :key="a.key" v-bind="attrsOf(a)"
              :class="['w-full flex items-center gap-2.5 px-3 py-2 cursor-pointer text-left',
                       a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                       a.disabled ? 'opacity-50 pointer-events-none' : '']"
              :title="titleOf(a)" @click="runItem(a)">
              <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
              </svg>
              <span class="min-w-0"><span class="block">{{ a.loading ? '…' : a.label }}</span><span v-if="reasonOf(a)" class="mt-0.5 block text-xs leading-snug text-warning-700">{{ reasonOf(a) }}</span></span>
            </component>
            <div v-if="hasSecondaryDesktop" class="sm:hidden">
              <component :is="tagOf(a)" v-for="a in secondaryDesktop" :key="a.key" v-bind="attrsOf(a)"
                :class="['w-full flex items-center gap-2.5 px-3 py-2 cursor-pointer text-left',
                         a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                         a.disabled ? 'opacity-50 pointer-events-none' : '']"
                :title="titleOf(a)" @click="runItem(a)">
                <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
                  fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
                </svg>
                <span class="min-w-0"><span class="block">{{ a.loading ? '…' : a.label }}</span><span v-if="reasonOf(a)" class="mt-0.5 block text-xs leading-snug text-warning-700">{{ reasonOf(a) }}</span></span>
              </component>
            </div>
            <div v-if="hasOverflow" :class="['my-1 border-t border-neutral-100', hasSecondaryOverflow ? '' : 'sm:hidden']"></div>
          </template>

          <!-- overflow vždy -->
          <component :is="tagOf(a)" v-for="a in overflow" :key="a.key" v-bind="attrsOf(a)"
            :class="['w-full flex items-center gap-2.5 px-3 py-2 cursor-pointer text-left',
                     a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                     a.disabled ? 'opacity-50 pointer-events-none' : '']"
            :title="titleOf(a)" @click="runItem(a)">
            <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
              fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
            </svg>
            <span class="min-w-0"><span class="block">{{ a.loading ? '…' : a.label }}</span><span v-if="reasonOf(a)" class="mt-0.5 block text-xs leading-snug text-warning-700">{{ reasonOf(a) }}</span></span>
          </component>

          <!-- advanced → rozbalovací „Pokročilé" (méně časté / admin / destruktivní akce) -->
          <template v-if="hasAdvanced">
            <div v-if="hasOverflow || hasSecondaryOverflow || hasSecondaryDesktop"
              :class="['my-1 border-t border-neutral-100', (hasOverflow || hasSecondaryOverflow) ? '' : 'sm:hidden']"></div>
            <button type="button" @click.stop="advancedOpen = !advancedOpen"
              class="w-full flex items-center justify-between gap-2 px-3 py-2 text-neutral-500 hover:bg-neutral-50 cursor-pointer text-left">
              <span class="inline-flex items-center gap-2.5">
                <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.929.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.893-.15c-.543-.09-.94-.56-.94-1.109v-1.094c0-.55.397-1.02.94-1.11l.893-.149c.425-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                </svg>
                {{ t('common.advanced') }}
              </span>
              <svg class="w-3.5 h-3.5 text-neutral-400 transition-transform" :class="{ 'rotate-180': advancedOpen }"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <template v-if="advancedOpen">
              <component :is="tagOf(a)" v-for="a in advanced" :key="a.key" v-bind="attrsOf(a)"
                :class="['w-full flex items-center gap-2.5 pl-6 pr-3 py-2 cursor-pointer text-left',
                         a.variant === 'danger' ? 'text-danger-600 hover:bg-danger-50' : 'text-neutral-700 hover:bg-neutral-50',
                         a.disabled ? 'opacity-50 pointer-events-none' : '']"
                :title="titleOf(a)" @click="runItem(a)">
                <svg v-if="a.icon" :class="['w-4 h-4 shrink-0', a.variant === 'danger' ? 'text-danger-600' : MENU_ICON[a.variant ?? 'neutral']]"
                  fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
                </svg>
                <span class="min-w-0"><span class="block">{{ a.loading ? '…' : a.label }}</span><span v-if="reasonOf(a)" class="mt-0.5 block text-xs leading-snug text-warning-700">{{ reasonOf(a) }}</span></span>
              </component>
            </template>
          </template>
        </div>
      </template>
    </WorkspaceTeleport>
  </div>

    <!--
      Proč je hlavní akce zašedlá. Bez téhle věty uživatel jen mačká mrtvé
      tlačítko — tooltip na dotyku neexistuje a u `disabled` prvku ho přeskočí
      i čtečka obrazovky.
    -->
    <p v-for="note in blockedNotes" :key="note" data-test="action-disabled-reason"
      class="max-w-prose text-xs leading-snug text-warning-700 md:text-right">
      {{ note }}
    </p>
  </div>
</template>
