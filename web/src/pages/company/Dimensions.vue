<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  dimensionsApi,
  type DimensionAccountCandidate,
  type DimensionAccountMapRow,
  type DimensionGroupInfo,
  type DimensionKind,
  type DimensionLevel,
  type DimensionType,
  type DimensionValue,
} from '@/api/dimensions'
import { accountingApi, type CostCenter } from '@/api/accounting'
import { logbookApi, type Car } from '@/api/logbook'
import { projectsApi, type Project } from '@/api/projects'
import { useDimensions } from '@/composables/useDimensions'
import { useAutoSlug } from '@/composables/useAutoSlug'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled, btnFilledSm, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
// Záložka Pravidla se načte až při otevření.
const DimensionRulesPanel = defineAsyncComponent(() => import('@/components/dimensions/DimensionRulesPanel.vue'))
import Modal from '@/components/ui/Modal.vue'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import DateInput from '@/components/ui/DateInput.vue'

/**
 * Firma → Dimenze: typy dimenzí a stromy jejich hodnot. Záložky dělí firemní
 * dimenze (jen tahle firma) a globální (sdílené skupinou firem).
 */
const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const dims = useDimensions()

const KINDS: DimensionKind[] = ['cost_center', 'project', 'vehicle', 'location', 'deal', 'custom']

const loading = ref(true)
const failed = ref(false)
const busy = ref(false)
const level = ref<DimensionLevel>('company')
/** Záložka Pravidla (povinné dimenze podle účtu, kontrola deníku). */
const showRules = ref(false)
const selectedTypeId = ref<number | null>(null)
const groupInfo = ref<DimensionGroupInfo | null>(null)
const expanded = ref<Set<number>>(new Set())

const canWrite = computed(() => auth.canWrite('accounting'))
const canManageCompany = computed(() => auth.canWrite('settings.company.write') && auth.isCompanyAdminRole)
const enabled = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.dimensions_enabled === true)
const group = computed(() => dims.overview.value?.group ?? null)
const typesOfLevel = computed(() => dims.types.value.filter(ty => ty.level === level.value))
const selectedType = computed(() => dims.types.value.find(ty => ty.id === selectedTypeId.value) ?? null)

async function load() {
  loading.value = true
  failed.value = false
  try {
    await dims.reload()
    if (selectedTypeId.value === null || !typesOfLevel.value.some(ty => ty.id === selectedTypeId.value)) {
      selectedTypeId.value = typesOfLevel.value[0]?.id ?? null
    }
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadGroup() {
  try {
    groupInfo.value = await dimensionsApi.group()
  } catch {
    groupInfo.value = null
  }
}

onMounted(async () => {
  if (!enabled.value) {
    loading.value = false
    return
  }
  await load()
  await loadGroup()
  await loadAccountMap()
})

// Výběr schvalovatele přímo v řádku potřebuje seznam uživatelů hned, ne až po otevření formuláře.
watch(() => selectedType.value?.requires_approval, on => { if (on) void loadLinks() })

watch(level, () => {
  selectedTypeId.value = typesOfLevel.value[0]?.id ?? null
  closeForms()
})

function errorMessage(e: any): string {
  if (e?.response?.data?.error?.code === 'system_dimension_immutable') return t('dimensions.system_history_conflict')
  return e?.response?.data?.error?.message || t('common.error')
}

async function run<T>(fn: () => Promise<T>, success?: string): Promise<T | null> {
  busy.value = true
  try {
    const result = await fn()
    if (success) toast.success(success)
    return result
  } catch (e) {
    toast.error(errorMessage(e))
    return null
  } finally {
    busy.value = false
  }
}

async function enable() {
  const data = await run(() => dimensionsApi.setEnabled(true, true), t('dimensions.enabled_toast'))
  if (!data) return
  supplierStore.patchSupplier(supplierStore.currentSupplierId, { dimensions_enabled: true })
  dims.setOverview(data)
  await load()
  await loadGroup()
}

async function createDefaults() {
  const data = await run(() => dimensionsApi.createDefaults(), t('dimensions.defaults_created'))
  if (!data) return
  dims.setOverview(data)
  await load()
}

// ── typy ─────────────────────────────────────────────────────────────────

const typeFormOpen = ref(false)
const typeForm = reactive({
  id: null as number | null,
  code: '',
  name: '',
  kind: 'custom' as DimensionKind,
  level: 'company' as DimensionLevel,
  show_on_documents: true,
  is_active: true,
  sort_order: 100,
  drives_accounts: false,
  drives_accounts_mask: '5, 6',
  requires_approval: false,
  approval_threshold: '' as string | number,
})

// Kód typu musí projít `^[a-z0-9_-]{1,30}$`, proto verzálkový kód z názvu převedeme na malá písmena.
const typeSlug = useAutoSlug(value => { typeForm.code = value.toLowerCase() }, {
  mode: 'code', maxLen: 30, taken: () => dims.types.value.map(ty => ty.code.toUpperCase()),
})
const valueSlug = useAutoSlug(value => { valueForm.code = value }, {
  mode: 'code', maxLen: 50, taken: () => dims.values.value.filter(v => v.type_id === selectedTypeId.value).map(v => v.code),
})

function newType() {
  closeForms()
  Object.assign(typeForm, {
    id: null, code: '', name: '', kind: 'custom', level: level.value, show_on_documents: true, is_active: true, sort_order: 100,
    requires_approval: false, approval_threshold: '',
  })
  typeSlug.init('')
  typeFormOpen.value = true
}

/** Limit z formuláře: prázdné nebo nekladné číslo = schvaluje se vždy. */
function thresholdPayload(): number | null {
  const raw = typeForm.approval_threshold
  if (raw === '' || raw === null) return null
  const n = Number(raw)
  return Number.isFinite(n) && n > 0 ? n : null
}

function editType(type: DimensionType) {
  closeForms()
  Object.assign(typeForm, {
    id: type.id, code: type.code, name: type.name, kind: type.kind, level: type.level,
    show_on_documents: type.show_on_documents, is_active: type.is_active, sort_order: type.sort_order,
    drives_accounts: type.drives_accounts === true, drives_accounts_mask: type.drives_accounts_mask || '5, 6',
    requires_approval: type.requires_approval === true,
    approval_threshold: type.approval_threshold ?? '',
  })
  typeFormOpen.value = true
}

async function saveType() {
  const payload = {
    name: typeForm.name.trim(),
    show_on_documents: typeForm.show_on_documents,
    is_active: typeForm.is_active,
    sort_order: typeForm.sort_order,
  }
  const approval = {
    requires_approval: typeForm.requires_approval,
    approval_threshold: typeForm.requires_approval ? thresholdPayload() : null,
  }
  let result = typeForm.id === null
    ? await run(() => dimensionsApi.createType({ ...payload, code: typeForm.code.trim(), kind: typeForm.kind, level: typeForm.level }), t('common.saved'))
    : await run(() => dimensionsApi.updateType(typeForm.id as number, {
      ...payload,
      drives_accounts: typeForm.drives_accounts,
      ...(typeForm.drives_accounts ? { drives_accounts_mask: typeForm.drives_accounts_mask.trim() } : {}),
      ...approval,
    }), t('common.saved'))
  if (!result) return
  // Nový typ se zakládá bez schvalování (smlouva ho přijímá až na PATCH), zapne se druhým krokem.
  if (typeForm.id === null && approval.requires_approval) {
    const created = result
    const patched = await run(() => dimensionsApi.updateType(created.id, approval))
    if (patched) result = patched
  }
  typeFormOpen.value = false
  await load()
  selectedTypeId.value = result.id
  await loadAccountMap()
}

async function toggleTypeApproval(type: DimensionType) {
  const result = await run(() => dimensionsApi.updateType(type.id, { requires_approval: !type.requires_approval }),
    type.requires_approval ? t('dimensions.approval_off_toast') : t('dimensions.approval_on_toast'))
  if (!result) return
  await load()
  if (!type.requires_approval) void loadLinks()
}

async function setApprover(value: DimensionValue, userId: number | null) {
  const result = await run(() => dimensionsApi.updateValue(value.id, { responsible_user_id: userId }), t('common.saved'))
  if (result) await load()
}

async function toggleTypeActive(type: DimensionType) {
  if (type.system_key) return
  const result = await run(() => dimensionsApi.updateType(type.id, { is_active: !type.is_active }),
    type.is_active ? t('dimensions.type_deactivated_toast') : t('dimensions.type_activated'))
  if (result) await load()
}

async function removeType(type: DimensionType) {
  if (type.system_key) return
  if (!confirm(t('dimensions.type_delete_confirm', { name: type.name }))) return
  const result = await run(() => dimensionsApi.deleteType(type.id))
  if (!result) return
  toast.success(result.deleted ? t('common.deleted') : t('dimensions.type_deactivated'))
  await load()
}

// ── hodnoty ──────────────────────────────────────────────────────────────

const canCreateValue = computed(() => !selectedType.value?.system_key || selectedType.value.system_key === 'activity_kind')
const editingValue = computed(() => dims.values.value.find(value => value.id === valueForm.id))
const builtinValue = computed(() => Boolean(selectedType.value?.system_key && editingValue.value?.semantic_key))

const valueFormOpen = ref(false)
const valueForm = reactive({
  id: null as number | null,
  code: '',
  name: '',
  parent_id: null as number | null,
  is_active: true,
  responsible_user_id: null as number | null,
  responsible_note: '',
  car_id: null as number | null,
  project_id: null as number | null,
  cost_center_id: null as number | null,
  note: '',
})

const candidates = ref<{ id: number; name: string }[]>([])
const cars = ref<Car[]>([])
const projects = ref<Project[]>([])
const costCenters = ref<CostCenter[]>([])
let linksLoaded = false

async function loadLinks() {
  if (linksLoaded) return
  linksLoaded = true
  const [c, ca, pr, cc] = await Promise.allSettled([
    dimensionsApi.responsibleCandidates(),
    logbookApi.listCars(),
    projectsApi.list({ per_page: 500, sort: 'name' }),
    accountingApi.listCostCenters(true),
  ])
  if (c.status === 'fulfilled') candidates.value = c.value
  if (ca.status === 'fulfilled') cars.value = ca.value
  if (pr.status === 'fulfilled') projects.value = pr.value.data
  if (cc.status === 'fulfilled') costCenters.value = cc.value
}

const tree = computed(() => (selectedTypeId.value ? dims.treeOf(selectedTypeId.value) : []))
const childCount = computed(() => {
  const out = new Map<number, number>()
  for (const { value } of tree.value) {
    if (value.parent_id !== null) out.set(value.parent_id, (out.get(value.parent_id) ?? 0) + 1)
  }
  return out
})
/** Viditelné řádky stromu — podřízené sbalené hodnoty se schovají. */
const visibleTree = computed(() => {
  const hidden = new Set<number>()
  return tree.value.filter(({ value }) => {
    if (value.parent_id !== null && (hidden.has(value.parent_id) || !expanded.value.has(value.parent_id))) {
      hidden.add(value.id)
      return false
    }
    return true
  })
})

function toggle(valueId: number) {
  const next = new Set(expanded.value)
  if (next.has(valueId)) next.delete(valueId)
  else next.add(valueId)
  expanded.value = next
}

/** Hodnoty, které mají podřízené — jen ty jde rozbalit. */
const parentIds = computed(() => [...childCount.value.keys()])
const allExpanded = computed(() => parentIds.value.length > 0 && parentIds.value.every(id => expanded.value.has(id)))

function toggleAll() {
  expanded.value = allExpanded.value ? new Set() : new Set(parentIds.value)
}

/** Možní rodiče: hodnoty typu kromě hodnoty samé a její větve. */
const parentOptions = computed(() => {
  const blocked = new Set<number>()
  if (valueForm.id !== null) {
    blocked.add(valueForm.id)
    let changed = true
    while (changed) {
      changed = false
      for (const { value } of tree.value) {
        if (value.parent_id !== null && blocked.has(value.parent_id) && !blocked.has(value.id)) {
          blocked.add(value.id)
          changed = true
        }
      }
    }
  }
  return tree.value.filter(({ value }) => !blocked.has(value.id))
})

function newValue(parentId: number | null = null) {
  if (!canCreateValue.value) return
  closeForms()
  Object.assign(valueForm, {
    id: null, code: '', name: '', parent_id: parentId, is_active: true, responsible_user_id: null,
    responsible_note: '', car_id: null, project_id: null, cost_center_id: null, note: '',
  })
  valueSlug.init('')
  if (parentId !== null) expanded.value = new Set([...expanded.value, parentId])
  mapRows.value = []
  valueFormOpen.value = true
  void loadLinks()
}

// ── účtotvorná dimenze: hodnota × syntetika → analytika ─────────────────

interface AccountMapRowForm {
  synthetic_account_id: number | null
  analytic_account_id: number | null
  valid_from: string
  valid_to: string
  creating: boolean
  new_code: string
  new_name: string
}

const accountMap = ref<DimensionAccountMapRow[]>([])
const accountCandidates = ref<DimensionAccountCandidate[]>([])
const mapRows = ref<AccountMapRowForm[]>([])
const drivingSelected = computed(() => selectedType.value?.drives_accounts === true)

async function loadAccountMap() {
  if (!drivingSelected.value) {
    accountMap.value = []
    return
  }
  try {
    accountMap.value = await dimensionsApi.accountMap()
  } catch {
    accountMap.value = []
  }
}

watch(selectedTypeId, () => { void loadAccountMap() })

function mapOf(valueId: number) {
  return accountMap.value.filter(r => r.dimension_value_id === valueId)
}

async function loadAccountCandidates() {
  if (!selectedType.value) return
  try {
    accountCandidates.value = await dimensionsApi.accountCandidates(selectedType.value.id)
  } catch {
    accountCandidates.value = []
  }
}

function analyticsOf(syntheticId: number | null) {
  return accountCandidates.value.find(c => c.id === syntheticId)?.analytics ?? []
}

function emptyMapRow(): AccountMapRowForm {
  return { synthetic_account_id: null, analytic_account_id: null, valid_from: '', valid_to: '', creating: false, new_code: '', new_name: '' }
}

function startCreateAnalytic(row: AccountMapRowForm) {
  const synthetic = accountCandidates.value.find(c => c.id === row.synthetic_account_id)
  if (!synthetic) return
  row.creating = true
  row.new_code = `${synthetic.code}.`
  row.new_name = valueForm.name.trim()
}

async function createAnalytic(row: AccountMapRowForm) {
  if (row.synthetic_account_id === null) return
  const code = row.new_code.trim()
  const account = await run(
    () => accountingApi.createAccount({ parent_id: row.synthetic_account_id as number, account_code: code, name: row.new_name.trim() }),
    t('dimensions.account_map_created', { code }),
  )
  if (!account) return
  await loadAccountCandidates()
  row.analytic_account_id = account.id
  row.creating = false
}

function editValue(value: DimensionValue) {
  closeForms()
  Object.assign(valueForm, {
    id: value.id, code: value.code, name: value.name, parent_id: value.parent_id, is_active: value.is_active,
    responsible_user_id: value.responsible_user_id, responsible_note: value.responsible_note ?? '',
    car_id: value.car_id, project_id: value.project_id, cost_center_id: value.cost_center_id, note: value.note ?? '',
  })
  mapRows.value = mapOf(value.id).map(r => ({
    ...emptyMapRow(),
    synthetic_account_id: r.synthetic_account_id,
    analytic_account_id: r.analytic_account_id,
    valid_from: r.valid_from ?? '',
    valid_to: r.valid_to ?? '',
  }))
  valueFormOpen.value = true
  void loadLinks()
  if (drivingSelected.value) void loadAccountCandidates()
}

function closeForms() {
  typeFormOpen.value = false
  valueFormOpen.value = false
}

async function saveValue() {
  if (!selectedType.value) return
  const payload = {
    ...(!builtinValue.value ? {
      name: valueForm.name.trim(),
      parent_id: valueForm.parent_id,
      is_active: valueForm.is_active,
    } : {}),
    responsible_user_id: valueForm.responsible_user_id,
    responsible_note: valueForm.responsible_note.trim() || null,
    note: valueForm.note.trim() || null,
    ...(selectedType.value.level === 'company' && !selectedType.value.system_key ? {
      car_id: valueForm.car_id,
      project_id: valueForm.project_id,
      ...(valueForm.id !== null || valueForm.cost_center_id !== null ? { cost_center_id: valueForm.cost_center_id } : {}),
    } : {}),
  }
  const typeId = selectedType.value.id
  const result = valueForm.id === null
    ? await run(() => dimensionsApi.createValue(typeId, { ...payload, code: valueForm.code.trim() }), t('common.saved'))
    : await run(() => dimensionsApi.updateValue(valueForm.id as number, payload), t('common.saved'))
  if (!result) return
  // Mapa účtů se ukládá týmž tlačítkem (jedno společné Uložit).
  if (drivingSelected.value && valueForm.id !== null) {
    const rows = mapRows.value
      .filter(r => r.synthetic_account_id !== null && r.analytic_account_id !== null)
      .map(r => ({
        synthetic_account_id: r.synthetic_account_id as number,
        analytic_account_id: r.analytic_account_id as number,
        valid_from: r.valid_from || null,
        valid_to: r.valid_to || null,
      }))
    const saved = await run(() => dimensionsApi.saveAccountMap(valueForm.id as number, rows))
    if (!saved) return
  }
  valueFormOpen.value = false
  await load()
  await loadAccountMap()
}

async function toggleActive(value: DimensionValue) {
  if (selectedType.value?.system_key && value.semantic_key) return
  const result = await run(() => dimensionsApi.updateValue(value.id, { is_active: !value.is_active }),
    value.is_active ? t('dimensions.value_closed_toast') : t('dimensions.value_opened_toast'))
  if (result) await load()
}

async function removeValue(value: DimensionValue) {
  if (selectedType.value?.system_key && value.semantic_key) return
  if (!confirm(t('dimensions.value_delete_confirm', { name: value.name }))) return
  const result = await run(() => dimensionsApi.deleteValue(value.id))
  if (!result) return
  toast.success(result.deleted ? t('common.deleted') : t('dimensions.value_closed_due_to_usage'))
  await load()
}

// ── skupina firem ────────────────────────────────────────────────────────

const groupName = ref('')
const joinGroupId = ref<number | null>(null)

async function createGroup() {
  const info = await run(() => dimensionsApi.createGroup(groupName.value), t('dimensions.group_created'))
  if (!info) return
  groupInfo.value = info
  groupName.value = ''
  await load()
}

async function joinGroup() {
  if (!joinGroupId.value) return
  const info = await run(() => dimensionsApi.joinGroup(joinGroupId.value as number), t('dimensions.group_joined'))
  if (!info) return
  groupInfo.value = info
  await load()
}

async function leaveGroup() {
  if (!confirm(t('dimensions.group_leave_confirm'))) return
  const info = await run(() => dimensionsApi.leaveGroup(), t('dimensions.group_left'))
  if (!info) return
  groupInfo.value = info
  await load()
}

function kindLabel(kind: DimensionKind) {
  return t(`dimensions.kind.${kind}`)
}

function valueCount(typeId: number) {
  return dims.values.value.filter(v => v.type_id === typeId).length
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('dimensions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('dimensions.subtitle') }}</p>
      </div>
      <div v-if="enabled" class="flex flex-wrap gap-2">
        <RouterLink to="/accounting/dimension-profit" :class="btnOutline('neutral')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('dimensions.profit_link') }}
        </RouterLink>
        <button v-if="canWrite" type="button" :disabled="busy" :class="btnFilled('primary')" class="whitespace-nowrap" @click="newType">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('dimensions.type_new') }}
        </button>
      </div>
    </div>

    <EmptyState v-if="!enabled" boxed icon="tag"
      :title="t(auth.hasCommercialFeatures ? 'dimensions.disabled_title' : 'dimensions.license_title')"
      :message="t(auth.hasCommercialFeatures ? 'dimensions.disabled_hint' : 'dimensions.license_hint')"
      :cta="!auth.hasCommercialFeatures ? t('dimensions.license_cta') : canManageCompany ? t('dimensions.enable') : undefined"
      :to="!auth.hasCommercialFeatures ? '/activation/purchase' : undefined"
      @action="enable" />

    <template v-else>
      <div class="flex flex-wrap gap-2 border-b border-neutral-200 mb-4" role="tablist">
        <button v-for="tab in (['company', 'global'] as const)" :key="tab" type="button" role="tab"
                :aria-selected="!showRules && level === tab"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
                :class="!showRules && level === tab ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
                :data-test="`dimensions-tab-${tab}`"
                @click="level = tab; showRules = false">
          {{ t(`dimensions.tab_${tab}`) }}
        </button>
        <button type="button" role="tab" :aria-selected="showRules"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
                :class="showRules ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
                data-test="dimensions-tab-rules"
                @click="showRules = true">
          {{ t('dimensions.tab_rules') }}
        </button>
      </div>

      <DimensionRulesPanel v-if="showRules" :can-write="canWrite" />

      <template v-else>
      <!-- Skupina firem: jen u globálních dimenzí. -->
      <section v-if="level === 'global'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4" data-test="dimensions-group">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('dimensions.group_title') }}</h2>
        <template v-if="group">
          <p class="text-sm"><span class="font-medium">{{ group.name }}</span></p>
          <ul class="mt-2 flex flex-wrap gap-2">
            <li v-for="m in group.members" :key="m.id" class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">
              {{ m.company_name }}<span v-if="m.ic" class="text-neutral-400"> · {{ m.ic }}</span>
            </li>
          </ul>
          <button v-if="canManageCompany" type="button" :disabled="busy" :class="btnOutlineSm('danger')" class="mt-3" @click="leaveGroup">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('dimensions.group_leave') }}
          </button>
        </template>
        <template v-else>
          <p class="text-sm text-neutral-600">{{ t('dimensions.group_none') }}</p>
          <div v-if="canManageCompany" class="mt-3 flex flex-wrap items-end gap-2">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.group_name') }}</label>
              <input v-model="groupName" type="text" maxlength="190" class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <button type="button" :disabled="busy || !groupName.trim()" :class="btnFilled('primary')" class="whitespace-nowrap" @click="createGroup">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('dimensions.group_create') }}
            </button>
            <template v-if="(groupInfo?.candidates.length ?? 0) > 0">
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.group_join_label') }}</label>
                <select v-model="joinGroupId" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option :value="null">—</option>
                  <option v-for="g in groupInfo?.candidates" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
              </div>
              <button type="button" :disabled="busy || !joinGroupId" :class="btnOutline('primary')" class="whitespace-nowrap" @click="joinGroup">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
                {{ t('dimensions.group_join') }}
              </button>
            </template>
          </div>
        </template>
      </section>

      <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="failed" variant="failed" boxed @action="load" />
      <EmptyState v-else-if="typesOfLevel.length === 0 && (level === 'company' || group)" boxed icon="tag"
        :title="t('dimensions.types_empty')"
        :message="t('dimensions.types_empty_hint')"
        :cta="canWrite ? t('dimensions.defaults_create') : undefined"
        @action="createDefaults" />

      <div v-else-if="typesOfLevel.length > 0" class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-4">
        <!-- Typy -->
        <nav class="bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 self-start" data-test="dimension-types">
          <button v-for="type in typesOfLevel" :key="type.id" type="button"
                  class="w-full text-left px-4 py-3 hover:bg-neutral-50"
                  :class="{ 'bg-primary-50': type.id === selectedTypeId, 'opacity-60': !type.is_active }"
                  @click="selectedTypeId = type.id; closeForms()">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium">{{ type.name }}<span v-if="type.system_key" class="ml-2 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700">{{ t('dimensions.system_badge') }}</span></span>
              <span class="text-xs text-neutral-400">{{ valueCount(type.id) }}</span>
            </div>
            <div class="text-xs text-neutral-500">
              {{ kindLabel(type.kind) }}<span v-if="!type.show_on_documents"> · {{ t('dimensions.type_hidden_on_documents') }}</span><span v-if="type.drives_accounts"> · {{ t('dimensions.drives_accounts_badge') }}</span><span v-if="type.requires_approval"> · {{ t('dimensions.approval_badge') }}</span>
            </div>
          </button>
        </nav>

        <!-- Strom hodnot -->
        <section v-if="selectedType" class="bg-surface border border-neutral-200 rounded-lg shadow-sm" data-test="dimension-tree">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200">
            <div>
              <h2 class="text-lg font-semibold">{{ selectedType.name }}</h2>
              <p v-if="selectedType.system_key" class="text-xs text-primary-700 break-words" data-test="dimension-system-key">{{ t('dimensions.system_badge') }}</p>
              <p class="text-xs text-neutral-500">
                {{ kindLabel(selectedType.kind) }} · {{ t(`dimensions.level_${selectedType.level}`) }}<span v-if="selectedType.drives_accounts"> · {{ t('dimensions.drives_accounts_badge') }} ({{ selectedType.drives_accounts_mask }})</span><span v-if="selectedType.requires_approval" data-test="dimension-type-approval-badge"> · {{ selectedType.approval_threshold ? t('dimensions.approval_badge_threshold', { amount: selectedType.approval_threshold }) : t('dimensions.approval_badge') }}</span>
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <label v-if="canWrite" class="inline-flex items-center gap-2 text-sm text-neutral-700 whitespace-nowrap mr-1" data-test="dimension-type-active">
                <ToggleSwitch :model-value="selectedType.is_active" :disabled="busy || !!selectedType.system_key" :label="selectedType.system_key ? t('dimensions.system_type_hint') : t('dimensions.type_active')"
                              @update:model-value="toggleTypeActive(selectedType)" />
                {{ selectedType.is_active ? t('dimensions.type_state_active') : t('dimensions.type_state_inactive') }}
              </label>
              <label v-if="canWrite" class="inline-flex items-center gap-2 text-sm text-neutral-700 whitespace-nowrap mr-1"
                     :title="t('dimensions.requires_approval_hint')" data-test="dimension-type-approval-switch">
                <ToggleSwitch :model-value="selectedType.requires_approval === true" :disabled="busy" :label="t('dimensions.requires_approval')"
                              @update:model-value="toggleTypeApproval(selectedType)" />
                {{ t('dimensions.approval_switch') }}
              </label>
              <button v-if="parentIds.length > 0" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="dimension-toggle-all" @click="toggleAll">
                <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': allExpanded }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
                {{ allExpanded ? t('dimensions.collapse_all') : t('dimensions.expand_all') }}
              </button>
              <button v-if="canWrite" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="editType(selectedType)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('dimensions.type_edit') }}
              </button>
              <button v-if="canWrite" type="button" :class="btnOutlineSm('danger')" class="whitespace-nowrap" :disabled="busy || !!selectedType.system_key" :title="selectedType.system_key ? t('dimensions.system_type_hint') : undefined" data-test="dimension-type-delete" @click="removeType(selectedType)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
              <button v-if="canWrite && canCreateValue" type="button" :class="btnFilledSm('primary')" class="whitespace-nowrap" data-test="dimension-value-new" @click="newValue(null)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('dimensions.value_new') }}
              </button>
            </div>
          </div>

          <div v-if="selectedType.system_key" class="px-4 py-3 space-y-1 text-sm text-neutral-600 border-b border-neutral-200">
            <p>{{ t('dimensions.system_type_hint') }}</p>
            <p>{{ t('dimensions.system_assignment_hint') }}</p>
            <p v-if="selectedType.system_key === 'activity_kind'">{{ t('dimensions.system_custom_hint') }}</p>
          </div>
          <p v-if="tree.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('dimensions.values_empty') }}</p>
          <ul v-else class="divide-y divide-neutral-100">
            <li v-for="{ value, depth } in visibleTree" :key="value.id"
                class="flex flex-wrap items-center gap-2 px-4 py-2"
                :data-test="`dimension-value-${value.code}`">
              <div class="flex flex-wrap items-center gap-2 min-w-0 flex-1" :class="{ 'opacity-60': !value.is_active }" :style="{ paddingLeft: `${depth * 1.25}rem` }">
                <button v-if="childCount.get(value.id)" type="button" class="w-5 h-5 inline-flex items-center justify-center text-neutral-500"
                        :aria-expanded="expanded.has(value.id)" :aria-label="t('dimensions.toggle_children')" @click="toggle(value.id)">
                  <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-90': expanded.has(value.id) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                </button>
                <span v-else class="w-5" />
                <span v-if="!value.semantic_key" class="font-mono text-xs text-neutral-500 whitespace-nowrap">{{ value.code }}</span>
                <span class="truncate">{{ dims.nameOf(value) }}</span>
                <span v-if="selectedType.system_key && value.semantic_key" class="rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 break-all" :title="t('dimensions.builtin_hint')">{{ t('dimensions.builtin_badge') }}</span>
                <span v-if="!value.is_active" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">{{ t('dimensions.closed') }}</span>
                <span v-if="value.car_registration" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ value.car_registration }}</span>
                <span v-if="value.cost_center_code" class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600">{{ t('dimensions.cost_center_short') }} {{ value.cost_center_code }}</span>
                <span v-if="selectedType.requires_approval && value.responsible_user_id && !canWrite" class="rounded bg-success-50 px-1.5 py-0.5 text-xs text-success-600 whitespace-nowrap"
                      :data-test="`dimension-approver-${value.code}`">
                  {{ t('dimensions.approver_badge') }}: {{ value.responsible_user_name }}
                </span>
                <span v-else-if="!selectedType.requires_approval && (value.responsible_user_name || value.responsible_note)" class="text-xs text-neutral-500 truncate">
                  · {{ value.responsible_user_name || value.responsible_note }}
                </span>
                <span v-for="m in (drivingSelected ? mapOf(value.id) : [])" :key="m.id"
                      class="rounded bg-primary-50 px-1.5 py-0.5 text-xs font-mono text-primary-700 whitespace-nowrap"
                      :title="m.analytic_name">{{ m.synthetic_code }} → {{ m.analytic_code }}</span>
              </div>
              <div v-if="canWrite" class="flex flex-wrap items-center justify-end gap-1">
                <label v-if="selectedType.requires_approval" class="inline-flex items-center gap-1.5 text-xs text-neutral-500 mr-1">
                  <span class="hidden sm:inline">{{ t('dimensions.approver_label') }}</span>
                  <select :value="value.responsible_user_id ?? ''" :disabled="busy"
                          class="h-7 max-w-44 px-1.5 border rounded text-xs bg-surface"
                          :class="value.responsible_user_id ? 'border-primary-300 text-neutral-800' : 'border-neutral-300 text-neutral-500'"
                          :aria-label="t('dimensions.approver_label')" :data-test="`dimension-approver-select-${value.code}`"
                          @focus="loadLinks()"
                          @change="setApprover(value, ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value))">
                    <option value="">{{ t('dimensions.approver_none') }}</option>
                    <option v-if="value.responsible_user_id && !candidates.some(u => u.id === value.responsible_user_id)" :value="value.responsible_user_id">
                      {{ value.responsible_user_name }}
                    </option>
                    <option v-for="u in candidates" :key="u.id" :value="u.id">{{ u.name }}</option>
                  </select>
                </label>
                <button v-if="canCreateValue" type="button" :class="btnOutlineSm('neutral')" :title="t('dimensions.value_add_child')" @click="newValue(value.id)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  <span class="hidden sm:inline">{{ t('dimensions.value_add_child') }}</span>
                </button>
                <button type="button" :class="btnOutlineSm('neutral')" :title="t('common.edit')" @click="editValue(value)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                </button>
                <span class="inline-flex items-center px-1.5" data-test="dimension-value-active">
                  <ToggleSwitch :model-value="value.is_active" :disabled="busy || !!(selectedType.system_key && value.semantic_key)"
                                :label="selectedType.system_key && value.semantic_key ? t('dimensions.builtin_hint') : value.is_active ? t('dimensions.value_close') : t('dimensions.value_open')"
                                @update:model-value="toggleActive(value)" />
                </span>
                <button type="button" :class="btnOutlineSm('danger')" :title="selectedType.system_key && value.semantic_key ? t('dimensions.builtin_hint') : t('common.delete')" :disabled="busy || !!(selectedType.system_key && value.semantic_key)" data-test="dimension-value-delete" @click="removeValue(value)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </div>
            </li>
          </ul>
        </section>
      </div>
      </template>

      <!-- Formulář typu -->
      <Modal v-if="typeFormOpen && canWrite" :title="typeForm.id === null ? t('dimensions.type_new') : t('dimensions.type_edit')"
             width-class="max-w-2xl" @close="typeFormOpen = false">
       <div class="space-y-4" data-test="dimension-type-form">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.name') }}</label>
            <input v-model="typeForm.name" type="text" maxlength="100" @input="typeForm.id === null && typeSlug.fromName(typeForm.name)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.code') }}</label>
            <input v-model="typeForm.code" type="text" maxlength="30" @input="typeSlug.markManual(typeForm.code)" :disabled="typeForm.id !== null" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('dimensions.type_code_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.kind_label') }}</label>
            <select v-model="typeForm.kind" :disabled="typeForm.id !== null" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100">
              <option v-for="k in KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.level_label') }}</label>
            <select v-model="typeForm.level" :disabled="typeForm.id !== null" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100">
              <option value="company">{{ t('dimensions.level_company') }}</option>
              <option value="global" :disabled="!group">{{ t('dimensions.level_global') }}</option>
            </select>
            <p v-if="!group" class="text-xs text-neutral-400 mt-1">{{ t('dimensions.level_global_needs_group') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.sort_order') }}</label>
            <input v-model.number="typeForm.sort_order" type="number" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="typeForm.show_on_documents" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.show_on_documents') }}
        </label>
        <label v-if="typeForm.id !== null" class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="typeForm.is_active" :disabled="!!dims.types.value.find(type => type.id === typeForm.id)?.system_key" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.type_active') }}
        </label>
        <div class="border-t border-neutral-200 pt-4 space-y-2" data-test="dimension-type-approval">
          <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
            <input v-model="typeForm.requires_approval" type="checkbox" class="rounded border-neutral-300" data-test="dimension-type-requires-approval" />
            {{ t('dimensions.requires_approval') }}
          </label>
          <p class="text-xs text-neutral-500">{{ t('dimensions.requires_approval_hint') }}</p>
          <div v-if="typeForm.requires_approval" class="max-w-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.approval_threshold') }}</label>
            <input v-model="typeForm.approval_threshold" type="number" min="0" step="0.01" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" data-test="dimension-type-approval-threshold" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('dimensions.approval_threshold_hint') }}</p>
          </div>
        </div>
        <div v-if="typeForm.id !== null" class="border-t border-neutral-200 pt-4 space-y-2">
          <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
            <input v-model="typeForm.drives_accounts" type="checkbox" class="rounded border-neutral-300" data-test="dimension-type-drives-accounts" />
            {{ t('dimensions.drives_accounts') }}
          </label>
          <p class="text-xs text-neutral-500">{{ t('dimensions.drives_accounts_hint') }}</p>
          <div v-if="typeForm.drives_accounts" class="max-w-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.drives_accounts_mask') }}</label>
            <input v-model="typeForm.drives_accounts_mask" type="text" maxlength="190" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" data-test="dimension-type-drives-mask" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('dimensions.drives_accounts_mask_hint') }}</p>
          </div>
        </div>
       </div>
       <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="typeFormOpen = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || !typeForm.name.trim() || (typeForm.id === null && !typeForm.code.trim())" :class="btnFilled('primary')" class="whitespace-nowrap" @click="saveType">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ busy ? t('common.saving') : t('common.save') }}
          </button>
        </div>
       </template>
      </Modal>

      <!-- Formulář hodnoty -->
      <Modal v-if="valueFormOpen && canWrite && selectedType" :title="valueForm.id === null ? t('dimensions.value_new') : t('dimensions.value_edit')"
             width-class="max-w-2xl" @close="valueFormOpen = false">
       <div class="space-y-4" data-test="dimension-value-form">
        <p v-if="builtinValue" class="text-sm text-primary-700" data-test="dimension-builtin-hint">{{ t('dimensions.builtin_hint') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.name') }}</label>
            <input v-if="builtinValue && editingValue" :value="dims.nameOf(editingValue)" disabled type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" data-test="dimension-value-name" />
            <input v-else v-model="valueForm.name" :disabled="builtinValue" type="text" maxlength="190" @input="valueForm.id === null && valueSlug.fromName(valueForm.name)" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" data-test="dimension-value-name" />
          </div>
          <div v-if="!builtinValue">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.code') }}</label>
            <input v-model="valueForm.code" type="text" maxlength="50" @input="valueSlug.markManual(valueForm.code)" :disabled="valueForm.id !== null" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" data-test="dimension-value-code" />
            <p v-if="valueForm.id !== null" class="text-xs text-neutral-400 mt-1">{{ t('dimensions.code_immutable') }}</p>
            <p v-else class="text-xs text-neutral-400 mt-1">{{ t('dimensions.value_code_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.parent') }}</label>
            <select v-model="valueForm.parent_id" :disabled="builtinValue" data-test="dimension-value-parent" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option :value="null">{{ t('dimensions.parent_none') }}</option>
              <option v-for="{ value, depth } in parentOptions" :key="value.id" :value="value.id">
                {{ '  '.repeat(depth) }}{{ dims.labelOf(value) }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ selectedType.requires_approval ? t('dimensions.approver_label') : t('dimensions.responsible') }}</label>
            <select v-model="valueForm.responsible_user_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
                    data-test="dimension-value-responsible">
              <option :value="null">—</option>
              <option v-for="u in candidates" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <p v-if="selectedType.requires_approval" class="text-xs mt-1 text-neutral-400">{{ t('dimensions.approver_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.responsible_note') }}</label>
            <input v-model="valueForm.responsible_note" type="text" maxlength="190" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('dimensions.responsible_hint') }}</p>
          </div>
          <template v-if="selectedType.level === 'company' && !selectedType.system_key">
            <div v-if="selectedType.kind === 'vehicle' || valueForm.car_id">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.link_car') }}</label>
              <select v-model="valueForm.car_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">—</option>
                <option v-for="car in cars" :key="car.id" :value="car.id">{{ car.registration }}{{ car.name ? ` – ${car.name}` : '' }}</option>
              </select>
            </div>
            <div v-if="selectedType.kind === 'cost_center' || valueForm.cost_center_id">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.link_cost_center') }}</label>
              <select v-model="valueForm.cost_center_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">{{ valueForm.id === null ? t('dimensions.cost_center_auto') : '—' }}</option>
                <option v-for="cc in costCenters" :key="cc.id" :value="cc.id">{{ cc.code }} – {{ cc.name }}</option>
              </select>
            </div>
            <div v-if="selectedType.kind === 'project' || valueForm.project_id">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.link_project') }}</label>
              <select v-model="valueForm.project_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">—</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
          </template>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.note') }}</label>
            <input v-model="valueForm.note" type="text" maxlength="500" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="valueForm.is_active" :disabled="builtinValue" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.value_active') }}
        </label>
        <section v-if="drivingSelected" class="border-t border-neutral-200 pt-4" data-test="dimension-account-map">
          <h3 class="text-sm font-semibold text-neutral-700">{{ t('dimensions.account_map_title') }}</h3>
          <p class="text-xs text-neutral-500 mt-1">{{ t('dimensions.account_map_hint') }}</p>
          <p v-if="valueForm.id === null" class="text-sm text-neutral-500 mt-3">{{ t('dimensions.account_map_after_create') }}</p>
          <template v-else>
            <p v-if="mapRows.length === 0" class="text-sm text-neutral-500 mt-3">{{ t('dimensions.account_map_empty') }}</p>
            <div v-for="(row, i) in mapRows" :key="i" class="mt-3 rounded-md border border-neutral-200 p-3 space-y-2" :data-test="`dimension-account-map-row-${i}`">
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_9rem_9rem] gap-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.account_map_synthetic') }}</label>
                  <select v-model="row.synthetic_account_id" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
                          @change="row.analytic_account_id = null; row.creating = false">
                    <option :value="null">{{ t('dimensions.account_map_select') }}</option>
                    <option v-for="c in accountCandidates" :key="c.id" :value="c.id">{{ c.code }} – {{ c.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.account_map_analytic') }}</label>
                  <select v-model="row.analytic_account_id" :disabled="row.synthetic_account_id === null"
                          class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100">
                    <option :value="null">{{ t('dimensions.account_map_select') }}</option>
                    <option v-for="a in analyticsOf(row.synthetic_account_id)" :key="a.id" :value="a.id">{{ a.code }} – {{ a.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.account_map_valid_from') }}</label>
                  <DateInput v-model="row.valid_from" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.account_map_valid_to') }}</label>
                  <DateInput v-model="row.valid_to" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                </div>
              </div>
              <div v-if="row.creating" class="flex flex-wrap items-end gap-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.account_map_new_code') }}</label>
                  <input v-model="row.new_code" type="text" maxlength="10" class="w-32 h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                </div>
                <div class="flex-1 min-w-[12rem]">
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.account_map_new_name') }}</label>
                  <input v-model="row.new_name" type="text" maxlength="190" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                </div>
                <button type="button" :disabled="busy || !row.new_code.trim() || !row.new_name.trim()" :class="btnFilledSm('success')" class="whitespace-nowrap" @click="createAnalytic(row)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('dimensions.account_map_create') }}
                </button>
                <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="row.creating = false">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  {{ t('common.cancel') }}
                </button>
              </div>
              <div class="flex flex-wrap justify-end gap-2">
                <button v-if="!row.creating && row.synthetic_account_id !== null" type="button" :class="btnOutlineSm('primary')" class="whitespace-nowrap" @click="startCreateAnalytic(row)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('dimensions.account_map_new_analytic') }}
                </button>
                <button type="button" :class="btnOutlineSm('danger')" class="whitespace-nowrap" @click="mapRows.splice(i, 1)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  {{ t('dimensions.account_map_remove') }}
                </button>
              </div>
            </div>
            <button type="button" :class="btnOutlineSm('primary')" class="whitespace-nowrap mt-3" data-test="dimension-account-map-add" @click="mapRows.push(emptyMapRow())">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('dimensions.account_map_add') }}
            </button>
          </template>
        </section>
       </div>
       <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="valueFormOpen = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || !valueForm.name.trim() || (valueForm.id === null && !valueForm.code.trim())" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="dimension-value-save" @click="saveValue">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ busy ? t('common.saving') : t('common.save') }}
          </button>
        </div>
       </template>
      </Modal>
    </template>
  </div>
</template>
