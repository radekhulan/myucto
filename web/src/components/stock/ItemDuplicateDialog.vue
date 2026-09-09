<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItem } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ item: StockItem }>()
const emit = defineEmits<{ close: []; created: [item: StockItem] }>()
const { t } = useI18n()
const sku = ref('')
const name = ref(`${props.item.name} ${t('stock.lifecycle.copy_suffix')}`)
const sections = ref<Array<'core' | 'product' | 'i18n' | 'categories' | 'tags' | 'attributes' | 'fees' | 'prices' | 'vendors'>>(['core', 'product', 'i18n', 'categories', 'tags', 'attributes'])
const busy = ref(false)
const error = ref('')
const choices = ['core', 'product', 'i18n', 'categories', 'tags', 'attributes', 'fees', 'prices', 'vendors'] as const

function toggle(value: typeof choices[number]) {
  const index = sections.value.indexOf(value)
  if (index < 0) sections.value.push(value)
  else sections.value.splice(index, 1)
}

async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    emit('created', await stockApi.duplicateItem(props.item.id, { sku: sku.value, name: name.value, row_version: props.item.row_version, sections: sections.value }))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="t('stock.lifecycle.duplicate_title')" @close="emit('close')">
    <form class="space-y-4" @submit.prevent="submit">
      <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ t('stock.lifecycle.duplicate_hint') }}</p>
      <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">
        {{ t('stock.items.col_sku') }}
        <input v-model.trim="sku" required maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2" />
      </label>
      <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">
        {{ t('stock.items.col_name') }}
        <input v-model.trim="name" required maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2" />
      </label>
      <fieldset class="rounded-lg border border-neutral-200 bg-surface p-3">
        <legend class="px-1 text-sm font-medium">{{ t('stock.lifecycle.copy_sections') }}</legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <label v-for="choice in choices" :key="choice" class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="sections.includes(choice)" @change="toggle(choice)" />
            {{ t(`stock.lifecycle.sections.${choice}`) }}
          </label>
        </div>
      </fieldset>
      <p class="text-xs text-neutral-500">{{ t('stock.lifecycle.duplicate_excludes') }}</p>
      <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnFilled('primary')" :disabled="busy"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12 4v16m8-8H4" /></svg>{{ t('stock.lifecycle.duplicate') }}</button>
      </div>
    </form>
  </Modal>
</template>
