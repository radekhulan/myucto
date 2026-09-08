<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client } from '@/api/clients'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

type Opt = { value: number; label: string; secondary?: string }

const props = defineProps<{
  modelValue: number | null
  /** Open modal pro vytvoření nového vendoru — parent ho otevře a po vytvoření vrátí id přes update:modelValue */
  onCreateNew?: () => void
}>()

const emit = defineEmits<{
  'update:modelValue': [v: number | null]
  'selected': [vendor: Client | null]
}>()

const { t } = useI18n()
const cache = ref<Client[]>([])    // akumulovaná cache (výsledky hledání + vybraný)
const options = ref<Opt[]>([])      // aktuální výsledky hledání (server-side)
const loading = ref(false)
let searchVersion = 0
const selectedOption = ref<Opt | null>(null)

function toOpt(c: Client): Opt {
  return { value: c.id, label: c.company_name, secondary: c.ic ? `IČO ${c.ic}` : (c.dic || undefined) }
}
function merge(list: Client[]) {
  const byId = new Map(cache.value.map(c => [c.id, c]))
  for (const c of list) byId.set(c.id, c)
  cache.value = Array.from(byId.values())
}

async function onSearch(q: string) {
  const version = ++searchVersion
  loading.value = true
  try {
    const query = q.trim()
    const res = await clientsApi.list({ q: query || undefined, role: query ? 'all' : 'vendors', archived: false, per_page: 50 })
    if (version !== searchVersion) return
    merge(res.data)
    options.value = res.data.map(toOpt)
  } catch { /* ignore */ } finally {
    if (version === searchVersion) loading.value = false
  }
}

// Edit / externí nastavení modelValue: dotáhni dodavatele podle id kvůli zobrazení labelu.
async function ensureLoaded(id: number | null) {
  if (id === null) { selectedOption.value = null; return }
  const existing = cache.value.find(c => c.id === id)
  if (existing) { selectedOption.value = toOpt(existing); return }
  try {
    const full = await clientsApi.get(id)
    merge([full])
    selectedOption.value = toOpt(full)
  } catch {
    selectedOption.value = { value: id, label: `#${id}` }
  }
}

function onChange(id: number | string | null) {
  const numId = id === null ? null : Number(id)
  emit('update:modelValue', numId)
  const vendor = numId === null ? null : (cache.value.find(c => c.id === numId) ?? null)
  selectedOption.value = vendor ? toOpt(vendor) : null
  emit('selected', vendor)
}

onMounted(() => { ensureLoaded(props.modelValue) })
watch(() => props.modelValue, (v) => {
  if (v !== (selectedOption.value?.value ?? null)) ensureLoaded(v)
})

// Parent po vytvoření nového vendoru nastaví modelValue a zavolá reload.
async function reload() { await ensureLoaded(props.modelValue) }
defineExpose({ reload })
</script>

<template>
  <div class="space-y-1">
    <label class="block text-sm text-neutral-700">{{ t('vendor.picker_label') }}</label>
    <div class="flex items-center gap-2">
      <div class="flex-1">
        <SearchableSelect
          :model-value="modelValue"
          remote
          :loading="loading"
          :options="options"
          :selected-option="selectedOption"
          @search="onSearch"
          :placeholder="t('vendor.search_placeholder')"
          @update:model-value="onChange"
        />
      </div>
      <button
        v-if="onCreateNew"
        type="button"
        @click="onCreateNew"
        class="cursor-pointer px-3 py-1.5 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50 whitespace-nowrap"
      >
        {{ t('vendor.create_new') }}
      </button>
    </div>
  </div>
</template>
