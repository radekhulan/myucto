<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getActivePinia } from 'pinia'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { authApi } from '@/api/auth'
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { useTheme } from '@/composables/useTheme'
import { useWorkspaceStore, type PaneId } from '@/stores/workspace'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { authorizationGuard, applyAuthorizationMeta } from '@/router'
import { createPaneRouter } from '@/router/createPaneRouter'
import { getPaneRuntime } from '@/workspace/runtimeRegistry'
import { createWindowManager } from '@/workspace/windowManager'
import { mirrorWindowLocation } from '@/workspace/windowHistory'
import { resizePaneFractions } from '@/workspace/panelSizing'
import { paneCountFromShortcut, panelIndexFromShortcut } from '@/workspace/panelShortcuts'
import { installShiftClickNavigation } from '@/workspace/frameNavigation'
import WorkspacePane from './WorkspacePane.vue'
import { NEW_TAB_PATH } from '@/workspace/windowTabs'
import { openTabsSummary, plainMenuItems, setOpenTabSelectHandler, workspaceMenuItems, workspaceOpenTabs, type WorkspaceMenuItem } from '@/workspace/newTabData'

const props = defineProps<{ accentFor?: (fullPath: string) => string, agendaFor?: (fullPath: string) => string, menuItems?: WorkspaceMenuItem[] }>()
const workspace = useWorkspaceStore()
const { t } = useI18n()
const { locale } = useI18n()
const shortcuts = useKeyboardShortcuts()
const { isDark } = useTheme()
const navigation = useWorkspaceNavigation()
const supplier = useSupplierStore()
const auth = useAuthStore()
const toast = useToast()
const primaryRouter = useRouter()
const frameLayer = ref<HTMLElement | null>(null)
workspace.resetLayout(1, primaryRouter.currentRoute.value.fullPath)
const pinia = getActivePinia()!
function globalNavigation(to: Parameters<typeof primaryRouter.push>[0], mode?: 'assign' | 'replace'): void {
  if (typeof to === 'string' && mode) {
    const url = new URL(to, window.location.origin)
    if (['http:', 'https:'].includes(url.protocol)) window.location[mode](url.href)
  } else if (typeof to === 'string' && /^https?:\/\//i.test(to)) window.location.replace(to)
  else void primaryRouter.push(to)
}
const windows = createWindowManager({
  pinia,
  frames: true,
  frameContainer: () => frameLayer.value,
  agendaFor: fullPath => props.agendaFor?.(fullPath) ?? '',
  currentSupplier: () => ({ id: supplier.currentSupplierId, name: supplier.currentSupplier?.company_name ?? '' }),
  switchSupplier: async id => {
    if (supplier.domainLocked || !supplier.availableSuppliers.some(candidate => candidate.id === id)) return false
    const previous = supplier.currentSupplierId
    auth.clearPermissions()
    supplier.setSupplier(id)
    if (!await auth.refresh() || supplier.currentSupplierId !== id) {
      supplier.setSupplier(previous)
      await auth.refresh()
      return false
    }
    if (!auth.isDemo) await authApi.setDefaultSupplier(id).catch(() => undefined)
    return true
  },
  appearance: fullPath => ({ theme: isDark.value ? 'dark' : 'light', locale: locale.value,
    accent: props.accentFor?.(fullPath) ?? 'primary',
    singlePanel: workspace.paneCount === 1,
    ...(fullPath.split(/[?#]/)[0] === NEW_TAB_PATH ? { menuItems: workspaceMenuItems.value, openTabs: workspaceOpenTabs.value } : {}),
    csrfToken: auth.csrfToken,
    shortcutActions: shortcuts.actions.value.map(action => ({ ...action })),
    globalShortcuts: [...Object.values(shortcuts.effectiveBindings.value).filter(Boolean), 'ctrl+k', 'ctrl+alt+1', 'ctrl+alt+2', 'ctrl+alt+3', 'alt+shift+1', 'alt+shift+2', 'alt+shift+3'] }),
  keyboard: event => {
    const keyboardEvent = new KeyboardEvent('keydown', { ...event, bubbles: true, cancelable: true })
    const action = shortcuts.actionForEvent(keyboardEvent)
    if (event.editable && action && action.id !== 'search.global' && !action.id.startsWith('windows.')) return
    window.dispatchEvent(keyboardEvent)
  },
  globalNavigate: globalNavigation,
  createRouter: () => createPaneRouter({
    prepareRoutes: applyAuthorizationMeta,
    guard: (to, from) => authorizationGuard(to, from, { allowGlobalSideEffects: false, onGlobalNavigation: globalNavigation }),
    onGlobalNavigation: globalNavigation,
  }),
  authorize: async (router, state) => {
    const resolved = state ? router.resolve(state.fullPath) : router.currentRoute.value
    const route = { ...resolved, name: resolved.name ?? undefined }
    const result = await authorizationGuard(route, route, { allowGlobalSideEffects: false, onGlobalNavigation: globalNavigation })
    return result === true
  },
  onError: () => toast.error(t('workspace.load_error')),
})
function sourcePaneForClick(event: MouseEvent): PaneId {
  const id = event.target instanceof Element
    ? event.target.closest<HTMLElement>('[data-workspace-pane]')?.dataset.workspacePane
    : undefined
  return workspace.panes.find(pane => pane.id === id)?.id ?? workspace.activePaneId
}
const removeShiftClick = installShiftClickNavigation(primaryRouter,
  () => true,
  (to, event) => { void windows.openInTab(to, sourcePaneForClick(event)) })
let syncingUrl = false
let mirrorTask = Promise.resolve()
let stopped = false
const removeGlobalAfterEach = primaryRouter.afterEach((to, _from, failure) => {
  if (!failure && !syncingUrl && to.matched.some(record => record.meta.requiresAuth)) {
    const id = primaryRouter.options.history.state.workspaceWindowId
    const existing = typeof id === 'string' ? workspace.windows.find(window => window.id === id && window.fullPath === to.fullPath) : undefined
    if (existing) void windows.select(existing.id)
    else void windows.open(to.fullPath, 'primary')
  }
})
const primaryWindow = computed(() => workspace.windows.find(window => window.paneId === 'primary'))
watch([() => workspace.panes[0]?.fullPath, () => workspace.companySwitching, () => primaryWindow.value?.id], ([fullPath, switching, id]) => {
  if (switching) return
  const target = fullPath ?? '/'
  mirrorTask = mirrorTask.then(async () => {
    if (stopped || target !== (workspace.panes[0]?.fullPath ?? '/') || id !== primaryWindow.value?.id) return
    syncingUrl = true
    try { await mirrorWindowLocation(primaryRouter, target, id, primaryWindow.value?.supplierId) }
    finally { syncingUrl = false }
  }).catch(() => toast.error(t('workspace.load_error')))
})
const host = ref<HTMLElement | null>(null)
const grid = ref<HTMLElement | null>(null)
const width = ref(0)
const viewportHeight = ref<number | null>(null)
const paneFractions = ref<number[]>([1])
let observer: ResizeObserver | null = null
let resizeIndex: number | null = null
let resizeStartX = 0
let resizeStartFractions: number[] = []
const splitterWidth = 6

const allowTwo = computed(() => width.value >= 1100)
const allowThree = computed(() => width.value >= 1600)
const gridStyle = computed(() => ({
  height: viewportHeight.value === null ? undefined : `${viewportHeight.value}px`,
  gridTemplateColumns: paneFractions.value
    .flatMap((fraction, index) => [
      `minmax(18rem, ${fraction}fr)`,
      ...(index < paneFractions.value.length - 1 ? [`${splitterWidth}px`] : []),
    ])
    .join(' '),
}))

function measureViewport(): void {
  if (!host.value) return
  width.value = host.value.clientWidth
  const footer = document.querySelector<HTMLElement>('footer.sticky')
  const main = host.value.closest('main')
  const bottomPadding = workspace.paneCount === 1 && main ? parseFloat(getComputedStyle(main).paddingBottom) || 0 : 0
  viewportHeight.value = Math.max(240, Math.floor(window.innerHeight - Math.max(0, host.value.getBoundingClientRect().top)
    - (footer?.getBoundingClientRect().height ?? 0) - bottomPadding))
}

function resetFractions(): void {
  paneFractions.value = Array.from({ length: workspace.paneCount }, () => 1 / workspace.paneCount)
}

function resizeByPixels(index: number, deltaX: number, source: number[]): void {
  if (!grid.value) return
  const paneWidth = grid.value.clientWidth - splitterWidth * (workspace.paneCount - 1)
  if (paneWidth <= 0) return
  paneFractions.value = resizePaneFractions(source, index, deltaX, paneWidth)
}

function onResizeMove(event: PointerEvent): void {
  if (resizeIndex === null) return
  resizeByPixels(resizeIndex, event.clientX - resizeStartX, resizeStartFractions)
}

function stopResize(): void {
  resizeIndex = null
  document.removeEventListener('pointermove', onResizeMove)
  document.removeEventListener('pointerup', stopResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
}

function startResize(index: number, event: PointerEvent): void {
  resizeIndex = index
  resizeStartX = event.clientX
  resizeStartFractions = [...paneFractions.value]
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
  document.addEventListener('pointermove', onResizeMove)
  document.addEventListener('pointerup', stopResize, { once: true })
}

function resizeWithKeyboard(index: number, event: KeyboardEvent): void {
  if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return
  event.preventDefault()
  resizeByPixels(index, event.key === 'ArrowLeft' ? -32 : 32, paneFractions.value)
}

function activatePanelShortcut(event: KeyboardEvent): void {
  if (event.repeat || event.isComposing || event.getModifierState?.('AltGraph')
    || (event.target instanceof Element && event.target.closest('[data-shortcut-capture]'))) return
  const index = panelIndexFromShortcut(event)
  if (index !== null) {
    const pane = workspace.panes[index]
    if (!pane) return
    event.preventDefault()
    event.stopImmediatePropagation()
    navigation.activatePane(pane.id)
    getPaneRuntime(pane.id)?.root.focus({ preventScroll: true })
    getPaneRuntime(pane.id)?.focus?.()
    return
  }

  const count = paneCountFromShortcut(event)
  if (count === null || count > workspace.maximumPaneCount) return
  event.preventDefault()
  event.stopImmediatePropagation()
  void navigation.setPaneCount(count)
}

async function enforceSupportedLayout(): Promise<void> {
  if ((workspace.paneCount === 3 && !allowThree.value) || (workspace.paneCount === 2 && !allowTwo.value)) {
    await navigation.setPaneCount(1)
  }
}

watch(() => auth.user?.id, () => {
  windows.reset()
  workspace.resetLayout(1, primaryRouter.currentRoute.value.fullPath)
  void windows.open(primaryRouter.currentRoute.value.fullPath, 'primary')
}, { flush: 'sync' })
watch([locale, isDark, shortcuts.effectiveBindings, () => auth.csrfToken], () => windows.updateFrames(), { deep: true, flush: 'sync' })
function reloadShortcuts(): void { void shortcuts.load(true) }
watch(() => auth.permissions, () => { void windows.reauthorize() }, { deep: true })
watch(() => props.menuItems, items => { workspaceMenuItems.value = plainMenuItems(items ?? []) }, { immediate: true })
// Nová záložka bez firmy (okno přímo v panelu) čte přehled ze stejného modulu.
watch(() => workspace.windows.map(window => `${window.id}:${window.tab}:${window.title}:${window.paneId}`).join('|'),
  () => { workspaceOpenTabs.value = openTabsSummary(workspace.windows) }, { immediate: true, flush: 'sync' })
setOpenTabSelectHandler(id => { void windows.select(id) })
watch(width, () => { void enforceSupportedLayout() })
watch(() => workspace.paneCount, () => { resetFractions(); void nextTick(() => { measureViewport(); windows.updateFrames() }) }, { immediate: true })
watch([allowTwo, allowThree], () => {
  workspace.setMaximumPaneCount(allowThree.value ? 3 : allowTwo.value ? 2 : 1)
  windows.updateFrames()
}, { immediate: true })

onMounted(() => {
  document.documentElement.classList.add('workspace-shell')
  window.addEventListener('myucto:shortcuts-changed', reloadShortcuts)
  void windows.open(primaryRouter.currentRoute.value.fullPath, 'primary')
  window.addEventListener('keydown', activatePanelShortcut)
  observer = new ResizeObserver(measureViewport)
  if (host.value) observer.observe(host.value)
  for (const element of document.querySelectorAll('header.sticky, footer.sticky, main, [data-workspace-tabs]')) observer.observe(element)
  window.addEventListener('resize', measureViewport)
  void nextTick(() => {
    width.value = host.value?.clientWidth ?? 0
    measureViewport()
    void enforceSupportedLayout()
  })
})

onBeforeUnmount(() => {
  removeShiftClick()
  document.documentElement.classList.remove('workspace-shell')
  window.removeEventListener('myucto:shortcuts-changed', reloadShortcuts)
  stopped = true
  removeGlobalAfterEach()
  windows.destroy()
  window.removeEventListener('keydown', activatePanelShortcut)
  observer?.disconnect()
  window.removeEventListener('resize', measureViewport)
  stopResize()
})
</script>

<template>
  <div ref="host" class="workspace-host relative min-h-0 min-w-0" :class="workspace.paneCount > 1 ? 'workspace-host-multi' : ''">
    <div ref="grid" class="workspace-grid min-h-0 min-w-0" :inert="workspace.companySwitching" :class="workspace.paneCount > 1 ? 'grid' : 'block'" :style="gridStyle">
      <template v-for="(pane, index) in workspace.panes" :key="`${pane.id}:${workspace.layoutRevision}`">
        <WorkspacePane
          :pane-id="pane.id"
          :index="index + 1"
          :global-router="primaryRouter"
          :show-header="workspace.paneCount > 1"
          :single="workspace.paneCount === 1"
        />
        <div
          v-if="index < workspace.panes.length - 1"
          class="workspace-splitter group relative z-10 cursor-col-resize bg-surface print:hidden"
          role="separator"
          tabindex="0"
          aria-orientation="vertical"
          :aria-label="t('workspace.resize_panels')"
          @pointerdown.prevent="startResize(index, $event)"
          @keydown="resizeWithKeyboard(index, $event)"
        >
          <span class="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-neutral-200 transition-all group-hover:w-0.5 group-hover:bg-primary-400 group-focus:w-0.5 group-focus:bg-primary-500" />
        </div>
      </template>
    </div>
    <div ref="frameLayer" class="pointer-events-none absolute inset-0 overflow-hidden" :inert="workspace.companySwitching" />
  </div>
</template>

<style scoped>
.workspace-grid {
  min-height: 0;
  height: calc(100dvh - 9.1rem);
  min-height: 24rem;
}

.workspace-host-multi {
  margin-right: -2rem;
  margin-bottom: -1.5rem;
  margin-left: -2rem;
}

.workspace-host-multi:first-child {
  margin-top: -1.5rem;
}

.workspace-host-multi .workspace-grid {
  height: calc(100vh - 6.1rem);
  min-height: 32rem;
}

@media print {
  .workspace-grid {
    display: block;
    height: auto;
    min-height: 0;
  }

  .workspace-pane:not(.workspace-pane-active) {
    display: none;
  }
}
</style>
