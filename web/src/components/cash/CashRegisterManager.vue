<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { cashApi, type CashRegister } from '@/api/cash'
import { cashErrorCode, cashErrorMessage } from '@/api/cashErrors'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { formatMoney } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline, btnIconSm, disabledTitle, BTN_DISABLED_NOTE } from '@/components/ui/buttonStyles'

/**
 * Modal správy pokladen (§6.4). CRUD nad cash_registers; analytika 211 z osnovy.
 * Smazání jen bez dokladů (409 → nabídka deaktivace).
 *
 * Vícesekční editor = JEDNO společné Uložit (konvence UI): změny v seznamu
 * (název, účet, výchozí, aktivní, smazání) i nová pokladna se sbírají do
 * rozpracovaného stavu a odešlou se najednou lištou dole. Dřív tu bylo tlačítko
 * u formuláře, okamžitý zápis na každý přepínač v řádku a nativní confirm()
 * u mazání — tři různé způsoby potvrzení jedné obrazovky.
 */
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>()

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()

// Daňová evidence (Epic DE §6): pokladna nemá journal → nepotřebuje účet 211 v osnově
// (COA se pro tax_evidence neseeduje). Účet je pak volitelný a select se skryje.
const isTaxEvidence = computed(() => supplierStore.currentSupplier?.accounting_mode === 'tax_evidence')

interface RegisterRow {
  source: CashRegister
  name: string
  account_code: string
  is_default: boolean
  own_series: boolean
  is_active: boolean
  /** Počáteční stav valutové pokladny ve valutě a jeho den; prázdné = bez počátečního stavu. */
  opening_amount: string
  opening_date: string
  remove: boolean
}

const rows = ref<RegisterRow[]>([])
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')

// Analytiky 211 z osnovy (aktivní) — nabídka pro select.
const cashAccounts = computed(() =>
  accounts.value
    .filter(a => a.is_active && a.account_code.startsWith('211'))
    .sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

const form = ref({ name: '', account_code: '', currency_code: 'CZK', is_default: false, own_series: false })
// Valutová pokladna (§11): u cizí měny je analytika volitelná (BE ji přidělí/dohraje automaticky).
const isForeignForm = computed(() => form.value.currency_code !== 'CZK')
const creating = computed(() => form.value.name.trim() !== '')

function toRow(r: CashRegister): RegisterRow {
  return {
    source: r,
    name: r.name,
    account_code: r.account_code,
    is_default: r.is_default,
    own_series: r.own_series === true,
    is_active: r.is_active,
    opening_amount: r.opening_foreign_amount != null ? String(r.opening_foreign_amount) : '',
    opening_date: r.opening_foreign_date ?? '',
    remove: false,
  }
}

async function load() {
  loading.value = true
  try {
    // G6 (audit 2026-07): osnova je double_entry-only (tax_evidence firma ji nemá
    // naseedovanou a GET /api/accounting/accounts je teď pro ni gatovaný 403) —
    // v DE se účet stejně nenabízí (viz isTaxEvidence výše), takže se ani nefetchuje.
    const [regs, accs] = await Promise.all([
      cashApi.listRegisters(true),
      isTaxEvidence.value ? Promise.resolve<ChartAccount[]>([]) : accountingApi.listAccounts(),
    ])
    rows.value = regs.map(toRow)
    accounts.value = accs
    if (!form.value.account_code && cashAccounts.value.length > 0) {
      form.value.account_code = cashAccounts.value[0].account_code
    }
  } catch (e: any) {
    toast.error(cashErrorMessage(e, t))
  } finally {
    loading.value = false
  }
}
onMounted(load)

/** Výchozí pokladna je právě jedna — volba v řádku odebere příznak ostatním i formuláři. */
function pickDefault(row: RegisterRow) {
  for (const r of rows.value) r.is_default = r === row
  form.value.is_default = false
}
function pickNewDefault(value: boolean) {
  form.value.is_default = value
  if (value) for (const r of rows.value) r.is_default = false
}

function isForeignRow(r: RegisterRow): boolean {
  return !!r.source.currency_code && r.source.currency_code !== 'CZK'
}
function openingChanged(r: RegisterRow): boolean {
  if (!isForeignRow(r)) return false
  const amount = r.opening_amount.trim() === '' ? null : Number(r.opening_amount.trim().replace(',', '.'))
  return amount !== (r.source.opening_foreign_amount ?? null) || r.opening_date !== (r.source.opening_foreign_date ?? '')
}

function rowChanged(r: RegisterRow): boolean {
  return r.remove
    || r.name.trim() !== r.source.name
    || r.account_code !== r.source.account_code
    || r.is_default !== r.source.is_default
    || r.own_series !== (r.source.own_series === true)
    || r.is_active !== r.source.is_active
    || openingChanged(r)
}
const changedRows = computed(() => rows.value.filter(rowChanged))
const removedRows = computed(() => rows.value.filter(r => r.remove))
const dirty = computed(() => changedRows.value.length > 0 || creating.value)

/** Věta, proč Uložit nejde (konvence BTN_DISABLED_NOTE). */
const blockedReason = computed<string | null>(() => {
  if (rows.value.some(r => !r.remove && r.name.trim() === '')) return t('cash.validation.name')
  if (rows.value.some(r => !r.remove && isForeignRow(r) && (r.opening_amount.trim() === '') !== (r.opening_date === ''))) {
    return t('cash.register_opening_foreign_hint')
  }
  if (creating.value && !isTaxEvidence.value && !isForeignForm.value && !form.value.account_code) {
    return t('cash.validation.account')
  }
  if (!dirty.value) return t('cash.register_nothing_to_save')
  return null
})
const canSave = computed(() => !saving.value && dirty.value && blockedReason.value === null)

async function saveAll() {
  error.value = ''
  if (!canSave.value) { error.value = blockedReason.value ?? ''; return }
  saving.value = true
  try {
    // Pořadí: nejdřív smazat, pak upravit, nakonec založit. Opačně by nová pokladna
    // mohla narazit na analytiku, kterou právě uvolňuje mazaná/upravovaná pokladna.
    for (const r of removedRows.value) {
      try {
        await cashApi.deleteRegister(r.source.id)
      } catch (e: any) {
        if (cashErrorCode(e) === 'register_has_documents') {
          toast.warning(t('cash.register_deactivate_hint'))
          throw e
        }
        throw e
      }
    }
    for (const r of changedRows.value) {
      if (r.remove) continue
      await cashApi.updateRegister(r.source.id, {
        name: r.name.trim(),
        account_code: r.account_code,
        is_default: r.is_default,
        own_series: r.own_series,
        is_active: r.is_active,
        ...(isForeignRow(r) ? {
          opening_foreign_amount: r.opening_amount.trim() === '' ? null : r.opening_amount.trim(),
          opening_foreign_date: r.opening_date || null,
        } : {}),
      })
    }
    if (creating.value) {
      await cashApi.createRegister({
        name: form.value.name.trim(),
        account_code: form.value.account_code,
        currency_code: form.value.currency_code,
        is_default: form.value.is_default,
        own_series: form.value.own_series,
      })
      form.value = { name: '', account_code: cashAccounts.value[0]?.account_code ?? '', currency_code: 'CZK', is_default: false, own_series: false }
    }
    toast.success(t('common.saved'))
    await load()
    emit('changed')
  } catch (e: any) {
    error.value = cashErrorMessage(e, t)
    // Část operací mohla projít — přenačti, ať formulář neukazuje stav, který už neplatí.
    await load()
    emit('changed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg max-w-2xl w-full my-8">
      <header class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="text-lg font-semibold">{{ t('cash.registers_manage') }}</h3>
        <button type="button" @click="emit('close')" :title="t('common.close')" :aria-label="t('common.close')"
          :class="btnIconSm('neutral')">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        </button>
      </header>

      <div class="p-5 space-y-5">
        <!-- Seznam pokladen -->
        <div v-if="loading" class="text-center text-neutral-500 py-6 text-sm">{{ t('common.loading') }}</div>
        <EmptyState v-else-if="rows.length === 0" dense accent="neutral" icon="coin" :title="t('cash.register_empty')" />
        <div v-else class="overflow-x-auto border border-neutral-200 rounded-lg">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('cash.register_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('cash.register_account') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('cash.balance') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('cash.register_default') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('cash.register_own_series') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('common.active') }}</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in rows" :key="r.source.id" :class="{ 'opacity-50': !r.is_active, 'bg-danger-50': r.remove }">
                <td class="px-3 py-2">
                  <input v-model="r.name" type="text" :disabled="r.remove"
                    :aria-label="t('cash.register_name')"
                    class="w-full h-8 px-2 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-100" />
                  <div v-if="isForeignRow(r)" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-neutral-600"
                    :title="t('cash.register_opening_foreign_hint')">
                    <span>{{ t('cash.register_opening_foreign') }}</span>
                    <input v-model="r.opening_amount" type="text" inputmode="decimal" :disabled="r.remove"
                      :aria-label="t('cash.register_opening_foreign')"
                      class="w-24 h-7 px-2 border border-neutral-300 rounded-md text-right font-mono disabled:bg-neutral-100" />
                    <span class="font-mono">{{ r.source.currency_code }}</span>
                    <input v-model="r.opening_date" type="date" :disabled="r.remove"
                      :aria-label="t('cash.register_opening_foreign_date')" :title="t('cash.register_opening_foreign_date')"
                      class="h-7 px-2 border border-neutral-300 rounded-md disabled:bg-neutral-100" />
                  </div>
                </td>
                <td class="px-3 py-2">
                  <span class="font-mono">{{ r.account_code }}</span>
                  <span class="text-neutral-500 ml-1">{{ r.source.account_name }}</span>
                  <span v-if="r.source.currency_code && r.source.currency_code !== 'CZK'"
                    class="ml-1 text-xs font-mono px-1 rounded bg-primary-50 text-primary-700">{{ r.source.currency_code }}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">
                  {{ formatMoney(r.source.balance) }}
                  <span v-if="r.source.balance_foreign != null" class="block text-xs text-neutral-500">
                    {{ formatMoney(r.source.balance_foreign) }} {{ r.source.currency_code }}
                  </span>
                </td>
                <td class="px-3 py-2 text-center">
                  <input type="radio" :checked="r.is_default" :disabled="r.remove || !r.is_active"
                    :aria-label="t('cash.register_default')"
                    @change="pickDefault(r)" class="cursor-pointer" />
                </td>
                <td class="px-3 py-2 text-center">
                  <input type="checkbox" v-model="r.own_series" :disabled="r.remove"
                    :aria-label="t('cash.register_own_series')" :title="t('cash.register_own_series_hint')"
                    class="cursor-pointer" />
                </td>
                <td class="px-3 py-2 text-center">
                  <input type="checkbox" v-model="r.is_active" :disabled="r.remove"
                    :aria-label="t('common.active')" class="cursor-pointer" />
                </td>
                <td class="px-3 py-2 text-right">
                  <button type="button" @click="r.remove = !r.remove"
                    :title="r.remove ? t('common.restore') : t('common.delete')"
                    :aria-label="r.remove ? t('common.restore') : t('common.delete')"
                    :class="btnIconSm(r.remove ? 'neutral' : 'danger')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="r.remove ? ICONS.uturn : ICONS.trash" />
                    </svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Formulář nové pokladny -->
        <div class="border-t border-neutral-200 pt-4">
          <h4 class="text-sm font-medium text-neutral-700 mb-3">{{ t('cash.register_create') }}</h4>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div :class="isTaxEvidence ? 'sm:col-span-2' : ''">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.register_name') }}</label>
              <input v-model="form.name" type="text"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div v-if="!isTaxEvidence">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.register_currency') }}</label>
              <select v-model="form.currency_code"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="CZK">CZK</option>
                <option value="EUR">EUR</option>
                <option value="USD">USD</option>
                <option value="GBP">GBP</option>
              </select>
            </div>
            <div v-if="!isTaxEvidence" class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1">
                {{ t('cash.register_account') }}
                <span v-if="isForeignForm" class="text-neutral-400">({{ t('common.optional') }})</span>
              </label>
              <select v-if="!isForeignForm" v-model="form.account_code"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="" disabled>—</option>
                <option v-for="a in cashAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} - {{ a.name }}</option>
              </select>
              <input v-else v-model="form.account_code" type="text" :placeholder="t('cash.register_account_placeholder')"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              <p v-if="isForeignForm" class="text-xs text-neutral-500 mt-1">{{ t('cash.register_foreign_hint') }}</p>
              <RouterLink v-else to="/accounting/accounts" class="text-xs text-primary-600 hover:text-primary-700 mt-1 inline-block">
                {{ t('cash.register_account_manage') }}
              </RouterLink>
            </div>
          </div>
          <label class="inline-flex items-center gap-2 mt-3 text-sm text-neutral-700 cursor-pointer">
            <input type="checkbox" :checked="form.is_default"
              @change="pickNewDefault(($event.target as HTMLInputElement).checked)" class="cursor-pointer" />
            {{ t('cash.register_default') }}
          </label>
          <label class="flex items-start gap-2 mt-2 text-sm text-neutral-700 cursor-pointer">
            <input type="checkbox" v-model="form.own_series" class="mt-0.5 cursor-pointer" />
            <span>{{ t('cash.register_own_series') }}
              <span class="block text-xs text-neutral-500">{{ t('cash.register_own_series_hint') }}</span></span>
          </label>
        </div>
      </div>

      <!-- Jedno společné Uložit pro obě sekce (seznam i nová pokladna). -->
      <footer class="sticky bottom-0 px-5 py-4 border-t border-neutral-200 bg-surface rounded-b-xl space-y-2">
        <p v-if="removedRows.length" class="text-xs text-danger-600">
          {{ t('cash.register_delete_pending', { names: removedRows.map(r => r.source.name).join(', ') }) }}
        </p>
        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <button type="button" @click="emit('close')" :class="btnOutline('neutral')">{{ t('common.close') }}</button>
          <button type="button" @click="saveAll" :disabled="!canSave" :class="btnFilled('primary')"
            :title="disabledTitle(!canSave, blockedReason)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
        <p v-if="blockedReason && dirty" :class="[BTN_DISABLED_NOTE, 'text-right']">{{ blockedReason }}</p>
      </footer>
    </div>
  </div>
</template>
