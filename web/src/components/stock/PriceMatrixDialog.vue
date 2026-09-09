<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CatalogBulkSelection } from '@/api/catalogBulk'
import Modal from '@/components/ui/Modal.vue'
import PriceMatrix from '@/pages/eshop/PriceMatrix.vue'

const props = defineProps<{
  selection: CatalogBulkSelection
  selectedCount: number
}>()

const emit = defineEmits<{
  (event: 'close'): void
}>()
const { t } = useI18n()

const frozenSelection: CatalogBulkSelection = props.selection.all_matching
  ? {
      all_matching: true,
      filters: JSON.parse(JSON.stringify(props.selection.filters)),
      excluded_ids: [...props.selection.excluded_ids],
    }
  : { all_matching: false, ids: [...props.selection.ids] }
const frozenSelectedCount = props.selectedCount
</script>

<template>
  <Modal :title="t('eshop.price_matrix.title')" width-class="max-w-7xl" @close="emit('close')">
    <PriceMatrix embedded :selection="frozenSelection" :selected-count="frozenSelectedCount" />
  </Modal>
</template>
