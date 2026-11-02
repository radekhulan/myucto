<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, btnIconSm, ICONS } from '@/components/ui/buttonStyles'
import { geometryFor, sectionsFor } from '@/dashboard/layoutRegistry'
import type { DashboardLayoutState } from '@/dashboard/useDashboardLayout'
import type { LayoutItem, WidgetDefinition } from '@/dashboard/types'
const props = defineProps<{ state: DashboardLayoutState }>()
const emit = defineEmits<{ close: []; reload: [] }>()
const { t } = useI18n()
const search = ref('')
const dragged = ref('')
const target = ref<string | null>(null)
const definitions = computed(() => new Map(props.state.catalog.value?.widgets.map(definition => [definition.type, definition]) ?? []))
const catalog = computed(() => (props.state.catalog.value?.widgets ?? []).filter(widget => {
  const text = `${t(widget.titleKey)} ${t(widget.descriptionKey)}`.toLocaleLowerCase()
  return text.includes(search.value.trim().toLocaleLowerCase())
}))
const missingSections = computed(() => sectionsFor(props.state.board).filter(section => !props.state.draft.value?.items.some(item => item.kind === 'section' && item.type === section.type)))
const full = computed(() => (props.state.draft.value?.items.length ?? 0) >= 64 || (props.state.draft.value?.items.filter(item => item.kind === 'widget').length ?? 0) >= 32)
function title(item: LayoutItem): string {
  if (item.kind === 'divider') return t('dashboardBoard.divider')
  if (item.kind === 'section') return t(sectionsFor(props.state.board).find(section => section.type === item.type)!.titleKey)
  return t(`dashboardWidgets.widgets.${item.type.replaceAll('.', '_')}.title`)
}
function move(item: LayoutItem, direction: number) {
  const list = props.state.draft.value?.items ?? []
  const index = list.findIndex(row => row.id === item.id)
  if (index + direction < 0 || index + direction >= list.length) return
  props.state.move(item.id, direction < 0 ? list[index - 1]!.id : list[index + 2]?.id ?? null)
}
function drop(before: string | null) {
  if (dragged.value) props.state.move(dragged.value, before)
  dragged.value = ''; target.value = null
}
function optionLabel(value: string | number | boolean): string {
  if (['auto','all','table','chart','both'].includes(String(value))) return t(`dashboardBoard.${value}`)
  if (['current_month','last_month','ytd','prev_year_ytd','last_12m'].includes(String(value))) return t(`portal.period_${value}`)
  return String(value)
}
function optionChanged(item: LayoutItem, key: string, event: Event) {
  const input = event.target as HTMLInputElement | HTMLSelectElement
  if (!input.checkValidity()) { input.reportValidity(); return }
  const definition = definitions.value.get(item.type)?.options[key]
  if (!definition) return
  const raw = input.value
  item.options[key] = definition.type === 'enum' ? definition.values!.find(value => String(value) === raw)!
    : ['all', 'auto'].includes(raw.toLowerCase()) ? raw.toLowerCase() : raw.toUpperCase()
  void props.state.query()
}
function alreadyAdded(widget: WidgetDefinition) {
  return widget.type === 'actions.todo' && props.state.draft.value?.items.some(item => item.kind === 'widget' && item.type === widget.type)
}
</script>
<template>
  <Modal :title="t('dashboardLayout.title')" width-class="max-w-7xl" @close="!state.saving.value && emit('close')">
    <div class="space-y-5">
      <p class="text-sm text-neutral-500">{{ t('dashboardLayout.help') }}</p>
      <p v-if="!state.writable.value" role="status" class="text-sm text-warning-600">{{ t('dashboardLayout.demo') }}</p>
      <p v-if="state.hiddenCount.value" class="text-sm text-neutral-500">{{ t('dashboardLayout.hidden', { count: state.hiddenCount.value }) }}</p>
      <div v-if="state.error.value" role="alert" class="rounded-lg border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600 space-y-2">
        <p>{{ t(`dashboardLayout.${state.error.value}`) }}</p>
        <button v-if="state.error.value === 'conflict'" type="button" :class="btnOutline('warning')" @click="emit('reload')"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('dashboardLayout.reload') }}</button>
      </div>
      <ol class="space-y-2" :aria-label="t('dashboardLayout.order')">
        <li v-for="(item,index) in state.draft.value?.items" :key="item.id" :data-editor-item="item.id"
          class="flex flex-wrap items-center gap-2 rounded-lg border bg-surface p-3" :class="target === item.id ? 'border-primary-500' : 'border-neutral-200'"
          @dragover.prevent="target = item.id" @drop.prevent="drop(item.id)">
          <button type="button" :draggable="!state.saving.value" :disabled="state.saving.value" :aria-label="t('dashboardLayout.drag', { title: title(item) })" :class="btnIconSm('neutral')"
            @dragstart="dragged = item.id; $event.dataTransfer?.setData('text/plain', item.id)" @dragend="dragged = ''; target = null">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.swap" /></svg>
          </button>
          <div class="min-w-0 flex-1"><span class="text-sm font-medium">{{ title(item) }}</span><p v-if="item.kind === 'widget' && !definitions.has(item.type)" class="text-xs text-neutral-500">{{ t('dashboardBoard.unavailable') }}</p></div>
          <div class="flex flex-wrap items-center gap-1">
            <button type="button" :disabled="index === 0 || state.saving.value" :aria-label="t('dashboardLayout.up', { title: title(item) })" :class="btnIconSm('neutral')" @click="move(item,-1)"><svg class="w-4 h-4 rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.chevron" /></svg></button>
            <button type="button" :disabled="index === (state.draft.value?.items.length ?? 0) - 1 || state.saving.value" :aria-label="t('dashboardLayout.down', { title: title(item) })" :class="btnIconSm('neutral')" @click="move(item,1)"><svg class="w-4 h-4 rotate-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.chevron" /></svg></button>
            <button type="button" :disabled="state.saving.value" :aria-label="t('dashboardLayout.remove', { title: title(item) })" :class="btnIconSm('danger')" @click="state.remove(item.id)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.trash" /></svg></button>
          </div>
          <div v-if="item.kind === 'widget' && definitions.has(item.type)" class="basis-full flex flex-wrap gap-3 pt-2">
            <label class="text-xs text-neutral-500">{{ t('dashboardBoard.width') }}<select v-model.number="item.span" :disabled="state.saving.value" class="block mt-1 rounded-md border border-neutral-300 bg-surface px-2 py-1.5"><option v-for="span in (geometryFor(item.type)!.maxSpan - geometryFor(item.type)!.minSpan + 1)" :key="span" :value="span + geometryFor(item.type)!.minSpan - 1">{{ span + geometryFor(item.type)!.minSpan - 1 }}/12</option></select></label>
            <label v-if="geometryFor(item.type)!.heights.length > 1" class="text-xs text-neutral-500">{{ t('dashboardBoard.height') }}<select v-model="item.height" :disabled="state.saving.value" class="block mt-1 rounded-md border border-neutral-300 bg-surface px-2 py-1.5"><option v-for="height in geometryFor(item.type)!.heights" :key="height" :value="height">{{ t(`dashboardBoard.height_${height}`) }}</option></select></label>
            <label v-for="(option,key) in definitions.get(item.type)!.options" :key="key" class="min-w-0 text-xs text-neutral-500">{{ t(`dashboardBoard.${key}`) }}
              <select v-if="option.type === 'enum'" :value="item.options[key]" :disabled="state.saving.value" class="block mt-1 max-w-full rounded-md border border-neutral-300 bg-surface px-2 py-1.5" @change="optionChanged(item, key, $event)"><option v-for="value in option.values" :key="String(value)" :value="String(value)">{{ optionLabel(value) }}</option></select>
              <input v-else :value="item.options[key]" :pattern="option.pattern" maxlength="4" :disabled="state.saving.value" class="block mt-1 w-28 max-w-full rounded-md border border-neutral-300 bg-surface px-2 py-1.5" @change="optionChanged(item, key, $event)" />
            </label>
          </div>
        </li>
      </ol>
      <div class="rounded-lg border border-dashed border-neutral-300 p-3 text-center text-xs text-neutral-500" @dragover.prevent="target = null" @drop.prevent="drop(null)">{{ t('dashboardLayout.dropEnd') }}</div>
      <section v-if="missingSections.length" class="space-y-2"><h3 class="text-sm font-medium">{{ t('dashboardLayout.restoreBlocks') }}</h3><div class="flex flex-wrap gap-2"><button v-for="section in missingSections" :key="section.type" type="button" :disabled="state.saving.value || (state.draft.value?.items.length ?? 0) >= 64" :class="btnOutline('neutral')" @click="state.addSection(section.type)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t(section.titleKey) }}</button></div></section>
      <section class="space-y-3">
        <h3 class="text-sm font-medium">{{ t('dashboardLayout.catalog') }}</h3>
        <input v-model="search" type="search" :aria-label="t('dashboardLayout.search')" :placeholder="t('dashboardLayout.search')" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
        <p v-if="full" role="status" class="text-xs text-warning-600">{{ t('dashboardLayout.limit') }}</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <article v-for="widget in catalog" :key="widget.type" class="min-w-0 rounded-lg border border-neutral-200 bg-surface p-3 flex flex-col gap-2">
            <h4 class="text-sm font-medium">{{ t(widget.titleKey) }}</h4><p class="text-xs text-neutral-500 flex-1">{{ t(widget.descriptionKey) }}</p>
            <button type="button" :disabled="state.saving.value || full || alreadyAdded(widget)" :class="btnOutline('primary')" @click="state.add(widget)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t(alreadyAdded(widget) ? 'dashboardLayout.alreadyAdded' : 'dashboardLayout.add') }}</button>
          </article>
        </div>
        <p v-if="!catalog.length" class="text-sm text-neutral-500">{{ t('dashboardLayout.noMatch') }}</p>
        <button type="button" :disabled="state.saving.value || (state.draft.value?.items.length ?? 0) >= 64 || (state.draft.value?.items.filter(item => item.kind === 'divider').length ?? 0) >= 16" :class="btnOutline('neutral')" @click="state.addDivider()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('dashboardBoard.addDivider') }}</button>
      </section>
    </div>
    <template #footer>
      <div class="flex flex-wrap items-center gap-2 w-full">
        <button type="button" :disabled="state.saving.value || !state.writable.value" :title="!state.writable.value ? t('dashboardLayout.demo') : undefined" :class="btnFilled('success')" @click="state.persist()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('dashboardBoard.save') }}</button>
        <button type="button" :disabled="state.saving.value" :class="btnOutline('primary')" @click="emit('close')"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.eye" /></svg>{{ t('dashboardLayout.preview') }}</button>
        <button type="button" :disabled="state.saving.value" :class="btnOutline('neutral')" @click="state.cancel()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('dashboardBoard.cancel') }}</button>
        <button type="button" :disabled="state.saving.value || !state.writable.value" :title="!state.writable.value ? t('dashboardLayout.demo') : undefined" :class="[btnOutline('warning'), 'sm:ml-auto']" @click="state.persist(true)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.uturn" /></svg>{{ t('dashboardBoard.reset') }}</button>
      </div>
    </template>
  </Modal>
</template>
