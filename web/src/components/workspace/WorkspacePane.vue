<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { Router } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore, type PaneId } from '@/stores/workspace'
import { paneForCtrlClick, internalRouteFromAnchor, internalRouteFromElement, prepareExternalAnchorNavigation } from '@/workspace/panelLinks'
import { getPaneRuntime, registerPaneRuntime } from '@/workspace/runtimeRegistry'
import { getWindowManager } from '@/workspace/windowRegistry'

const props = defineProps<{
  paneId: PaneId
  index: number
  globalRouter: Router
  primaryRouter?: Router
  showHeader: boolean
  single?: boolean
}>()
const { t } = useI18n()
const workspace = useWorkspaceStore()
const navigation = useWorkspaceNavigation()
const paneElement = ref<HTMLElement | null>(null)
const mountTarget = ref<HTMLElement | null>(null)
const dragOver = ref(false)
const closeLabel = computed(() => t(workspace.panes.length > 1 ? 'workspace.close_panel' : 'workspace.close_content'))
const workspaceRouteMime = 'application/x-myucto-route'
let unregisterRuntime: (() => void) | null = null
let detach: (() => void) | undefined

function paneState() {
  return workspace.panes.find(pane => pane.id === props.paneId)
}

async function navigate(to: Parameters<Router['push']>[0]): Promise<void> {
  const manager = getWindowManager()
  if (manager) await manager.open(to, props.paneId)
  else await props.globalRouter.push(to)
}

function openLink(event: MouseEvent): void {
  if (prepareExternalAnchorNavigation(event)) return
  if (event.button !== 0 || event.metaKey || event.shiftKey || event.altKey) return
  if (!(event.target instanceof Element)) return
  const target = internalRouteFromElement(event.target)
  if (!target || !props.globalRouter.resolve(target).matched.some(record => record.meta.requiresAuth)) return
  const rightPaneId = event.ctrlKey ? paneForCtrlClick(props.paneId, workspace.panes) : props.paneId
  if (!rightPaneId) return
  event.preventDefault()
  event.stopPropagation()
  navigation.activatePane(rightPaneId)
  void getPaneRuntime(rightPaneId)?.navigate(target)
}

function onPanePointerDown(event: PointerEvent): void {
  navigation.activatePane(props.paneId)
  if (!event.ctrlKey || event.button !== 0 || event.metaKey || event.shiftKey || event.altKey) return
  if (!(event.target instanceof Element) || !internalRouteFromElement(event.target)) return
  if (paneForCtrlClick(props.paneId, workspace.panes)) event.preventDefault()
}

function hasLinkDrag(event: DragEvent): boolean {
  const types = Array.from(event.dataTransfer?.types ?? [])
  return !types.includes('Files') && (types.includes(workspaceRouteMime) || types.includes('text/uri-list'))
}

function onDragStart(event: DragEvent): void {
  if (!(event.target instanceof Element) || !event.dataTransfer) return
  const target = internalRouteFromElement(event.target)
  if (!target) return
  const url = new URL(target, window.location.origin).href
  event.dataTransfer.setData('text/uri-list', url)
  event.dataTransfer.setData('text/plain', url)
  event.dataTransfer.setData(workspaceRouteMime, target)
  event.dataTransfer.effectAllowed = 'link'
}

function onDragOver(event: DragEvent): void {
  if (!hasLinkDrag(event)) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'link'
  dragOver.value = true
}

function onDragLeave(event: DragEvent): void {
  if (event.currentTarget instanceof Element && event.relatedTarget instanceof Node
    && event.currentTarget.contains(event.relatedTarget)) return
  dragOver.value = false
}

function onDrop(event: DragEvent): void {
  dragOver.value = false
  if (!hasLinkDrag(event)) return
  const raw = event.dataTransfer?.getData(workspaceRouteMime)
    || event.dataTransfer?.getData('text/uri-list').split(/\r?\n/).find(line => line && !line.startsWith('#'))
  if (!raw) return
  const anchor = document.createElement('a')
  anchor.href = raw.trim()
  const target = internalRouteFromAnchor(anchor)
  if (!target) return
  event.preventDefault()
  event.stopPropagation()
  navigation.activatePane(props.paneId)
  void navigate(target)
}

onMounted(() => {
  if (!paneElement.value) return
  const manager = getWindowManager()
  if (mountTarget.value) detach = manager?.attach(props.paneId, mountTarget.value)
  unregisterRuntime = registerPaneRuntime({
    id: props.paneId,
    root: paneElement.value,
    get router() { return manager?.routerFor(props.paneId) ?? props.globalRouter },
    navigate,
    focus: () => manager?.focusPane(props.paneId),
    back: () => { void manager?.previous() },
    forward: () => { void manager?.next() },
    clear: () => {
      manager?.parkPane(props.paneId)
      workspace.updatePane(props.paneId, { fullPath: null, title: null, canGoBack: false, canGoForward: false })
    },
    destroy: () => { detach?.(); detach = undefined },
  })
})

onBeforeUnmount(() => {
  unregisterRuntime?.()
  detach?.()
})
</script>

<template>
  <section
    ref="paneElement"
    :data-workspace-pane="paneId"
    class="workspace-pane h-full min-w-0 min-h-0 focus:outline-none"
    :class="single
      ? ''
      : [
          'workspace-pane-container overflow-hidden bg-surface',
          workspace.activePaneId === paneId ? 'workspace-pane-active' : 'workspace-pane-inactive',
          dragOver ? 'workspace-pane-drag-over' : '',
        ]"
    :aria-label="t('workspace.panel_number', { number: index })"
    :tabindex="0"
    @pointerdown.capture="onPanePointerDown"
    @click.capture="openLink"
    @dragstart.capture="onDragStart"
    @dragover.capture="onDragOver"
    @dragleave="onDragLeave"
    @drop.capture="onDrop"
    @keydown.enter.self="navigation.activatePane(paneId)"
    @keydown.space.self.prevent="navigation.activatePane(paneId)"
  >
    <header
      v-if="showHeader"
      class="workspace-pane-header flex h-10 items-center gap-0.5 border-b px-2 transition-colors"
      :class="workspace.activePaneId === paneId ? 'workspace-pane-header-active' : 'workspace-pane-header-inactive'"
    >
      <button type="button" class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition-all hover:bg-primary-50 hover:text-primary-700 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-primary-400 disabled:cursor-default disabled:opacity-25 disabled:hover:bg-transparent disabled:hover:shadow-none"
              :disabled="!paneState()?.canGoBack" :aria-label="t('workspace.back')" :title="t('workspace.back')"
              @click="navigation.back(paneId)">
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="m12.5 4.5-5 5.5 5 5.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
      <button type="button" class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition-all hover:bg-primary-50 hover:text-primary-700 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-primary-400 disabled:cursor-default disabled:opacity-25 disabled:hover:bg-transparent disabled:hover:shadow-none"
              :disabled="!paneState()?.canGoForward" :aria-label="t('workspace.forward')" :title="t('workspace.forward')"
              @click="navigation.forward(paneId)">
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="m7.5 4.5 5 5.5-5 5.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
      <span
        class="ml-1 min-w-0 flex-1 truncate text-xs font-semibold"
        :class="workspace.activePaneId === paneId ? 'text-primary-800' : 'text-neutral-600'"
      >
        {{ paneState()?.title || t('workspace.empty_panel') }}
      </span>
      <button
        type="button"
        class="ml-1 inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-400 transition-all hover:bg-danger-100 hover:text-danger-700 hover:shadow-sm hover:ring-1 hover:ring-danger-200 focus-visible:ring-2 focus-visible:ring-danger-400"
        :aria-label="closeLabel"
        :title="closeLabel"
        @click.stop="navigation.closePane(paneId)"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round" />
        </svg>
      </button>
    </header>
    <div v-if="dragOver" class="pointer-events-none absolute inset-2 z-30 flex items-center justify-center rounded-lg border-2 border-dashed border-primary-500 bg-primary-50/90 text-sm font-semibold text-primary-800 shadow-lg">
      {{ t('workspace.drop_link_here') }}
    </div>
    <div
      class="workspace-pane-scroll min-h-0"
      :class="showHeader ? 'h-[calc(100%-2.5rem)]' : 'h-full'"
    >
      <div ref="mountTarget" v-show="paneState()?.fullPath" class="h-full min-h-0" :class="single ? '' : 'p-4 sm:p-5'">
        <slot />
      </div>
      <div v-if="!paneState()?.fullPath" class="flex h-full min-h-48 items-center justify-center p-6 text-center text-sm text-neutral-500">
        {{ t('workspace.choose_menu_item') }}
      </div>
    </div>
  </section>
</template>

<style scoped>
.workspace-pane-header {
  position: relative;
  box-sizing: border-box;
  border-top: 2px solid transparent;
  border-bottom-color: var(--color-neutral-200);
  background: color-mix(in oklab, var(--color-neutral-50) 42%, var(--color-surface));
}

.workspace-pane-header::after {
  content: "";
  position: absolute;
  right: 0;
  bottom: -1px;
  left: 0;
  height: 3px;
  background: linear-gradient(
    to right,
    color-mix(in oklab, var(--color-neutral-400) 55%, transparent),
    transparent 55%
  );
  opacity: 0.45;
  pointer-events: none;
  transition: background 160ms ease, opacity 160ms ease;
}

.workspace-pane-header-active {
  border-top-color: transparent;
  border-bottom-color: color-mix(in oklab, var(--color-primary-300) 65%, var(--color-neutral-200));
  background: color-mix(in oklab, var(--color-primary-50) 52%, var(--color-surface));
}

.workspace-pane-header-active::after {
  background: linear-gradient(
    to right,
    var(--module-accent, var(--color-primary-500)),
    transparent 55%
  );
  opacity: 0.9;
}

.workspace-pane-header-inactive {
  border-top-color: transparent;
}

.workspace-pane:focus-visible .workspace-pane-header {
  box-shadow: inset 0 0 0 2px color-mix(in oklab, var(--color-primary-500) 55%, transparent);
}

.workspace-pane-container {
  position: relative;
  container-name: workspace;
  container-type: inline-size;
}

.workspace-pane-drag-over {
  box-shadow: inset 0 0 0 2px var(--color-primary-500);
}

.workspace-pane-scroll {
  scrollbar-color: color-mix(in oklab, var(--color-neutral-400) 68%, transparent) transparent;
  scrollbar-width: thin;
}

.workspace-pane-scroll::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.workspace-pane-scroll::-webkit-scrollbar-track {
  background: transparent;
}

.workspace-pane-scroll::-webkit-scrollbar-thumb {
  min-height: 36px;
  border: 2px solid transparent;
  border-radius: 999px;
  background: color-mix(in oklab, var(--color-neutral-400) 68%, transparent);
  background-clip: padding-box;
}

.workspace-pane-scroll::-webkit-scrollbar-thumb:hover {
  background: color-mix(in oklab, var(--color-primary-500) 72%, var(--color-neutral-400));
  background-clip: padding-box;
}

.workspace-pane-scroll::-webkit-scrollbar-corner {
  background: transparent;
}
</style>
