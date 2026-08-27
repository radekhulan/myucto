<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollStatutoryEvidence,
  type PayrollStatutoryEvidenceRow,
  type PayrollStatutoryEvidenceSection,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import { useToast } from '@/composables/useToast'
import { loadDefaultHealthInsurerCode } from '@/composables/usePayrollDefaultInsurer'
import { healthInsurerOptions } from '@/utils/healthInsurers'
import {
  applyFieldChange,
  CUSTOM_REASON,
  defaultRow,
  reasonLabelKey,
  reasonOptions,
  rowIssues,
  sectionIssues,
  statutoryText,
  STATUTORY_SECTIONS,
  visibleFields,
  type StatutoryFieldSpec,
  type StatutoryFormContext,
  type StatutoryIssue,
  type StatutorySectionSpec,
} from './statutoryEvidenceForm'

/**
 * Zákonná evidence osoby.
 *
 * Není to „další formulář": bez prohlášení k dani, daňové rezidence,
 * příslušnosti k sociálnímu a zdravotnímu pojištění a evidence slevy
 * pracujícího důchodce shodí mzdový běh celý zákonný výpočet do ručního
 * posouzení. Panel proto neukazuje obecnou nápovědu, ale konkrétní seznam
 * toho, co k danému měsíci chybí — a pojmenovává to stejnými důvody, jaké
 * hlásí výpočet.
 *
 * Hodnoty jako `czech_regime_verified` nebo `insurer_status = verified` jsou
 * PRÁVNÍ SKUTEČNOSTI, ne přepínače. Ke každé se proto váže doklad; lidské
 * vysvětlení má vlastní pole. Kdo doklad nemá, zvolí variantu „neověřeno" —
 * ta jde uložit taky, jen zůstane vidět jako blokátor.
 *
 * Běžný český zaměstnanec ale nemá co vyplňovat: „Přidat záznam" rovnou
 * nabídne rezidenta CZ, český sociální i zdravotní režim a pojišťovnu, u které
 * je osoba vedená. Co plyne z jiné odpovědi, se neptáme, a doklad se vybírá
 * z typických důvodů — kanonickou referenci vygeneruje formulář. Pravidla
 * (a jejich vazba na serverový validátor) jsou v `statutoryEvidenceForm.ts`.
 */
const props = defineProps<{
  personId: number
  canWrite: boolean
}>()

const SECTIONS = STATUTORY_SECTIONS

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const editing = ref(false)
const loadError = ref('')
const saveError = ref('')
const evidence = ref<PayrollStatutoryEvidence | null>(null)
const drafts = ref<Record<string, PayrollStatutoryEvidenceRow[]>>({})
const employerInsurerCode = ref<string | null>(null)

const insurerOptions = healthInsurerOptions()

/** Evidence se vyhodnocuje k měsíci; výchozí je ten, ve kterém uživatel je. */
const effectiveOn = ref(monthEnd(new Date()))

function monthEnd(date: Date): string {
  const end = new Date(date.getFullYear(), date.getMonth() + 1, 0)
  const month = String(end.getMonth() + 1).padStart(2, '0')
  return `${end.getFullYear()}-${month}-${String(end.getDate()).padStart(2, '0')}`
}

function monthStart(iso: string): string {
  return `${iso.slice(0, 7)}-01`
}

const blockers = computed(() => evidence.value?.blockers ?? [])
const frozenThrough = computed(() => evidence.value?.frozen_through ?? null)

/** Reference na základy u jiného zaměstnavatele — volba plátce doplatku minima. */
const otherEmployerBases = computed(() => evidence.value?.other_employer_bases ?? [])

/**
 * Zvolit lze jen zaměstnavatele, který má za TENTÝŽ měsíc doložený vyměřovací
 * základ — jinak zápis odmítne validátor snímku.
 */
function employerReferencesFor(row: PayrollStatutoryEvidenceRow): string[] {
  const period = statutoryText(row, 'period_start')
  return otherEmployerBases.value
    .filter(base => period === '' || statutoryText(base, 'period_start') === period)
    .map(base => statutoryText(base, 'employer_reference'))
    .filter(reference => reference !== '')
}

/**
 * Výchozí pojišťovna pro předvyplnění. Nejdřív ta, u které je osoba vedená —
 * ta je v už načtené evidenci, takže nestojí ani jeden request navíc. Teprve
 * když osoba historii nemá, sáhne se po výchozí pojišťovně zaměstnavatele
 * (načtená nejvýš jednou za běh aplikace, ne na každou kartu).
 */
const personInsurerCode = computed(() => {
  let latest: { from: string; code: string } | null = null
  for (const row of drafts.value.health_coverages ?? []) {
    const code = statutoryText(row, 'insurer_code')
    if (code === '') continue
    const from = statutoryText(row, 'effective_from')
    if (latest === null || from >= latest.from) latest = { from, code }
  }
  return latest?.code ?? null
})

const defaultInsurerCode = computed(() => personInsurerCode.value ?? employerInsurerCode.value)

function contextFor(row: PayrollStatutoryEvidenceRow): StatutoryFormContext {
  return {
    effectiveOn: effectiveOn.value,
    defaultInsurerCode: defaultInsurerCode.value,
    employerReferences: employerReferencesFor(row),
  }
}

function isFrozen(section: StatutorySectionSpec, row: PayrollStatutoryEvidenceRow): boolean {
  const start = section.kind === 'month' ? row.period_start : row.effective_from
  return typeof start === 'string'
    && frozenThrough.value !== null
    && start <= frozenThrough.value
}

function fieldValue(row: PayrollStatutoryEvidenceRow, key: string): string {
  return statutoryText(row, key)
}

function setField(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  next: string,
) {
  row[key] = next === '' ? null : next
  applyFieldChange(section, row, key, contextFor(row))
}

function onSelect(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  event: Event,
) {
  setField(section, row, key, (event.target as HTMLSelectElement).value)
}

function onInput(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  event: Event,
) {
  setField(section, row, key, (event.target as HTMLInputElement).value)
}

const customReferenceEditors = reactive(
  new WeakMap<PayrollStatutoryEvidenceRow, Set<string>>(),
)

function usesCustomReference(row: PayrollStatutoryEvidenceRow, key: string): boolean {
  return customReferenceEditors.get(row)?.has(key) ?? false
}

/** Prázdno, typický důvod, nebo „jiné", když v řádku je vlastní označení. */
function reasonSelection(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  field: StatutoryFieldSpec,
): string {
  if (usesCustomReference(row, field.key)) return CUSTOM_REASON
  const current = statutoryText(row, field.key)
  if (current === '') return ''
  return reasonOptions(section.key, field.key, row).includes(current)
    ? current
    : CUSTOM_REASON
}

function onReason(
  row: PayrollStatutoryEvidenceRow,
  field: StatutoryFieldSpec,
  event: Event,
) {
  const selected = (event.target as HTMLSelectElement).value
  const customFields = customReferenceEditors.get(row) ?? new Set<string>()
  if (selected === CUSTOM_REASON) customFields.add(field.key)
  else customFields.delete(field.key)
  customReferenceEditors.set(row, customFields)
  row[field.key] = selected === CUSTOM_REASON || selected === '' ? null : selected
}

function hydrate(value: PayrollStatutoryEvidence) {
  evidence.value = value
  const next: Record<string, PayrollStatutoryEvidenceRow[]> = {}
  for (const section of SECTIONS) {
    next[section.key] = (value.sections[section.key] ?? []).map(row => ({ ...row }))
  }
  drafts.value = next
}

function addRow(section: StatutorySectionSpec) {
  const rows = drafts.value[section.key] ?? []
  const row = defaultRow(section, monthStart(effectiveOn.value), {
    effectiveOn: effectiveOn.value,
    defaultInsurerCode: defaultInsurerCode.value,
    employerReferences: [],
  })
  drafts.value[section.key] = [...rows, row]
}

function removeRow(section: StatutorySectionSpec, index: number) {
  const rows = [...(drafts.value[section.key] ?? [])]
  rows.splice(index, 1)
  drafts.value[section.key] = rows
}

function issuesFor(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): StatutoryIssue[] {
  return rowIssues(section, row, contextFor(row))
}

/** Vše, co formulář umí zachytit dřív, než to odmítne server. */
const issues = computed<Array<{ section: PayrollStatutoryEvidenceSection; issue: StatutoryIssue }>>(() => {
  const found: Array<{ section: PayrollStatutoryEvidenceSection; issue: StatutoryIssue }> = []
  for (const section of SECTIONS) {
    const rows = drafts.value[section.key] ?? []
    for (const issue of sectionIssues(section, rows)) found.push({ section: section.key, issue })
    for (const row of rows) {
      for (const issue of issuesFor(section, row)) found.push({ section: section.key, issue })
    }
  }
  return found
})

function issueText(issue: StatutoryIssue): string {
  const params: Record<string, string> = { ...(issue.params ?? {}) }
  if (params.label !== undefined) params.label = t(params.label)
  return t(`payroll.people.statutory_evidence.issue.${issue.key}`, params)
}

async function load() {
  loading.value = true
  loadError.value = ''
  saveError.value = ''
  try {
    hydrate(await payrollApi.statutoryEvidence(props.personId, effectiveOn.value))
  } catch (exception) {
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

function cancel() {
  editing.value = false
  saveError.value = ''
  if (evidence.value) hydrate(evidence.value)
}

async function save() {
  if (saving.value) return
  saveError.value = ''
  // Chyby, které formulář zná, nemá smysl posílat na server — ten by z nich
  // vrátil jednu obecnější a uživatel by hledal, které pole ji způsobilo.
  if (issues.value.length > 0) {
    saveError.value = t('payroll.people.statutory_evidence.issues_block_save')
    return
  }
  saving.value = true
  try {
    const sections = {} as Record<PayrollStatutoryEvidenceSection, PayrollStatutoryEvidenceRow[]>
    for (const section of SECTIONS) {
      sections[section.key] = (drafts.value[section.key] ?? []).map(row => ({ ...row }))
    }
    hydrate(await payrollApi.saveStatutoryEvidence(props.personId, {
      effective_on: effectiveOn.value,
      sections,
    }))
    editing.value = false
    toast.success(t('payroll.people.statutory_evidence.saved'))
  } catch (exception) {
    // Server jmenuje konkrétní důvod (překryv, díra v řadě, chybějící doklad,
    // uzavřené období) — obecná hláška by ho jen zakryla.
    saveError.value = apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.save_failed'),
    )
  } finally {
    saving.value = false
  }
}

watch(() => props.personId, () => { editing.value = false; void load() })
watch(effectiveOn, () => { editing.value = false; void load() })
onMounted(() => {
  void load()
  void loadDefaultHealthInsurerCode().then((code) => { employerInsurerCode.value = code })
})
</script>

<template>
  <details
    class="group rounded-lg border border-payroll-500/30 bg-surface"
    data-test="statutory-evidence"
    :open="blockers.length > 0"
  >
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.statutory_evidence.title') }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.people.statutory_evidence.subtitle') }}
        </span>
      </span>
      <span
        v-if="blockers.length > 0"
        class="shrink-0 rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800"
        data-test="statutory-evidence-badge"
      >{{ t('payroll.people.statutory_evidence.blocked_badge', { count: blockers.length }, blockers.length) }}</span>
    </summary>

    <div class="border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

      <p
        v-else-if="loadError"
        class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
        role="alert"
        data-test="statutory-evidence-load-error"
      >{{ loadError }}</p>

      <template v-else>
        <label class="mb-3 block text-xs text-neutral-600">
          {{ t('payroll.people.statutory_evidence.effective_on') }}
          <input
            v-model="effectiveOn"
            type="date"
            class="mt-1 block rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm"
            data-test="statutory-evidence-effective-on"
          >
          <span class="mt-1 block text-neutral-500">
            {{ t('payroll.people.statutory_evidence.effective_on_hint') }}
          </span>
        </label>

        <div
          v-if="blockers.length > 0"
          class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 p-2 text-xs text-warning-800"
          data-test="statutory-evidence-blockers"
        >
          <p class="font-medium">{{ t('payroll.people.statutory_evidence.blockers_title') }}</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-4">
            <li v-for="blocker in blockers" :key="blocker">
              {{ t(`payroll.people.statutory_evidence.blocker.${blocker}`) }}
            </li>
          </ul>
          <p class="mt-1">{{ t('payroll.people.statutory_evidence.blockers_consequence') }}</p>
        </div>
        <p
          v-else
          class="mb-3 rounded-md bg-success-50 px-3 py-2 text-xs text-success-800"
          data-test="statutory-evidence-complete"
        >{{ t('payroll.people.statutory_evidence.complete') }}</p>

        <p
          v-if="frozenThrough"
          class="mb-3 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
          data-test="statutory-evidence-frozen"
        >{{ t('payroll.people.statutory_evidence.frozen_hint', { day: frozenThrough }) }}</p>

        <div class="space-y-4">
          <section v-for="section in SECTIONS" :key="section.key" :data-test="`section-${section.key}`">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
              {{ t(`payroll.people.statutory_evidence.section.${section.key}`) }}
            </h4>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t(`payroll.people.statutory_evidence.section_hint.${section.key}`) }}
            </p>

            <p
              v-if="(drafts[section.key] ?? []).length === 0"
              class="mt-2 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
            >{{ t('payroll.people.statutory_evidence.empty') }}</p>

            <div
              v-for="(row, index) in drafts[section.key] ?? []"
              :key="`${section.key}-${row.id ?? `new-${index}`}`"
              class="mt-2 rounded-md border border-neutral-200 p-2"
              :data-test="`row-${section.key}-${index}`"
            >
              <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <label v-if="section.kind === 'month'" class="block text-xs text-neutral-600">
                  {{ t('payroll.people.statutory_evidence.period_start') }}
                  <input
                    v-model="row.period_start"
                    type="date"
                    :disabled="!editing || saving || isFrozen(section, row)"
                    :data-test="`${section.key}-${index}-period_start`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                </label>
                <template v-else>
                  <label class="block text-xs text-neutral-600">
                    {{ t('payroll.people.statutory_evidence.effective_from') }}
                    <input
                      v-model="row.effective_from"
                      type="date"
                      :disabled="!editing || saving || isFrozen(section, row)"
                      :data-test="`${section.key}-${index}-effective_from`"
                      class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    >
                  </label>
                  <label class="block text-xs text-neutral-600">
                    {{ t('payroll.people.statutory_evidence.effective_to') }}
                    <input
                      v-model="row.effective_to"
                      type="date"
                      :disabled="!editing || saving"
                      :data-test="`${section.key}-${index}-effective_to`"
                      class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    >
                  </label>
                </template>

                <label
                  v-for="field in visibleFields(section, row)"
                  :key="field.key"
                  class="block text-xs text-neutral-600"
                >
                  {{ t(`payroll.people.statutory_evidence.field.${field.key}`) }}

                  <select
                    v-if="field.kind === 'enum'"
                    :value="fieldValue(row, field.key)"
                    :disabled="!editing || saving"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    @change="onSelect(section, row, field.key, $event)"
                  >
                    <option v-for="option in field.options" :key="option" :value="option">
                      {{ t(`payroll.people.statutory_evidence.option.${field.key}.${option}`) }}
                    </option>
                  </select>

                  <CountrySelect
                    v-else-if="field.kind === 'country'"
                    :model-value="fieldValue(row, field.key)"
                    :disabled="!editing || saving"
                    :clearable="false"
                    required
                    accent="payroll"
                    class="mt-1 block"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    @update:model-value="setField(section, row, field.key, $event)"
                  />

                  <select
                    v-else-if="field.kind === 'insurer'"
                    :value="fieldValue(row, field.key)"
                    :disabled="!editing || saving"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    @change="onSelect(section, row, field.key, $event)"
                  >
                    <option value="">{{ t('payroll.people.statutory_evidence.insurer_unset') }}</option>
                    <option v-for="insurer in insurerOptions" :key="insurer.value" :value="insurer.value">
                      {{ insurer.label }}
                    </option>
                  </select>

                  <select
                    v-else-if="field.kind === 'employer'"
                    :value="fieldValue(row, field.key)"
                    :disabled="!editing || saving"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    @change="onSelect(section, row, field.key, $event)"
                  >
                    <option value="">{{ t('payroll.people.statutory_evidence.employer_none') }}</option>
                    <option
                      v-for="reference in employerReferencesFor(row)"
                      :key="reference"
                      :value="reference"
                    >{{ reference }}</option>
                  </select>

                  <input
                    v-else-if="field.kind === 'document'"
                    :value="fieldValue(row, field.key)"
                    type="number"
                    min="1"
                    inputmode="numeric"
                    :disabled="!editing || saving || isFrozen(section, row)"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    @input="onInput(section, row, field.key, $event)"
                  >

                  <input
                    v-else-if="field.kind === 'date'"
                    :value="fieldValue(row, field.key)"
                    type="date"
                    :disabled="!editing || saving"
                    :data-test="`${section.key}-${index}-${field.key}`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                    @change="onInput(section, row, field.key, $event)"
                  >

                  <template v-else>
                    <select
                      :value="reasonSelection(section, row, field)"
                      :disabled="!editing || saving"
                      :data-test="`${section.key}-${index}-${field.key}-reason`"
                      class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                      @change="onReason(row, field, $event)"
                    >
                      <option value="">
                        {{ t('payroll.people.statutory_evidence.reference_optional') }}
                      </option>
                      <option
                        v-for="reason in reasonOptions(section.key, field.key, row)"
                        :key="reason"
                        :value="reason"
                      >{{ t(`payroll.people.statutory_evidence.reason.${reasonLabelKey(reason)}`) }}</option>
                      <option :value="CUSTOM_REASON">
                        {{ t('payroll.people.statutory_evidence.reason_custom') }}
                      </option>
                    </select>
                    <template v-if="reasonSelection(section, row, field) === CUSTOM_REASON">
                      <input
                        v-model="row[field.key]"
                        type="text"
                        :disabled="!editing || saving"
                        :placeholder="t('payroll.people.statutory_evidence.reference_placeholder')"
                        :data-test="`${section.key}-${index}-${field.key}`"
                        class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                      >
                      <span class="mt-0.5 block text-neutral-400">
                        {{ t('payroll.people.statutory_evidence.reference_hint') }}
                      </span>
                    </template>
                  </template>
                </label>

                <label class="block text-xs text-neutral-600 sm:col-span-2 lg:col-span-3">
                  {{ t('payroll.people.statutory_evidence.evidence_note') }}
                  <input
                    v-model="row.evidence_note"
                    type="text"
                    :disabled="!editing || saving"
                    :placeholder="t('payroll.people.statutory_evidence.evidence_note_placeholder')"
                    :data-test="`${section.key}-${index}-evidence_note`"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                  >
                </label>
              </div>

              <ul
                v-if="issuesFor(section, row).length > 0"
                class="mt-2 list-disc space-y-0.5 rounded-md border border-warning-500/30 bg-warning-50 py-1.5 pl-6 pr-2 text-xs text-warning-800"
                :data-test="`issues-${section.key}-${index}`"
              >
                <li v-for="issue in issuesFor(section, row)" :key="issue.key">
                  {{ issueText(issue) }}
                </li>
              </ul>

              <div class="mt-2 flex items-center justify-between">
                <span v-if="isFrozen(section, row)" class="text-xs text-neutral-500">
                  {{ t('payroll.people.statutory_evidence.row_frozen') }}
                </span>
                <span v-else />
                <button
                  v-if="editing && !isFrozen(section, row)"
                  type="button"
                  :class="btnOutline('danger')"
                  :disabled="saving"
                  :data-test="`remove-${section.key}-${index}`"
                  @click="removeRow(section, index)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                  {{ t('payroll.people.statutory_evidence.remove_row') }}
                </button>
              </div>
            </div>

            <p
              v-for="issue in sectionIssues(section, drafts[section.key] ?? [])"
              :key="issue.key"
              class="mt-2 rounded-md border border-warning-500/30 bg-warning-50 px-2 py-1.5 text-xs text-warning-800"
              :data-test="`issues-${section.key}`"
            >{{ issueText(issue) }}</p>

            <button
              v-if="editing"
              type="button"
              :class="`mt-2 ${btnOutline('neutral')}`"
              :disabled="saving"
              :data-test="`add-${section.key}`"
              @click="addRow(section)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.statutory_evidence.add_row') }}
            </button>
          </section>
        </div>

        <p
          v-if="saveError"
          class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
          role="alert"
          data-test="statutory-evidence-error"
        >{{ saveError }}</p>

        <div v-if="canWrite" class="mt-3 flex justify-end gap-2">
          <button
            v-if="!editing"
            type="button"
            :class="btnOutline('primary')"
            data-test="start-statutory-evidence"
            @click="editing = true"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
            {{ t('payroll.people.statutory_evidence.edit') }}
          </button>
          <template v-else>
            <button
              type="button"
              :class="btnOutline('neutral')"
              :disabled="saving"
              @click="cancel"
            >{{ t('common.cancel') }}</button>
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="saving"
              data-test="statutory-evidence-save"
              @click="save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </template>
        </div>
      </template>
    </div>
  </details>
</template>
