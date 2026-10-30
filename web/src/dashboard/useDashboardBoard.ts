import { computed, onBeforeUnmount, ref, shallowRef, type Ref } from 'vue'
import { dashboardBoardApi } from './api'
import { cloneLayout, visibleInstances, type BoardId, type DashboardLayout, type WidgetCatalog, type WidgetDefinition, type WidgetResult } from './types'

/** Úplná osobní konfigurace zůstává oddělená od aktuálně povolených instancí. */
export function useDashboardBoard(board: Ref<BoardId>, api = dashboardBoardApi) {
  const catalog = shallowRef<WidgetCatalog | null>(null)
  const saved = ref<DashboardLayout | null>(null)
  const draft = ref<DashboardLayout | null>(null)
  const revision = ref('')
  const businessActivity = ref<boolean | null>(null)
  const results = shallowRef<Record<string, WidgetResult>>({})
  const editing = ref(false)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const noCompany = ref(false)
  let epoch = 0
  let queryEpoch = 0
  const layout = computed(() => editing.value ? draft.value : saved.value)
  const visible = computed(() => visibleInstances(layout.value, catalog.value))
  const dirty = computed(() => editing.value && JSON.stringify(draft.value) !== JSON.stringify(saved.value))
  const hiddenCount = computed(() => (layout.value?.widgets.length ?? 0) - visible.value.length)
  function invalidate() {
    epoch++; queryEpoch++; catalog.value = null; saved.value = null; draft.value = null
    results.value = {}; businessActivity.value = null; editing.value = false; error.value = ''; noCompany.value = false; loading.value = false; saving.value = false
  }
  async function query() {
    const token = epoch; const request = ++queryEpoch
    const widgets = visible.value.filter(w => w.type !== 'layout.divider').map(w => ({ ...w, options: { ...w.options } }))
    try {
      const response = await api.query(board.value, widgets)
      if (token !== epoch || request !== queryEpoch) return
      results.value = response.widgets
      businessActivity.value = response.businessActivity?.hasDocuments ?? catalog.value?.businessActivity?.hasDocuments ?? null
    } catch {
      if (token === epoch && request === queryEpoch) results.value = Object.fromEntries(widgets.map(w => [w.id, { status: 'error' as const }]))
    }
  }
  async function load() {
    if (editing.value) { await query(); return }
    const token = ++epoch
    loading.value = !saved.value; error.value = ''; noCompany.value = false
    try {
      const [available, personal] = await Promise.all([api.catalog(board.value), api.layout(board.value)])
      if (token !== epoch || editing.value) return
      catalog.value = available; businessActivity.value = available.businessActivity?.hasDocuments ?? null; revision.value = personal.revision
      saved.value = cloneLayout(personal.layout ?? available.defaultLayout)
      await query()
    } catch (e: unknown) {
      if (token !== epoch || editing.value) return
      results.value = {}; catalog.value = null; saved.value = null
      noCompany.value = (e as { response?: { status?: number } }).response?.status === 403
      error.value = 'loadError'
    } finally { if (token === epoch) loading.value = false }
  }
  function edit() { if (saved.value) { draft.value = cloneLayout(saved.value); editing.value = true; error.value = '' } }
  function cancel() { draft.value = null; editing.value = false; error.value = ''; void load() }
  function add(def: WidgetDefinition) {
    if (!draft.value || draft.value.widgets.length >= 64) return
    const sameKind = draft.value.widgets.filter(w => (w.type === 'layout.divider') === (def.type === 'layout.divider')).length
    if (sameKind >= 32) return
    if (!def.repeatable && draft.value.widgets.some(w => w.type === def.type)) return
    draft.value.widgets.push({ id: `widget-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`, type: def.type, span: def.defaultSpan, height: (def.defaultHeight ?? 'auto') as 'auto', options: Object.fromEntries(Object.entries(def.options).map(([k, v]) => [k, v.default])) })
    void query()
  }
  async function persist(reset = false) {
    if (!draft.value || !catalog.value || saving.value) return
    const token = epoch; saving.value = true; error.value = ''
    try {
      const response = reset ? await api.reset(board.value, revision.value) : await api.save(board.value, cloneLayout(draft.value), revision.value)
      if (token !== epoch) return
      revision.value = response.revision; saved.value = cloneLayout(response.layout ?? catalog.value.defaultLayout)
      editing.value = false; draft.value = null; await query()
    } catch (e: unknown) {
      if (token === epoch) error.value = (e as { response?: { status?: number } }).response?.status === 409 ? 'conflict' : 'loadError'
    } finally { if (token === epoch) saving.value = false }
  }
  onBeforeUnmount(invalidate)
  return { catalog, saved, draft, layout, visible, revision, results, businessActivity, editing, loading, saving, error, noCompany, dirty, hiddenCount, invalidate, load, query, edit, cancel, add, persist }
}
