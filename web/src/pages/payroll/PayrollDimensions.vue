<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollDimension,
  type PayrollDimensionPayload,
  type PayrollDimensionType,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useAutoSlug } from '@/composables/useAutoSlug'

const props = defineProps<{
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const editorOpen = ref(false)
const editingId = ref<number | null>(null)
const dimensions = ref<PayrollDimension[]>([])
const loadError = ref('')
const saveError = ref('')
const conflict = ref(false)
const historyLocked = ref(false)
const showValidation = ref(false)
const typeFilter = ref<PayrollDimensionType | ''>('')
const form = ref<PayrollDimensionPayload>(newDimension())

const TYPES: PayrollDimensionType[] = ['cost_center', 'project', 'activity']

const COLUMNS: ColumnDef[] = [
  { key: 'type', labelKey: 'payroll.employer.dimensions.type' },
  { key: 'code', labelKey: 'payroll.employer.dimensions.code', required: true },
  { key: 'name', labelKey: 'payroll.employer.dimensions.name' },
  { key: 'validity', labelKey: 'payroll.employer.dimensions.validity' },
  { key: 'account', labelKey: 'payroll.employer.dimensions.account', defaultHidden: true },
  { key: 'status', labelKey: 'payroll.employer.dimensions.status' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-dimensions', COLUMNS)

function localDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function newDimension(): PayrollDimensionPayload {
  return {
    dimension_type: 'cost_center',
    code: '',
    name: '',
    valid_from: localDate(),
    valid_to: null,
    is_active: true,
    default_account_code: null,
    row_version: 0,
  }
}

const typeOptions = computed(() => TYPES.map(value => ({
  value,
  label: t(`payroll.employer.dimensions.type_options.${value}`),
})))
const filterOptions = computed(() => [
  { value: '', label: t('payroll.employer.dimensions.filter_type_all') },
  ...typeOptions.value,
])
const filteredDimensions = computed(() => typeFilter.value === ''
  ? dimensions.value
  : dimensions.value.filter(dimension => dimension.dimension_type === typeFilter.value))

/** Obsazené kódy — kolize řeší auto-generátor suffixem `_2`, `_3`. */
const takenCodes = computed(() => dimensions.value
  .filter(dimension => dimension.id !== editingId.value)
  .map(dimension => dimension.code))

const codeSlug = useAutoSlug(value => { form.value.code = value }, {
  maxLen: 50,
  mode: 'code',
  taken: () => takenCodes.value,
})

function codeError(): string | null {
  const code = form.value.code.trim().toUpperCase()
  if (!/^[A-Z0-9][A-Z0-9._-]{0,49}$/.test(code)) return t('payroll.employer.dimensions.validation.code')
  if (takenCodes.value.some(taken => taken.trim().toUpperCase() === code)) {
    return t('payroll.employer.dimensions.validation.code_duplicate')
  }
  return null
}

function nameError(): string | null {
  const name = form.value.name.trim()
  return name === '' || name.length > 190 ? t('payroll.employer.dimensions.validation.name') : null
}

const valid = computed(() => {
  if (codeError() !== null) return false
  if (nameError() !== null) return false
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.value.valid_from)) return false
  const validTo = nullable(form.value.valid_to)
  if (validTo !== null && (!/^\d{4}-\d{2}-\d{2}$/.test(validTo) || validTo < form.value.valid_from)) return false
  const account = nullable(form.value.default_account_code)
  if (account !== null && !/^[0-9]{3}[.A-Z0-9]{0,13}$/.test(account.toUpperCase())) return false
  return true
})

function nullable(value: string | null): string | null {
  const normalized = value?.trim() ?? ''
  return normalized === '' ? null : normalized
}

function dimensionIsEffective(dimension: PayrollDimension): boolean {
  const today = localDate()
  return dimension.is_active
    && dimension.valid_from <= today
    && (dimension.valid_to === null || dimension.valid_to >= today)
}

function openNew() {
  editingId.value = null
  form.value = newDimension()
  saveError.value = ''
  conflict.value = false
  historyLocked.value = false
  showValidation.value = false
  codeSlug.init('', false)
  editorOpen.value = true
}

function edit(dimension: PayrollDimension) {
  editingId.value = dimension.id
  form.value = {
    dimension_type: dimension.dimension_type,
    code: dimension.code,
    name: dimension.name,
    valid_from: dimension.valid_from,
    valid_to: dimension.valid_to,
    is_active: dimension.is_active,
    default_account_code: dimension.default_account_code,
    row_version: dimension.row_version,
  }
  saveError.value = ''
  conflict.value = false
  historyLocked.value = false
  showValidation.value = false
  codeSlug.init(dimension.code, true)
  editorOpen.value = true
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    dimensions.value = await payrollApi.payrollDimensions()
  } catch (error: unknown) {
    loadError.value = apiMessage(error) || t('payroll.employer.dimensions.load_failed')
  } finally {
    loading.value = false
  }
}

async function reloadCurrent() {
  const dimensionId = editingId.value
  if (dimensionId === null) return
  await load()
  const current = dimensions.value.find(dimension => dimension.id === dimensionId)
  if (current) {
    edit(current)
    return
  }
  editorOpen.value = false
  editingId.value = null
}

async function save() {
  showValidation.value = true
  saveError.value = ''
  conflict.value = false
  historyLocked.value = false
  if (!props.canWrite || !valid.value) return

  saving.value = true
  try {
    const payload: PayrollDimensionPayload = {
      ...form.value,
      code: form.value.code.trim().toUpperCase(),
      name: form.value.name.trim(),
      valid_to: nullable(form.value.valid_to),
      default_account_code: nullable(form.value.default_account_code)?.toUpperCase() ?? null,
    }
    const saved = editingId.value === null
      ? await payrollApi.createPayrollDimension(payload)
      : await payrollApi.updatePayrollDimension(editingId.value, payload)
    const index = dimensions.value.findIndex(dimension => dimension.id === saved.id)
    if (index === -1) dimensions.value.unshift(saved)
    else dimensions.value.splice(index, 1, saved)
    editorOpen.value = false
    editingId.value = null
    toast.success(t('payroll.employer.dimensions.saved'))
  } catch (error: unknown) {
    saveError.value = apiMessage(error) || t('payroll.employer.dimensions.save_failed')
    const code = apiCode(error)
    conflict.value = code === 'row_version_conflict'
    historyLocked.value = code === 'dimension_history_locked'
  } finally {
    saving.value = false
  }
}

/**
 * Smazání dimenze bez potvrzovacího dialogu, ale s možností vzít to zpět.
 *
 * Nativní `confirm()` se ptal PŘED akcí, a to u vratné věci znamená daň za
 * každé jedno smazání — u pěti dimenzí pět dialogů, které navíc neřeknou,
 * které dimenze se to týká. Undo toast obrátí pořadí: akce proběhne hned
 * a osm vteřin je čas ji vrátit. Server dimenzi v použití stejně nepustí
 * (`dimension_in_use`), takže se dialogem nic nechránilo.
 *
 * Vrácení dimenzi ZALOŽÍ ZNOVU (nové `id`, tytéž údaje) — obnovit smazaný
 * řádek server neumí. Pro uživatele je výsledek stejný; vazby, které by
 * smazání blokovaly, existovat nemohly.
 */
async function remove(dimension: PayrollDimension) {
  if (!props.canWrite) return
  try {
    await payrollApi.deletePayrollDimension(dimension.id)
    dimensions.value = dimensions.value.filter(item => item.id !== dimension.id)
    toast.success(t('payroll.employer.dimensions.deleted'), {
      label: t('common.undo'),
      handler: () => restore(dimension),
    })
  } catch (error: unknown) {
    const code = apiCode(error)
    toast.error(code === 'dimension_in_use'
      ? t('payroll.employer.dimensions.in_use_error')
      : (apiMessage(error) || t('payroll.employer.dimensions.delete_failed')))
  }
}

async function restore(dimension: PayrollDimension) {
  try {
    const restored = await payrollApi.createPayrollDimension({
      dimension_type: dimension.dimension_type,
      code: dimension.code,
      name: dimension.name,
      valid_from: dimension.valid_from,
      valid_to: dimension.valid_to,
      is_active: dimension.is_active,
      default_account_code: dimension.default_account_code,
      row_version: 0,
    })
    dimensions.value = [...dimensions.value, restored]
    toast.success(t('payroll.employer.dimensions.restored'))
  } catch (error: unknown) {
    toast.error(apiMessage(error) || t('payroll.employer.dimensions.restore_failed'))
  }
}

function apiMessage(error: unknown): string {
  if (!isAxiosError<{ error?: { message?: string } }>(error)) return ''
  return error.response?.data?.error?.message ?? ''
}

function apiCode(error: unknown): string {
  if (!isAxiosError<{ error?: { code?: string } }>(error)) return ''
  return error.response?.data?.error?.code ?? ''
}

onMounted(load)
</script>

<template>
  <div class="space-y-5">
    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.employer.dimensions.title') }}
          </h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">
            {{ t('payroll.employer.dimensions.hint') }}
          </p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.employer.dimensions.filter_type') }}
            </span>
            <SearchableSelect
              :model-value="typeFilter"
              :options="filterOptions"
              :clearable="false"
              accent="payroll"
              @update:model-value="typeFilter = ($event ?? '') as PayrollDimensionType | ''"
            />
          </label>
          <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="openNew">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.plus" />
            </svg>
            {{ t('payroll.employer.dimensions.add') }}
          </button>
        </div>
      </div>

      <div
        v-if="loadError"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        <p>{{ loadError }}</p>
        <button type="button" :class="[btnOutline('danger'), 'mt-3']" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.employer.dimensions.retry') }}
        </button>
      </div>

      <p v-if="!loading && filteredDimensions.length === 0" class="mt-5 text-sm text-neutral-500">
        {{ t('payroll.employer.dimensions.empty') }}
      </p>

      <div v-else-if="filteredDimensions.length" class="mt-5 hidden md:block">
        <div class="mb-2 flex flex-wrap items-center justify-end gap-2">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th v-if="tbl.isVisible('type')" class="px-3 py-2">{{ t('payroll.employer.dimensions.type') }}</th>
                <th v-if="tbl.isVisible('code')" class="px-3 py-2">{{ t('payroll.employer.dimensions.code') }}</th>
                <th v-if="tbl.isVisible('name')" class="px-3 py-2">{{ t('payroll.employer.dimensions.name') }}</th>
                <th v-if="tbl.isVisible('validity')" class="px-3 py-2">{{ t('payroll.employer.dimensions.validity') }}</th>
                <th v-if="tbl.isVisible('account')" class="px-3 py-2">{{ t('payroll.employer.dimensions.account') }}</th>
                <th v-if="tbl.isVisible('status')" class="px-3 py-2">{{ t('payroll.employer.dimensions.status') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-3 py-2 text-right">{{ t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
              <tr v-for="dimension in filteredDimensions" :key="dimension.id">
                <td v-if="tbl.isVisible('type')" class="px-3 py-3">{{ t(`payroll.employer.dimensions.type_options.${dimension.dimension_type}`) }}</td>
                <td v-if="tbl.isVisible('code')" class="px-3 py-3 font-mono">{{ dimension.code }}</td>
                <td v-if="tbl.isVisible('name')" class="px-3 py-3">{{ dimension.name }}</td>
                <td v-if="tbl.isVisible('validity')" class="px-3 py-3">{{ dimension.valid_from }} – {{ dimension.valid_to ?? '∞' }}</td>
                <td v-if="tbl.isVisible('account')" class="px-3 py-3 font-mono text-neutral-600">{{ dimension.default_account_code ?? '—' }}</td>
                <td v-if="tbl.isVisible('status')" class="px-3 py-3">
                  <span
                    :class="[
                      'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                      !dimension.is_active
                        ? 'bg-neutral-100 text-neutral-600'
                        : dimensionIsEffective(dimension)
                          ? 'bg-success-50 text-success-700'
                          : 'bg-warning-50 text-warning-700',
                    ]"
                  >
                    {{ t(!dimension.is_active
                      ? 'payroll.employer.dimensions.inactive'
                      : dimensionIsEffective(dimension)
                        ? 'payroll.employer.dimensions.effective'
                        : 'payroll.employer.dimensions.outside_period') }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('actions')" class="px-3 py-3 text-right">
                  <div class="flex flex-wrap justify-end gap-2">
                    <button v-if="canWrite" type="button" :class="btnOutline('neutral')" @click="edit(dimension)">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.edit" />
                      </svg>
                      {{ t('common.edit') }}
                    </button>
                    <button v-if="canWrite" type="button" :class="btnOutline('danger')" @click="remove(dimension)">
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.trash" />
                      </svg>
                      {{ t('payroll.employer.dimensions.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="filteredDimensions.length" class="mt-5 space-y-3 md:hidden">
        <article
          v-for="dimension in filteredDimensions"
          :key="`mobile-${dimension.id}`"
          class="rounded-lg border border-neutral-200 p-4"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs uppercase tracking-wide text-neutral-500">
                {{ t(`payroll.employer.dimensions.type_options.${dimension.dimension_type}`) }}
              </p>
              <p class="font-medium text-neutral-900">{{ dimension.code }} — {{ dimension.name }}</p>
              <p class="mt-1 text-sm text-neutral-500">{{ dimension.valid_from }} – {{ dimension.valid_to ?? '∞' }}</p>
            </div>
            <span
              :class="[
                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                !dimension.is_active
                  ? 'bg-neutral-100 text-neutral-600'
                  : dimensionIsEffective(dimension)
                    ? 'bg-success-50 text-success-700'
                    : 'bg-warning-50 text-warning-700',
              ]"
            >
              {{ t(!dimension.is_active
                ? 'payroll.employer.dimensions.inactive'
                : dimensionIsEffective(dimension)
                  ? 'payroll.employer.dimensions.effective'
                  : 'payroll.employer.dimensions.outside_period') }}
            </span>
          </div>
          <div v-if="canWrite" class="mt-3 flex flex-wrap gap-2">
            <button type="button" :class="[btnOutline('neutral'), 'flex-1 justify-center']" @click="edit(dimension)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.edit" />
              </svg>
              {{ t('common.edit') }}
            </button>
            <button type="button" :class="[btnOutline('danger'), 'flex-1 justify-center']" @click="remove(dimension)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.trash" />
              </svg>
              {{ t('payroll.employer.dimensions.delete') }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <section
      v-if="editorOpen"
      class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 shadow-sm sm:p-6"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t(editingId === null ? 'payroll.employer.dimensions.new_title' : 'payroll.employer.dimensions.edit_title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-600">
            {{ t('payroll.employer.dimensions.editor_hint') }}
          </p>
        </div>
        <button type="button" :class="btnOutline('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
      </div>

      <div v-if="saveError" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
        <p>{{ saveError }}</p>
        <p v-if="historyLocked" class="mt-1">{{ t('payroll.employer.dimensions.terminate_hint') }}</p>
        <button
          v-if="conflict"
          type="button"
          :class="[btnOutline('warning'), 'mt-3']"
          @click="reloadCurrent"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.employer.dimensions.reload_current') }}
        </button>
      </div>
      <div
        v-if="showValidation && !valid"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        {{ t('payroll.employer.dimensions.validation.title') }}
      </div>

      <div class="mt-5 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        <div>
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.dimensions.type') }}
          </span>
          <SearchableSelect
            :model-value="form.dimension_type"
            :options="typeOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
            @update:model-value="form.dimension_type = ($event ?? 'cost_center') as PayrollDimensionType"
          />
        </div>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.dimensions.name') }}<RequiredMark />
          </span>
          <input v-model="form.name" type="text" maxlength="190" :disabled="!canWrite" :aria-invalid="showValidation && nameError() !== null" data-test="dimension-name" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20" @input="codeSlug.fromName(form.name)">
          <span v-if="showValidation && nameError()" class="mt-1 block text-xs text-danger-600">{{ nameError() }}</span>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.dimensions.code') }}<RequiredMark />
          </span>
          <input v-model="form.code" type="text" maxlength="50" :disabled="!canWrite" :aria-invalid="showValidation && codeError() !== null" data-test="dimension-code" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20" @input="codeSlug.markManual(form.code)">
          <span v-if="showValidation && codeError()" class="mt-1 block text-xs text-danger-600">{{ codeError() }}</span>
          <span v-else-if="editingId === null" class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.dimensions.code_hint') }}</span>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.dimensions.valid_from') }}
          </span>
          <input v-model="form.valid_from" type="date" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.dimensions.valid_to') }}
          </span>
          <input v-model="form.valid_to" type="date" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.employer.dimensions.default_account_code') }}
          </span>
          <input v-model="form.default_account_code" type="text" maxlength="16" :disabled="!canWrite" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.dimensions.default_account_hint') }}</span>
        </label>
        <label class="flex items-start gap-2 pt-6">
          <input v-model="form.is_active" type="checkbox" :disabled="!canWrite" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
          <span class="text-sm text-neutral-700">{{ t('payroll.employer.dimensions.is_active') }}</span>
        </label>
      </div>

      <div class="mt-6 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
        <button
          v-if="canWrite"
          type="button"
          :class="btnFilled('primary')"
          :disabled="saving"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </section>
  </div>
</template>
