<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client, type ClientPayload } from '@/api/clients'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

/**
 * Porovnání vybraného kontaktu dokladu s veřejným registrem: CZ kontakt s IČO jde do ARES,
 * jinak podle DIČ do VIES. Rozdíly lze po polích převzít do karty kontaktu přes
 * PUT /api/clients/{id} (částečný payload, chybějící klíč = beze změny).
 * Lookup i úprava karty vyžadují na serveru zápis do `clients`, proto se bez něj tlačítko
 * nevykreslí vůbec.
 */
type RegistryField = 'company_name' | 'street' | 'city' | 'zip' | 'country_iso2' | 'ic' | 'dic' | 'is_vat_payer'
type RegistryValues = Partial<Record<RegistryField, string | boolean>>
type Row = { key: RegistryField; label: string; stored: string | boolean | null; registry: string | boolean; differs: boolean }

const props = defineProps<{
  clientId: number | null
  /** Kontakt z cache editoru; bez něj (nebo s jiným id) se karta dotáhne sama. */
  client?: Pick<Client, 'id' | 'ic' | 'dic' | 'country_iso2'> | null
  buttonClass?: string
}>()

const emit = defineEmits<{
  updated: [client: Client, fields: RegistryField[]]
}>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const ident = ref<Pick<Client, 'id' | 'ic' | 'dic' | 'country_iso2'> | null>(null)
let identVersion = 0

watch(() => [props.clientId, props.client] as const, async ([id, hint]) => {
  const version = ++identVersion
  if (!id) { ident.value = null; return }
  if (hint && hint.id === id) { ident.value = hint; return }
  try {
    const full = await clientsApi.get(id)
    if (version === identVersion) ident.value = full
  } catch {
    if (version === identVersion) ident.value = null
  }
}, { immediate: true })

const canUse = computed(() => auth.canWrite('clients'))

function normIc(v: string | null | undefined): string { return (v || '').replace(/\D/g, '') }
function normDic(v: string | null | undefined): string { return (v || '').toUpperCase().replace(/[\s-]/g, '') }

/** CZ kontakt s IČO → ARES; jinak DIČ → VIES. Zahraniční IČO do ARES nepatří. */
function sourceFor(c: Pick<Client, 'ic' | 'dic' | 'country_iso2'> | null): 'ares' | 'vies' | null {
  if (!c) return null
  const country = (c.country_iso2 || 'CZ').toUpperCase()
  if (country === 'CZ' && normIc(c.ic).length === 8) return 'ares'
  if (/^[A-Z]{2}[A-Z0-9+*]{2,12}$/.test(normDic(c.dic))) return 'vies'
  return null
}

const source = computed(() => sourceFor(ident.value))
const buttonTitle = computed(() => {
  if (source.value === 'ares') return t('client.registry_check.title_ares')
  if (source.value === 'vies') return t('client.registry_check.title_vies')
  return t('client.registry_check.disabled_reason')
})

const open = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const usedSource = ref<'ares' | 'vies'>('ares')
const stored = ref<Client | null>(null)
const registry = ref<RegistryValues>({})
const unreliable = ref<boolean | null>(null)
const selected = ref<Set<RegistryField>>(new Set())

function fieldLabel(key: RegistryField): string {
  switch (key) {
    case 'company_name': return t('client.registry_check.field_company_name')
    case 'street': return t('client.registry_check.field_street')
    case 'city': return t('client.registry_check.field_city')
    case 'zip': return t('client.registry_check.field_zip')
    case 'country_iso2': return t('client.registry_check.field_country')
    case 'ic': return t('client.registry_check.field_ic')
    case 'dic': return t('client.registry_check.field_dic')
    case 'is_vat_payer': return t('client.registry_check.field_vat_payer')
  }
}

function normForCompare(key: RegistryField, v: string | boolean | null | undefined): string {
  if (typeof v === 'boolean') return v ? '1' : '0'
  const s = (v ?? '').toString().replace(/\s+/g, ' ').trim()
  if (key === 'zip') return s.replace(/\s/g, '')
  if (key === 'ic') return normIc(s)
  if (key === 'dic' || key === 'country_iso2') return normDic(s)
  return s
}

const FIELDS: RegistryField[] = ['company_name', 'street', 'city', 'zip', 'country_iso2', 'ic', 'dic', 'is_vat_payer']

const rows = computed<Row[]>(() => {
  const c = stored.value
  if (!c) return []
  const out: Row[] = []
  for (const key of FIELDS) {
    const reg = registry.value[key]
    // Prázdnou hodnotu registru nikdy nenabízíme — přepsala by uložený údaj prázdnem.
    if (reg === undefined || (typeof reg === 'string' && reg.trim() === '')) continue
    const own = key === 'is_vat_payer' ? (c.is_vat_payer ?? null) : ((c[key] as string | null | undefined) ?? null)
    out.push({ key, label: fieldLabel(key), stored: own, registry: reg, differs: normForCompare(key, own) !== normForCompare(key, reg) })
  }
  return out
})
const hasDiff = computed(() => rows.value.some(r => r.differs))

function display(v: string | boolean | null): string {
  if (v === null || v === '') return '-'
  if (typeof v === 'boolean') return v ? t('client.registry_check.yes') : t('client.registry_check.no')
  return v
}

function toggle(key: RegistryField) {
  const next = new Set(selected.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selected.value = next
}

function errMessage(e: any, fallback: string): string {
  return e?.response?.data?.error?.message || fallback
}

async function check() {
  const id = props.clientId
  if (!id || !source.value) return
  open.value = true
  loading.value = true
  error.value = ''
  notice.value = ''
  registry.value = {}
  unreliable.value = null
  stored.value = null
  selected.value = new Set()
  try {
    const c = await clientsApi.get(id)
    stored.value = c
    const src = sourceFor(c)
    if (!src) { error.value = t('client.registry_check.disabled_reason'); return }
    usedSource.value = src
    if (src === 'ares') {
      const r = await clientsApi.lookupAres(normIc(c.ic))
      if (!r.found || !r.data) { error.value = t('client.registry_check.ares_not_found'); return }
      const d = r.data
      registry.value = {
        company_name: d.company_name, street: d.street, city: d.city, zip: d.zip,
        country_iso2: d.country_iso2 || 'CZ', ic: d.ic, dic: d.dic, is_vat_payer: d.is_vat_payer,
      }
      const dic = normDic(d.dic)
      if (d.is_vat_payer && dic.startsWith('CZ')) {
        try {
          const b = await clientsApi.lookupBank(dic.replace(/\D/g, ''))
          unreliable.value = b.source === 'error' ? null : b.unreliable
        } catch { /* spolehlivost je jen doplňková informace */ }
      }
    } else {
      const r = await clientsApi.lookupVies(normDic(c.dic))
      if (r.source === 'error') { error.value = t('client.registry_check.vies_unavailable'); return }
      if (!r.valid) {
        // Neplatné DIČ ve VIES = subjekt není registrovaný k DPH; nabídneme jen plátcovství.
        notice.value = t('client.registry_check.vies_invalid')
        registry.value = { is_vat_payer: false }
      } else {
        registry.value = {
          company_name: r.name ?? '',
          street: r.parsed?.street ?? '', city: r.parsed?.city ?? '', zip: r.parsed?.zip ?? '',
          country_iso2: r.country ?? '', is_vat_payer: true,
        }
      }
    }
    selected.value = new Set(rows.value.filter(r => r.differs).map(r => r.key))
  } catch (e: any) {
    error.value = errMessage(e, t('client.registry_check.lookup_failed'))
  } finally {
    loading.value = false
  }
}

async function save() {
  const c = stored.value
  if (!c || selected.value.size === 0) return
  const payload: Partial<ClientPayload> = {}
  const fields: RegistryField[] = []
  for (const r of rows.value) {
    if (!r.differs || !selected.value.has(r.key)) continue
    ;(payload as Record<string, unknown>)[r.key] = r.registry
    fields.push(r.key)
  }
  if (fields.length === 0) return
  saving.value = true
  error.value = ''
  try {
    const updated = await clientsApi.update(c.id, payload)
    if (props.clientId === updated.id) ident.value = updated
    toast.success(t('client.registry_check.saved'))
    emit('updated', updated, fields)
    open.value = false
  } catch (e: any) {
    error.value = errMessage(e, t('client.registry_check.save_failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <template v-if="clientId && canUse">
    <button type="button" data-test="registry-check" :class="[btnOutline('neutral'), 'shrink-0', buttonClass]"
      :disabled="!source" :title="buttonTitle" :aria-label="buttonTitle" @click="check">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
      {{ source === 'vies' ? 'VIES' : 'ARES' }}
    </button>
    <Modal v-if="open" :title="t('client.registry_check.modal_title', { source: usedSource === 'vies' ? 'VIES' : 'ARES' })"
      width-class="max-w-2xl" @close="open = false">
      <p v-if="loading" class="text-sm text-neutral-500">{{ t('client.registry_check.loading') }}</p>
      <template v-else>
        <p v-if="error" data-test="registry-error" class="rounded-md border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</p>
        <p v-if="notice" class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ notice }}</p>
        <template v-if="rows.length > 0">
          <p v-if="!hasDiff" data-test="registry-up-to-date" class="mb-3 rounded-md border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">
            {{ t('client.registry_check.up_to_date') }}
          </p>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                  <th class="py-2 pr-2 w-8"></th>
                  <th class="py-2 pr-3">{{ t('client.registry_check.col_field') }}</th>
                  <th class="py-2 pr-3">{{ t('client.registry_check.col_stored') }}</th>
                  <th class="py-2">{{ t('client.registry_check.col_registry') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in rows" :key="r.key" :data-test="`registry-row-${r.key}`"
                  class="border-b border-neutral-100 align-top" :class="r.differs ? 'bg-warning-50/60' : ''">
                  <td class="py-2 pr-2">
                    <input v-if="r.differs" type="checkbox" class="mt-0.5 cursor-pointer" :checked="selected.has(r.key)"
                      :aria-label="r.label" @change="toggle(r.key)" />
                  </td>
                  <td class="py-2 pr-3 text-neutral-600 whitespace-nowrap">{{ r.label }}</td>
                  <td class="py-2 pr-3" :class="r.differs ? 'text-neutral-500 line-through decoration-neutral-400' : ''">{{ display(r.stored) }}</td>
                  <td class="py-2" :class="r.differs ? 'font-medium text-warning-700' : 'text-neutral-700'">{{ display(r.registry) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="unreliable !== null" class="mt-3 text-sm">
            <span class="text-neutral-600">{{ t('client.vat_payer_reliability') }}:</span>
            <span v-if="unreliable" data-test="registry-unreliable" class="ml-1 px-2 py-0.5 rounded bg-danger-50 text-danger-600 font-medium">{{ t('client.vat_payer_unreliable') }}</span>
            <span v-else class="ml-1 px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_reliable') }}</span>
          </p>
          <p class="mt-3 text-xs text-neutral-500">{{ t('client.registry_check.hint') }}</p>
        </template>
      </template>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="open = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
          <button v-if="hasDiff" type="button" data-test="registry-apply" :class="btnFilled('primary')"
            :disabled="saving || selected.size === 0" @click="save">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('client.registry_check.apply') }}
          </button>
        </div>
      </template>
    </Modal>
  </template>
</template>
