<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { suppliersApi, type SupplierListItem, type SupplierCreatePayload } from '@/api/suppliers'
import { clientsApi } from '@/api/clients'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

const suppliers = ref<SupplierListItem[]>([])
const loading = ref(false)

async function loadSuppliers() {
  loading.value = true
  try { suppliers.value = await suppliersApi.list() }
  finally { loading.value = false }
}

onMounted(async () => {
  await loadSuppliers()
  // Onboarding gate (#151): dashboard sem posílá s ?create=supplier → rovnou otevři
  // formulář pro vytvoření prvního dodavatele.
  if (route.query.create === 'supplier') newSupplier()
})

// ─── Suppliers (multi-tenant firmy) ───────────────────────────────────────
const supplierDraft = reactive<SupplierCreatePayload>({
  company_name: '', street: '', city: '', zip: '', email: '',
  country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
  commercial_register: '',
  default_payment_due_days: 14, default_hourly_rate: 1500,
})
const supplierCreateOpen = ref(false)
const supplierAresLoading = ref(false)
const supplierAresMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

// Bankovní účet nového dodavatele (volitelný, lze načíst z registru plátců DPH)
const supplierBank = reactive({ currency: 'CZK', account_number: '', bank_code: '', bank_name: '', iban: '', bic: '' })
const supplierBankLoading = ref(false)
const supplierBankMessage = ref<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null)
const supplierBankAccounts = ref<import('@/api/clients').CrpDphAccount[]>([])

function supplierApplyBank(acc: import('@/api/clients').CrpDphAccount) {
  if (acc.iban) {
    supplierBank.currency = 'EUR'
    supplierBank.iban = acc.iban
  } else {
    supplierBank.currency = 'CZK'
    supplierBank.account_number = acc.prefix ? `${acc.prefix}-${acc.number}` : acc.number
    supplierBank.bank_code = acc.bank_code
  }
}

async function supplierLookupBank() {
  const dic = (supplierDraft.dic || '').replace(/\D/g, '')
  if (!/^\d{8,10}$/.test(dic)) {
    supplierBankMessage.value = { type: 'error', text: t('supplier.bank_lookup_no_dic') }
    return
  }
  supplierBankLoading.value = true
  supplierBankMessage.value = null
  supplierBankAccounts.value = []
  try {
    const r = await clientsApi.lookupBank(dic)
    supplierBankAccounts.value = r.accounts
    if (r.accounts.length === 0) {
      supplierBankMessage.value = { type: 'error', text: t('supplier.bank_lookup_none') }
    } else {
      supplierApplyBank(r.accounts[0])
      supplierBankMessage.value = r.accounts.length === 1
        ? { type: 'success', text: t('supplier.bank_lookup_one') }
        : { type: 'success', text: t('supplier.bank_lookup_many', { n: r.accounts.length }) }
    }
    if (r.unreliable === true) supplierBankMessage.value = { type: 'warning', text: t('supplier.bank_lookup_unreliable') }
  } catch (e: any) {
    supplierBankMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.bank_lookup_failed') }
  } finally {
    supplierBankLoading.value = false
  }
}

function newSupplier() {
  Object.assign(supplierDraft, {
    company_name: '', street: '', city: '', zip: '', email: '',
    country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
    commercial_register: '', taxpayer_type: undefined,
    default_payment_due_days: 14, default_hourly_rate: 1500,
  })
  Object.assign(supplierBank, { currency: 'CZK', account_number: '', bank_code: '', bank_name: '', iban: '', bic: '' })
  supplierBankMessage.value = null
  supplierBankAccounts.value = []
  supplierAresMessage.value = null
  supplierCreateOpen.value = true
}

async function supplierLookupAres() {
  const ic = (supplierDraft.ic || '').trim()
  if (!/^\d{8}$/.test(ic)) {
    supplierAresMessage.value = { type: 'error', text: t('supplier.ares_invalid_ic') }
    return
  }
  supplierAresLoading.value = true
  supplierAresMessage.value = null
  try {
    const r = await clientsApi.lookupAres(ic)
    if (!r.found || !r.data) {
      supplierAresMessage.value = { type: 'error', text: t('supplier.ares_not_found') }
      return
    }
    const d = r.data
    supplierDraft.company_name = d.company_name || supplierDraft.company_name
    supplierDraft.street       = d.street       || supplierDraft.street
    supplierDraft.city         = d.city         || supplierDraft.city
    supplierDraft.zip          = d.zip          || supplierDraft.zip
    supplierDraft.country_iso2 = d.country_iso2 || supplierDraft.country_iso2 || 'CZ'
    supplierDraft.ic           = d.ic           || ic
    supplierDraft.dic          = d.dic          || supplierDraft.dic
    supplierDraft.is_vat_payer = d.is_vat_payer
    supplierDraft.commercial_register = d.commercial_register || supplierDraft.commercial_register
    if (d.taxpayer_type === 'fo' || d.taxpayer_type === 'po') supplierDraft.taxpayer_type = d.taxpayer_type
    supplierAresMessage.value = { type: 'success', text: t('supplier.ares_loaded', { name: d.company_name }) }
  } catch (e: any) {
    supplierAresMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.ares_failed') }
  } finally {
    supplierAresLoading.value = false
  }
}

async function saveSupplier() {
  if (!supplierDraft.company_name || !supplierDraft.street || !supplierDraft.city || !supplierDraft.zip || !supplierDraft.email) {
    toast.error(t('common.error'))
    return
  }
  try {
    const payload = { ...supplierDraft }
    if (supplierBank.account_number || supplierBank.iban) {
      payload.bank_account = {
        currency: supplierBank.currency,
        account_number: supplierBank.account_number || undefined,
        bank_code: supplierBank.bank_code || undefined,
        bank_name: supplierBank.bank_name || undefined,
        iban: supplierBank.iban || undefined,
        bic: supplierBank.bic || undefined,
      }
    }
    await suppliersApi.create(payload)
    supplierCreateOpen.value = false
    toast.success(t('common.saved'))
    await loadSuppliers()
    await auth.refresh()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeSupplier(s: SupplierListItem) {
  if (s.clients_count > 0 || s.invoices_count > 0) return
  if (!confirm(t('supplier.delete_confirm'))) return
  try {
    await suppliersApi.delete(s.id)
    toast.success(t('common.deleted'))
    await loadSuppliers()
    await auth.refresh()
    if (supplierStore.currentSupplierId === s.id) {
      const first = suppliers.value[0]
      if (first) supplierStore.setSupplier(first.id)
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function switchSupplier(id: number) {
  if (id === supplierStore.currentSupplierId) return
  supplierStore.setSupplier(id)
  window.location.reload()
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('supplier.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('supplier.list_subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <section v-else>
      <div class="flex justify-end mb-3">
        <button @click="newSupplier" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('supplier.new') }}
        </button>
      </div>

      <!-- Desktop tabulka -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-10"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.company_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('supplier.ic') }} / {{ t('supplier.dic') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('supplier.invoices') }}</th>
                <th class="px-3 py-2 w-48"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="s in suppliers" :key="s.id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 text-center">
                  <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base" :title="t('supplier.active_label')">●</span>
                </td>
                <td class="px-3 py-2">
                  <div class="font-medium text-neutral-900">{{ s.company_name }}</div>
                  <div v-if="s.display_name && s.display_name !== s.company_name" class="text-xs text-neutral-500">{{ s.display_name }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs">
                  <span v-if="s.ic">{{ s.ic }}</span>
                  <span v-if="s.ic && s.dic"> / </span>
                  <span v-if="s.dic">{{ s.dic }}</span>
                  <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ s.clients_count }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ s.invoices_count }}</td>
                <!-- Tlačítka, ne holé odkazy: obojí tu MĚNÍ stav (přepnutí firmy,
                     smazání), takže mají vypadat jako akce — a `whitespace-nowrap`
                     drží dvojici na jednom řádku. -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <div class="flex items-center justify-end gap-1.5">
                    <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
                      :class="btnOutlineSm('primary')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
                      {{ t('supplier.switch') }}
                    </button>
                    <button v-if="auth.isSuperadmin" @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || suppliers.length <= 1"
                      :class="btnOutlineSm('danger')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                      {{ t('common.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="s in suppliers" :key="`m-${s.id}`" class="px-4 py-3">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 flex items-center gap-1.5 min-w-0 truncate">
              <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base shrink-0" :title="t('supplier.active_label')">●</span>
              {{ s.company_name }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">
              <span v-if="s.ic">{{ s.ic }}</span>
              <span v-if="s.ic && s.dic"> / </span>
              <span v-if="s.dic">{{ s.dic }}</span>
              <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
            </span>
            <span class="font-mono">{{ t('supplier.clients') }}: {{ s.clients_count }} · {{ t('supplier.invoices') }}: {{ s.invoices_count }}</span>
          </div>
          <div class="flex gap-3 mt-2 text-xs">
            <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchSupplier(s.id)"
              class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('supplier.switch') }}</button>
            <button v-if="auth.isSuperadmin" @click="removeSupplier(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || suppliers.length <= 1"
              class="cursor-pointer ml-auto text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Supplier create modal (multi-tenant firma) -->
    <div v-if="supplierCreateOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('supplier.create_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-4">{{ t('supplier.create_hint') }}</p>
        <form @submit.prevent="saveSupplier">
          <div class="space-y-3">
            <div class="bg-primary-50/50 border border-primary-200 rounded-md p-3">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.ares_lookup') }}</label>
              <div class="flex gap-2">
                <input v-model="supplierDraft.ic" type="text" placeholder="12345678" maxlength="8"
                  @keydown.enter.prevent="supplierLookupAres"
                  class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <button type="button" @click="supplierLookupAres" :disabled="supplierAresLoading"
                  :class="btnFilled('primary')">
                  <svg v-if="!supplierAresLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  <span v-else>…</span>
                  {{ supplierAresLoading ? t('common.loading') : t('supplier.ares_load') }}
                </button>
              </div>
              <div v-if="supplierAresMessage" class="mt-2 text-xs px-2 py-1 rounded"
                :class="supplierAresMessage.type === 'success' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
                {{ supplierAresMessage.text }}
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.company_name') }} *</label>
              <input v-model="supplierDraft.company_name" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.dic') }}</label>
              <input v-model="supplierDraft.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.street') }} *</label>
              <input v-model="supplierDraft.street" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.zip') }} *</label>
                <input v-model="supplierDraft.zip" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div class="col-span-2">
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.city') }} *</label>
                <input v-model="supplierDraft.city" type="text" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.email') }} *</label>
              <input v-model="supplierDraft.email" type="email" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.commercial_register') }}</label>
              <input v-model="supplierDraft.commercial_register" type="text" :placeholder="t('settings.commercial_register_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>

            <div class="border-t border-neutral-200 pt-3">
              <div class="flex items-center justify-between mb-2 gap-2">
                <label class="block text-xs font-medium text-neutral-700">{{ t('settings.account_cz') }} / {{ t('settings.iban') }} <span class="text-neutral-400">{{ t('common.optional') }}</span></label>
                <button type="button" @click="supplierLookupBank" :disabled="supplierBankLoading"
                  :class="[btnOutline('primary'), 'shrink-0']">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  {{ supplierBankLoading ? t('common.loading') : t('supplier.bank_lookup') }}
                </button>
              </div>
              <div v-if="supplierBankMessage" class="mb-2 text-xs px-2 py-1 rounded"
                :class="{
                  'bg-success-50 text-success-600': supplierBankMessage.type === 'success',
                  'bg-danger-50 text-danger-500': supplierBankMessage.type === 'error',
                  'bg-warning-50 text-warning-600': supplierBankMessage.type === 'warning',
                }">
                {{ supplierBankMessage.text }}
              </div>
              <div v-if="supplierBankAccounts.length > 1" class="mb-2 flex flex-wrap gap-1.5">
                <button v-for="(acc, i) in supplierBankAccounts" :key="i" type="button" @click="supplierApplyBank(acc)"
                  class="cursor-pointer px-2 py-1 text-xs font-mono border border-neutral-300 rounded hover:bg-primary-50 hover:border-primary-300">
                  {{ acc.display }}
                </button>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('common.currency') }}</label>
                  <select v-model="supplierBank.currency" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                    <option value="CZK">CZK</option>
                    <option value="EUR">EUR</option>
                  </select>
                </div>
                <template v-if="supplierBank.currency === 'CZK'">
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.currency_account_cz') }}</label>
                    <input v-model="supplierBank.account_number" placeholder="1000000005" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_code') }}</label>
                    <input v-model="supplierBank.bank_code" maxlength="4" placeholder="0100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                </template>
                <template v-else>
                  <div class="col-span-2">
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
                    <input v-model="supplierBank.iban" placeholder="CZ65 0800 0000 1920 0014 5399" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                  </div>
                </template>
              </div>
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
            <button type="button" @click="supplierCreateOpen = false" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}</button>
            <button type="submit" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.create') }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
