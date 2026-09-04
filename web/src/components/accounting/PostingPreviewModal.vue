<script setup lang="ts">
/**
 * Náhled kontace před zaúčtováním dokladu.
 *
 * Do zavedení tohohle popupu se doklad zaúčtoval rovnou po `confirm()` a uživatel návrh
 * nikdy neviděl. U dokladu v cizí měně nebo v přenesené daňové povinnosti má zápis víc
 * než dva řádky a slepé odklepnutí je přesně ten úkon, po kterém se chyba hledá zpětně
 * v deníku.
 *
 * Návrh přichází ze serveru z TÝCHŽ builderů, které zápis opravdu vyrobí — vlastní
 * dopočet na klientovi by se s nimi rozešel a byl by horší než žádný náhled.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi, postingErrorI18nKey,
  type PostingPreview, type ChartAccount, type JournalTemplateSummary,
} from '@/api/accounting'
import Modal from '../ui/Modal.vue'
import JournalLinesEditor, { type EditorLine } from './JournalLinesEditor.vue'
import { btnOutline, btnFilled } from '../ui/buttonStyles'
import { formatMoney } from '@/composables/useFormat'
import { expenseRulesApi, type ExpenseKind } from '@/api/expenseRules'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  open: boolean
  source: 'invoices' | 'purchase-invoices'
  docId: number
  /** Popisek dokladu do hlavičky (číslo faktury). */
  docLabel?: string | null
}>()

const emit = defineEmits<{
  close: []
  posted: []
}>()

const { t } = useI18n()
const toast = useToast()

const preview = ref<PostingPreview | null>(null)
const loading = ref(false)
const posting = ref(false)
const error = ref('')

const totals = computed(() => {
  const rows = preview.value?.lines ?? []
  const debit = rows.filter(l => l.side === 'debit').reduce((s, l) => s + l.amount, 0)
  const credit = rows.filter(l => l.side === 'credit').reduce((s, l) => s + l.amount, 0)
  return { debit, credit }
})

// ── Editace návrhu ─────────────────────────────────────────────────────────
// Zápis se posílá s vlastními řádky JEN když účetní návrh opravdu upravila. Neupravený
// návrh jde původní cestou, kdy si kontaci staví server — ta je ověřená a nemá smysl ji
// obcházet jen proto, že popup umí editovat.
const editing = ref(false)
const lines = ref<EditorLine[]>([])
const accounts = ref<ChartAccount[]>([])
const description = ref('')
const editorRef = ref<InstanceType<typeof JournalLinesEditor> | null>(null)

const isPurchase = computed(() => props.source === 'purchase-invoices')

async function startEditing(): Promise<void> {
  if (!preview.value) return
  if (accounts.value.length === 0) {
    accounts.value = await accountingApi.listAccounts()
  }
  lines.value = preview.value.lines.map(l => ({
    account_code: l.account_code,
    side: l.side as 'debit' | 'credit',
    amount: l.amount,
  }))
  editing.value = true
}

function cancelEditing(): void {
  editing.value = false
  lines.value = []
}

// ── Šablony ručních zápisů ─────────────────────────────────────────────────
const templates = ref<JournalTemplateSummary[]>([])
const templatesOpen = ref(false)
const templateQuery = ref('')

/** Filtr přes název i popis — účetní si šablonu pamatuje spíš podle obsahu než jména. */
const filteredTemplates = computed(() => {
  const q = templateQuery.value.trim().toLowerCase()
  if (!q) return templates.value
  return templates.value.filter(t =>
    t.name.toLowerCase().includes(q) || (t.description ?? '').toLowerCase().includes(q))
})

async function openTemplates(): Promise<void> {
  if (templates.value.length === 0) {
    templates.value = await accountingApi.listJournalTemplates()
  }
  templateQuery.value = ''
  templatesOpen.value = true
}

// ── „Příště účtovat stejně" ────────────────────────────────────────────────
// Pravidlo klasifikace výdaje z opravené kontace. Nabízet místo toho VÝBĚR z existujících
// pravidel by nedávalo smysl: návrh v popupu je právě jejich výstupem, takže „použij
// pravidlo" by dělalo totéž co vypsat účet, jen přes další kliknutí. Chybí opačný směr —
// aby oprava, kterou účetní udělá teď, platila i příště.
const createRule = ref(false)

/**
 * Nákladový účet z kontace: jediný řádek MD, který není DPH. Když jich je víc (rozúčtování
 * na střediska), pravidlo se nenabízí — neslo by jen jeden účet a který, to by byl dohad.
 */
const costAccount = computed<string | null>(() => {
  const debits = lines.value.filter(l => l.side === 'debit' && !l.account_code.startsWith('343'))
  return debits.length === 1 && debits[0].account_code ? debits[0].account_code : null
})

const proposedCostAccount = computed<string | null>(() => {
  const debits = (preview.value?.lines ?? []).filter(l => l.side === 'debit' && !l.account_code.startsWith('343'))
  return debits.length === 1 ? debits[0].account_code : null
})

/** Nabídka dává smysl jen když účetní účet OPRAVDU změnila — jinak pravidlo nic nemění. */
const canCreateRule = computed(() =>
  editing.value
  && !!preview.value?.rule_basis
  && !!costAccount.value
  && costAccount.value !== proposedCostAccount.value)

/**
 * Šablona přinese ÚČTY a strany; částky se dopočítat nedají, takže se přebírají
 * z návrhu podle strany. Šablona s jiným počtem řádků než návrh částky nedostane —
 * rozpočítat celkovou částku mezi neznámé řádky by byl dohad.
 */
async function applyTemplate(id: number): Promise<void> {
  const detail = await accountingApi.getJournalTemplate(id)
  const sameShape = detail.lines.length === lines.value.length
  lines.value = detail.lines.map((l, i) => ({
    account_code: l.account_code,
    side: l.side as 'debit' | 'credit',
    amount: l.default_amount ?? (sameShape ? lines.value[i]?.amount ?? null : null),
  }))
  templatesOpen.value = false
}

// ── AI dotaz na kontaci ────────────────────────────────────────────────────
const aiOpen = ref(false)
const aiQuery = ref('')
const aiLoading = ref(false)
const aiError = ref('')
const aiSuggestion = ref<{ debit_account_code: string; reasoning: string } | null>(null)

async function askAi(): Promise<void> {
  const q = aiQuery.value.trim()
  if (q.length < 3 || aiLoading.value) return
  aiLoading.value = true
  aiError.value = ''
  aiSuggestion.value = null
  try {
    aiSuggestion.value = await accountingApi.purchaseAiSuggest(props.docId, q)
  } catch (e: any) {
    aiError.value = t('accounting.posting_preview.ai_unavailable')
  } finally {
    aiLoading.value = false
  }
}

/**
 * AI navrhuje jen nákladový účet, takže se přepíše PRVNÍ řádek na straně MD. Ostatní
 * řádky (DPH, závazek) plynou z dokladu a model do nich co mluvit nemá.
 */
function applyAiSuggestion(): void {
  const code = aiSuggestion.value?.debit_account_code
  if (!code) return
  const target = lines.value.find(l => l.side === 'debit')
  if (target) target.account_code = code
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  preview.value = null
  editing.value = false
  lines.value = []
  aiSuggestion.value = null
  aiQuery.value = ''
  try {
    preview.value = await accountingApi.postingPreview(props.source, props.docId)
    description.value = ''
  } catch (e: any) {
    error.value = t(postingErrorI18nKey(e?.response?.data?.error?.code))
  } finally {
    loading.value = false
  }
}

watch(() => [props.open, props.docId], ([open]) => {
  if (open) load()
}, { immediate: true })

const canPost = computed(() => {
  if (!preview.value || loading.value || posting.value || preview.value.already_posted) return false
  return editing.value ? (editorRef.value?.valid ?? false) : preview.value.balanced
})

async function confirmPost(): Promise<void> {
  if (!canPost.value) return
  posting.value = true
  error.value = ''
  try {
    const body = editing.value
      ? {
          description: description.value || undefined,
          lines: lines.value.map(l => ({
            account_code: l.account_code,
            side: l.side,
            amount: l.amount ?? 0,
          })),
        }
      : undefined
    if (isPurchase.value) {
      await accountingApi.postPurchase(props.docId, body)
    } else {
      await accountingApi.postInvoice(props.docId, body)
    }
    // Pravidlo se zakládá AŽ po úspěšném zaúčtování a jeho selhání zaúčtování neruší —
    // doklad je zaúčtovaný správně i bez pravidla, kdežto opačné pořadí by při chybě
    // zápisu nechalo v systému pravidlo odvozené z kontace, která nikdy nevznikla.
    if (createRule.value && canCreateRule.value) {
      const basis = preview.value!.rule_basis!
      try {
        await expenseRulesApi.createRule({
          name: `${basis.vendor_name} → ${costAccount.value}`.slice(0, 120),
          vendor_client_id: basis.vendor_client_id,
          vendor_name_contains: null,
          description_contains: null,
          amount_min: null,
          amount_max: null,
          expense_kind: basis.expense_kind as ExpenseKind,
          target_account_code: costAccount.value,
          application_mode: 'auto',
          priority: 100,
          is_active: true,
        })
        toast.success(t('accounting.posting_preview.rule_created'))
      } catch {
        toast.error(t('accounting.posting_preview.rule_failed'))
      }
    }
    emit('posted')
    emit('close')
  } catch (e: any) {
    error.value = t(postingErrorI18nKey(e?.response?.data?.error?.code))
  } finally {
    posting.value = false
  }
}
</script>

<template>
  <Modal v-if="open" :title="t('accounting.posting_preview.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4">
      <p v-if="docLabel" class="text-sm text-neutral-600">
        {{ t('accounting.posting_preview.document') }}: <span class="font-mono">{{ docLabel }}</span>
      </p>

      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

      <div v-if="error" class="px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
        {{ error }}
      </div>

      <template v-if="preview && !loading">
        <!-- Už zaúčtovaný doklad se neúčtuje podruhé; server je sice idempotentní,
             ale uživatel to má vědět dřív, než klikne. -->
        <div v-if="preview.already_posted"
          class="px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-sm">
          {{ t('accounting.posting_preview.already_posted', { id: preview.already_posted }) }}
        </div>

        <div class="text-sm text-neutral-600">
          {{ t('accounting.posting_preview.entry_date') }}: <span class="font-mono">{{ preview.entry_date }}</span>
        </div>

        <!-- Editace návrhu: MD/D, rozúčtování na víc řádků, šablony, dotaz na AI. -->
        <template v-if="editing">
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" :class="btnOutline('neutral')" @click="openTemplates">
              {{ t('accounting.posting_preview.use_template') }}
            </button>
            <button v-if="isPurchase" type="button" :class="btnOutline('neutral')" @click="aiOpen = !aiOpen">
              {{ t('accounting.posting_preview.ask_ai') }}
            </button>
            <button type="button" class="text-xs text-neutral-500 hover:underline ml-auto" @click="cancelEditing">
              {{ t('accounting.posting_preview.back_to_proposal') }}
            </button>
          </div>

          <div v-if="templatesOpen" class="border border-neutral-200 rounded-md p-2 space-y-1">
            <!-- Filtr až od desítky šablon: u kratšího seznamu je rychlejší přečíst ho. -->
            <input v-if="templates.length > 10" v-model="templateQuery" type="text"
              :placeholder="t('accounting.posting_preview.template_search')"
              class="w-full h-9 px-2 mb-1 border border-neutral-300 rounded-md text-sm" />
            <div class="max-h-48 overflow-y-auto space-y-1">
              <p v-if="templates.length === 0" class="text-sm text-neutral-500">
                {{ t('accounting.posting_preview.no_templates') }}
              </p>
              <p v-else-if="filteredTemplates.length === 0" class="text-sm text-neutral-500">
                {{ t('accounting.posting_preview.no_template_match') }}
              </p>
              <button v-for="tpl in filteredTemplates" :key="tpl.id" type="button"
                class="block w-full text-left text-sm px-2 py-1 rounded hover:bg-neutral-100"
                @click="applyTemplate(tpl.id)">
                {{ tpl.name }}
                <span v-if="tpl.description" class="text-neutral-500"> — {{ tpl.description }}</span>
              </button>
            </div>
          </div>

          <div v-if="aiOpen && isPurchase" class="border border-neutral-200 rounded-md p-2 space-y-2">
            <p class="text-xs text-neutral-500">{{ t('accounting.posting_preview.ai_hint') }}</p>
            <div class="flex gap-2">
              <input v-model="aiQuery" type="text" :placeholder="t('accounting.posting_preview.ai_placeholder')"
                class="flex-1 h-10 px-2 border border-neutral-300 rounded-md text-sm"
                @keyup.enter="askAi" />
              <button type="button" :class="btnOutline('neutral')" :disabled="aiLoading || aiQuery.trim().length < 3"
                @click="askAi">
                {{ aiLoading ? t('common.loading') : t('accounting.posting_preview.ai_ask') }}
              </button>
            </div>
            <p v-if="aiError" class="text-sm text-danger-600">{{ aiError }}</p>
            <div v-if="aiSuggestion" class="text-sm flex items-center gap-2">
              <span class="font-mono">{{ aiSuggestion.debit_account_code }}</span>
              <span v-if="aiSuggestion.reasoning" class="text-neutral-500 truncate">{{ aiSuggestion.reasoning }}</span>
              <button type="button" class="text-primary-600 hover:underline text-xs ml-auto shrink-0"
                @click="applyAiSuggestion">
                {{ t('accounting.posting_preview.ai_apply') }}
              </button>
            </div>
          </div>

          <label class="block text-sm">
            <span class="block text-neutral-500 mb-1">{{ t('accounting.posting_preview.description') }}</span>
            <input v-model="description" type="text"
              class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
          </label>

          <JournalLinesEditor ref="editorRef" v-model="lines" :accounts="accounts" list-id="posting-coa" />

          <label v-if="canCreateRule" class="flex items-start gap-2 text-sm">
            <input v-model="createRule" type="checkbox" class="mt-0.5" />
            <span>
              {{ t('accounting.posting_preview.create_rule', {
                vendor: preview.rule_basis!.vendor_name,
                account: costAccount,
              }) }}
              <span class="block text-xs text-neutral-500">
                {{ t('accounting.posting_preview.create_rule_hint') }}
              </span>
            </span>
          </label>
        </template>

        <div v-else class="overflow-x-auto border border-neutral-200 rounded-md">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.posting_preview.account') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.posting_preview.debit') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.posting_preview.credit') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(l, i) in preview.lines" :key="i">
                <td class="px-3 py-2">
                  <span class="font-mono">{{ l.account_code }}</span>
                  <span v-if="l.account_name" class="text-neutral-500"> — {{ l.account_name }}</span>
                  <span v-if="l.cost_center" class="ml-2 text-xs text-neutral-400">{{ l.cost_center }}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">
                  {{ l.side === 'debit' ? formatMoney(l.amount, 'CZK') : '' }}
                </td>
                <td class="px-3 py-2 text-right font-mono">
                  {{ l.side === 'credit' ? formatMoney(l.amount, 'CZK') : '' }}
                </td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-neutral-300 font-semibold">
              <tr>
                <td class="px-3 py-2">{{ t('accounting.posting_preview.total') }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatMoney(totals.debit, 'CZK') }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatMoney(totals.credit, 'CZK') }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <p v-if="!editing && !preview.balanced"
          class="px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
          {{ t('accounting.posting_preview.unbalanced') }}
        </p>

        <p v-if="!editing && preview.ai_override" class="text-xs text-neutral-500">
          {{ t('accounting.posting_preview.ai_source', { account: preview.ai_override }) }}
        </p>
        <p class="text-xs text-neutral-500">
          {{ editing ? t('accounting.posting_preview.edited_hint') : t('accounting.posting_preview.hint') }}
        </p>
      </template>

      <div class="flex items-center justify-end gap-2 pt-2 border-t border-neutral-200">
        <button v-if="preview && !editing && !preview.already_posted" type="button" :class="btnOutline('neutral')"
          @click="startEditing">
          {{ t('accounting.posting_preview.edit') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled('primary')" :disabled="!canPost" @click="confirmPost">
          {{ t('accounting.posting_preview.confirm') }}
        </button>
      </div>
    </div>
  </Modal>
</template>
