<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionFields from './DimensionFields.vue'
import DimensionChips from './DimensionChips.vue'
import DimensionSplitEditor from './DimensionSplitEditor.vue'
import {
  dimensionsApi, compactDimensions,
  type DimensionDocType, type DimensionMap, type DimensionPrefillParams, type DimensionSplitShare, type DimensionSplits,
} from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { applyPrefill } from '@/composables/useDocumentDimensions'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Dimenze hlavičky dokladu na jeho detailu (bankovní pohyb, pokladní doklad, faktura).
 * Uložení promítne dimenze i do už zaúčtovaných řádků dokladu — mění se jen
 * analytika, proto to jde i u dokladu v uzavřeném období.
 *
 * `prefill` (spárovaná faktura / klient) doplní prázdné typy jako návrh — uloží
 * se až tlačítkem, doklad se sám nemění.
 */
const props = withDefaults(defineProps<{
  docType: DimensionDocType
  docId: number
  readonly?: boolean
  /** Bez rámečku a nadpisu (vložení do existující karty). */
  bare?: boolean
  prefill?: DimensionPrefillParams | null
  /** Sbalitelná karta jako Zaúčtování; výchozí stav sbalený. */
  collapsible?: boolean
  /** Sbalitelnou kartu otevřít hned (např. chybí povinná dimenze). */
  initiallyOpen?: boolean
}>(), { readonly: false, bare: false, prefill: null, collapsible: false, initiallyOpen: false })

const emit = defineEmits<{ saved: [header: DimensionMap] }>()

const { t } = useI18n()
const toast = useToast()
const dims = useDimensions()

const header = ref<DimensionMap>({})
const saved = ref<string>('{}')
const loading = ref(false)
const saving = ref(false)
const needsRepost = ref(false)
/** Změna by přesunula řádek na jinou analytiku (účtotvorná dimenze), ne jen rozdělila náklad. */
const needsRepostAccount = ref(false)
const autoFilled = ref<Record<number, number>>({})

/** Rozpady dokladu podle pořadí položky (0 = hlavička); panel upravuje jen hlavičku. */
const splits = ref<Record<number, DimensionSplits>>({})
const savedSplits = ref<string>('{}')
const splitOpen = ref(false)
const headerSplits = computed<DimensionSplits>(() => splits.value[0] ?? {})
/** Typ s rozpadem hlavičky nemá jedinou hodnotu — ve výběru se nenabízí. */
const singleTypes = computed(() => dims.documentTypes.value.filter(ty => !(headerSplits.value[ty.id]?.length)))

function applySplit(typeId: number, shares: DimensionSplitShare[] | null) {
  const current = { ...headerSplits.value }
  if (shares === null) {
    delete current[typeId]
  } else {
    current[typeId] = shares
    header.value = { ...header.value, [typeId]: null }
  }
  splits.value = { ...splits.value, 0: current }
  splitOpen.value = false
}

const dirty = computed(() => JSON.stringify(compactDimensions(header.value)) !== saved.value
  || JSON.stringify(splits.value) !== savedSplits.value)
const editable = computed(() => !props.readonly && dims.canEdit.value)
const hasAutoFilled = computed(() =>
  Object.entries(autoFilled.value).some(([typeId, valueId]) => header.value[Number(typeId)] === valueId))

/*
 * Sbalitelná karta je výchozím stavem zavřená. Neuložený návrh (předvyplnění)
 * ani výzva k přeúčtování se ale schovat nesmí, proto ji otevřou.
 */
const open = ref(!props.collapsible || props.initiallyOpen)
watch([dirty, needsRepost], ([isDirty, repost]) => {
  if (isDirty || repost) open.value = true
})

async function load() {
  if (!dims.enabled.value || props.docId <= 0) return
  loading.value = true
  try {
    await dims.load()
    const data = await dimensionsApi.getDocument(props.docType, props.docId)
    header.value = { ...data.header }
    saved.value = JSON.stringify(compactDimensions(data.header))
    splits.value = { ...(data.splits ?? {}) }
    savedSplits.value = JSON.stringify(splits.value)
    autoFilled.value = {}
  } catch {
    header.value = {}
  } finally {
    loading.value = false
  }
  await applyPrefillParams()
}

let prefillSeq = 0
async function applyPrefillParams() {
  if (!editable.value || !dims.enabled.value) return
  const params = props.prefill ?? {}
  const seq = ++prefillSeq
  let defaults: Record<number, number> = {}
  if (Object.values(params).some(v => v != null && v > 0)) {
    try {
      defaults = (await dimensionsApi.prefill(params)).header
    } catch {
      return
    }
  }
  if (seq !== prefillSeq) return
  const result = applyPrefill(header.value, autoFilled.value, defaults)
  header.value = result.header
  autoFilled.value = result.autoFilled
}

async function save() {
  saving.value = true
  try {
    const result = await dimensionsApi.saveDocument(props.docType, props.docId, { header: header.value, splits: splits.value })
    saved.value = JSON.stringify(compactDimensions(result.header))
    splits.value = { ...(result.splits ?? {}) }
    savedSplits.value = JSON.stringify(splits.value)
    autoFilled.value = {}
    needsRepost.value = result.restamp.needs_repost
    needsRepostAccount.value = result.restamp.account_change === true
    toast.success(result.restamp.lines > 0
      ? t('dimensions.saved_restamped', { count: result.restamp.lines })
      : t('dimensions.saved'))
    emit('saved', result.header)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
watch(() => [props.docType, props.docId], load)
watch(() => JSON.stringify(props.prefill ?? {}), () => { if (!loading.value) void applyPrefillParams() })
</script>

<template>
  <section v-if="dims.enabled.value && dims.documentTypes.value.length > 0 && collapsible"
           class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden"
           data-test="document-dimensions">
    <div class="flex items-center justify-between gap-2 pr-5" :class="open ? 'border-b border-neutral-200' : ''">
      <button type="button" class="flex-1 min-w-0 px-5 py-3 flex items-center justify-between gap-2 text-left hover:bg-neutral-50 cursor-pointer"
        :aria-expanded="open" data-test="document-dimensions-toggle" @click="open = !open">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dimensions.panel_title') }}</h3>
        <svg class="w-4 h-4 text-neutral-400 transition-transform shrink-0" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
      </button>
      <button v-if="editable && dirty" type="button" :disabled="saving" :class="btnFilled('primary')" class="whitespace-nowrap" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ saving ? t('common.saving') : t('dimensions.save') }}
      </button>
    </div>
    <div v-show="open" class="px-5 py-4">
      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <template v-else>
        <DimensionFields v-model="header" :types="singleTypes" :disabled="!editable" />
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <DimensionChips :dimensions="{}" :splits="headerSplits" class="flex" data-test="document-splits" />
          <button v-if="editable" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="document-split" @click="splitOpen = true">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ t('dimensions.split.open') }}
          </button>
        </div>
      </template>
      <p v-if="!loading && hasAutoFilled" class="mt-2 text-xs text-neutral-500" data-test="dimension-autofilled">{{ t('dimensions.defaults.autofilled') }}</p>
      <p v-if="needsRepost" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-800">
        {{ t(needsRepostAccount ? 'dimensions.needs_repost_account' : 'dimensions.needs_repost') }}
      </p>
    </div>
    <DimensionSplitEditor
      v-if="splitOpen"
      :types="dims.documentTypes.value"
      :current="headerSplits"
      :title="t('dimensions.split.title_document')"
      @close="splitOpen = false"
      @save="applySplit"
    />
  </section>
  <section v-else-if="dims.enabled.value && dims.documentTypes.value.length > 0"
           :class="bare ? '' : 'bg-surface border border-neutral-200 rounded-lg shadow-sm p-4'"
           data-test="document-dimensions">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dimensions.panel_title') }}</h3>
      <button v-if="editable && dirty" type="button" :disabled="saving" :class="btnFilled('primary')" class="whitespace-nowrap" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ saving ? t('common.saving') : t('dimensions.save') }}
      </button>
    </div>
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <template v-else>
      <DimensionFields v-model="header" :types="singleTypes" :disabled="!editable" />
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <DimensionChips :dimensions="{}" :splits="headerSplits" class="flex" data-test="document-splits" />
        <button v-if="editable" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" data-test="document-split" @click="splitOpen = true">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
          {{ t('dimensions.split.open') }}
        </button>
      </div>
    </template>
    <DimensionSplitEditor
      v-if="splitOpen"
      :types="dims.documentTypes.value"
      :current="headerSplits"
      :title="t('dimensions.split.title_document')"
      @close="splitOpen = false"
      @save="applySplit"
    />
    <p v-if="!loading && hasAutoFilled" class="mt-2 text-xs text-neutral-500" data-test="dimension-autofilled">{{ t('dimensions.defaults.autofilled') }}</p>
    <p v-if="needsRepost" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-800">
      {{ t(needsRepostAccount ? 'dimensions.needs_repost_account' : 'dimensions.needs_repost') }}
    </p>
  </section>
</template>
