<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '../ui/Modal.vue'
import JournalLinesEditor, { type EditorLine } from './JournalLinesEditor.vue'
import { btnFilled, btnOutline } from '../ui/buttonStyles'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { closingApi, type FindingRemedy } from '@/api/closing'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'

/**
 * Doúčtování nálezu kontroly spárovaných plateb.
 *
 * Návrh se NEBERE z tabulky na klientovi — server si nález spočítá znovu a teprve z něj
 * odvodí řádky. Kdyby částku určoval požadavek, dala by se podvrhnout a nechat si zápis
 * odklepnout; navíc tím vzniká užitečná pojistka: nález mezitím vyřešený vrátí 404
 * a okno místo návrhu řekne, že už není co doúčtovat.
 *
 * U nálezů bez jednoznačného řešení se pole schválně otevře PRÁZDNÉ. Předvyplnit dohad
 * tam, kde systém správnou odpověď nezná, znamená nechat si od účetní potvrdit chybu —
 * a schválený nesprávný zápis se hledá hůř než neopravený nález.
 */

const props = defineProps<{
  periodId: number
  docType: 'invoice' | 'purchase_invoice'
  docId: number
  issue: string
}>()

const emit = defineEmits<{ close: []; posted: [] }>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const gone = ref(false)
const saving = ref(false)
const remedy = ref<FindingRemedy | null>(null)
const accounts = ref<ChartAccount[]>([])
const lines = ref<EditorLine[]>([])
const description = ref('')
const entryDate = ref('')

const editorRef = ref<InstanceType<typeof JournalLinesEditor> | null>(null)

const reversalOnly = computed(() => remedy.value?.proposal?.kind === 'fx_reversal')

const canSubmit = computed(() =>
  !saving.value && !gone.value && !reversalOnly.value
  && lines.value.length >= 2
  && (editorRef.value?.valid ?? false))

onMounted(async () => {
  try {
    const [r, coa] = await Promise.all([
      closingApi.findingRemedy(props.periodId, props.docType, props.docId, props.issue),
      accountingApi.listAccounts(),
    ])
    remedy.value = r
    accounts.value = coa
    entryDate.value = r.entry_date
    description.value = r.proposal?.description ?? ''
    lines.value = (r.proposal?.lines ?? []).map(l => ({
      account_code: l.account_code,
      side: l.side as 'debit' | 'credit',
      amount: l.amount,
    }))
    // Bez návrhu se otevře prázdný pár řádků, aby účetní měla kam psát.
    if (lines.value.length === 0 && r.proposal?.kind !== 'fx_reversal') {
      lines.value = [
        { account_code: '', side: 'debit', amount: null },
        { account_code: '', side: 'credit', amount: null },
      ]
    }
  } catch (e: any) {
    if (e?.response?.status === 404) gone.value = true
    else toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
})

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  try {
    await accountingApi.createEntry({
      entry_date: entryDate.value,
      description: description.value || undefined,
      document_no: remedy.value?.doc_no ?? undefined,
      lines: lines.value.map(l => ({
        account_code: l.account_code,
        side: l.side,
        amount: l.amount ?? 0,
      })),
    })
    toast.success(t('accounting.finding_remedy.posted'))
    emit('posted')
    emit('close')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('accounting.finding_remedy.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('accounting.finding_remedy.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4">
      <p v-if="loading" class="text-sm text-neutral-500">{{ t('accounting.finding_remedy.loading') }}</p>

      <p v-else-if="gone" class="text-sm text-warning-700 bg-warning-50 dark:bg-warning-500/[0.06] rounded-md px-3 py-2">
        {{ t('accounting.finding_remedy.gone') }}
      </p>

      <template v-else-if="remedy">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
          <dt class="text-neutral-500">{{ t('accounting.finding_remedy.document') }}</dt>
          <dd class="font-mono">{{ remedy.doc_no ?? '—' }}</dd>
          <dt class="text-neutral-500">{{ t('accounting.finding_remedy.partner') }}</dt>
          <dd>{{ remedy.partner_name ?? '—' }}</dd>
          <dt class="text-neutral-500">{{ t('accounting.finding_remedy.difference') }}</dt>
          <dd class="font-mono">{{ formatMoney(remedy.impact_czk, 'CZK') }}</dd>
        </dl>

        <p v-if="remedy.proposal" class="text-sm text-neutral-600 bg-neutral-50 dark:bg-neutral-500/[0.06] rounded-md px-3 py-2">
          {{ remedy.proposal.description }}
        </p>
        <div v-else class="text-sm rounded-md border border-warning-200 bg-warning-50 dark:bg-warning-500/[0.06] px-3 py-2">
          <p class="font-medium text-warning-800 dark:text-warning-200">
            {{ t('accounting.finding_remedy.no_proposal_title') }}
          </p>
          <p class="text-warning-700 dark:text-warning-300 mt-0.5">
            {{ t('accounting.finding_remedy.no_proposal') }}
          </p>
        </div>

        <p v-if="reversalOnly" class="text-sm text-warning-700">
          {{ t('accounting.finding_remedy.reversal_only') }}
        </p>

        <template v-else>
          <div class="grid grid-cols-2 gap-3">
            <label class="text-sm">
              <span class="block text-neutral-500 mb-1">{{ t('accounting.finding_remedy.entry_date') }}</span>
              <DateInput v-model="entryDate"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
            </label>
            <label class="text-sm">
              <span class="block text-neutral-500 mb-1">{{ t('accounting.finding_remedy.description') }}</span>
              <input v-model="description" type="text"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
            </label>
          </div>

          <JournalLinesEditor ref="editorRef" v-model="lines" :accounts="accounts" list-id="remedy-coa" />
        </template>
      </template>

      <div class="flex justify-end gap-2 pt-2 border-t border-neutral-200">
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          {{ t('common.close') }}
        </button>
        <button v-if="!gone && !reversalOnly" type="button" :class="btnFilled('primary')"
          :disabled="!canSubmit" @click="submit">
          {{ t('accounting.finding_remedy.confirm') }}
        </button>
      </div>
    </div>
  </Modal>
</template>
