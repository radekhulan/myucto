<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionPicker from './DimensionPicker.vue'
import { useDimensions } from '@/composables/useDimensions'
import { dimensionsApi, type BulkDocumentDimensionsResult } from '@/api/dimensions'
import { bulkDimensionsPayload, type BulkDimensionChoice } from './bulkDimensions'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Hromadné nastavení dimenzí hlavičky vybraných dokladů ze seznamu. U každého typu
 * se volí Ponechat / Nastavit (s hodnotou) / Odebrat; server každý doklad uloží
 * stejnou cestou jako panel Dimenze na detailu a odmítnuté vrátí s důvodem.
 */
const props = defineProps<{ ids: number[] }>()
const emit = defineEmits<{ close: []; done: [result: BulkDocumentDimensionsResult]; failed: [error: unknown] }>()

const { t } = useI18n()
const dims = useDimensions()

const choices = ref<Record<number, BulkDimensionChoice>>({})
const busy = ref(false)

function resetChoices() {
  const next: Record<number, BulkDimensionChoice> = {}
  for (const type of dims.documentTypes.value) next[type.id] = choices.value[type.id] ?? { mode: 'keep', valueId: null }
  choices.value = next
}

onMounted(async () => {
  await dims.load()
  resetChoices()
})
watch(() => dims.documentTypes.value.map(ty => ty.id).join(','), resetChoices)

const payload = computed(() => bulkDimensionsPayload(choices.value))

async function apply() {
  if (!payload.value || busy.value) return
  busy.value = true
  try {
    emit('done', await dimensionsApi.bulkInvoiceDimensions(props.ids, payload.value))
  } catch (e) {
    emit('failed', e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" data-test="bulk-dimensions-modal"
    @click.self="!busy && emit('close')">
    <div class="bg-surface rounded-lg shadow-xl w-full max-w-xl max-h-[90vh] overflow-y-auto p-5 space-y-4">
      <div>
        <h2 class="text-lg font-semibold">{{ t('dimensions.bulk_title') }}</h2>
        <p class="text-sm text-neutral-500 mt-1">{{ t('dimensions.bulk_hint', { n: ids.length }) }}</p>
      </div>

      <div v-if="dims.loading.value && dims.documentTypes.value.length === 0" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else class="space-y-3">
        <div v-for="type in dims.documentTypes.value" :key="type.id"
          class="grid grid-cols-1 sm:grid-cols-[10rem_9rem_1fr] gap-2 sm:items-center" data-test="bulk-dimension-row">
          <span class="text-sm font-medium text-neutral-700">{{ type.name }}</span>
          <select v-if="choices[type.id]" v-model="choices[type.id].mode" :aria-label="type.name" :disabled="busy"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" data-test="bulk-dimension-mode">
            <option value="keep">{{ t('dimensions.bulk_mode_keep') }}</option>
            <option value="set">{{ t('dimensions.bulk_mode_set') }}</option>
            <option value="remove">{{ t('dimensions.bulk_mode_remove') }}</option>
          </select>
          <DimensionPicker v-if="choices[type.id]?.mode === 'set'" v-model="choices[type.id].valueId"
            :type-id="type.id" :disabled="busy" teleport :aria-label="type.name"
            :placeholder="t('dimensions.bulk_value_required')" />
        </div>
      </div>

      <p v-if="!payload" class="text-xs text-neutral-500">{{ t('dimensions.bulk_nothing') }}</p>

      <div class="flex flex-wrap justify-end gap-2 pt-1">
        <button type="button" :disabled="busy" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :disabled="busy || !payload" :class="btnFilled('primary')" class="whitespace-nowrap"
          data-test="bulk-dimensions-apply" @click="apply">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ busy ? t('common.saving') : t('dimensions.bulk_apply') }}
        </button>
      </div>
    </div>
  </div>
</template>
