<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
const props = defineProps<{ supplierId: number; modelValue: number | null; selectedLabel?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: number | null]; selected: [value: { id: number; name: string }] }>()
const { t } = useI18n()
const options = ref<Array<{ value: number; label: string; secondary?: string }>>([])
const loading = ref(false)
const failed = ref(false)
const truncated = ref(false)
let generation = 0
onBeforeUnmount(() => generation++)
watch(() => props.supplierId, () => { generation++; options.value = []; loading.value = false; failed.value = false; truncated.value = false })
const selected = computed(() => props.modelValue === null ? null : options.value.find(v => v.value === props.modelValue) ?? { value: props.modelValue, label: props.selectedLabel || t('propertyRegistry.choosePerson') })
async function search(q: string) {
  const version = ++generation
  const supplier = props.supplierId
  loading.value = true
  failed.value = false
  try {
    const { data } = await api.get<{ data: Array<{ id: number; company_name: string; main_email: string; street: string; city: string }>; meta: { total: number } }>('/clients', { headers: { 'X-Supplier-Id': String(supplier) }, params: { q, role: 'all', per_page: 50 } })
    if (version !== generation || supplier !== props.supplierId) return
    options.value = data.data.map(p => ({ value: p.id, label: p.company_name, secondary: [p.city, p.street, p.main_email].filter(Boolean).join(' · ') }))
    truncated.value = data.meta.total > data.data.length
  } catch { if (version === generation) { options.value = []; failed.value = true } } finally { if (version === generation) loading.value = false }
}
function select(value: string | number | null) {
  const id = value === null ? null : Number(value)
  emit('update:modelValue', id)
  const option = options.value.find(v => v.value === id)
  if (id !== null && option) emit('selected', { id, name: option.label })
}
</script>

<template>
  <div><SearchableSelect remote :model-value="modelValue" :selected-option="selected" :options="options" :loading="loading" :truncated="truncated" :truncated-label="t('propertyRegistry.narrowSearch')" :placeholder="t('propertyRegistry.choosePerson')" :no-results-label="t('common.no_results')" @search="search" @update:model-value="select" /><p v-if="failed" role="alert" class="text-sm text-danger-600">{{ t('propertyRegistry.searchFailed') }}</p></div>
</template>
