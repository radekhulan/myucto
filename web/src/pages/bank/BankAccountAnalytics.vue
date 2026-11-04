<script setup lang="ts">
/**
 * Vlastní bankovní účty firmy — metadata pro kontaci: druh účtu (běžný / spořicí /
 * termínovaný vklad), label a analytika 221.xxx (analytic_suffix). Účty detekuje
 * systém z výpisů/nastavení; tady se jen pojmenují a zaškatulkují, aby deník
 * a výkazy členily účet 221 po analytikách.
 *
 * Se zapnutými dimenzemi nese účet i výchozí dimenze: zaúčtování je doplní pohybu,
 * který hodnotu typu nemá sám ani z hrazených dokladů (úroky, poplatky).
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { bankPostingApi, type SupplierBankAccount, type BankAccountKind } from '@/api/bankPosting'
import { dimensionsApi, compactDimensions, type DimensionMap } from '@/api/dimensions'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDimensions } from '@/composables/useDimensions'
import EmptyState from '@/components/ui/EmptyState.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import DimensionChips from '@/components/dimensions/DimensionChips.vue'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const dims = useDimensions()

const KINDS: BankAccountKind[] = ['current', 'savings', 'term_deposit']

// Tvar kódu analytiky — TEČKOVANÝ, shodně se zbytkem osnovy (501.100, 345.100) i se
// serverem (BankAnalyticAssigner::codeFor, migrace 1322). Ukládá se pořád jen suffix
// ('100'), prefix je čistě zobrazení.
const BANK_PREFIX = '221.'

const accounts = ref<SupplierBankAccount[]>([])
const loading = ref(true)
const error = ref('')
const saving = ref(false)

// Drafty per účet — uložené hodnoty se drží zvlášť, ať jde poznat „změněno".
interface Draft { kind: BankAccountKind; label: string; analytic_suffix: string; document_series: string; is_active: boolean }
const drafts = ref<Record<number, Draft>>({})

const canWrite = computed(() => auth.canWrite('accounting'))

// Výchozí dimenze účtu: uložené mapy zvlášť od draftů (jako ostatní pole), řádek
// s výběrem se rozbalí tlačítkem ve sloupci.
const dimensionsVisible = computed(() => dims.enabled.value && dims.documentTypes.value.length > 0)
const savedDimensions = ref<Record<number, string>>({})
const dimensionDrafts = ref<Record<number, DimensionMap>>({})
const dimensionsOpen = ref<Record<number, boolean>>({})
const columnCount = computed(() => (dimensionsVisible.value ? 8 : 7))

function draftFrom(a: SupplierBankAccount): Draft {
  return {
    kind: a.kind,
    label: a.label ?? '',
    analytic_suffix: a.analytic_suffix ?? '',
    document_series: a.document_series ?? '',
    is_active: Boolean(a.is_active),
  }
}

function dimensionsDirty(a: SupplierBankAccount): boolean {
  if (!dimensionsVisible.value || !dimensionDrafts.value[a.id]) return false
  return JSON.stringify(compactDimensions(dimensionDrafts.value[a.id])) !== (savedDimensions.value[a.id] ?? '{}')
}

function isDirty(a: SupplierBankAccount): boolean {
  return fieldsDirty(a) || dimensionsDirty(a)
}

function fieldsDirty(a: SupplierBankAccount): boolean {
  const d = drafts.value[a.id]
  if (!d) return false
  return d.kind !== a.kind
    || d.label !== (a.label ?? '')
    || d.analytic_suffix !== (a.analytic_suffix ?? '')
    || normalizeSeries(d.document_series) !== (a.document_series ?? '')
    || d.is_active !== Boolean(a.is_active)
}

const dirtyCount = computed(() => accounts.value.filter(isDirty).length)

// Stejný tvar jako na serveru (BankDocumentNumber::SERIES_PATTERN).
const SERIES_PATTERN = /^[A-Z0-9]{1,10}$/

function normalizeSeries(value: string): string {
  return value.trim().toUpperCase()
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    accounts.value = await bankPostingApi.listAccounts()
    const d: Record<number, Draft> = {}
    for (const a of accounts.value) d[a.id] = draftFrom(a)
    drafts.value = d
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
  try {
    await loadDimensions()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function loadDimensions() {
  if (!dims.enabled.value) return
  await dims.load()
  if (!dimensionsVisible.value) return
  const loaded = await Promise.all(accounts.value.map(async (a) => [a.id, await dimensionsApi.getDefaults('accounting/bank-accounts', a.id)] as const))
  const drafts: Record<number, DimensionMap> = {}
  const saved: Record<number, string> = {}
  for (const [id, map] of loaded) {
    drafts[id] = { ...map }
    saved[id] = JSON.stringify(compactDimensions(map))
  }
  dimensionDrafts.value = drafts
  savedDimensions.value = saved
}

function toggleDimensions(id: number) {
  dimensionsOpen.value = { ...dimensionsOpen.value, [id]: !dimensionsOpen.value[id] }
}

async function saveAll() {
  if (!canWrite.value || saving.value || dirtyCount.value === 0) return
  saving.value = true
  let renumbered = 0
  try {
    for (const a of accounts.value.filter(isDirty)) {
      if (!fieldsDirty(a)) {
        await dimensionsApi.saveDefaults('accounting/bank-accounts', a.id, dimensionDrafts.value[a.id])
        continue
      }
      const d = drafts.value[a.id]
      const series = normalizeSeries(d.document_series)
      if (!SERIES_PATTERN.test(series)) {
        toast.error(t('bank.analytics.series_invalid', { account: accountLabel(a) }))
        return
      }
      const seriesClash = accounts.value.find(
        (o) => o.id !== a.id && normalizeSeries(drafts.value[o.id]?.document_series ?? '') === series,
      )
      if (seriesClash) {
        toast.error(t('bank.analytics.series_taken', { series, account: accountLabel(seriesClash) }))
        return
      }
      if (d.analytic_suffix !== '' && !/^[0-9]{1,6}$/.test(d.analytic_suffix)) {
        toast.error(t('bank.analytics.suffix_invalid', { account: accountLabel(a) }))
        return
      }
      // Analytiku nelze zrušit — účet ji mít musí, jinak se jeho pohyby vrátí na ploché 221.
      if (d.analytic_suffix === '' && (a.analytic_suffix ?? '') !== '') {
        toast.error(t('bank.analytics.suffix_required', { account: accountLabel(a) }))
        return
      }
      // Dvě čísla na jednom účtu = promíchaný zůstatek; server to odmítne taky.
      const clash = accounts.value.find(
        (o) => o.id !== a.id && (drafts.value[o.id]?.analytic_suffix ?? '') === d.analytic_suffix && d.analytic_suffix !== '',
      )
      if (clash) {
        toast.error(t('bank.analytics.suffix_taken', { suffix: BANK_PREFIX + d.analytic_suffix, account: accountLabel(clash) }))
        return
      }
      // Výchozí dimenze až po kontrole polí účtu, ať chyba v řadě nebo analytice neuloží půlku řádku.
      if (dimensionsDirty(a)) {
        await dimensionsApi.saveDefaults('accounting/bank-accounts', a.id, dimensionDrafts.value[a.id])
      }
      // Úvěrový účet kreditní karty: druh, analytiku (231) i archivaci spravuje stránka
      // Kreditní karty; vypnout ho tu nejde (pohyby by spadly na 221).
      const seriesPatch = series !== (a.document_series ?? '') ? { document_series: series } : {}
      const saved = await bankPostingApi.updateAccount(a.id, a.kind === 'credit_card'
        ? { label: d.label.trim() || null, ...seriesPatch }
        : {
            kind: d.kind,
            label: d.label.trim() || null,
            analytic_suffix: d.analytic_suffix.trim() || null,
            is_active: d.is_active,
            ...seriesPatch,
          })
      renumbered += saved.renumbered_entries ?? 0
    }
    toast.success(t('bank.analytics.saved'))
    if (renumbered > 0) toast.success(t('bank.analytics.series_renumbered', { count: renumbered }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await load()
  } finally {
    saving.value = false
  }
}

function accountLabel(a: SupplierBankAccount): string {
  if (a.account_number) return a.account_number + (a.bank_code ? '/' + a.bank_code : '')
  return a.iban ?? String(a.id)
}

onMounted(load)
</script>

<template>
  <div class="max-w-6xl">
    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('bank.analytics.explainer_title') }}</p>
      <p>{{ t('bank.analytics.explainer_body') }}</p>
      <p class="mt-2">{{ t('bank.analytics.series_explainer') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <EmptyState v-else-if="accounts.length === 0" boxed icon="coin" :title="t('bank.analytics.empty')" />

    <template v-else>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.currency') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('bank.analytics.col.kind') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('bank.analytics.col.analytic') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('bank.analytics.col.series') }}</th>
                <th v-if="dimensionsVisible" class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('bank.analytics.col.dimensions') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('bank.analytics.col.active') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="a in accounts" :key="a.id">
              <tr :class="{ 'bg-primary-50/40': isDirty(a) }">
                <td class="px-3 py-2 font-mono whitespace-nowrap">
                  {{ accountLabel(a) }}
                  <div v-if="a.iban && a.account_number" class="text-[10px] text-neutral-400">{{ a.iban }}</div>
                </td>
                <td class="px-3 py-2 font-mono">{{ a.currency ?? '—' }}</td>
                <td class="px-3 py-2">
                  <input v-model="drafts[a.id].label" type="text" maxlength="120" :disabled="!canWrite"
                         :placeholder="t('bank.analytics.label_placeholder')"
                         class="w-44 h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50" />
                </td>
                <td class="px-3 py-2">
                  <span v-if="a.kind === 'credit_card'" class="whitespace-nowrap">{{ t('bank.analytics.kinds.credit_card') }}</span>
                  <select v-else v-model="drafts[a.id].kind" :disabled="!canWrite"
                          class="h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50">
                    <option v-for="k in KINDS" :key="k" :value="k">{{ t(`bank.analytics.kinds.${k}`) }}</option>
                  </select>
                </td>
                <td class="px-3 py-2">
                  <RouterLink v-if="a.kind === 'credit_card'" :to="{ name: 'credit-cards' }" class="text-primary-700 hover:underline whitespace-nowrap">
                    {{ t('bank.analytics.credit_card_managed') }}
                  </RouterLink>
                  <div v-else class="flex items-center gap-2 font-mono">
                    <div class="flex items-center gap-1">
                      <span class="text-neutral-400">{{ BANK_PREFIX }}</span>
                      <input v-model="drafts[a.id].analytic_suffix" type="text" maxlength="6" :disabled="!canWrite"
                             placeholder="—"
                             class="w-20 h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface font-mono disabled:bg-neutral-50" />
                    </div>
                    <span v-if="drafts[a.id].analytic_suffix" class="text-neutral-500 whitespace-nowrap">
                      = {{ BANK_PREFIX + drafts[a.id].analytic_suffix }}
                    </span>
                  </div>
                </td>
                <td class="px-3 py-2">
                  <input v-model="drafts[a.id].document_series" type="text" maxlength="10" :disabled="!canWrite"
                         placeholder="BCR"
                         class="w-24 h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface font-mono uppercase disabled:bg-neutral-50" />
                </td>
                <td v-if="dimensionsVisible" class="px-3 py-2">
                  <div class="flex flex-wrap items-center gap-2">
                    <DimensionChips :dimensions="dimensionDrafts[a.id]" />
                    <button type="button" :class="btnOutlineSm('neutral')"
                            :aria-expanded="!!dimensionsOpen[a.id]" data-test="bank-account-dimensions-toggle"
                            @click="toggleDimensions(a.id)">
                      <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="canWrite ? ICONS.edit : ICONS.eye" />
                      </svg>
                      {{ dimensionsOpen[a.id] ? t('bank.analytics.dimensions_hide') : (canWrite ? t('bank.analytics.dimensions_edit') : t('bank.analytics.dimensions_show')) }}
                    </button>
                  </div>
                </td>
                <td class="px-3 py-2 text-center">
                  <input v-model="drafts[a.id].is_active" type="checkbox" :disabled="!canWrite || a.kind === 'credit_card'"
                         class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                </td>
              </tr>
              <tr v-if="dimensionsVisible && dimensionsOpen[a.id] && dimensionDrafts[a.id]" :class="{ 'bg-primary-50/40': isDirty(a) }"
                  data-test="bank-account-dimensions">
                <td :colspan="columnCount" class="px-3 pb-3 pt-1">
                  <p class="text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.defaults.title') }}</p>
                  <DimensionFields v-model="dimensionDrafts[a.id]" :disabled="!canWrite" />
                  <p class="text-xs text-neutral-500 mt-1">{{ t('dimensions.defaults.hint_bank_account') }}</p>
                </td>
              </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="canWrite" class="sticky bottom-[calc(var(--app-footer-height,0px)+1rem)] mt-4 flex items-center justify-end gap-3 bg-surface/95 backdrop-blur border border-neutral-200 rounded-lg px-4 py-3 shadow-md">
        <span v-if="dirtyCount" class="text-xs text-neutral-500">
          {{ t('bank.analytics.unsaved', { count: dirtyCount }) }}
        </span>
        <button type="button" :disabled="dirtyCount === 0 || saving"
                class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm disabled:opacity-50"
                @click="saveAll">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </template>
  </div>
</template>
