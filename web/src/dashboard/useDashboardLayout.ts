import { computed, onActivated, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { dashboardLayoutApi } from './api'
import { defaultLayout, geometryFor } from './layoutRegistry'
import { cloneLayout, moveBefore, type BoardId, type DashboardLayout, type WidgetCatalog, type WidgetData, type WidgetDefinition, type WidgetResult } from './types'

/** Rozložení je osobní; data a katalog vždy patří aktuálnímu uživateli a firmě. */
export function useDashboardLayout(board: BoardId, api = dashboardLayoutApi) {
  const auth = useAuthStore()
  const supplier = useSupplierStore()
  const catalog = shallowRef<WidgetCatalog | null>(null)
  const saved = ref<DashboardLayout | null>(null)
  const draft = ref<DashboardLayout | null>(null)
  const revision = ref('0')
  const editing = ref(false)
  const loading = ref(false)
  const saving = ref(false)
  const recovered = ref(false)
  const writable = ref(true)
  const error = ref('')
  const results = shallowRef<Record<string, WidgetResult>>({})
  let epoch = 0
  let queryEpoch = 0
  let activated = false
  const layout = computed(() => draft.value ?? saved.value ?? defaultLayout(board))
  const allowedTypes = computed(() => new Set(catalog.value?.widgets.map(widget => widget.type) ?? []))
  const dataItems = computed(() => layout.value.items.filter(item => item.kind === 'widget' && allowedTypes.value.has(item.type)))
  const visible = computed(() => layout.value.items.filter(item => item.kind !== 'widget' || allowedTypes.value.has(item.type)))
  const hiddenCount = computed(() => layout.value.items.length - visible.value.length)

  function invalidate() {
    epoch++; queryEpoch++
    catalog.value = null; saved.value = null; draft.value = null; results.value = {}
    editing.value = false; loading.value = false; saving.value = false; error.value = ''; recovered.value = false
  }
  async function query(): Promise<void> {
    const token = epoch; const request = ++queryEpoch
    if (!dataItems.value.length) { results.value = {}; return }
    const widgets = dataItems.value.map(item => ({ ...item, options: { ...item.options } }))
    try {
      const response = await api.query(board, widgets)
      if (token === epoch && request === queryEpoch) results.value = response.widgets
    } catch {
      if (token === epoch && request === queryEpoch) results.value = Object.fromEntries(widgets.map(item => [item.id, { status: 'error' as const }]))
    }
  }
  async function load() {
    if (editing.value || loading.value || !auth.user?.id || !supplier.hasSupplier) return
    const token = epoch
    loading.value = true
    error.value = ''
    try {
      const [available, personal] = await Promise.all([api.catalog(board), api.layout(board), import('@/i18n').then(({ ensureNamespaces }) => ensureNamespaces(['dashboardWidgets']))])
      if (token !== epoch || editing.value) return
      catalog.value = available; revision.value = personal.revision; recovered.value = personal.recovered
      writable.value = personal.writable !== false && !auth.isDemo
      saved.value = personal.layout ? cloneLayout(personal.layout) : null
      await query()
    } catch {
      if (token === epoch) error.value = 'loadError'
    } finally { if (token === epoch) loading.value = false }
  }
  function edit() {
    if (catalog.value && !loading.value) { draft.value = cloneLayout(layout.value); editing.value = true; error.value = '' }
  }
  function cancel() { if (!saving.value) { draft.value = null; editing.value = false; error.value = ''; void query() } }
  function remove(id: string) { if (draft.value && !saving.value) draft.value.items = draft.value.items.filter(item => item.id !== id) }
  function move(id: string, before: string | null) { if (draft.value && !saving.value) moveBefore(draft.value, id, before) }
  function addSection(type: string) {
    const item = defaultLayout(board).items.find(section => section.type === type)
    if (item && draft.value && !saving.value && draft.value.items.length < 64 && !draft.value.items.some(row => row.kind === 'section' && row.type === type)) draft.value.items.push(item)
  }
  function add(definition: WidgetDefinition) {
    if (!draft.value || saving.value || draft.value.items.length >= 64 || draft.value.items.filter(item => item.kind === 'widget').length >= 32) return
    const geometry = geometryFor(definition.type)
    if (!geometry || !allowedTypes.value.has(definition.type)
      || (definition.type === 'actions.todo' && draft.value.items.some(item => item.kind === 'widget' && item.type === definition.type))) return
    draft.value.items.push({ id: `widget-${Array.from(crypto.getRandomValues(new Uint32Array(4)), value => value.toString(16)).join('-')}`, kind: 'widget', type: definition.type,
      span: geometry.defaultSpan, height: geometry.defaultHeight,
      options: Object.fromEntries(Object.entries(definition.options).map(([key, option]) => [key, option.default])) })
    void query()
  }
  function addDivider() {
    if (draft.value && !saving.value && draft.value.items.length < 64 && draft.value.items.filter(item => item.kind === 'divider').length < 16) {
      draft.value.items.push({ id: `divider-${Array.from(crypto.getRandomValues(new Uint32Array(4)), value => value.toString(16)).join('-')}`, kind: 'divider', type: 'divider', span: 12, height: 'auto', options: {} })
    }
  }
  async function persist(reset = false) {
    if (!draft.value || saving.value || !writable.value) return
    const token = epoch
    saving.value = true; error.value = ''
    try {
      const response = reset ? await api.reset(board, revision.value) : await api.save(board, cloneLayout(draft.value), revision.value)
      if (token !== epoch) return
      revision.value = response.revision; saved.value = response.layout; recovered.value = false
      draft.value = null; editing.value = false
      await query()
    } catch (failure: unknown) {
      if (token === epoch) error.value = (failure as { response?: { status?: number } }).response?.status === 409 ? 'conflict' : 'saveError'
    } finally { if (token === epoch) saving.value = false }
  }
  async function reloadWidget(id: string): Promise<WidgetData> {
    const token = epoch
    await query()
    const result = results.value[id]
    if (token !== epoch || !result?.data || !['ok', 'empty'].includes(result.status)) throw new Error('Widget data unavailable')
    return result.data
  }
  // Při přepnutí kontextu nesmí staré odpovědi znovu zveřejnit předchozí data ani návrh.
  watch(() => JSON.stringify([auth.user?.id, supplier.currentSupplierId, auth.permissions, auth.license]), () => {
    invalidate(); void load()
  }, { immediate: true, flush: 'sync' })
  onActivated(() => { if (activated) void load(); activated = true })
  onBeforeUnmount(invalidate)
  useWindowRefresh(load, () => !editing.value && !saving.value, { partial: true })
  return { board, catalog, layout, draft, visible, hiddenCount, results, revision, editing, loading, saving, recovered, writable, error,
    load, query, edit, cancel, remove, move, add, addDivider, addSection, persist, reloadWidget }
}
export type DashboardLayoutState = ReturnType<typeof useDashboardLayout>
