<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItem, type StockItemCopySection, type StockItemTemplate } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ item: StockItem }>()
const emit = defineEmits<{ created: [item: StockItem] }>()
const { t, locale } = useI18n()
const templates = ref<StockItemTemplate[]>([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const saveOpen = ref(false)
const templateName = ref('')
const selected = ref<StockItemCopySection[]>(['core', 'product', 'i18n', 'categories', 'tags', 'attributes'])
const applying = ref<StockItemTemplate | null>(null)
const sku = ref('')
const name = ref('')
const choices: StockItemCopySection[] = ['core', 'product', 'i18n', 'categories', 'tags', 'attributes', 'fees', 'prices', 'vendors']

async function load() {
  loading.value = true
  error.value = ''
  try {
    templates.value = await stockApi.listItemTemplates()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function toggle(section: StockItemCopySection) {
  const index = selected.value.indexOf(section)
  if (index < 0) selected.value.push(section)
  else selected.value.splice(index, 1)
}

async function save() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await stockApi.saveItemTemplate(props.item.id, {
      name: templateName.value,
      row_version: props.item.row_version,
      sections: selected.value,
    })
    saveOpen.value = false
    templateName.value = ''
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function openApply(template: StockItemTemplate) {
  applying.value = template
  sku.value = ''
  name.value = `${template.name} ${t('stock.lifecycle.copy_suffix')}`
  error.value = ''
}

async function apply() {
  if (!applying.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const created = await stockApi.applyItemTemplate(applying.value.id, {
      sku: sku.value,
      name: name.value,
      row_version: applying.value.row_version,
    })
    applying.value = null
    emit('created', created)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function remove(template: StockItemTemplate) {
  if (!window.confirm(t('stock.lifecycle.templates.delete_confirm', { name: template.name }))) return
  busy.value = true
  error.value = ''
  try {
    await stockApi.deleteItemTemplate(template.id, template.row_version)
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(new Date(value))
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-5 dark:border-neutral-700">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold">{{ t('stock.lifecycle.templates.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('stock.lifecycle.templates.hint') }}</p>
      </div>
      <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" @click="saveOpen = true">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12 4v16m8-8H4" /></svg>
        {{ t('stock.lifecycle.templates.save') }}
      </button>
    </div>

    <p v-if="loading" class="mt-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="templates.length === 0" class="mt-4 text-sm text-neutral-500">{{ t('stock.lifecycle.templates.empty') }}</p>
    <div v-else class="mt-4 divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
      <div v-for="template in templates" :key="template.id" class="flex flex-wrap items-center justify-between gap-3 p-3">
        <div class="min-w-0">
          <p class="font-medium">{{ template.name }}</p>
          <p class="mt-1 text-xs text-neutral-500">
            {{ template.sections.map(section => t(`stock.lifecycle.sections.${section}`)).join(', ') }} · {{ formatDate(template.updated_at) }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="busy" @click="openApply(template)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12 5v14m-7-7h14" /></svg>
            {{ t('stock.lifecycle.templates.use') }}
          </button>
          <button type="button" :class="btnOutline('danger')" class="whitespace-nowrap" :disabled="busy" @click="remove(template)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M6 7h12m-9 0V5h6v2m-8 0 1 12h8l1-12" /></svg>
            {{ t('common.delete') }}
          </button>
        </div>
      </div>
    </div>
    <p v-if="error && !saveOpen && !applying" class="mt-3 text-sm text-danger-600" role="alert">{{ error }}</p>
  </section>

  <Modal v-if="saveOpen" :title="t('stock.lifecycle.templates.save_title')" @close="saveOpen = false">
    <form class="space-y-4" @submit.prevent="save">
      <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">
        {{ t('stock.lifecycle.templates.name') }}
        <input v-model.trim="templateName" required maxlength="120" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2" />
      </label>
      <fieldset class="rounded-lg border border-neutral-200 bg-surface p-3 dark:border-neutral-700">
        <legend class="px-1 text-sm font-medium">{{ t('stock.lifecycle.copy_sections') }}</legend>
        <div class="grid gap-2 sm:grid-cols-2">
          <label v-for="choice in choices" :key="choice" class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="selected.includes(choice)" @change="toggle(choice)" />
            {{ t(`stock.lifecycle.sections.${choice}`) }}
          </label>
        </div>
      </fieldset>
      <p class="text-xs text-neutral-500">{{ t('stock.lifecycle.templates.snapshot_hint') }}</p>
      <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="saveOpen = false">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnFilled('primary')" :disabled="busy">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M5 5h14v14H5zM8 5v5h8V5" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </form>
  </Modal>

  <Modal v-if="applying" :title="t('stock.lifecycle.templates.use_title', { name: applying.name })" @close="applying = null">
    <form class="space-y-4" @submit.prevent="apply">
      <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ t('stock.lifecycle.templates.use_hint') }}</p>
      <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">
        {{ t('stock.items.col_sku') }}
        <input v-model.trim="sku" required maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2" />
      </label>
      <label class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">
        {{ t('stock.items.col_name') }}
        <input v-model.trim="name" required maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2" />
      </label>
      <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="applying = null">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnFilled('primary')" :disabled="busy">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M12 5v14m-7-7h14" /></svg>
          {{ t('stock.lifecycle.templates.create') }}
        </button>
      </div>
    </form>
  </Modal>
</template>
