<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { accountingApi, type ChartAccount, type CostCenter, type LinkCandidate, type LinkableDocType } from '@/api/accounting'
import type { Client } from '@/api/clients'
import {
  internalDocumentsApi, INTERNAL_DOCUMENT_KINDS,
  type InternalDocumentKind, type InternalDocumentLink, type InternalDocumentPayload,
} from '@/api/internalDocuments'
import { compactDimensions } from '@/api/dimensions'
import { INTERNAL_DOCUMENT_TEMPLATES, type InternalDocumentTemplate } from '@/config/internalDocumentTemplates'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { useDimensions } from '@/composables/useDimensions'
import { formatMoney } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import {
  autoBalanceAmount, autoBalanceIndex, balancingLine, emptyLine, isBalanced, lineTotals, resolvedLines,
  type DraftLine,
} from '@/utils/internalDocumentLines'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import DocumentLinkPicker from '@/components/accounting/DocumentLinkPicker.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const dims = useDimensions()
const { blockDemoMutation } = useDemoMode()
const pageId = useId()

const editId = computed(() => Number(route.params.id || 0))
const isEdit = computed(() => editId.value > 0)
const canPost = computed(() => auth.canWrite('accounting.journal.post'))
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const accounts = ref<ChartAccount[]>([])
const costCenters = ref<CostCenter[]>([])
const activeTemplate = ref<string | null>(null)

const today = appIsoDate()
const form = reactive({
  kind: 'other' as InternalDocumentKind,
  title: '',
  document_date: today,
  entry_date: today,
  partner_id: null as number | null,
  partner_name: '',
  note: '',
})
/** Datum účetního případu jde s datem dokladu, dokud ho účetní nezmění ručně. */
const entryDateTouched = ref(false)
const lines = ref<DraftLine[]>([emptyLine('debit'), emptyLine('credit')])

interface PickedLink extends InternalDocumentLink { label: string }
const links = ref<PickedLink[]>([])
const pickedKeys = computed(() => links.value.map(l => `${l.doc_type}:${l.doc_id}`))
const linkTypes = computed<LinkableDocType[] | undefined>(() =>
  INTERNAL_DOCUMENT_TEMPLATES.find(tpl => tpl.key === activeTemplate.value)?.linkTypes)

const accountChoices = computed(() => accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const autoIndex = computed(() => autoBalanceIndex(lines.value))
const autoAmount = computed(() => autoBalanceAmount(lines.value))
const totals = computed(() => lineTotals(lines.value))
const balanced = computed(() => isBalanced(lines.value))
const canBalance = computed(() => balancingLine(lines.value) !== null)

watch(() => form.document_date, value => { if (!entryDateTouched.value) form.entry_date = value })

function lineInputId(index: number): string {
  return `${pageId}-line-${index}-account`
}

async function focusLine(index: number) {
  await nextTick()
  document.getElementById(lineInputId(index))?.focus()
}

function addLine(focus = true) {
  const last = lines.value[lines.value.length - 1]
  lines.value.push(emptyLine(last?.side === 'debit' ? 'credit' : 'debit'))
  if (focus) void focusLine(lines.value.length - 1)
}

function removeLine(index: number) {
  if (lines.value.length > 1) lines.value.splice(index, 1)
}

function balance() {
  const line = balancingLine(lines.value)
  if (!line) return
  lines.value.push(line)
  void focusLine(lines.value.length - 1)
}

/** Enter v částce posledního řádku přidá další řádek (zadání bez myši). */
function onAmountEnter(index: number, event: KeyboardEvent) {
  if (event.ctrlKey || event.metaKey || index !== lines.value.length - 1) return
  event.preventDefault()
  addLine()
}

function applyTemplate(tpl: InternalDocumentTemplate) {
  activeTemplate.value = tpl.key
  form.kind = tpl.kind
  form.title = t(`internal_documents.templates.${tpl.key}.title`)
  lines.value = tpl.lines.map(line => emptyLine(line.side, line.account_code))
  void focusLine(tpl.lines.findIndex(line => !line.account_code) >= 0 ? tpl.lines.findIndex(line => !line.account_code) : 0)
}

function onPartnerSelected(client: Client | null) {
  form.partner_name = client?.company_name || ''
}

function onPartnerNameInput() {
  form.partner_id = null
}

function addLink(candidate: LinkCandidate) {
  if (pickedKeys.value.includes(`${candidate.doc_type}:${candidate.doc_id}`)) return
  links.value.push({
    doc_type: candidate.doc_type,
    doc_id: candidate.doc_id,
    note: null,
    label: [candidate.label, candidate.sublabel, candidate.amount !== null ? formatMoney(candidate.amount, candidate.currency) : null]
      .filter(Boolean).join(' · '),
  })
  if (form.partner_id === null && !form.partner_name && candidate.sublabel) form.partner_name = candidate.sublabel
}

function removeLink(index: number) {
  links.value.splice(index, 1)
}

function docTypeLabel(type: string): string {
  const key = `accounting.journal.source.${type}`
  const value = t(key)
  return value === key ? type : value
}

function payload(): InternalDocumentPayload {
  return {
    kind: form.kind,
    title: form.title.trim(),
    document_date: form.document_date,
    entry_date: form.entry_date || form.document_date,
    partner_id: form.partner_id,
    partner_name: form.partner_name.trim() || null,
    note: form.note.trim() || null,
    lines: resolvedLines(lines.value)
      .filter(line => line.account_code.trim() || Number(line.amount) > 0)
      .map(line => {
        const lineDims = compactDimensions(line.dimensions)
        return {
          account_code: line.account_code.trim(),
          side: line.side,
          amount: Number(line.amount),
          is_red_storno: !!line.is_red_storno,
          cost_center: line.cost_center.trim() || null,
          ...(dims.enabled.value && Object.keys(lineDims).length ? { dimensions: lineDims } : {}),
        }
      }),
    document_links: links.value.map(link => ({ doc_type: link.doc_type, doc_id: link.doc_id, ...(link.note?.trim() ? { note: link.note.trim() } : {}) })),
  }
}

function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

/**
 * @param post Po uložení rovnou zaúčtovat (ruční zápis deníku). Nevyrovnaný
 *   doklad jde uložit jen jako koncept.
 */
async function save(post = false) {
  if (saving.value || blockDemoMutation()) return
  error.value = ''
  if (!form.title.trim() || !form.document_date) { error.value = t('internal_documents.validation_required'); return }
  const body = payload()
  if (body.lines.some(line => !line.account_code || !(line.amount > 0))) { error.value = t('internal_documents.lines_incomplete'); return }
  if (post && !balanced.value) { error.value = t('internal_documents.not_balanced'); return }
  saving.value = true
  let id = editId.value
  try {
    const saved = isEdit.value ? await internalDocumentsApi.update(id, body) : await internalDocumentsApi.create(body)
    id = saved.id
    if (post) {
      const posted = await internalDocumentsApi.post(id)
      toast.success(t('internal_documents.posted_toast', { number: posted.document_no || '' }))
    } else {
      toast.success(t('internal_documents.saved_draft'))
    }
    await router.push({ name: 'accounting-internal-document-detail', params: { id } })
  } catch (e: any) {
    error.value = errorMessage(e)
    // Koncept už uložený, jen se nezaúčtoval: další pokus musí jít jako úprava.
    if (post && id > 0 && !isEdit.value) await router.replace({ name: 'accounting-internal-document-edit', params: { id } })
  } finally {
    saving.value = false
  }
}

function onKeydown(event: KeyboardEvent) {
  const mod = event.ctrlKey || event.metaKey
  if (mod && event.key === 'Enter') {
    event.preventDefault()
    void save(canPost.value)
  } else if (mod && (event.key === 's' || event.key === 'S')) {
    event.preventDefault()
    void save(false)
  } else if (event.altKey && (event.key === 'n' || event.key === 'N' || event.key === 'Insert')) {
    event.preventDefault()
    addLine()
  }
}

async function load() {
  loading.value = true
  try {
    const requests: Promise<unknown>[] = [
      accountingApi.listAccounts().then(value => { accounts.value = value }).catch(() => { accounts.value = [] }),
      accountingApi.listCostCenters().then(value => { costCenters.value = value }).catch(() => { costCenters.value = [] }),
    ]
    if (isEdit.value) {
      requests.push(internalDocumentsApi.get(editId.value).then(doc => {
        if (doc.status !== 'draft') {
          void router.replace({ name: 'accounting-internal-document-detail', params: { id: editId.value } })
          return
        }
        form.kind = doc.kind
        form.title = doc.title
        form.document_date = doc.document_date
        form.entry_date = doc.entry_date
        entryDateTouched.value = doc.entry_date !== doc.document_date
        form.partner_id = doc.partner_id
        form.partner_name = doc.partner_name || ''
        form.note = doc.note || ''
        lines.value = (doc.lines ?? []).length
          ? (doc.lines ?? []).map(line => ({
            account_code: line.account_code,
            side: line.side,
            amount: Number(line.amount),
            is_red_storno: !!line.is_red_storno,
            cost_center: line.cost_center || '',
            dimensions: { ...(line.dimensions ?? {}) },
          }))
          : [emptyLine('debit'), emptyLine('credit')]
        links.value = doc.document_links.map(link => ({ ...link, label: `${docTypeLabel(link.doc_type)} ${link.doc_title || `#${link.doc_id}`}` }))
      }))
    } else {
      const template = INTERNAL_DOCUMENT_TEMPLATES.find(tpl => tpl.key === route.query.template)
      if (template) applyTemplate(template)
    }
    await Promise.all(requests)
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div @keydown="onKeydown">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <RouterLink :to="isEdit ? { name: 'accounting-internal-document-detail', params: { id: editId } } : { name: 'accounting-internal-documents' }"
          class="text-sm text-primary-700 hover:underline">← {{ t('internal_documents.back') }}</RouterLink>
        <h1 class="mt-1 text-2xl font-semibold">{{ t(isEdit ? 'internal_documents.edit' : 'internal_documents.quick_entry') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('internal_documents.form_hint') }}</p>
      </div>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <form v-else class="max-w-5xl space-y-4" @submit.prevent="save(false)">
      <section v-if="!isEdit" class="rounded-lg border border-neutral-200 bg-surface p-4" data-test="templates">
        <h2 class="mb-1 font-semibold">{{ t('internal_documents.templates_title') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('internal_documents.templates_hint') }}</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="tpl in INTERNAL_DOCUMENT_TEMPLATES" :key="tpl.key" type="button" :data-test="`template-${tpl.key}`"
            :aria-pressed="activeTemplate === tpl.key" :title="t(`internal_documents.templates.${tpl.key}.hint`)"
            :class="[activeTemplate === tpl.key ? btnFilled('primary') : btnOutline('neutral'), 'whitespace-nowrap']" @click="applyTemplate(tpl)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ t(`internal_documents.templates.${tpl.key}.title`) }}
          </button>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-4 font-semibold">{{ t('internal_documents.details') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="text-sm font-medium lg:col-span-3">{{ t('internal_documents.item') }}
            <input v-model="form.title" required maxlength="255" data-test="title" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('internal_documents.kind_label') }}
            <select v-model="form.kind" data-test="kind" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option v-for="k in INTERNAL_DOCUMENT_KINDS" :key="k" :value="k">{{ t(`internal_documents.kind.${k}`) }}</option>
            </select>
          </label>
          <label class="text-sm font-medium">{{ t('internal_documents.document_date') }}
            <DateInput v-model="form.document_date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('internal_documents.entry_date') }}
            <DateInput v-model="form.entry_date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" @update:model-value="entryDateTouched = true" />
          </label>
          <div class="text-sm font-medium sm:col-span-2">
            <span>{{ t('internal_documents.partner_directory') }}</span>
            <ClientSearchSelect v-model="form.partner_id" :selected-label="form.partner_name" class="mt-1 block" @selected="onPartnerSelected" />
          </div>
          <label class="text-sm font-medium sm:col-span-2 lg:col-span-4">{{ t('internal_documents.partner') }}
            <input v-model="form.partner_name" maxlength="190" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" @input="onPartnerNameInput" />
          </label>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4" data-test="lines">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
          <div>
            <h2 class="font-semibold">{{ t('internal_documents.lines') }}</h2>
            <p class="mt-0.5 text-sm text-neutral-500">{{ t('internal_documents.lines_hint') }}</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="!canBalance" data-test="balance" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="balance">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
              {{ t('internal_documents.balance') }}
            </button>
            <button type="button" data-test="add-line" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="addLine()">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('internal_documents.add_line') }}
            </button>
          </div>
        </div>
        <datalist :id="`${pageId}-cost-centers`">
          <option v-for="center in costCenters" :key="center.id" :value="center.code">{{ center.code }} - {{ center.name }}</option>
        </datalist>
        <div class="space-y-2">
          <div v-for="(line, index) in lines" :key="index" class="grid grid-cols-12 items-start gap-2" data-test="line">
            <div class="col-span-12 sm:col-span-5">
              <ChartAccountSelect v-model="line.account_code" :accounts="accountChoices" :input-id="lineInputId(index)" compact
                :aria-label="t('internal_documents.line_account', { n: index + 1 })" :placeholder="t('accounting.manual.account_placeholder')" />
            </div>
            <div class="col-span-4 sm:col-span-2">
              <select v-model="line.side" :aria-label="t('internal_documents.line_side', { n: index + 1 })"
                class="h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-xs">
                <option value="debit">{{ t('accounting.journal.side.debit') }}</option>
                <option value="credit">{{ t('accounting.journal.side.credit') }}</option>
              </select>
            </div>
            <div class="col-span-5 sm:col-span-2">
              <input v-model.number="line.amount" type="number" step="0.01" min="0" data-test="line-amount"
                :aria-label="t('internal_documents.line_amount', { n: index + 1 })"
                :placeholder="autoIndex === index && autoAmount !== null ? String(autoAmount) : t('accounting.manual.amount')"
                class="h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-right text-xs tabular-nums"
                :class="autoIndex === index && autoAmount !== null ? 'placeholder:italic placeholder:text-primary-600' : ''"
                @keydown.enter="onAmountEnter(index, $event)" />
              <p v-if="autoIndex === index && autoAmount !== null" class="mt-0.5 text-xs text-primary-700">{{ t('internal_documents.auto_balance') }}</p>
            </div>
            <div class="col-span-2 sm:col-span-2">
              <input v-model="line.cost_center" :list="`${pageId}-cost-centers`" type="text" maxlength="50"
                :aria-label="t('internal_documents.line_cost_center', { n: index + 1 })" :placeholder="t('accounting.manual.cost_center')"
                class="h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-xs" />
            </div>
            <div class="col-span-1 flex h-8 items-center justify-center">
              <button type="button" :disabled="lines.length <= 1" :aria-label="t('internal_documents.remove_line', { n: index + 1 })"
                class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-30" @click="removeLine(index)">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
            <DimensionFields v-if="dims.enabled.value" v-model="line.dimensions" compact class="col-span-12 sm:col-span-11" />
          </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3 text-sm">
          <div class="flex flex-wrap items-center gap-4">
            <span class="text-neutral-500">{{ t('accounting.journal.side.debit') }}: <strong class="font-mono text-neutral-800" data-test="total-debit">{{ formatMoney(totals.debit) }}</strong></span>
            <span class="text-neutral-500">{{ t('accounting.journal.side.credit') }}: <strong class="font-mono text-neutral-800" data-test="total-credit">{{ formatMoney(totals.credit) }}</strong></span>
            <span class="rounded px-2 py-0.5 text-xs font-medium" data-test="balance-badge" role="status"
              :class="balanced ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
              {{ balanced ? t('accounting.manual.balanced') : t('accounting.manual.unbalanced', { diff: formatMoney(totals.diff) }) }}
            </span>
          </div>
          <p class="text-xs text-neutral-500">{{ t('internal_documents.shortcuts') }}</p>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-1 font-semibold">{{ t('internal_documents.links_title') }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t(activeTemplate === 'offset' ? 'internal_documents.links_hint_offset' : 'internal_documents.links_hint') }}</p>
        <ul v-if="links.length" class="mb-2 divide-y divide-neutral-200 rounded-lg border border-neutral-200">
          <li v-for="(link, index) in links" :key="`${link.doc_type}:${link.doc_id}`" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-3 py-2 text-sm">
            <div class="min-w-0 flex-1">
              <span class="text-xs text-neutral-500">{{ docTypeLabel(link.doc_type) }}</span>
              <span class="ml-2 font-medium">{{ link.label }}</span>
              <input v-model="link.note" type="text" maxlength="255" :placeholder="t('accounting.journal.links.note_placeholder')"
                class="mt-1.5 h-8 w-full rounded-md border border-neutral-300 px-2 text-xs" />
            </div>
            <button type="button" :class="btnOutlineSm('danger')" @click="removeLink(index)">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('accounting.journal.links.remove') }}
            </button>
          </li>
        </ul>
        <DocumentLinkPicker :excluded="pickedKeys" :types="linkTypes" @select="addLink" />
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <label class="text-sm font-medium">{{ t('internal_documents.note') }}
          <textarea v-model="form.note" rows="3" maxlength="10000" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
        <p v-if="!isEdit" class="mt-2 text-xs text-neutral-500">{{ t('internal_documents.attachments_after_save') }}</p>
      </section>

      <p v-if="error" class="text-sm text-danger-600" role="alert" data-test="error">{{ error }}</p>

      <div class="flex flex-wrap gap-2">
        <button v-if="canPost" type="button" :disabled="saving || !balanced" data-test="save-post" :class="btnFilled('success')" @click="save(true)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('internal_documents.save_and_post') }}
        </button>
        <button type="submit" :disabled="saving" data-test="save-draft" :class="btnOutline('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('internal_documents.save_draft') }}
        </button>
        <RouterLink :to="isEdit ? { name: 'accounting-internal-document-detail', params: { id: editId } } : { name: 'accounting-internal-documents' }" :class="btnOutline('neutral')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </RouterLink>
      </div>
    </form>
  </div>
</template>
