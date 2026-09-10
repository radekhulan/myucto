<script setup lang="ts">
import { computed, ref } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Modal from '../ui/Modal.vue'
import { btnOutline } from '../ui/buttonStyles'
import { closingApi } from '../../api/closing'
import FindingRemedyModal from './FindingRemedyModal.vue'

/**
 * Nálezy JEDNÉ kontroly (uzávěrka i měsíční kontrola).
 *
 * Nahrazuje dosavadní renderer, který byl WHITELIST podle klíče kontroly — takže každá
 * nová kontrola v `buildChecks()` tiše spadla do `JSON.stringify` (16 z 33 na měsíční
 * kontrole, 29 z 33 na uzávěrkové). Tahle komponenta renderuje podle TVARU dat, ne podle
 * klíče, takže nová kontrola se zobrazí správně, aniž by o ní kdokoli věděl.
 *
 * Seznam se inline NEVYKRESLUJE — u velké firmy jich jsou stovky. V řádku je jen počet,
 * detail je v popupu a plný seznam v CSV.
 */

export interface Finding {
  doc_type?: string | null
  doc_id?: number | null
  doc_no?: string | null
  /** Datum dokladu (u bankovních nálezů datum transakce) — bez něj se nález nedá zařadit v čase. */
  doc_date?: string | null
  partner_name?: string | null
  amount?: number | null
  currency?: string | null
  entry_id?: number | null
  /** Měna dokladu — údaje v `detail` jsou v ní, kdežto `amount` je dopad v korunách. */
  doc_currency?: string | null
  account_id?: number | null
  account_code?: string | null
  name?: string | null
  note?: string | null
  /** Kódy nálezů (např. amount_mismatch) — překládají se, nezobrazují syrové. */
  issues?: string[]
  /** Podrobnosti ke kódům: o kolik nesedí částka, jaká jména se rozcházejí. */
  detail?: Record<string, Record<string, unknown>> | null
}

export interface CheckValue {
  count?: number
  findings?: Finding[]
  truncated?: boolean
  kind?: 'document' | 'account' | 'scalar'
  [k: string]: unknown
}

const props = defineProps<{
  checkKey: string
  label: string
  value: unknown
  /** URL pro CSV export; když chybí, tlačítko se nezobrazí. */
  exportUrl?: string
  /** Odkaz na filtrovaný seznam (např. „nezaúčtované faktury"), pokud pro kontrolu dává smysl. */
  listLink?: RouteLocationRaw | null
  /** Období pro živé donačtení detailu. Bez něj se popup plní z předané hodnoty. */
  periodId?: number | null
  /** Rozsah měsíční kontroly — aby živý detail počítal totéž, co řádek v tabulce. */
  dateFrom?: string
  dateTo?: string
}>()

const { t } = useI18n()
const open = ref(false)

/**
 * Živě donačtený detail. Předaná `value` pochází buď z uloženého snímku kroku (useknutý
 * na deset položek), nebo z odpovědi měsíční kontroly (useknuté na padesát). V obou
 * případech se stávalo, že kontrola hlásila 21 nálezů a popup ukázal 10 — počet i řádky
 * musí ale pocházet z jednoho běhu, jinak uživatel nemá jak zjistit, co mu chybí.
 */
const liveValue = ref<CheckValue | null>(null)
const loading = ref(false)
const loadFailed = ref(false)

async function openDetail() {
  open.value = true
  if (!props.periodId || liveValue.value !== null) return

  loading.value = true
  loadFailed.value = false
  try {
    const r = await closingApi.checkFindings(props.periodId, props.checkKey, props.dateFrom, props.dateTo)
    const v = r.value
    if (v !== null && typeof v === 'object' && !Array.isArray(v)) liveValue.value = v as CheckValue
  } catch {
    // Popup se nezavírá — snímek je pořád lepší než prázdné okno; jen se označí,
    // že jde o starší data, aby se useknutý seznam nevydával za úplný.
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

const parsed = computed<CheckValue | null>(() => {
  if (liveValue.value !== null) return liveValue.value
  const v = props.value
  if (v === null || typeof v !== 'object' || Array.isArray(v)) return null
  return v as CheckValue
})

/**
 * Nálezy, včetně ZPĚTNÉ KOMPATIBILITY se starým tvarem.
 *
 * Payload kroku `precheck` je auditní snímek — zůstává v DB navždy a přepisovat historii
 * se nesmí. Období uzavřená před sjednocením tvaru proto pořád nesou `items`/`accounts`/
 * `ids` místo `findings`. Bez téhle větve by se u nich vypsalo „42 nálezů" a prázdná
 * tabulka, což vypadá jako rozbitá aplikace — hůř než původní syrový JSON.
 */
const findings = computed<Finding[]>(() => {
  const v = parsed.value
  if (!v) return []
  if (Array.isArray(v.findings)) return v.findings

  // Skupiny majetku bez oprávek nesou vnořená `asset_ids` — rozbalí se na kartu.
  if (Array.isArray(v.groups)) {
    return (v.groups as Record<string, unknown>[]).flatMap(g => {
      const note = [g.asset_account_code, g.accumulated_account_code].filter(Boolean).join(' / ')
      return ((g.asset_ids as number[]) ?? []).map(id => ({
        doc_type: 'asset', doc_id: id, note,
        amount: typeof g.asset_balance === 'number' ? g.asset_balance : null,
      }))
    })
  }

  const legacy = (Array.isArray(v.items) && v.items)
    || (Array.isArray(v.documents) && v.documents)
    || (Array.isArray(v.accounts) && v.accounts)
    || (Array.isArray(v.ids) && v.ids)
  if (!legacy) return []

  return (legacy as unknown[]).map(normalizeLegacy)
})

/** Klíče, pod kterými starý tvar nesl částku / protistranu / číslo dokladu. */
function pickNum(r: Record<string, unknown>, keys: string[]): number | null {
  for (const k of keys) {
    const v = r[k]
    if (typeof v === 'number') return v
    if (typeof v === 'string' && v !== '' && !Number.isNaN(Number(v))) return Number(v)
  }
  return null
}
function pickStr(r: Record<string, unknown>, keys: string[]): string | null {
  for (const k of keys) {
    const v = r[k]
    if (typeof v === 'string' && v !== '') return v
    if (typeof v === 'number') return String(v)
  }
  return null
}

function normalizeLegacy(row: unknown): Finding {
  if (typeof row === 'number') return { doc_id: row }
  if (row === null || typeof row !== 'object') return {}
  const r = row as Record<string, unknown>

  return {
    doc_type: pickStr(r, ['doc_type', 'source_type', 'match_kind']),
    doc_id: pickNum(r, ['doc_id', 'invoice_id', 'id']),
    doc_no: pickStr(r, ['doc_no', 'doc_number', 'varsymbol']),
    doc_date: pickStr(r, ['doc_date', 'issue_date', 'tax_date', 'tx_posted_at', 'entry_date', 'date', 'due_date']),
    partner_name: pickStr(r, ['partner_name', 'partner', 'counterparty_name']),
    amount: pickNum(r, ['amount', 'saldo', 'residual', 'bal', 'impact_czk', 'booked']),
    currency: pickStr(r, ['currency', 'currency_code', 'doc_currency']),
    entry_id: pickNum(r, ['entry_id']),
    account_id: pickNum(r, ['account_id']),
    account_code: pickStr(r, ['account_code']),
    name: pickStr(r, ['name']),
    // `issues` se NESLEPUJE do textu — kódy se překládají a doplňují konkrétním
    // údajem z `detail`. Syrové „counterparty_mismatch" účetní neřekne ani co je
    // špatně, ani o kolik.
    issues: Array.isArray(r.issues) ? (r.issues as unknown[]).map(String) : undefined,
    detail: (r.detail !== null && typeof r.detail === 'object' && !Array.isArray(r.detail))
      ? r.detail as Record<string, Record<string, unknown>>
      : null,
    note: Array.isArray(r.issues) ? null : pickStr(r, ['note']),
  }
}

/**
 * Popis nálezu: přeložený kód + konkrétní údaj, kvůli kterému neprošel.
 *
 * Bez toho druhého je hláška k ničemu — „částka nesedí" bez rozdílu nedá účetní nic,
 * co by mohla ověřit, a nutí ji doklad dohledávat ručně.
 */
function issueText(f: Finding): string {
  // Snímek kroku `precheck` je auditní záznam a zůstává v DB navždy. Období uzavřená
  // dřív nesou kódy nálezů už SLEPENÉ do `note` („amount_mismatch, currency_mismatch"),
  // takže bez tohohle fallbacku by se v české aplikaci pořád ukazovala angličtina.
  // Konkrétní čísla u nich doplnit nejde — v starém tvaru nebyla uložena.
  const codes = f.issues?.length ? f.issues : legacyIssueCodes(f.note)
  if (!codes.length) return f.note ?? ''
  return codes.map(code => {
    const key = `checks.issue.${code}`
    const label = t(key)
    const base = label === key ? code : label
    const d = f.detail?.[code]
    if (!d) return base
    if (code === 'amount_mismatch' && typeof d.diff === 'number') {
      // Rozdíl i očekávaná částka jsou v měně DOKLADU, kdežto sloupec Částka nese dopad
      // přepočtený na koruny. Bez zkratky měny by u cizoměnové faktury stálo vedle sebe
      // „89,40 Kč" a „−3,67" a nešlo by poznat, že jde o totéž v jiné měně.
      const cur = f.doc_currency ? ` ${f.doc_currency}` : ''
      return `${base}: ${money.format(d.diff as number)}${cur} (očekáváno ${money.format(Number(d.expected))}${cur})`
    }
    if (code === 'counterparty_mismatch') {
      return `${base}: „${String(d.counterparty_name ?? '')}“ × „${String(d.partner_name ?? '')}“`
    }
    if (code === 'currency_mismatch') {
      return `${base}: ${String(d.tx_currency ?? '')} × ${String(d.doc_currency ?? '')}`
    }
    if (code === 'fx_on_czk_czk' && typeof d.amount === 'number') {
      return `${base}: ${money.format(d.amount as number)}`
    }
    // Rozdíly proti vytěžené příloze: hodnota na dokladu × hodnota na příloze.
    if ('doc' in d && 'attachment' in d) {
      return `${base}: ${fmtDetailValue(d.doc)} × ${fmtDetailValue(d.attachment)}`
    }
    return base
  }).join(' · ')
}

// Počet bere přednostně z dat; u starého tvaru bez `count` spadne na délku seznamu,
// aby nevzniklo „0 nálezů" nad neprázdnou tabulkou.
const total = computed(() => parsed.value?.count ?? findings.value.length)
const truncated = computed(() => parsed.value?.truncated === true)
const isAccount = computed(() =>
  parsed.value?.kind === 'account'
  || (parsed.value?.kind === undefined && Array.isArray(parsed.value?.accounts)))

/** Skalární hodnoty (zůstatky, rozdíly) — kontrola bez seznamu nálezů. */
const scalars = computed<[string, unknown][]>(() => {
  const v = parsed.value
  if (!v) return []
  // Vyloučené i klíče STARÉHO tvaru, jinak by se u historických payloadů vypsalo
  // „items: [object Object]" — přesně ten šum, kvůli kterému tahle komponenta vznikla.
  const skip = ['findings', 'truncated', 'kind', 'count', 'items', 'documents', 'accounts', 'ids', 'groups']
  return Object.entries(v).filter(([k, val]) =>
    !skip.includes(k) && (typeof val === 'number' || typeof val === 'string'))
})

const money = new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
function fmtAmount(a?: number | null): string {
  return a === null || a === undefined ? '' : money.format(a)
}

/**
 * Kódy nálezů vytažené ze starého slepeného `note`.
 *
 * Vrací prázdné pole, když text není seznamem známých kódů — volná poznámka
 * („2× duplicitní VS") se překládat nemá a musí projít beze změny.
 */
const KNOWN_ISSUE_CODES = new Set([
  'amount_mismatch', 'counterparty_mismatch', 'currency_mismatch', 'fx_on_czk_czk',
  'marked_paid_unposted',
])

function legacyIssueCodes(note?: string | null): string[] {
  if (!note) return []
  const parts = note.split(',').map(p => p.trim()).filter(Boolean)
  return parts.length > 0 && parts.every(p => KNOWN_ISSUE_CODES.has(p)) ? parts : []
}

function fmtDetailValue(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'number') return money.format(v)
  if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}/.test(v)) return fmtDate(v)
  return String(v)
}

/** Datum bez času. Payloady nesou i `2026-07-19 01:01:16` — ořízne se na den. */
function fmtDate(d?: string | null): string {
  if (!d) return ''
  const day = new Date(d.slice(0, 10) + 'T00:00:00')
  return Number.isNaN(day.getTime()) ? d : day.toLocaleDateString('cs-CZ')
}

/**
 * Popisek skalárního pole. Bez překladu se v české aplikaci vypisovaly syrové klíče
 * (`fiscal_year`, `balance`, `difference`) — technický dojem a pro účetní nesrozumitelné.
 * Neznámý klíč se vypíše tak, jak je: mlčky ho schovat by zatajilo informaci.
 */
function fieldLabel(key: string): string {
  const k = `checks.field.${key}`
  const v = t(k)
  return v === k ? key : v
}

/**
 * Hodnoty, které jsou počty nebo roky — nesmí se formátovat jako částka (2 025,00 Kč).
 *
 * `_year$` místo výčtu konkrétních klíčů: dřív tu byl jen `fiscal_year`, takže
 * `prior_year` se vypisoval jako „2 025,00". Sufix pokryje i klíče, které teprve
 * vzniknou. `prior_year_turnover` sem NEPATŘÍ a taky nespadne — končí na `_turnover`.
 */
const COUNT_LIKE = /(^count$|_count$|_year$|^year$|^group$|^months?$)/

function fmtScalar(key: string, value: unknown): string {
  if (typeof value !== 'number') {
    // Enum hodnoty (status: approved) mají vlastní překlad, jinak projdou beze změny.
    const k = `checks.value.${String(value)}`
    const v = t(k)
    return v === k ? String(value) : v
  }
  return COUNT_LIKE.test(key) ? String(value) : money.format(value)
}

/**
 * Doúčtování nálezu — jen u kontroly spárovaných plateb a jen tam, kde vůbec vzniká
 * účetní zápis. `counterparty_mismatch` je evidenční nesoulad jmen: účtovat se nemá nic,
 * takže tlačítko by vedlo k zápisu, který tam nepatří. Bez období nemá endpoint co
 * počítat, proto se tlačítko bez `periodId` nezobrazí vůbec.
 */
const NON_POSTING_ISSUES = new Set(['counterparty_mismatch'])

const canRemedy = computed(() => props.checkKey === 'payment_match_audit' && !!props.periodId)

function remediableIssue(f: Finding): string | null {
  if (!canRemedy.value || !f.doc_id) return null
  const codes = f.issues ?? legacyIssueCodes(f.note)
  return codes.find(c => !NON_POSTING_ISSUES.has(c)) ?? null
}

const remedyTarget = ref<{
  periodId: number
  docType: 'invoice' | 'purchase_invoice'
  docId: number
  issue: string
} | null>(null)

function openRemedy(f: Finding) {
  const issue = remediableIssue(f)
  if (!issue || !props.periodId || !f.doc_id) return
  remedyTarget.value = {
    periodId: props.periodId,
    docType: f.doc_type === 'purchase_invoice' ? 'purchase_invoice' : 'invoice',
    docId: f.doc_id,
    issue,
  }
}

/** Po zaúčtování se detail načte znovu — nález už tam být nemá. */
async function onRemedyPosted() {
  remedyTarget.value = null
  liveValue.value = null
  await openDetail()
}

/** Cíl odkazu podle typu dokladu. `null` = na tenhle typ route neexistuje. */
function docLink(f: Finding): RouteLocationRaw | null {
  if (isAccount.value) {
    return f.account_id ? { name: 'accounting-account-statement', params: { accountId: f.account_id } } : null
  }
  if (!f.doc_id) return null
  switch (f.doc_type) {
    case 'invoice':
      return { name: 'invoice-detail', params: { id: f.doc_id } }
    case 'purchase_invoice':
      return { name: 'purchase-invoice-detail', params: { id: f.doc_id } }
    // Deník nemá detail route jednoho zápisu — vzor v projektu je seznam s query.
    case 'journal_entry':
      return { name: 'accounting-journal', query: { entry_id: f.doc_id } }
    case 'asset':
      return { name: 'accounting-asset-detail', params: { id: f.doc_id } }
    default:
      return null
  }
}
</script>

<template>
  <!-- Kontrola bez nálezů nevypisuje NIC. Stav už nese badge vedle; vypisovat
       {"count":0,"items":[]} byl jen šum, který uživateli nic neříkal. -->
  <template v-if="findings.length > 0 || total > 0">
    <button type="button" class="text-primary-600 hover:underline text-sm" @click="openDetail">
      {{ t('checks.findings_count', { count: total }) }}
    </button>
  </template>

  <span v-else-if="scalars.length" class="text-sm text-neutral-600">
    <span v-for="([k, v], i) in scalars" :key="k">
      <span v-if="i > 0" class="text-neutral-300"> · </span>{{ fieldLabel(k) }}:
      <span class="font-mono">{{ fmtScalar(k, v) }}</span>
    </span>
  </span>

  <span v-else class="text-sm text-neutral-400">—</span>

  <Modal v-if="open" :title="label" width-class="max-w-5xl" @close="open = false">
    <div class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-neutral-500">
          <span v-if="loading">{{ t('checks.loading') }}</span>
          <span v-else>{{ truncated
            ? t('checks.showing_capped', { shown: findings.length, total })
            : t('checks.findings_count', { count: total }) }}</span>
        </p>
        <div class="flex items-center gap-2">
          <RouterLink v-if="listLink" :to="listLink" :class="btnOutline('neutral')" @click="open = false">
            {{ t('checks.open_list') }}
          </RouterLink>
          <a v-if="exportUrl" :href="exportUrl" :class="btnOutline('neutral')" download>
            {{ t('checks.export_csv') }}
          </a>
        </div>
      </div>

      <p v-if="loadFailed" class="text-xs text-warning-700 bg-warning-50 dark:bg-warning-500/[0.06] rounded-md px-3 py-2">
        {{ t('checks.detail_failed') }}
      </p>

      <div class="overflow-x-auto border border-neutral-200 rounded-md">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr v-if="isAccount">
              <th class="px-3 py-2 text-left font-medium">{{ t('checks.account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('checks.name') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('checks.amount') }}</th>
            </tr>
            <tr v-else>
              <th class="px-3 py-2 text-left font-medium">{{ t('checks.document') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('checks.doc_date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('checks.partner') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('checks.amount') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('checks.note') }}</th>
              <th v-if="canRemedy" class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(f, i) in findings" :key="i" class="hover:bg-neutral-50">
              <template v-if="isAccount">
                <td class="px-3 py-2 font-mono">
                  <RouterLink v-if="docLink(f)" :to="docLink(f)!" class="text-primary-600 hover:underline">
                    {{ f.account_code }}
                  </RouterLink>
                  <span v-else>{{ f.account_code }}</span>
                </td>
                <td class="px-3 py-2">{{ f.name }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtAmount(f.amount) }}</td>
              </template>
              <template v-else>
                <td class="px-3 py-2 font-mono">
                  <RouterLink v-if="docLink(f)" :to="docLink(f)!" class="text-primary-600 hover:underline">
                    {{ f.doc_no || `#${f.doc_id}` }}
                  </RouterLink>
                  <span v-else>{{ f.doc_no || `#${f.doc_id}` }}</span>
                  <RouterLink v-if="f.entry_id" :to="{ name: 'accounting-journal', query: { entry_id: f.entry_id } }"
                    class="ml-2 text-xs text-neutral-400 hover:underline">
                    {{ t('checks.entry') }} #{{ f.entry_id }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2 whitespace-nowrap text-neutral-600">{{ fmtDate(f.doc_date) }}</td>
                <td class="px-3 py-2">{{ f.partner_name }}</td>
                <td class="px-3 py-2 text-right font-mono">
                  {{ fmtAmount(f.amount) }}<span v-if="f.currency" class="text-neutral-400 ml-1">{{ f.currency }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-500">{{ issueText(f) }}</td>
                <td v-if="canRemedy" class="px-3 py-2 text-right whitespace-nowrap">
                  <button v-if="remediableIssue(f)" type="button"
                    class="text-xs text-primary-600 hover:underline"
                    @click="openRemedy(f)">
                    {{ t('accounting.finding_remedy.button') }}
                  </button>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="truncated" class="text-xs text-neutral-500">
        {{ t('checks.capped_hint') }}
      </p>
    </div>
  </Modal>

  <FindingRemedyModal v-if="remedyTarget" v-bind="remedyTarget"
    @close="remedyTarget = null" @posted="onRemedyPosted" />
</template>
