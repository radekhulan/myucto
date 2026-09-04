<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import WorkspaceNavLink from '@/components/workspace/WorkspaceNavLink.vue'
import { useWorkspaceStore } from '@/stores/workspace'

type Accent = 'primary' | 'primaryDeep' | 'warning' | 'success' | 'danger' | 'neutral' | 'accent' | 'teal' | 'payroll'

interface NavItem {
  to: string
  label: string
  icon: string
  external?: boolean
  newTo?: string
  badge?: number
  dividerBefore?: boolean
  accent?: Accent
  attention?: 'danger' | 'warning' | null
}

interface NavSection {
  key: string
  title?: string
  accent?: Accent
  icon: string
  items: NavItem[]
}

const props = defineProps<{
  sections: NavSection[]
  isActive: (item: NavItem) => boolean
  canCreate: (item: NavItem) => boolean
  createTarget: (item: NavItem) => string
  quickNewLabel: string
  menuLabel: string
  expandLabel?: string
}>()

const emit = defineEmits<{
  expand: []
}>()

const ACCENT_TILE: Record<Accent, string> = {
  primary: 'bg-primary-50 text-primary-600 ring-primary-400/40',
  primaryDeep: 'bg-primary-100 text-primary-700 ring-primary-600/40',
  warning: 'bg-warning-50 text-warning-600 ring-warning-500/40',
  success: 'bg-success-50 text-success-600 ring-success-500/40',
  danger: 'bg-danger-50 text-danger-600 ring-danger-500/40',
  neutral: 'bg-neutral-100 text-neutral-600 ring-neutral-400/40',
  accent: 'bg-accent-50 text-accent-600 ring-accent-500/40',
  teal: 'bg-teal-50 text-teal-600 ring-teal-500/40',
  payroll: 'bg-payroll-50 text-payroll-600 ring-payroll-500/40',
}

const ACCENT_ITEM: Record<Accent, string> = {
  primary: 'text-primary-700 bg-primary-500/8 hover:bg-primary-500/15',
  primaryDeep: 'text-primary-700 bg-primary-600/8 hover:bg-primary-600/15',
  warning: 'text-warning-600 bg-warning-500/10 hover:bg-warning-500/20',
  success: 'text-success-600 bg-success-500/10 hover:bg-success-500/20',
  danger: 'text-danger-600 bg-danger-500/10 hover:bg-danger-500/20',
  neutral: 'text-neutral-600 bg-neutral-500/10 hover:bg-neutral-500/20',
  accent: 'text-accent-600 bg-accent-500/10 hover:bg-accent-500/20',
  teal: 'text-teal-600 bg-teal-500/10 hover:bg-teal-500/20',
  payroll: 'text-payroll-600 bg-payroll-500/10 hover:bg-payroll-500/20',
}

const workspace = useWorkspaceStore()
const root = ref<HTMLElement | null>(null)
const openKey = ref<string | null>(null)
const railSections = computed(() => props.sections.filter(section => section.title))
const openSection = computed(() => railSections.value.find(section => section.key === openKey.value) ?? null)

function sectionIsActive(section: NavSection): boolean {
  return section.items.some(item => !item.external && props.isActive(item))
}

function sectionHasAttention(section: NavSection): boolean {
  return section.items.some(item => item.attention || item.badge)
}

function toggleSection(key: string): void {
  openKey.value = openKey.value === key ? null : key
}

function close(): void {
  openKey.value = null
}

function onDocumentPointerDown(event: PointerEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) close()
}

function onDocumentKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') close()
}

watch(() => workspace.activeFullPath, close)

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerDown)
  document.addEventListener('keydown', onDocumentKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerDown)
  document.removeEventListener('keydown', onDocumentKeydown)
})
</script>

<template>
  <aside ref="root" class="relative z-20 w-14 shrink-0 nav-inverted bg-surface border-r border-neutral-200 shadow-sm">
    <nav class="flex h-full flex-col items-center gap-1 overflow-y-auto scrollbar-slim px-1.5 py-2" :aria-label="menuLabel">
      <button
        v-if="expandLabel"
        type="button"
        class="mb-1 inline-flex h-9 w-10 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-primary-700"
        :title="expandLabel"
        :aria-label="expandLabel"
        @click="emit('expand')"
      >
        <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
        </svg>
      </button>
      <button
        v-for="section in railSections"
        :key="section.key"
        type="button"
        class="relative inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-xl shadow-sm ring-1 transition-all hover:brightness-110 hover:shadow-md"
        :class="[
          ACCENT_TILE[section.accent ?? 'primary'],
          sectionIsActive(section) ? 'ring-2' : '',
          openKey === section.key ? 'scale-[1.04] ring-2 shadow-md' : '',
        ]"
        :title="section.title"
        :aria-label="section.title"
        :aria-expanded="openKey === section.key"
        @click.stop="toggleSection(section.key)"
      >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="section.icon" />
        </svg>
        <span v-if="sectionHasAttention(section)" class="absolute right-1 top-1 h-2 w-2 rounded-full bg-warning-500" aria-hidden="true"></span>
      </button>
    </nav>

    <transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 -translate-x-1"
      enter-to-class="opacity-100 translate-x-0"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100 translate-x-0"
      leave-to-class="opacity-0 -translate-x-1"
    >
      <div v-if="openSection" class="absolute left-full top-0 z-30 flex h-full w-72 flex-col border-r border-neutral-200 bg-surface shadow-xl">
        <div class="flex h-12 shrink-0 items-center gap-2 border-b border-neutral-200 px-3">
          <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg shadow-sm ring-1" :class="ACCENT_TILE[openSection.accent ?? 'primary']">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="openSection.icon" />
            </svg>
          </span>
          <span class="min-w-0 flex-1 truncate text-sm font-semibold text-neutral-800">{{ openSection.title }}</span>
          <button type="button" class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800" :aria-label="openSection.title" @click="close">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="flex-1 overflow-y-auto p-2">
          <template v-for="item in openSection.items" :key="item.to">
            <div v-if="item.dividerBefore" class="my-1.5 border-t border-neutral-200" aria-hidden="true"></div>
            <a
              v-if="item.external"
              :href="item.to"
              target="_blank"
              rel="noopener"
              class="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900"
              @click="close"
            >
              <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
            </a>
            <div v-else class="group relative rounded-md">
              <WorkspaceNavLink
                :to="item.to"
                class="flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm transition-colors"
                :class="[
                  props.isActive(item)
                    ? 'bg-primary-50 font-medium text-primary-700'
                    : item.accent
                      ? [ACCENT_ITEM[item.accent], 'font-medium']
                      : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900',
                  props.canCreate(item) ? 'pr-9' : '',
                ]"
                @click="close"
              >
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                <span v-if="item.attention" class="h-2 w-2 shrink-0 rounded-full" :class="item.attention === 'danger' ? 'bg-danger-500' : 'bg-warning-500'"></span>
                <span v-else-if="item.badge" class="min-w-5 rounded-full bg-warning-500/20 px-1.5 py-0.5 text-center text-[10px] font-semibold text-warning-600">{{ item.badge }}</span>
              </WorkspaceNavLink>
              <WorkspaceNavLink
                v-if="props.canCreate(item)"
                :to="props.createTarget(item)"
                :title="quickNewLabel"
                :aria-label="quickNewLabel"
                class="absolute right-1.5 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-neutral-400 hover:bg-primary-100 hover:text-primary-700"
                @click="close"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v10m5-5H7" />
                </svg>
              </WorkspaceNavLink>
            </div>
          </template>
        </div>
      </div>
    </transition>
  </aside>
</template>
