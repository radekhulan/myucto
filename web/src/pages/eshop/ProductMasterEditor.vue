<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { eshopApi, type Attribute, type AttributeOption, type EshopLocale, type Manufacturer } from '@/api/eshop'
import { productMastersApi, type ProductMaster, type ProductMasterI18nRow, type ProductMasterPayload } from '@/api/productMasters'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import ProductMasterVariants from '@/components/stock/ProductMasterVariants.vue'
import ProductContentTransfer from '@/components/stock/ProductContentTransfer.vue'
import MarkdownEditor from '@/components/ui/MarkdownEditor.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const isNew = computed(() => route.params.id === undefined || route.params.id === 'new')
const masterId = computed(() => isNew.value ? null : Number(route.params.id))
const canWrite = computed(() => auth.canWrite('eshop.write') && auth.canWrite('stock.items.write'))

const master = ref<ProductMaster | null>(null)
const name = ref('')
const manufacturerId = ref<number | null>(null)
const axisIds = ref<number[]>([])
const translations = ref<ProductMasterI18nRow[]>([])
const selectedLocale = ref('cs')
const locales = ref<EshopLocale[]>([])
const manufacturers = ref<Manufacturer[]>([])
const attributes = ref<Attribute[]>([])
const options = ref<Record<number, AttributeOption[]>>({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const active = ref(true)
let generation = 0
let controller: AbortController | null = null
let savedSnapshot = ''

const orderedTranslations = computed(() => [...translations.value].sort((a, b) => a.locale === 'cs' ? -1 : b.locale === 'cs' ? 1 : a.locale.localeCompare(b.locale)))
const dirty = computed(() => snapshot() !== savedSnapshot)

function emptyTranslation(locale: string): ProductMasterI18nRow {
  return { locale, name: null, short_desc: null, description: null, seo_title: null, seo_description: null }
}

function snapshot() { return JSON.stringify({ name: name.value, manufacturerId: manufacturerId.value, axisIds: axisIds.value, translations: translations.value }) }
function markSaved() { savedSnapshot = snapshot() }
function localeName(code: string) { return locales.value.find(locale => locale.code === code)?.name ?? code.toUpperCase() }
function addLocale(code: string) { if (code && !translations.value.some(row => row.locale === code)) translations.value.push(emptyTranslation(code)); selectedLocale.value = code }
function removeLocale(code: string) { if (code === 'cs') return; translations.value = translations.value.filter(row => row.locale !== code); selectedLocale.value = 'cs' }
function toggleAxis(id: number) { axisIds.value = axisIds.value.includes(id) ? axisIds.value.filter(value => value !== id) : [...axisIds.value, id] }

async function load() {
  const current = ++generation
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''
  try {
    const [localeRows, manufacturerRows, attributeRows, masterRow] = await Promise.all([
      eshopApi.listLocales(),
      eshopApi.listManufacturers(),
      eshopApi.listAttributes(),
      masterId.value ? productMastersApi.get(masterId.value, controller.signal) : Promise.resolve(null),
    ])
    if (!active.value || current !== generation) return
    locales.value = localeRows
    manufacturers.value = manufacturerRows
    attributes.value = attributeRows.filter(row => row.data_type === 'enum' && !row.is_multivalue && !row.archived)
    const optionPairs = await Promise.all(attributes.value.map(async attribute => [attribute.id, await eshopApi.listAttributeOptions(attribute.id)] as const))
    if (!active.value || current !== generation) return
    options.value = Object.fromEntries(optionPairs)
    master.value = masterRow
    name.value = masterRow?.name ?? ''
    manufacturerId.value = masterRow?.manufacturer_id ?? null
    axisIds.value = masterRow?.axes.map(axis => axis.attribute_id) ?? []
    translations.value = masterRow?.i18n.map(row => ({ ...row })) ?? [emptyTranslation('cs')]
    if (!translations.value.some(row => row.locale === 'cs')) translations.value.unshift(emptyTranslation('cs'))
    selectedLocale.value = 'cs'
    await nextTick()
    markSaved()
  } catch (err: any) {
    if (err?.code !== 'ERR_CANCELED' && current === generation) error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    if (current === generation) loading.value = false
  }
}

function payload(): ProductMasterPayload {
  return {
    name: name.value.trim(),
    manufacturer_id: manufacturerId.value,
    axis_attribute_ids: [...axisIds.value],
    i18n: translations.value.map(row => ({ ...row, name: row.name?.trim() || null })),
  }
}

async function save() {
  if (!canWrite.value || saving.value || !name.value.trim()) return
  saving.value = true
  error.value = ''
  try {
    if (master.value) {
      master.value = await productMastersApi.update(master.value.id, { ...payload(), row_version: master.value.row_version })
      name.value = master.value.name
      axisIds.value = master.value.axes.map(axis => axis.attribute_id)
      translations.value = master.value.i18n.map(row => ({ ...row }))
      markSaved()
      toast.success(t('common.saved'))
    } else {
      const created = await productMastersApi.create(payload())
      markSaved()
      toast.success(t('common.saved'))
      await router.replace(`/eshop/product-masters/${created.id}`)
    }
  } catch (err: any) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

function acceptUpdated(value: ProductMaster) {
  master.value = value
  name.value = value.name
  manufacturerId.value = value.manufacturer_id
  axisIds.value = value.axes.map(axis => axis.attribute_id)
  translations.value = value.i18n.map(row => ({ ...row }))
  markSaved()
}

function confirmDiscard() { return !dirty.value || confirm(t('stock.items.editor_ux.discard_changes')) }
onBeforeRouteLeave(() => confirmDiscard())
watch(() => supplier.currentSupplierId, () => { generation++; controller?.abort(); master.value = null; void router.replace('/eshop?tab=masters') })
watch(masterId, () => { generation++; controller?.abort(); void load() })
onMounted(load)
onActivated(() => { active.value = true; if (!loading.value && masterId.value) void load() })
onDeactivated(() => { active.value = false; generation++; controller?.abort() })
onBeforeUnmount(() => { active.value = false; generation++; controller?.abort() })
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-semibold">{{ isNew ? t('eshop.masters.new') : (master?.name ?? t('eshop.masters.detail_title')) }}</h1><p class="mt-0.5 text-sm text-neutral-500">{{ t('eshop.masters.master_hint') }}</p></div><RouterLink to="/eshop?tab=masters" :class="btnOutline('neutral')"><svg class="h-4 w-4 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('eshop.masters.back') }}</RouterLink></div>
    <div v-if="loading" class="py-16 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <template v-else>
      <form class="space-y-5" @submit.prevent="save">
        <fieldset :disabled="saving || !canWrite" class="space-y-5 border-0 p-0">
          <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm"><h2 class="mb-4 text-lg font-semibold">{{ t('eshop.masters.identity') }}</h2><div class="grid grid-cols-1 gap-4 md:grid-cols-2"><label class="block"><span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('eshop.masters.name') }} *</span><input v-model="name" required maxlength="255" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label><label class="block"><span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('eshop.masters.field_manufacturer') }}</span><select v-model="manufacturerId" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option :value="null">{{ t('eshop.item.none') }}</option><option v-for="row in manufacturers" :key="row.id" :value="row.id">{{ row.name }}</option></select></label></div><p class="mt-3 rounded-md bg-primary-50 px-3 py-2 text-sm text-primary-800">{{ t('eshop.masters.non_sellable_hint') }}</p></section>

          <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm"><h2 class="mb-1 text-lg font-semibold">{{ t('eshop.masters.axes') }}</h2><p class="mb-3 text-sm text-neutral-500">{{ t('eshop.masters.axes_hint') }}</p><div class="flex flex-wrap gap-2"><button v-for="attribute in attributes" :key="attribute.id" type="button" @click="toggleAxis(attribute.id)" class="cursor-pointer rounded-full border px-3 py-1.5 text-sm" :class="axisIds.includes(attribute.id) ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"><svg v-if="axisIds.includes(attribute.id)" class="mr-1 inline h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ attribute.name }}</button></div><p v-if="attributes.length === 0" class="text-sm text-neutral-500">{{ t('eshop.masters.no_axes') }}</p></section>

          <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm"><div class="mb-3 flex flex-wrap items-end justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ t('eshop.masters.content') }}</h2><p class="text-sm text-neutral-500">{{ t('eshop.masters.content_hint') }}</p></div><label class="block"><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.languages.select_locale') }}</span><select value="" @change="addLocale(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm"><option value="">{{ t('eshop.languages.select_locale') }}</option><option v-for="locale in locales.filter(value => !value.archived && !translations.some(row => row.locale === value.code))" :key="locale.code" :value="locale.code">{{ locale.name }}</option></select></label></div><div class="mb-3 flex flex-wrap gap-2 border-b border-neutral-200 pb-3"><button v-for="row in orderedTranslations" :key="row.locale" type="button" @click="selectedLocale = row.locale" class="cursor-pointer rounded-md border px-3 py-1.5 text-sm" :class="selectedLocale === row.locale ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600'">{{ localeName(row.locale) }}</button></div><div v-for="row in translations.filter(value => value.locale === selectedLocale)" :key="row.locale" class="space-y-3"><div class="flex justify-between"><strong>{{ localeName(row.locale) }}</strong><button v-if="row.locale !== 'cs'" type="button" @click="removeLocale(row.locale)" class="cursor-pointer text-sm text-danger-600">{{ t('common.remove') }}</button></div><label class="block"><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.masters.content_field.name') }}</span><input v-model="row.name" maxlength="255" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" /></label><label class="block"><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.masters.content_field.short_desc') }}</span><input v-model="row.short_desc" maxlength="500" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" /></label><label class="block"><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.masters.content_field.description') }}</span><MarkdownEditor v-model="row.description" :rows="6" /></label><div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.masters.content_field.seo_title') }}</span><input v-model="row.seo_title" maxlength="255" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" /></label><label><span class="mb-1 block text-xs font-medium text-neutral-500">{{ t('eshop.masters.content_field.seo_description') }}</span><input v-model="row.seo_description" maxlength="500" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" /></label></div><p class="text-xs text-neutral-500">{{ t('eshop.inheritance.slug_own') }}</p></div></section>
        </fieldset>
        <p v-if="error" class="rounded-md bg-danger-50 p-3 text-sm text-danger-600">{{ error }}</p>
        <div v-if="canWrite" class="flex flex-wrap justify-end"><button type="submit" :disabled="saving || !name.trim()" :class="btnFilled('success')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ saving ? t('common.saving') : t('common.save') }}</button></div>
      </form>
      <ProductMasterVariants v-if="master" :master="master" :options="options" :can-write="canWrite" @updated="acceptUpdated" />
      <ProductContentTransfer v-if="master" :master="master" :can-write="canWrite" />
    </template>
  </div>
</template>
