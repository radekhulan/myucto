<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, ref, toRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { useDashboardBoard } from '@/dashboard/useDashboardBoard'
import { moveBefore, moveInstance, type BoardId, type WidgetDefinition, type WidgetInstance } from '@/dashboard/types'
import { widgetComponents } from '@/dashboard/registry'
import { hasBusinessActivity } from '@/dashboard/activity'
import { groupWidgets, cellHeightForWidth, rowsForContent } from '@/dashboard/grid'
import DashboardDragHandle from './DashboardDragHandle.vue'
import WidgetCatalog from './WidgetCatalog.vue'
import WidgetResizeHandle from './WidgetResizeHandle.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
const props = defineProps<{ board: BoardId }>()
const emit = defineEmits<{ empty: [boolean] }>()
const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const state = useDashboardBoard(toRef(props, 'board'))
const { catalog, draft, visible, results, businessActivity, editing, loading, saving, error, noCompany, hiddenCount } = state
const dragging = ref('')
const dropTarget = ref('')
const dropGroup = ref('')
let insertionBefore: string | null | undefined
let dragOrigin = { x: 0, y: 0 }
const settingsFor = ref('')
const grid = ref<HTMLElement | null>(null)
const cellHeight = ref(40)
const measured = ref<Record<string, number>>({})
let observer: ResizeObserver | undefined
const elements = new Map<string, HTMLElement>()
function observeCard(id: string, element: unknown) {
  const old = elements.get(id)
  if (old) observer?.unobserve(old)
  if (element instanceof HTMLElement) { elements.set(id, element); observer?.observe(element) }
  else elements.delete(id)
}
function rowSpan(instance: WidgetInstance) { return rowsForContent(measured.value[instance.id] ?? 160, cellHeight.value, instance.height) }
onMounted(() => {
  if (typeof ResizeObserver === 'undefined') return
  observer = new ResizeObserver(entries => {
    for (const entry of entries) {
      if (entry.target === grid.value) cellHeight.value = cellHeightForWidth(entry.contentRect.width)
      else { const id = (entry.target as HTMLElement).dataset.widgetId; if (id) measured.value[id] = entry.borderBoxSize?.[0]?.blockSize ?? entry.contentRect.height + 32 }
    }
  })
  if (grid.value) observer.observe(grid.value)
  for (const element of elements.values()) observer.observe(element)
})
onBeforeUnmount(() => { observer?.disconnect(); cancelDrag() })
watch(saving, value => { if (value) cancelDrag() })
watch(editing, value => { if (!value) cancelDrag() })
const definitions = computed(() => new Map(catalog.value?.widgets.map(w => [w.type, w]) ?? []))
const addable = computed(() => catalog.value?.widgets.filter(w => w.type !== 'layout.divider').filter(w => w.repeatable || !draft.value?.widgets.some(i => i.type === w.type)) ?? [])
const dataWidgetCount = computed(() => draft.value?.widgets.filter(w => w.type !== 'layout.divider').length ?? 0)
const dividerCount = computed(() => draft.value?.widgets.filter(w => w.type === 'layout.divider').length ?? 0)
const groups = computed(() => groupWidgets(visible.value, editing.value))
function addDivider() { const def = definitions.value.get('layout.divider'); if (def) state.add(def) }

const button = 'inline-flex items-center justify-center gap-1.5 rounded-md border px-3 py-2 text-sm font-medium whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed'
function move(id: string, delta: number) {
  const index = visible.value.findIndex(w => w.id === id)
  const target = visible.value[index + delta]
  if (draft.value && target) moveInstance(draft.value, id, target.id)
}
function cancelDrag() { dragging.value = ''; dropTarget.value = ''; dropGroup.value = ''; insertionBefore = undefined }
function startDrag(id: string, x: number, y: number) { cancelDrag(); dragging.value = id; dragOrigin = { x, y } }
function dragTo(x: number, y: number) {
  if (!dragging.value || saving.value) { cancelDrag(); return }
  if (Math.hypot(x - dragOrigin.x, y - dragOrigin.y) < 6) return
  dropTarget.value = ''; dropGroup.value = ''; insertionBefore = undefined
  const contains = (rect: DOMRect) => rect.width > 0 && rect.height > 0 && x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom
  const cards = [...(grid.value?.querySelectorAll<HTMLElement>('[data-drop-id]') ?? [])]
  const hit = cards.find(element => element.dataset.dropId !== dragging.value && contains(element.getBoundingClientRect()))
  if (hit) {
    const id = hit.dataset.dropId!
    const rect = hit.getBoundingClientRect()
    const index = visible.value.findIndex(widget => widget.id === id)
    const after = x >= rect.left + rect.width / 2
    insertionBefore = after ? visible.value[index + 1]?.id ?? null : id
    dropTarget.value = id
    return
  }
  // Volné místo patří celé skupině, nikoliv nejbližší kartě nebo dalšímu oddělovači.
  const zone = [...(grid.value?.querySelectorAll<HTMLElement>('[data-group-dropzone]') ?? [])].find(element => contains(element.getBoundingClientRect()))
  if (zone) {
    const index = groups.value.findIndex(group => group.id === zone.dataset.groupDropzone)
    insertionBefore = groups.value[index + 1]?.divider?.id ?? null
    dropGroup.value = zone.dataset.groupDropzone ?? ''
  }
}
function finishDrag() {
  if (!saving.value && draft.value && dragging.value && insertionBefore !== undefined) moveBefore(draft.value, dragging.value, insertionBefore)
  cancelDrag()
}
function remove(id: string) { if (draft.value) draft.value.widgets = draft.value.widgets.filter(w => w.id !== id) }

function resizeAxes(definition: WidgetDefinition): ('width' | 'height' | 'both')[] {
  const width = definition.spans.length > 1
  const height = (definition.heights?.length ?? 0) > 1
  return [...(width ? ['width' as const] : []), ...(height ? ['height' as const] : []), ...(width && height ? ['both' as const] : [])]
}
function optionValue(value: string | number | boolean) {
  return typeof value === 'string' && ['all','auto','table','chart','both','left','right','above','below'].includes(value) ? t(`dashboardBoard.${value}`) : typeof value === 'string' && ['current_month','last_month','ytd','prev_year_ytd','last_12m'].includes(value) ? t(`portal.period_${value}`) : String(value)
}
function optionChanged(instance: WidgetInstance, name: string, event: Event, def: WidgetDefinition) {
  const value = (event.target as HTMLInputElement).value
  const option = def.options[name]
  instance.options[name] = option?.type === 'enum' ? option.values?.find(v => String(v) === value) ?? value : value.toUpperCase() === 'AUTO' ? 'auto' : value.toUpperCase() === 'ALL' ? 'all' : value.toUpperCase()
  void state.query()
}
// Při změně kontextu se citlivá data ihned vymažou a opožděné odpovědi zahodí.
watch(() => JSON.stringify([props.board, supplier.currentSupplierId, auth.user?.id, auth.permissions, auth.license, supplier.currentSupplier]), () => {
  cancelDrag(); settingsFor.value = ''; state.invalidate(); if (auth.user && supplier.hasSupplier) void state.load()
}, { immediate: true, flush: 'sync' })
useWindowRefresh('*', state.load, () => !editing.value && !saving.value)
onActivated(() => { if (auth.user && supplier.hasSupplier && !editing.value) void state.load() })
watch([results, businessActivity], ([value, activity]) => {
  emit('empty', (activity !== null || Object.keys(value).length > 0) && !(activity ?? hasBusinessActivity(value, visible.value)))
})
</script>
<template>
  <section class="space-y-4" :aria-busy="loading || saving">
    <header v-if="!noCompany" class="flex flex-wrap gap-3 items-start justify-between"><div class="min-w-0 flex-1"><slot name="heading" /></div><div class="flex flex-wrap gap-2 items-center">
      <button v-if="!editing" type="button" :disabled="!catalog || loading" :class="[button, 'border-neutral-300 text-neutral-700 hover:bg-neutral-50']" @click="state.edit"><span aria-hidden="true">✎</span>{{ t('dashboardBoard.edit') }}</button>
      <template v-else>
        <button type="button" :disabled="saving" :class="[button, 'border-success-600 bg-success-600 text-white hover:bg-success-700']" @click="state.persist()"><span aria-hidden="true">✓</span>{{ t('dashboardBoard.save') }}</button>
        <button type="button" :disabled="saving" :class="[button, 'border-neutral-300 text-neutral-700 hover:bg-neutral-50']" @click="state.cancel"><span aria-hidden="true">×</span>{{ t('dashboardBoard.cancel') }}</button>
        <button type="button" :disabled="saving" :class="[button, 'border-warning-500 text-warning-600 hover:bg-warning-50']" @click="state.persist(true)"><span aria-hidden="true">↺</span>{{ t('dashboardBoard.reset') }}</button>
      </template>
    </div></header>
    <slot name="before" />
    <p v-if="loading" role="status" class="py-8 text-center text-neutral-500">{{ t('dashboardBoard.loading') }}</p>
    <EmptyState v-else-if="noCompany" boxed accent="neutral" icon="lock" :title="t('portal.no_company')" />
    <div v-if="error && !noCompany" role="alert" class="rounded-lg border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600">
      {{ t(`dashboardBoard.${error}`) }}
      <button v-if="!editing" type="button" :class="[button, 'ml-2 border-danger-500 text-danger-600']" @click="state.load"><span aria-hidden="true">↻</span>{{ t('dashboardBoard.retry') }}</button>
    </div>
    <div v-if="editing" class="p-4 bg-surface border border-primary-200 rounded-lg space-y-3">
      <p v-if="hiddenCount" class="text-sm text-neutral-500">{{ t('dashboardBoard.hidden') }}</p>
      <WidgetCatalog :definitions="addable" :disabled="saving || dataWidgetCount >= 32" @add="state.add" />
      <button type="button" :disabled="saving || dividerCount >= 32" :class="[button, 'border-neutral-300 text-neutral-700 hover:bg-neutral-50']" @click="addDivider"><span aria-hidden="true">＋</span>{{ t('dashboardBoard.addDivider') }}</button>
      <p v-if="dataWidgetCount >= 32" class="text-xs text-warning-600">{{ t('dashboardBoard.limit') }}</p>
    </div>
    <p v-if="!loading && catalog && !visible.length" class="rounded-lg border border-neutral-200 bg-surface p-8 text-center text-neutral-500">{{ t('dashboardBoard.empty') }}</p>
    <div ref="grid" class="space-y-4" :style="{ '--cell-height': `${cellHeight}px` }">
      <section v-for="group in groups" :key="group.id" class="relative">
        <div v-if="editing && group.divider" class="relative z-20 w-full mb-3 rounded-md border border-dashed border-neutral-300 bg-surface/95 px-2 py-1 shadow-sm flex flex-wrap items-center gap-2" :data-drop-id="group.divider.id" :class="{ 'ring-2 ring-primary-400': dropTarget === group.divider.id }">
          <DashboardDragHandle :disabled="saving" @start="(x,y) => startDrag(group.divider!.id,x,y)" @move="dragTo" @finish="finishDrag" @cancel="cancelDrag" />
          <h2 class="text-xs font-medium" :title="t('dashboardBoard.dividerHint')">{{ t('dashboardBoard.divider') }}</h2>
          <div class="flex flex-wrap gap-1">
            <button type="button" :disabled="visible[0]?.id === group.divider.id || saving" :aria-label="t('dashboardBoard.up')" :class="[button, 'border-neutral-300 text-neutral-700']" @click="move(group.divider.id,-1)"><span aria-hidden="true">↑</span></button>
            <button type="button" :disabled="visible[visible.length - 1]?.id === group.divider.id || saving" :aria-label="t('dashboardBoard.down')" :class="[button, 'border-neutral-300 text-neutral-700']" @click="move(group.divider.id,1)"><span aria-hidden="true">↓</span></button>
            <button type="button" :disabled="saving" :class="[button, 'border-danger-500 text-danger-600 hover:bg-danger-50']" @click="remove(group.divider.id)"><span aria-hidden="true">×</span>{{ t('dashboardBoard.remove') }}</button>
          </div>
        </div>
        <div class="dashboard-grid grid grid-cols-1 lg:grid-cols-12 gap-4 items-start rounded-lg" :data-group-dropzone="group.id" :style="editing && !group.widgets.length ? { minHeight: '96px' } : {}" :class="{ 'ring-2 ring-primary-400 bg-primary-50/30': dropGroup === group.id }">
      <article v-for="instance in group.widgets" :key="instance.id" :class="['widget-cell relative min-w-0 bg-surface border rounded-lg shadow-sm', dropTarget === instance.id ? 'border-primary-500 ring-2 ring-primary-300' : editing ? 'border-primary-300' : 'border-neutral-200']" :style="{ '--widget-columns': instance.span, '--widget-rows': rowSpan(instance) }" :data-drop-id="instance.id" :data-drop-target="dropTarget === instance.id || undefined">
        <div :ref="element => observeCard(instance.id, element)" :data-widget-id="instance.id" class="p-4 widget-inner"><h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t(definitions.get(instance.type)!.titleKey) }}</h2>
        <p v-if="!results[instance.id]" class="text-sm text-neutral-500" role="status">{{ t('common.loading') }}</p>
        <p v-else-if="results[instance.id]?.status === 'error'" class="text-sm text-danger-600">{{ t('dashboardBoard.widgetError') }}</p>
        <p v-else-if="results[instance.id]?.status === 'unavailable'" class="text-sm text-neutral-500">{{ t('dashboardBoard.unavailable') }}</p>
        <p v-else-if="results[instance.id]?.status === 'empty'" class="text-sm text-neutral-500">{{ t('dashboardBoard.noData') }}</p>
        <component :is="widgetComponents[instance.type]" v-else :instance="instance" :data="results[instance.id]?.data ?? {}" />
        </div>
        <div v-if="editing" class="absolute -top-3 right-2 z-20 rounded-md border border-primary-200 bg-surface/95 shadow-sm flex flex-wrap items-center gap-0.5 p-0.5" data-widget-toolbar>
          <DashboardDragHandle :disabled="saving" @start="(x,y) => startDrag(instance.id,x,y)" @move="dragTo" @finish="finishDrag" @cancel="cancelDrag" />
          <button type="button" :disabled="visible[0]?.id === instance.id || saving" :aria-label="t('dashboardBoard.up')" :title="t('dashboardBoard.up')" class="p-1.5 rounded text-neutral-600 hover:bg-neutral-100 disabled:opacity-40" @click="move(instance.id,-1)">↑</button>
          <button type="button" :disabled="visible[visible.length - 1]?.id === instance.id || saving" :aria-label="t('dashboardBoard.down')" :title="t('dashboardBoard.down')" class="p-1.5 rounded text-neutral-600 hover:bg-neutral-100 disabled:opacity-40" @click="move(instance.id,1)">↓</button>
          <button type="button" :disabled="saving" :aria-label="t('dashboardBoard.options')" :title="t('dashboardBoard.options')" :aria-expanded="settingsFor === instance.id" class="p-1.5 rounded text-primary-600 hover:bg-primary-50" @click="settingsFor = settingsFor === instance.id ? '' : instance.id"><svg aria-hidden="true" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M4 7h16M4 17h16M8 4v6m8 4v6" stroke-linecap="round"/></svg></button>
          <button type="button" :disabled="saving" :aria-label="t('dashboardBoard.remove')" :title="t('dashboardBoard.remove')" class="p-1.5 rounded text-danger-600 hover:bg-danger-50" @click="remove(instance.id)"><svg aria-hidden="true" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round"/></svg></button>
          <div v-if="settingsFor === instance.id" class="absolute top-full right-0 mt-1 w-[min(20rem,calc(100vw-2rem))] rounded-lg border border-neutral-200 bg-surface p-3 shadow-lg" role="group" :aria-label="t('dashboardBoard.options')" data-widget-settings>
          <div class="flex flex-wrap gap-3">
            <label class="text-xs text-neutral-500">{{ t('dashboardBoard.width') }}<select v-model.number="instance.span" :disabled="saving" class="block rounded border border-neutral-300 bg-surface px-2 py-1 mt-1"><option v-for="span in definitions.get(instance.type)!.spans" :key="span" :value="span">{{ span }}/12</option></select></label>
            <label v-if="(definitions.get(instance.type)?.heights?.length ?? 0) > 1" class="text-xs text-neutral-500">{{ t('dashboardBoard.height') }}<select v-model="instance.height" :disabled="saving" class="block rounded border border-neutral-300 bg-surface px-2 py-1 mt-1"><option v-for="height in definitions.get(instance.type)!.heights" :key="height" :value="height">{{ t(`dashboardBoard.height_${height}`) }}</option></select></label>
            <label v-for="(option,name) in definitions.get(instance.type)!.options" v-show="name !== 'tablePosition' || instance.options.display === 'both'" :key="name" class="text-xs text-neutral-500 min-w-0">{{ t(`dashboardBoard.${name}`) }}
              <select v-if="option.type === 'enum'" :value="instance.options[name]" :disabled="saving" class="block max-w-full rounded border border-neutral-300 bg-surface px-2 py-1 mt-1" @change="optionChanged(instance, name, $event, definitions.get(instance.type)!)"><option v-for="value in option.values" :key="String(value)" :value="String(value)">{{ optionValue(value) }}</option></select>
              <input v-else :value="instance.options[name]" :disabled="saving" :pattern="option.pattern" maxlength="4" class="block w-24 max-w-full rounded border border-neutral-300 bg-surface px-2 py-1 mt-1" @change="optionChanged(instance, name, $event, definitions.get(instance.type)!)" />
            </label>
          </div>
          </div>
        </div>
        <template v-if="editing"><WidgetResizeHandle v-for="axis in resizeAxes(definitions.get(instance.type)!)" :key="axis" :axis="axis" :instance="instance" :definition="definitions.get(instance.type)!" :cell-width="cellHeight * 16 / 9" :cell-height="cellHeight" :disabled="saving" @resize="(span,height) => { instance.span = span; instance.height = height }" /></template>
      </article>
        </div>
      </section>
    </div>
  </section>
</template>

<style scoped>
@media (min-width: 1024px) {
  .dashboard-grid { grid-auto-rows: var(--cell-height); }
  .widget-cell { grid-column: span var(--widget-columns); grid-row-end: span var(--widget-rows); height: 100%; }
}
</style>
