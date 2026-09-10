<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { productMastersApi, type ProductVariantContext, type ProductVariantInheritance } from '@/api/productMasters'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const props = defineProps<{ itemId: number; rowVersion: number; variant: ProductVariantContext; canWrite: boolean }>()
const emit = defineEmits<{ (event: 'updated', value: ProductVariantContext, rowVersion: number): void }>()
const { t } = useI18n()
const toast = useToast()
const inheritance = ref<ProductVariantInheritance>({ manufacturer: false, i18n: {} })
const saving = ref(false)
const error = ref('')
const fields = ['name', 'short_desc', 'description', 'seo_title', 'seo_description'] as const

function reset() {
  inheritance.value = JSON.parse(JSON.stringify(props.variant.inheritance ?? { manufacturer: true, i18n: {} }))
  for (const row of props.variant.effective?.i18n ?? []) ensureLocale(row.locale)
}

function ensureLocale(locale: string) {
  inheritance.value.i18n[locale] ??= { name: true, short_desc: true, description: true, seo_title: true, seo_description: true }
  return inheritance.value.i18n[locale]!
}

async function save() {
  if (!props.canWrite || saving.value) return
  saving.value = true
  error.value = ''
  try {
    const master = await productMastersApi.updateVariant(props.variant.master_id, props.itemId, {
      link_row_version: props.variant.link_row_version,
      row_version: props.rowVersion,
      inheritance: inheritance.value,
    })
    const updated = master.variants.find(row => row.stock_item_id === props.itemId)
    if (updated) emit('updated', { ...props.variant, link_row_version: updated.link_row_version, inheritance: updated.inheritance, effective: updated.effective }, updated.row_version)
    toast.success(t('common.saved'))
  } catch (err: any) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

watch(() => props.variant, reset, { immediate: true, deep: true })
</script>

<template>
  <section class="space-y-5 rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ t('eshop.inheritance.title') }}</h2><p class="text-sm text-neutral-500">{{ t('eshop.inheritance.hint') }}</p></div><RouterLink :to="`/eshop/product-masters/${variant.master_id}`" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700 hover:underline"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>{{ variant.master_name ?? t('eshop.inheritance.open_master') }}</RouterLink></div>
    <div class="rounded-md border border-neutral-200 p-3"><label class="flex cursor-pointer items-start gap-3"><input v-model="inheritance.manufacturer" type="checkbox" :disabled="!canWrite" class="mt-0.5 rounded border-neutral-300 text-primary-600" /><span><strong class="block text-sm">{{ t('eshop.masters.field_manufacturer') }}</strong><span class="text-xs text-neutral-500">{{ inheritance.manufacturer ? t('eshop.inheritance.from_master') : t('eshop.inheritance.own') }}</span></span></label></div>
    <div v-for="locale in Object.keys(inheritance.i18n).sort(code => code === 'cs' ? -1 : 1)" :key="locale" class="overflow-hidden rounded-md border border-neutral-200"><h3 class="bg-neutral-50 px-3 py-2 text-sm font-semibold">{{ locale.toUpperCase() }}</h3><div class="divide-y divide-neutral-100"><label v-for="field in fields" :key="field" class="grid cursor-pointer grid-cols-[1fr_auto] items-center gap-3 px-3 py-2 text-sm"><span>{{ t(`eshop.masters.content_field.${field}`) }}<span v-if="field === 'name'" class="ml-2 text-xs text-neutral-500">{{ t('eshop.inheritance.slug_own') }}</span></span><span class="inline-flex items-center gap-2"><span class="text-xs" :class="ensureLocale(locale)[field] ? 'text-primary-700' : 'text-neutral-500'">{{ ensureLocale(locale)[field] ? t('eshop.inheritance.master') : t('eshop.inheritance.own') }}</span><input v-model="ensureLocale(locale)[field]" type="checkbox" :disabled="!canWrite" class="rounded border-neutral-300 text-primary-600" /></span></label></div></div>
    <div v-if="variant.effective" class="rounded-md bg-primary-50 p-3"><h3 class="mb-2 text-sm font-semibold text-primary-800">{{ t('eshop.inheritance.effective_preview') }}</h3><div v-for="row in variant.effective.i18n" :key="row.locale" class="text-sm"><strong>{{ row.locale.toUpperCase() }}</strong>: {{ row.name || '-' }}</div></div>
    <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
    <div v-if="canWrite" class="flex flex-wrap justify-end"><button type="button" @click="save" :disabled="saving" :class="btnFilled('success')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ saving ? t('common.saving') : t('common.save') }}</button></div>
  </section>
</template>
