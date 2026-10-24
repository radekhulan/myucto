<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { clientsApi, type AresLookupResult } from '@/api/clients'
import type { StereoCountryCandidate } from '@/api/stereoNx'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import CountrySelect from '@/components/ui/CountrySelect.vue'

interface CountryProposal {
  companyName: string
  ico: string
  dic: string
  street: string
  city: string
  zip: string
  country: string
  registry: 'ares' | 'vies'
  address?: string
}
type LookupError = 'not_found' | 'mismatched_ico' | 'invalid_country' | 'unavailable'
  | 'vies_unavailable' | 'vies_invalid' | 'vies_mismatched_vat' | 'vies_country_mismatch'
interface LookupState { pending: boolean; proposal: CountryProposal | null; error: LookupError | null; registry?: 'ares' | 'vies' }
const props = defineProps<{
  partners: StereoCountryCandidate[]
  modelValue: Record<string, string>
  disabled?: boolean
  changed?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, string>] }>()
const { t } = useI18n()
const states = ref(new Map<string, LookupState>())
const documentSections = ref(new Set<string>())
const documentLimits = ref(new Map<string, number>())
const cardOpen = ref(new Map<string, boolean>())
const manualOpen = ref(new Set<string>())
const manualDrafts = ref(new Map<string, string>())
const cardId = `stereo-country-card-${useId()}`
const EMPTY_STATE: LookupState = { pending: false, proposal: null, error: null }
const DOCUMENT_PAGE_SIZE = 50
// Prefixy vybírají jen ověřovací službu, neurčují daňový režim. XI zde nemá samostatnou ISO zemi.
const VIES_PREFIXES = new Set(['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'])
let generation = 0
let disposed = false
const pending = computed(() => [...states.value.values()].some(state => state.pending))
const blocked = computed(() => props.disabled || pending.value)
const rows = computed(() => props.partners.map(partner => ({
  partner, lookup: states.value.get(partner.source_key) ?? EMPTY_STATE,
  country: currentCountry(partner.source_key), eligible: eligible(partner), viesEligible: viesEligible(partner), vat: normalizeVat(partner.dic),
  open: !currentCountry(partner.source_key) || cardOpen.value.get(partner.source_key) === true,
})))
const confirmations = computed(() => new Map(props.partners.map(partner => [partner.source_key, currentCountry(partner.source_key)])))
watch(confirmations, (next, previous) => {
  const opened = new Map(cardOpen.value)
  for (const [key, country] of next) if (country !== previous.get(key)) {
    opened.delete(key)
    manualOpen.value = new Set([...manualOpen.value].filter(openKey => openKey !== key))
    const drafts = new Map(manualDrafts.value)
    drafts.delete(key)
    manualDrafts.value = drafts
  }
  cardOpen.value = opened
}, { flush: 'sync' })

function text(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}
function currentCountry(key: string): string {
  const value = props.modelValue[key]
  return Object.hasOwn(props.modelValue, key) ? text(value) : ''
}
function toggleCard(key: string): void {
  cardOpen.value = new Map(cardOpen.value).set(key, cardOpen.value.get(key) !== true)
}
function toggleManual(key: string): void {
  if (blocked.value) return
  const opened = new Set(manualOpen.value)
  if (opened.has(key)) opened.delete(key)
  else opened.add(key)
  manualOpen.value = opened
  manualDrafts.value = new Map(manualDrafts.value).set(key, '')
}
function setManualCountry(key: string, value: string): void {
  if (blocked.value || !manualOpen.value.has(key)) return
  manualDrafts.value = new Map(manualDrafts.value).set(key, text(value).toUpperCase())
}
function validManualCountry(key: string): boolean {
  const country = manualDrafts.value.get(key) ?? ''
  return /^[A-Z]{2}$/.test(country) && country !== 'EU'
}
function confirmManual(partner: StereoCountryCandidate): void {
  if (blocked.value || !manualOpen.value.has(partner.source_key) || !validManualCountry(partner.source_key)) return
  const country = manualDrafts.value.get(partner.source_key)!
  if (currentCountry(partner.source_key) === country) return
  emit('update:modelValue', { ...props.modelValue, [partner.source_key]: country })
}
function eligible(partner: StereoCountryCandidate): boolean {
  const ico = text(partner.ico)
  const dicPrefix = normalizeVat(partner.dic).match(/^[A-Z]{2}/)?.[0]
  const country = text(partner.country_code).toUpperCase()
  return /^\d{8}$/.test(ico) && (!dicPrefix || dicPrefix === 'CZ') && (!country || country === 'CZ')
}
function normalizeVat(value: unknown): string {
  return text(value).toUpperCase().replace(/[\s-]/g, '')
}
function viesEligible(partner: StereoCountryCandidate): boolean {
  const vat = normalizeVat(partner.dic)
  return /^[A-Z]{2}[A-Z0-9+*]{2,12}$/.test(vat) && VIES_PREFIXES.has(vat.slice(0, 2))
}
function vatCountry(prefix: string): string {
  return prefix === 'EL' ? 'GR' : prefix
}
function setState(key: string, state: LookupState): void {
  states.value = new Map(states.value).set(key, state)
}
function active(request: number): boolean {
  return !disposed && request === generation && !props.disabled
}
watch(() => props.partners, () => {
  generation++
  states.value = new Map()
  documentSections.value = new Set()
  documentLimits.value = new Map()
  cardOpen.value = new Map()
  manualOpen.value = new Set()
  manualDrafts.value = new Map()
}, { deep: true, flush: 'sync' })
watch(() => props.disabled, disabled => {
  if (!disabled) return
  generation++
  states.value = new Map([...states.value].map(([key, state]) => [key, { ...state, pending: false }]))
}, { flush: 'sync' })
onBeforeUnmount(() => { disposed = true; generation++ })

async function lookup(partner: StereoCountryCandidate): Promise<void> {
  if (blocked.value || !eligible(partner)) return
  const request = generation
  const key = partner.source_key
  const ico = text(partner.ico)
  setState(key, { pending: true, proposal: null, error: null })
  try {
    const result: AresLookupResult = await clientsApi.lookupAres(ico)
    if (!active(request)) return
    if (result?.found !== true || !result.data) {
      setState(key, { pending: false, proposal: null, error: 'not_found' })
      return
    }
    const data = result.data
    if (text(data.ic) !== ico) {
      setState(key, { pending: false, proposal: null, error: 'mismatched_ico' })
      return
    }
    const country = text(data.country_iso2).toUpperCase()
    if (!/^[A-Z]{2}$/.test(country) || country === 'EU') {
      setState(key, { pending: false, proposal: null, error: 'invalid_country' })
      return
    }
    setState(key, { pending: false, error: null, proposal: {
      companyName: text(data.company_name), ico, dic: text(data.dic),
      street: text(data.street), city: text(data.city), zip: text(data.zip), country, registry: 'ares',
    } })
  } catch {
    if (active(request)) setState(key, { pending: false, proposal: null, error: 'unavailable' })
  }
}
async function lookupVies(partner: StereoCountryCandidate): Promise<void> {
  if (blocked.value || !viesEligible(partner)) return
  const request = generation
  const key = partner.source_key
  const vat = normalizeVat(partner.dic)
  const failure = (error: LookupError): void => setState(key, { pending: false, proposal: null, error, registry: 'vies' })
  setState(key, { pending: true, proposal: null, error: null, registry: 'vies' })
  try {
    const result = await clientsApi.lookupVies(vat)
    if (!active(request)) return
    if (!result || result.source === 'error') { failure('vies_unavailable'); return }
    if (result.valid !== true) { failure('vies_invalid'); return }
    if (normalizeVat(result.vat_number) !== vat) { failure('vies_mismatched_vat'); return }
    const country = vatCountry(text(result.country).toUpperCase())
    if (country !== vatCountry(vat.slice(0, 2))) { failure('vies_country_mismatch'); return }
    setState(key, { pending: false, error: null, registry: 'vies', proposal: {
      companyName: text(result.name), ico: text(partner.ico), dic: vat,
      street: text(result.parsed?.street), city: text(result.parsed?.city), zip: text(result.parsed?.zip),
      address: text(result.address), country, registry: 'vies',
    } })
  } catch {
    if (active(request)) failure('vies_unavailable')
  }
}
function confirm(partner: StereoCountryCandidate): void {
  if (blocked.value) return
  const proposal = states.value.get(partner.source_key)?.proposal
  if (!proposal || currentCountry(partner.source_key) === proposal.country) return
  emit('update:modelValue', { ...props.modelValue, [partner.source_key]: proposal.country })
}
function undo(key: string): void {
  if (blocked.value || !Object.hasOwn(props.modelValue, key)) return
  const next = { ...props.modelValue }
  delete next[key]
  emit('update:modelValue', next)
}
function toggleDocuments(key: string, event: Event): void {
  const next = new Set(documentSections.value)
  if ((event.target as HTMLDetailsElement).open) next.add(key)
  else next.delete(key)
  documentSections.value = next
}
function loadMoreDocuments(key: string): void {
  documentLimits.value = new Map(documentLimits.value).set(key, (documentLimits.value.get(key) ?? DOCUMENT_PAGE_SIZE) + DOCUMENT_PAGE_SIZE)
}
</script>

<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-testid="stereo-partner-countries">
    <div>
      <h2 class="text-lg font-semibold">{{ t('stereo_nx.partner_countries.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('stereo_nx.partner_countries.hint') }}</p>
    </div>
    <p v-if="changed" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" role="status" data-testid="stereo-country-changed">{{ t('stereo_nx.partner_countries.changed') }}</p>
    <p v-if="!rows.length" class="text-sm text-neutral-600">{{ t('stereo_nx.partner_countries.empty') }}</p>
    <article v-for="(row, index) in rows" :key="row.partner.source_key" class="space-y-4 rounded-lg border border-neutral-200 p-4" :data-testid="`stereo-country-partner-${index}`">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <h3 class="min-w-0 break-words font-semibold">{{ row.partner.name || t('stereo_nx.partner_countries.unnamed') }}</h3>
        <div v-if="row.country" class="flex flex-wrap items-center gap-2">
          <span class="rounded-full bg-success-50 px-3 py-1 text-xs font-medium text-success-700" :data-testid="`stereo-country-confirmed-${index}`">{{ t('stereo_nx.partner_countries.confirmed', { country: row.country }) }}</span>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-testid="`stereo-country-expand-${index}`" :aria-expanded="row.open" :aria-controls="`${cardId}-${index}`" :disabled="blocked" @click="toggleCard(row.partner.source_key)"><svg class="h-4 w-4" :class="row.open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg>{{ t(row.open ? 'stereo_nx.partner_countries.collapse' : 'stereo_nx.partner_countries.expand') }}</button>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-testid="`stereo-country-undo-${index}`" :disabled="blocked" @click="undo(row.partner.source_key)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('stereo_nx.partner_countries.undo') }}</button>
        </div>
      </div>
      <div v-if="row.open" :id="`${cardId}-${index}`" class="space-y-4" :data-testid="`stereo-country-body-${index}`">
        <div>
          <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-600"><span>{{ t('stereo_nx.partner_countries.ico') }}: {{ row.partner.ico || '—' }}</span><span>{{ t('stereo_nx.partner_countries.dic') }}: {{ row.partner.dic || '—' }}</span></div>
          <p class="mt-1 break-words text-sm text-neutral-600">{{ t('stereo_nx.partner_countries.source_country') }}: {{ row.partner.source_country || t('stereo_nx.partner_countries.unknown_country') }}</p>
        </div>
        <p v-if="row.partner.confirmed_country && row.partner.confirmed_country !== row.country" class="text-xs text-neutral-500">{{ t('stereo_nx.partner_countries.previous_confirmation', { country: row.partner.confirmed_country }) }}</p>
        <details v-if="row.partner.documents.length" class="rounded-md border border-neutral-200" :open="documentSections.has(row.partner.source_key)" :data-testid="`stereo-country-documents-${index}`" @toggle="toggleDocuments(row.partner.source_key, $event)">
          <summary class="cursor-pointer px-3 py-2 text-sm font-medium">{{ t('stereo_nx.partner_countries.documents', { count: row.partner.documents.length }) }}</summary>
          <div v-if="documentSections.has(row.partner.source_key)" class="space-y-3 border-t border-neutral-200 px-3 py-3">
            <ul class="space-y-1 text-sm text-neutral-600"><li v-for="document in row.partner.documents.slice(0, documentLimits.get(row.partner.source_key) ?? DOCUMENT_PAGE_SIZE)" :key="document.source_key" class="break-words">{{ t(`stereo_nx.document_kind.${document.kind}`) }} · {{ document.document_no || t('stereo_nx.unnumbered_document') }}</li></ul>
            <div v-if="row.partner.documents.length > (documentLimits.get(row.partner.source_key) ?? DOCUMENT_PAGE_SIZE)" class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="blocked" @click="loadMoreDocuments(row.partner.source_key)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('stereo_nx.partner_countries.more_documents') }}</button></div>
          </div>
        </details>
        <p v-else class="rounded-md bg-neutral-50 px-3 py-2 text-sm text-neutral-600" :data-testid="`stereo-country-directory-only-${index}`">{{ t('stereo_nx.partner_countries.directory_only') }}</p>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :data-testid="`stereo-country-lookup-${index}`" :disabled="blocked || !row.eligible" @click="lookup(row.partner)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t(row.lookup.error && row.lookup.registry !== 'vies' ? 'stereo_nx.partner_countries.lookup_again' : 'stereo_nx.partner_countries.lookup') }}</button>
          <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :data-testid="`stereo-country-vies-${index}`" :disabled="blocked || !row.viesEligible" @click="lookupVies(row.partner)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t(row.lookup.error && row.lookup.registry === 'vies' ? 'stereo_nx.partner_countries.vies_again' : 'stereo_nx.partner_countries.vies_lookup') }}</button>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-testid="`stereo-country-manual-toggle-${index}`" :aria-expanded="manualOpen.has(row.partner.source_key)" :disabled="blocked" @click="toggleManual(row.partner.source_key)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('stereo_nx.partner_countries.manual_toggle') }}</button>
        </div>
        <p v-if="!row.eligible && !row.viesEligible" class="text-xs text-neutral-600">{{ t(row.vat.startsWith('XI') ? 'stereo_nx.partner_countries.xi_unsupported' : 'stereo_nx.partner_countries.ineligible') }}</p>
        <p v-if="row.viesEligible" class="text-xs text-neutral-600">{{ t('stereo_nx.partner_countries.vies_hint') }}</p>
        <p v-if="row.lookup.pending" class="text-sm text-primary-700" role="status" :data-testid="`stereo-country-pending-${index}`">{{ t(row.lookup.registry === 'vies' ? 'stereo_nx.partner_countries.vies_loading' : 'stereo_nx.partner_countries.loading') }}</p>
        <p v-if="row.lookup.error" class="rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" role="alert" :data-testid="`stereo-country-error-${index}`">{{ t(`stereo_nx.partner_countries.errors.${row.lookup.error}`) }}</p>
        <div v-if="manualOpen.has(row.partner.source_key)" class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
          <p class="text-sm text-neutral-600">{{ t('stereo_nx.partner_countries.manual_hint') }}</p>
          <label class="block max-w-sm text-sm font-medium">{{ t('stereo_nx.partner_countries.manual_country') }}<CountrySelect :model-value="manualDrafts.get(row.partner.source_key) ?? ''" :disabled="blocked" :data-testid="`stereo-country-manual-select-${index}`" class="mt-1" @update:model-value="setManualCountry(row.partner.source_key, $event)" /></label>
          <p v-if="validManualCountry(row.partner.source_key)" class="text-sm text-neutral-600">{{ t(row.partner.documents.length ? 'stereo_nx.partner_countries.scope' : 'stereo_nx.partner_countries.directory_scope', { count: row.partner.documents.length, country: manualDrafts.get(row.partner.source_key) }) }}</p>
          <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('success')" class="whitespace-nowrap" :data-testid="`stereo-country-manual-confirm-${index}`" :disabled="blocked || !validManualCountry(row.partner.source_key) || row.country === manualDrafts.get(row.partner.source_key)" @click="confirmManual(row.partner)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('stereo_nx.partner_countries.manual_confirm') }}</button></div>
        </div>
        <div v-if="row.lookup.proposal" class="space-y-3 rounded-lg border border-primary-500/30 bg-primary-50/30 p-4" :data-testid="`stereo-country-proposal-${index}`">
          <h4 class="text-sm font-semibold">{{ t(row.lookup.proposal.registry === 'vies' ? 'stereo_nx.partner_countries.vies_proposal_title' : 'stereo_nx.partner_countries.proposal_title') }}</h4>
          <dl class="grid gap-x-4 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-neutral-500">{{ t('stereo_nx.partner_countries.company_name') }}</dt><dd class="break-words">{{ row.lookup.proposal.companyName || '—' }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('stereo_nx.partner_countries.ico') }}</dt><dd>{{ row.lookup.proposal.ico }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('stereo_nx.partner_countries.dic') }}</dt><dd class="break-words">{{ row.lookup.proposal.dic || '—' }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('stereo_nx.partner_countries.address') }}</dt><dd class="whitespace-pre-line break-words">{{ row.lookup.proposal.address || [row.lookup.proposal.street, [row.lookup.proposal.zip, row.lookup.proposal.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') || '—' }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('stereo_nx.partner_countries.proposed_country') }}</dt><dd class="font-semibold">{{ row.lookup.proposal.country }}</dd></div>
          </dl>
          <p class="text-sm text-neutral-600">{{ t(row.partner.documents.length ? 'stereo_nx.partner_countries.scope' : 'stereo_nx.partner_countries.directory_scope', { count: row.partner.documents.length, country: row.lookup.proposal.country }) }}</p>
          <p class="text-xs text-neutral-600">{{ t('stereo_nx.partner_countries.history_hint') }}</p>
          <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('success')" class="whitespace-nowrap" :data-testid="`stereo-country-confirm-${index}`" :disabled="blocked || row.country === row.lookup.proposal.country" @click="confirm(row.partner)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('stereo_nx.partner_countries.confirm') }}</button></div>
        </div>
      </div>
    </article>
  </section>
</template>
