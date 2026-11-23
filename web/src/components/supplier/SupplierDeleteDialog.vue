<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { suppliersApi, type SupplierDeletePreview } from '@/api/suppliers'
import { apiErrorMessage } from '@/api/errors'

const props = defineProps<{
  supplier: { id: number; company_name: string; ic?: string | null }
}>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'deleted', id: number): void
}>()

const { t } = useI18n()
const preview = ref<SupplierDeletePreview | null>(null)
const previewError = ref('')
const typed = ref('')
const deleting = ref(false)
const error = ref('')

const expected = computed(() => {
  const ic = (preview.value?.ic ?? props.supplier.ic ?? '').trim()
  return ic !== '' ? ic : (preview.value?.company_name ?? props.supplier.company_name).trim()
})
const confirmWithIc = computed(() => (preview.value?.ic ?? props.supplier.ic ?? '').trim() !== '')
const matches = computed(() => expected.value !== '' && typed.value.trim() === expected.value)
const topTables = computed(() => (preview.value?.tables ?? []).slice(0, 8))

onMounted(async () => {
  try {
    preview.value = await suppliersApi.deletePreview(props.supplier.id)
  } catch (e) {
    previewError.value = apiErrorMessage(e, t('common.error'))
  }
})

async function submit() {
  if (!matches.value || deleting.value) return
  deleting.value = true
  error.value = ''
  try {
    await suppliersApi.delete(props.supplier.id, typed.value.trim())
    emit('deleted', props.supplier.id)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <Modal :title="t('supplier.delete_dialog.title', { name: supplier.company_name })" width-class="max-w-xl" @close="emit('close')">
    <div class="space-y-4 text-sm">
      <div class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2.5 text-danger-600" role="alert" data-testid="supplier-delete-warning">
        <p class="font-semibold">{{ t('supplier.delete_dialog.warning_title') }}</p>
        <p class="mt-1">{{ t('supplier.delete_dialog.warning') }}</p>
      </div>

      <div v-if="preview" data-testid="supplier-delete-preview">
        <p v-if="preview.is_last" class="text-danger-600 font-medium">{{ t('supplier.delete_dialog.is_last') }}</p>
        <p>{{ t('supplier.delete_dialog.summary', { rows: preview.total_rows, tables: preview.tables.length, files: preview.files }) }}</p>
        <ul v-if="topTables.length" class="mt-1.5 font-mono text-xs text-neutral-600 grid grid-cols-2 gap-x-4">
          <li v-for="row in topTables" :key="row.table" class="flex justify-between gap-2">
            <span class="truncate">{{ row.table }}</span><span>{{ row.rows }}</span>
          </li>
        </ul>
        <p v-if="preview.protected_tables > 0" class="mt-1.5 text-neutral-600">
          {{ t('supplier.delete_dialog.protected', { count: preview.protected_tables }) }}
        </p>
      </div>
      <p v-else-if="previewError" class="text-neutral-500">{{ previewError }}</p>

      <label class="block">
        <span class="font-medium">
          {{ confirmWithIc ? t('supplier.delete_dialog.type_ic', { value: expected }) : t('supplier.delete_dialog.type_name', { value: expected }) }}
        </span>
        <input v-model="typed" type="text" autocomplete="off" spellcheck="false" data-testid="supplier-delete-confirm"
          class="mt-1 w-full rounded-md border border-neutral-300 px-3 py-2 font-mono" @keydown.enter.prevent="submit" />
      </label>
      <p v-if="error" class="text-danger-600" role="alert">{{ error }}</p>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled('danger')" :disabled="!matches || deleting || preview?.is_last === true"
          data-testid="supplier-delete-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ deleting ? t('supplier.delete_dialog.deleting') : t('supplier.delete_dialog.submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
