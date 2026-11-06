<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionFields from './DimensionFields.vue'
import DimensionChips from './DimensionChips.vue'
import { dimensionsApi, compactDimensions, type DimensionDefaultsEntity, type DimensionMap } from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'

/**
 * Výchozí dimenze klienta, zakázky, produktu nebo kategorie produktů (Firma → Dimenze).
 *
 * `form` = sekce formuláře karty: výběry se ukládají přes `save(id)` až po uložení
 * karty (u nové karty id ještě není). `summary` = jen štítky na detailu, bez úprav.
 * Při vypnutých dimenzích se nevykreslí nic.
 */
const props = withDefaults(defineProps<{
  entity: DimensionDefaultsEntity
  entityId: number | null
  mode?: 'form' | 'summary'
}>(), { mode: 'form' })

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const dims = useDimensions()

const model = ref<DimensionMap>({})
const saved = ref('{}')

const visible = computed(() => dims.enabled.value && dims.documentTypes.value.length > 0)
/** Právo k úpravě karty, ke které výchozí dimenze patří (stejné jako úprava karty). */
const WRITE_PERMISSION: Record<DimensionDefaultsEntity, PermissionKey[]> = {
  clients: ['clients'],
  projects: ['projects'],
  'stock/items': ['stock.items.write', 'accounting'],
  'eshop/categories': ['eshop.write', 'accounting'],
  'accounting/bank-accounts': ['accounting'],
}
const canEdit = computed(() => dims.enabled.value && WRITE_PERMISSION[props.entity].every(p => auth.canWrite(p)))
const hint = computed(() => ({
  clients: t('dimensions.defaults.hint_client'),
  projects: t('dimensions.defaults.hint_project'),
  'stock/items': t('dimensions.defaults.hint_product'),
  'eshop/categories': t('dimensions.defaults.hint_category'),
  'accounting/bank-accounts': t('dimensions.defaults.hint_bank_account'),
} as Record<DimensionDefaultsEntity, string>)[props.entity])
const dirty = computed(() => JSON.stringify(compactDimensions(model.value)) !== saved.value)
const hasValues = computed(() => Object.keys(compactDimensions(model.value)).length > 0)

async function load() {
  if (!dims.enabled.value) return
  await dims.load()
  if (!props.entityId) {
    model.value = {}
    saved.value = '{}'
    return
  }
  try {
    const data = await dimensionsApi.getDefaults(props.entity, props.entityId)
    model.value = { ...data }
    saved.value = JSON.stringify(compactDimensions(data))
  } catch {
    model.value = {}
  }
}

/** Uloží výběr ke kartě `id` (po uložení karty). Chyba kartu neshazuje — jen se ohlásí. */
async function save(id: number): Promise<void> {
  if (!canEdit.value || !dirty.value || id <= 0) return
  try {
    const data = await dimensionsApi.saveDefaults(props.entity, id, model.value)
    saved.value = JSON.stringify(compactDimensions(data))
  } catch (e: any) {
    toast.error(t('dimensions.defaults.save_failed', { message: e?.response?.data?.error?.message || e?.message || '' }))
  }
}

onMounted(load)
watch(() => props.entityId, load)

defineExpose({ save, dirty })
</script>

<template>
  <div v-if="visible && mode === 'form'" class="pt-3 border-t border-neutral-100" data-test="entity-dimension-defaults">
    <p class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.defaults.title') }}</p>
    <DimensionFields v-model="model" :disabled="!canEdit" />
    <p class="text-xs text-neutral-500 mt-1">{{ hint }}</p>
  </div>
  <div v-else-if="visible && mode === 'summary' && hasValues"
       class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm" data-test="entity-dimension-defaults-summary">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('dimensions.defaults.title') }}</h3>
    <DimensionChips :dimensions="model" />
  </div>
</template>
