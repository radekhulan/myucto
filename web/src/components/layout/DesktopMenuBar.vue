<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue'
import WorkspaceNavLink from '@/components/workspace/WorkspaceNavLink.vue'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore } from '@/stores/workspace'

interface NavItem {
  to: string
  label: string
  icon: string
  external?: boolean
  newTo?: string
  badge?: number
  dividerBefore?: boolean
  /** Odlišení jedné položky uvnitř sekce (viz NavItem v AppLayout). */
  accent?: NavSection['accent']
  /** Tečka „je co řešit" — táž data jako v postranním menu a na dashboardu. */
  attention?: 'danger' | 'warning' | null
}

interface NavSection {
  key: string
  title?: string
  accent?: 'primary' | 'primaryDeep' | 'warning' | 'success' | 'danger' | 'neutral' | 'accent' | 'teal' | 'payroll'
  items: NavItem[]
}

/**
 * Accent → tint pilulky aktivní sekce. Táž paleta jako tečky a levé lišty v postranním
 * menu (ACCENT_DOT / ACCENT_RAIL v AppLayout), ať obě navigace mluví stejnou řečí.
 *
 * Proč `-50` a k tomu NEUTRÁLNÍ text: odstíny -50 mají v `styles/main.css` explicitní
 * tmavý protějšek (jsou dělané přesně jako „soft pill bg"), kdežto -600/-700 jsou
 * přemapované jen u primary a success — barevný text by v tmavém režimu u daní, peněz
 * nebo dokumentů spadl na kontrast pod 3:1. Barvu tak nese pozadí, čitelnost text.
 */
/**
 * Accent JEDNÉ položky v rozbalené nabídce. Tint jako u pilulky sekce, jen
 * s barevným textem — v nabídce není nic jiného, co by barvu neslo, takže
 * samotné pozadí by se ztratilo.
 */
const ACCENT_ITEM: Record<NonNullable<NavSection['accent']>, string> = {
  primary:     'bg-primary-50 text-primary-700',
  primaryDeep: 'bg-primary-100 text-primary-700',
  warning:     'bg-warning-50 text-warning-600',
  success:     'bg-success-50 text-success-600',
  danger:      'bg-danger-50 text-danger-600',
  neutral:     'bg-neutral-100 text-neutral-700',
  accent:      'bg-accent-50 text-accent-600',
  teal:        'bg-teal-50 text-teal-600',
  payroll:     'bg-payroll-50 text-payroll-600',
}

const ACCENT_PILL: Record<NonNullable<NavSection['accent']>, string> = {
  primary: 'bg-primary-50',
  primaryDeep: 'bg-primary-100',
  warning: 'bg-warning-50',
  success: 'bg-success-50',
  danger:  'bg-danger-50',
  neutral: 'bg-neutral-100',
  accent:  'bg-accent-50',
  teal:    'bg-teal-50',
  payroll: 'bg-payroll-50',
}

const props = defineProps<{
  sections: NavSection[]
  isActive: (item: NavItem) => boolean
  canCreate: (item: NavItem) => boolean
  createTarget: (item: NavItem) => string
  /** Popisek klávesové zkratky položky, nebo prázdno když žádnou nemá. */
  shortcutFor: (to: string) => string
  quickNewLabel: string
  menuLabel: string
}>()

const visibleSections = computed(() => props.sections.filter(section => !(
  (section.key === 'system_global' || section.key === 'system_signing')
  && section.items.length === 1 && section.items[0]?.to === '/manual'
)))

const workspace = useWorkspaceStore()
const workspaceNavigation = useWorkspaceNavigation()
const root = ref<HTMLElement | null>(null)
const openSection = ref<string | null>(null)
const hoverCapable = ref(false)
let hoverMql: MediaQueryList | null = null

function sectionIsActive(section: NavSection): boolean {
  return section.items.some(item => !item.external && props.isActive(item))
}

/** První položka sekce, na kterou se dá přejít routerem (externí odkazy přeskoč). */
function firstInternalTarget(section: NavSection): string | null {
  return section.items.find(item => !item.external)?.to ?? null
}

/**
 * Klik na název sekce. Na myši je submenu otevřené už od hoveru, takže klik
 * rovnou skočí na první položku (Prodej → Vydané faktury) — jinak by nedělal nic
 * a musel bys mířit do rozbalené nabídky na něco, co je stejně první v pořadí.
 *
 * Na klávesnici hover nenastane, takže první Enter sekci otevře a teprve druhý
 * naviguje — `aria-haspopup="menu"` tím zůstává pravdivé.
 *
 * Na dotyku se navigace nespouští vůbec: tam je klepnutí jediný způsob, jak
 * submenu otevřít i zavřít, a odskok na první položku by byl past na překlep.
 */
function activateSection(section: NavSection) {
  if (openSection.value !== section.key) {
    openSection.value = section.key
    return
  }
  if (!hoverCapable.value) {
    openSection.value = null
    return
  }
  const target = firstInternalTarget(section)
  if (target === null) return
  close()
  void workspaceNavigation.navigate(target)
}

function openFromPointer(key: string) {
  if (hoverCapable.value) openSection.value = key
}

function closeFromPointer(key: string) {
  if (hoverCapable.value && openSection.value === key) openSection.value = null
}

function close() {
  openSection.value = null
}

function onDocumentPointerDown(event: PointerEvent) {
  if (root.value && !root.value.contains(event.target as Node)) close()
}

function onDocumentKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') close()
}

function onHoverChange(event: MediaQueryListEvent) {
  hoverCapable.value = event.matches
}

watch(() => workspace.activeFullPath, close)

onMounted(() => {
  hoverMql = window.matchMedia('(hover: hover) and (pointer: fine)')
  hoverCapable.value = hoverMql.matches
  hoverMql.addEventListener('change', onHoverChange)
  document.addEventListener('pointerdown', onDocumentPointerDown)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  hoverMql?.removeEventListener('change', onHoverChange)
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  document.removeEventListener('keydown', onDocumentKeydown)
})
</script>

<template>
  <nav ref="root" class="flex min-w-0 flex-1 h-full items-stretch" :aria-label="menuLabel">
    <div
      v-for="(section, index) in visibleSections"
      :key="section.key"
      class="relative flex items-stretch"
      @pointerenter="openFromPointer(section.key)"
      @pointerleave="closeFromPointer(section.key)"
    >
      <!--
        Pilulka místo bloku přes celou výšku: hover se dřív roztáhl od horní k dolní
        hraně hlavičky a lišta působila jako řada zámkových dlaždic. `self-center`
        je nutné — obálka je `items-stretch` kvůli pozicování dropdownu (`top-full`),
        takže bez něj by se tlačítko roztáhlo zpátky na plnou výšku.
      -->
      <button
        type="button"
        class="cursor-pointer group relative inline-flex min-w-0 self-center items-center gap-1.5 px-2.5 xl:px-3 h-8 rounded-lg text-sm font-medium whitespace-nowrap text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 transition-colors"
        :class="sectionIsActive(section)
          ? `${ACCENT_PILL[section.accent ?? 'primary']} text-neutral-900 font-semibold`
          : (openSection === section.key ? 'bg-neutral-100 text-neutral-900' : '')"
        :aria-expanded="openSection === section.key"
        aria-haspopup="menu"
        @click.stop="activateSection(section)"
        @keydown.down.prevent="openSection = section.key"
      >
        <span>{{ section.title }}</span>
        <!--
          Šipka je jen našeptání, že položka rozbaluje — proto tlumená. Deset plných
          šipek vedle sebe přebíjelo samotné názvy sekcí.
        -->
        <svg
          class="w-3 h-3 shrink-0 transition-transform opacity-45 group-hover:opacity-70"
          :class="openSection === section.key ? 'rotate-180 opacity-70' : ''"
          fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <transition
        enter-active-class="transition duration-100 ease-out"
        enter-from-class="opacity-0 -translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-75 ease-in"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-1"
      >
        <div
          v-if="openSection === section.key"
          class="absolute top-full z-50 w-72 max-w-[calc(100vw-2rem)] bg-surface border border-neutral-200 dark:border-neutral-300 rounded-b-lg shadow-xl dark:shadow-[0_14px_32px_rgba(0,0,0,0.45)] py-1.5"
          :class="index >= visibleSections.length - 3 ? 'right-0' : 'left-0'"
          role="menu"
        >
          <template v-for="item in section.items" :key="item.to">
            <div v-if="item.dividerBefore" class="my-1.5 border-t border-neutral-200" aria-hidden="true"></div>
            <a
              v-if="item.external"
              :href="item.to"
              target="_blank"
              rel="noopener"
              class="flex items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700"
              role="menuitem"
              @click="close"
            >
              <svg class="w-4 h-4 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
              <span v-if="props.shortcutFor(item.to)" class="shrink-0 text-[11px] leading-none tracking-wide text-neutral-400">{{ props.shortcutFor(item.to) }}</span>
            </a>
            <div v-else class="relative group/item" role="none">
              <WorkspaceNavLink
                :to="item.to"
                class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors"
                :class="props.isActive(item)
                  ? 'bg-primary-50 text-primary-700 font-medium'
                  : item.accent
                    ? [ACCENT_ITEM[item.accent], 'font-medium']
                    : 'text-neutral-700 hover:bg-neutral-50 hover:text-primary-700'"
                role="menuitem"
                @click="close"
              >
                <svg class="w-4 h-4 shrink-0" :class="item.accent ? '' : 'text-neutral-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                <span
                  v-if="item.attention"
                  class="inline-block h-2 w-2 shrink-0 rounded-full"
                  :class="item.attention === 'danger' ? 'bg-danger-500' : 'bg-warning-500'"
                ></span>
                <span v-else-if="item.badge" class="min-w-5 rounded-full bg-warning-500/20 px-1.5 py-0.5 text-center text-[10px] font-semibold text-warning-600">{{ item.badge }}</span>
                <!--
                  Zkratka sedí na témže místě jako tlačítko „+" (to je absolutně
                  pozicované na right-2 a naskakuje na hover), takže při hoveru
                  ustoupí — jinak by se překrývaly. Když položka „+" nemá, zůstává
                  zkratka vidět i pod kurzorem.
                -->
                <span
                  v-if="props.shortcutFor(item.to)"
                  class="shrink-0 text-[11px] leading-none tracking-wide text-neutral-400 transition-opacity"
                  :class="props.canCreate(item) ? 'group-hover/item:opacity-0' : ''"
                >{{ props.shortcutFor(item.to) }}</span>
              </WorkspaceNavLink>
              <WorkspaceNavLink
                v-if="props.canCreate(item)"
                :to="props.createTarget(item)"
                :title="quickNewLabel"
                :aria-label="quickNewLabel"
                class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex h-6 w-6 items-center justify-center rounded-md text-neutral-400 opacity-0 group-hover/item:opacity-100 focus:opacity-100 hover:bg-primary-100 hover:text-primary-700"
                @click="close"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v10m5-5H7" />
                </svg>
              </WorkspaceNavLink>
            </div>
          </template>
        </div>
      </transition>
    </div>
  </nav>
</template>
