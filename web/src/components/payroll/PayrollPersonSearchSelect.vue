<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi } from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

export interface PayrollPersonSearchOption {
  value: number
  label: string
}

const DEFAULT_LIMIT = 25

const props = withDefaults(defineProps<{
  modelValue: number | null
  label: string
  placeholder?: string
  clearable?: boolean
  required?: boolean
  disabled?: boolean
  inputId?: string
  dataTest?: string
  candidates?: PayrollPersonSearchOption[]
  limit?: number
}>(), {
  placeholder: undefined,
  clearable: true,
  required: false,
  disabled: false,
  inputId: undefined,
  dataTest: undefined,
  candidates: undefined,
  limit: DEFAULT_LIMIT,
})

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { t } = useI18n()
const options = ref<PayrollPersonSearchOption[]>([])
const remoteSelected = ref<PayrollPersonSearchOption | null>(null)
const loading = ref(false)
const failed = ref(false)
const total = ref(0)
let searchSequence = 0
let selectedSequence = 0

const selectedOption = computed(() => {
  if (props.modelValue === null) return null
  return props.candidates?.find(option => option.value === props.modelValue)
    ?? options.value.find(option => option.value === props.modelValue)
    ?? (remoteSelected.value?.value === props.modelValue ? remoteSelected.value : null)
})

const truncated = computed(() => total.value > options.value.length)

function localSearch(query: string): void {
  const normalized = query.trim().toLocaleLowerCase()
  const matches = (props.candidates ?? []).filter(option =>
    normalized === '' || option.label.toLocaleLowerCase().includes(normalized),
  )
  total.value = matches.length
  options.value = matches.slice(0, props.limit)
  failed.value = false
}

async function search(query: string): Promise<void> {
  if (props.candidates !== undefined) {
    localSearch(query)
    return
  }

  const sequence = ++searchSequence
  loading.value = true
  failed.value = false
  try {
    const page = await payrollApi.peoplePage({
      limit: props.limit,
      offset: 0,
      q: query,
    })
    if (sequence !== searchSequence) return
    options.value = page.items.map(person => ({ value: person.id, label: person.full_name }))
    total.value = page.total
  } catch {
    if (sequence !== searchSequence) return
    options.value = []
    total.value = 0
    failed.value = true
  } finally {
    if (sequence === searchSequence) loading.value = false
  }
}

function update(value: number | null): void {
  if (value !== null) {
    remoteSelected.value = options.value.find(option => option.value === value) ?? null
  } else {
    remoteSelected.value = null
  }
  emit('update:modelValue', value)
}

watch(
  () => props.modelValue,
  async (employeeId) => {
    if (employeeId === null || props.candidates !== undefined || selectedOption.value !== null) return
    const sequence = ++selectedSequence
    try {
      const person = await payrollApi.person(employeeId)
      if (sequence === selectedSequence && props.modelValue === employeeId) {
        remoteSelected.value = { value: person.id, label: person.full_name }
      }
    } catch {
      if (sequence === selectedSequence) remoteSelected.value = null
    }
  },
  { immediate: true },
)

watch(
  () => props.candidates,
  () => {
    if (props.candidates !== undefined) localSearch('')
  },
)
</script>

<template>
  <div :data-test="dataTest">
    <SearchableSelect
      :model-value="modelValue"
      :options="options"
      :selected-option="selectedOption"
      :placeholder="placeholder ?? t('payroll.person_search.placeholder')"
      :no-results-label="t('payroll.person_search.no_results')"
      :loading-label="t('payroll.person_search.loading')"
      :truncated-label="t('payroll.person_search.truncated')"
      :clear-label="t('payroll.person_search.clear')"
      :clearable="clearable"
      :required="required"
      :disabled="disabled"
      :loading="loading"
      :truncated="truncated"
      :aria-label="label"
      :input-id="inputId"
      remote
      accent="payroll"
      @search="search"
      @update:model-value="update"
    />
    <p v-if="failed" class="mt-1 text-xs text-danger-700" role="alert">
      {{ t('payroll.person_search.load_failed') }}
    </p>
  </div>
</template>
