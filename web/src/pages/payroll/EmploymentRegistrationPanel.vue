<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportEnvironment,
  type PayrollRegistrationPreview,
  type PayrollRegistrationEvent,
  type PayrollRegistrationEventInput,
  type PayrollRegistrationEventInteraction,
  type PayrollRegistrationSubmission,
  type PayrollRegistrationA1Profile,
  type PayrollRegistrationA1ProfilePayload,
  type PayrollRegistrationChangeDetection,
  type PayrollRegistrationChangeProposal,
} from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate } from '@/composables/useFormat'

const props = defineProps<{
  employmentId: number
  canWrite: boolean
}>()

const { t } = useI18n()
const busy = ref(false)
const error = ref('')
const preview = ref<PayrollRegistrationPreview | null>(null)
const submission = ref<PayrollRegistrationSubmission | null>(null)
const showXml = ref(false)
const environment = ref<PayrollJmhzTransportEnvironment>('test')
const transport = ref<PayrollJmhzTransportPoll | null>(null)
const transportBusy = ref<'send' | 'poll' | 'close' | null>(null)
const transportMessage = ref('')
const changeDetection = ref<PayrollRegistrationChangeDetection | null>(null)
const changesBusy = ref(false)
const changeError = ref('')
const proposalBusy = ref<number | null>(null)
const dismissOpenFor = ref<number | null>(null)
const dismissNotes = ref<Record<number, string>>({})
const events = ref<PayrollRegistrationEvent[]>([])
const eventsBusy = ref(false)
const eventSaving = ref(false)
const eventError = ref('')
const selectedEventId = ref<number | null>(null)
const eventFormOpen = ref(false)
const eventInteraction = ref<PayrollRegistrationEventInteraction>('termination')
const effectiveOn = ref('')
const sourceReference = ref('')
const sourceSubmissionId = ref<number | null>(null)
const discoveredOn = ref('')
const newVariableSymbol = ref('')
const deltaField = ref('title_prefix')
const deltaValue = ref('')
const addressStreet = ref('')
const addressHouseNumber = ref('')
const addressOrientationNumber = ref('')
const addressPostalCode = ref('')
const addressCity = ref('')
const addressCountryCode = ref('CZ')
const addressRuianPoint = ref('')
const residencyCountryCode = ref('')
const residencyChangedOn = ref('')
const foreignName = ref('')
const foreignCountryCode = ref('')
const foreignIdentifier = ref('')
const foreignStreet = ref('')
const foreignHouseNumber = ref('')
const foreignOrientationNumber = ref('')
const foreignPostalCode = ref('')
const foreignCity = ref('')
const foreignSector = ref('')
const endedByDeath = ref<'omit' | 'yes' | 'no'>('omit')
const unemploymentMode = ref<
  'omit' | 'spec_early' | 'not_provided_2' | 'not_provided_3' | 'provided'
>('omit')
const earlyTerminationReason = ref('')
const averageNetEarnings = ref('')
const pensionPeriods = ref([{ from: '', to: '' }])
const employmentType = ref<'omit' | '1' | '2'>('omit')
const terminationReason = ref('')
const entitlement = ref<'omit' | 'yes' | 'no'>('omit')
const paidInFull = ref<'yes' | 'no'>('no')
const settlementAmountKind = ref('replacement')
const settlementAmount = ref('')
const notStartedConfirmed = ref(false)
const a1ProfileOpen = ref(false)
const a1ProfileLoading = ref(false)
const a1ProfileSaving = ref(false)
const a1ProfileError = ref('')
const a1ProfileMessage = ref('')
const a1ProfileJson = ref('')

function emptyA1Profile(): PayrollRegistrationA1ProfilePayload {
  return {
    effective_on: '',
    row_version: 0,
    permanent_address: {
      street: null,
      house_number: '',
      orientation_number: null,
      city: '',
      postal_code: '',
      country_code: '',
      ruian_point: null,
    },
    tax_residency: {
      country_code: '',
      identifier_type: null,
      identifier: null,
      residence_address: null,
    },
    employment: {
      activity_code: '',
      relationship_detail_code: null,
      actual_start_on: '',
      contract_start_on: null,
      small_scale: null,
      employment_status_code: null,
      work_mode_code: null,
      continuous_operation: null,
      prevailing_workplace_code: null,
      expected_workplaces: null,
      contract_workplace: null,
      workplace_city: null,
      workplace_municipality_code: null,
      profession_code: null,
      required_education_code: null,
      position_name: null,
      leadership: null,
    },
    pension: {
      type_code: null,
      received_from: null,
      early_retirement: false,
      reduced_retirement_age: false,
    },
    health_insurance_code: null,
    facts: {
      highest_education_code: null,
      disability_card: false,
      health_restrictions: [],
    },
    foreign_legislation: { applies: false, country_code: null },
    proof_identity: null,
    foreign_worker: null,
    czech_residence_address: null,
    contact_address: null,
    attachments: [],
  }
}

function editableA1Profile(profile: PayrollRegistrationA1Profile): PayrollRegistrationA1ProfilePayload {
  const {
    reference_hash: _referenceHash,
    created_at: _createdAt,
    created: _created,
    ...editable
  } = profile
  return editable as PayrollRegistrationA1ProfilePayload
}

async function loadA1Profile(): Promise<void> {
  a1ProfileLoading.value = true
  a1ProfileError.value = ''
  try {
    const profile = await payrollApi.employmentRegistrationA1Profile(props.employmentId)
    a1ProfileJson.value = JSON.stringify(
      profile === null ? emptyA1Profile() : editableA1Profile(profile),
      null,
      2,
    )
  } catch (exception) {
    a1ProfileError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.a1.load_failed'),
    )
  } finally {
    a1ProfileLoading.value = false
  }
}

async function saveA1Profile(): Promise<void> {
  if (!props.canWrite || a1ProfileSaving.value) return
  a1ProfileSaving.value = true
  a1ProfileError.value = ''
  a1ProfileMessage.value = ''
  try {
    const parsed: unknown = JSON.parse(a1ProfileJson.value)
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error(t('payroll.people.registration.a1.invalid_json'))
    }
    const profile = await payrollApi.saveEmploymentRegistrationA1Profile(
      props.employmentId,
      parsed as PayrollRegistrationA1ProfilePayload,
    )
    a1ProfileJson.value = JSON.stringify(editableA1Profile(profile), null, 2)
    a1ProfileMessage.value = t('payroll.people.registration.a1.saved', {
      version: profile.row_version,
    })
    resetPreparedFiling()
  } catch (exception) {
    a1ProfileError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.a1.save_failed'),
    )
  } finally {
    a1ProfileSaving.value = false
  }
}

const deltaFieldOptions = computed(() => eventInteraction.value === 'correction'
  ? ['title_prefix', 'tax_residency', 'relationship_detail_code', 'highest_education_code']
  : ['title_prefix', 'contact_address', 'tax_residency', 'relationship_detail_code', 'health_insurance_code'])

const selectedEvent = computed(() => events.value.find(
  event => event.id === selectedEventId.value,
) ?? null)

const sourceReferenceRequired = computed(() => eventInteraction.value !== 'termination')

const deltaValueReady = computed(() => {
  if (deltaField.value === 'contact_address') {
    return addressStreet.value.trim() !== ''
      && addressHouseNumber.value.trim() !== ''
      && addressPostalCode.value.trim() !== ''
      && addressCity.value.trim() !== ''
      && /^[A-Z]{2}$/u.test(addressCountryCode.value)
  }
  if (deltaField.value === 'tax_residency') {
    return /^[A-Z]{2}$/u.test(residencyCountryCode.value)
      && residencyChangedOn.value !== ''
  }
  return deltaValue.value.trim() !== ''
})

const settlementNeeded = computed(() => ['4', '5'].includes(terminationReason.value))

const a2Ready = computed(() => {
  if (unemploymentMode.value === 'spec_early') {
    return /^[0-9]$/u.test(earlyTerminationReason.value)
  }
  if (['not_provided_3', 'provided'].includes(unemploymentMode.value)) {
    const periodsReady = pensionPeriods.value.length > 0
      && pensionPeriods.value.every(period => period.from !== '' && period.to !== '')
    if (!/^[0-9]{1,10}$/u.test(averageNetEarnings.value) || !periodsReady) return false
  }
  if (unemploymentMode.value === 'provided' && employmentType.value !== 'omit') {
    if (!/^[0-9]{1,3}$/u.test(terminationReason.value)) return false
    if (settlementNeeded.value) {
      if (entitlement.value === 'omit') return false
      if (entitlement.value === 'yes' && !/^[0-9]{1,10}$/u.test(settlementAmount.value)) {
        return false
      }
    }
  }
  return true
})

const eventCanSave = computed(() => {
  if (!props.canWrite || eventSaving.value || busy.value || submission.value !== null
    || effectiveOn.value === ''
  ) return false
  if (sourceReferenceRequired.value && sourceReference.value.trim() === '') return false
  if (eventInteraction.value === 'termination') return a2Ready.value
  if (eventInteraction.value === 'change') return deltaValueReady.value
  if (eventInteraction.value === 'correction') {
    return deltaValueReady.value
      && discoveredOn.value !== ''
      && sourceSubmissionId.value !== null
      && sourceSubmissionId.value > 0
  }
  if (eventInteraction.value === 'variable_symbol_transfer') {
    return /^[0-9]{8,10}$/u.test(newVariableSymbol.value)
  }
  if (eventInteraction.value === 'czech_legislation_start') {
    return foreignName.value.trim() !== '' && /^[A-Z]{2}$/u.test(foreignCountryCode.value)
  }
  if (eventInteraction.value === 'czech_legislation_end') {
    return foreignName.value.trim() !== ''
      && /^[A-Z]{2}$/u.test(foreignCountryCode.value)
      && foreignIdentifier.value.trim() !== ''
  }
  if (eventInteraction.value === 'cancellation') {
    return sourceSubmissionId.value !== null
      && sourceSubmissionId.value > 0
      && notStartedConfirmed.value
  }
  return true
})

/**
 * Jedna plná primární akce podle stavu: dokud není náhled, je hlavní krok
 * „zjistit, co se podá"; potom „připravit podání". Dvě plná tlačítka vedle
 * sebe by nutila uživatele hádat, které z nich je to úřední.
 */
const primaryAction = computed<'preview' | 'prepare' | 'done'>(() => {
  if (submission.value) return 'done'
  return preview.value ? 'prepare' : 'preview'
})

const agendaLabel = computed(() => {
  const agenda = submission.value?.agenda_code ?? preview.value?.agenda_code
  return agenda ? t(`payroll.people.registration.agenda.${agenda}`) : ''
})

const interactionLabel = computed(() => {
  const key = submission.value?.interaction ?? preview.value?.interaction
  return key ? t(`payroll.people.registration.interaction.${key}`) : ''
})

const deadline = computed(
  () => submission.value?.deadline ?? preview.value?.deadline ?? null,
)

const transportAttempt = computed<PayrollJmhzTransportAttempt | null>(
  () => transport.value?.attempt ?? null,
)

const canPoll = computed(
  () => transportAttempt.value?.status === 'awaiting_protocol',
)

const canClose = computed(
  () => transport.value?.settled === true
    && transportAttempt.value?.closed_at == null,
)

const canSend = computed(() => transportAttempt.value === null || [
  'prepared',
  'failed',
  'expired',
].includes(transportAttempt.value.status))

const transportActions = computed<ActionItem[]>(() => {
  if (!submission.value) return []

  return [
    {
      key: 'registration-send',
      label: t(`payroll.people.registration.send_${environment.value}`),
      icon: 'send',
      tier: 'primary',
      variant: 'primary',
      show: canSend.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'send',
      run: send,
    },
    {
      key: 'registration-poll',
      label: t('payroll.people.registration.poll'),
      icon: 'cycle',
      tier: 'primary',
      variant: 'primary',
      show: canPoll.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'poll',
      run: poll,
    },
    {
      key: 'registration-close',
      label: t('payroll.people.registration.close'),
      icon: 'check',
      tier: 'primary',
      variant: 'success',
      show: canClose.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'close',
      run: close,
    },
  ]
})

function resetPreparedFiling(): void {
  preview.value = null
  submission.value = null
  transport.value = null
  transportMessage.value = ''
  showXml.value = false
}

function resetEventForm(): void {
  effectiveOn.value = ''
  sourceReference.value = ''
  sourceSubmissionId.value = null
  discoveredOn.value = ''
  newVariableSymbol.value = ''
  deltaField.value = eventInteraction.value === 'correction'
    ? 'title_prefix'
    : 'title_prefix'
  deltaValue.value = ''
  addressStreet.value = ''
  addressHouseNumber.value = ''
  addressOrientationNumber.value = ''
  addressPostalCode.value = ''
  addressCity.value = ''
  addressCountryCode.value = 'CZ'
  addressRuianPoint.value = ''
  residencyCountryCode.value = ''
  residencyChangedOn.value = ''
  foreignName.value = ''
  foreignCountryCode.value = ''
  foreignIdentifier.value = ''
  foreignStreet.value = ''
  foreignHouseNumber.value = ''
  foreignOrientationNumber.value = ''
  foreignPostalCode.value = ''
  foreignCity.value = ''
  foreignSector.value = ''
  endedByDeath.value = 'omit'
  unemploymentMode.value = 'omit'
  earlyTerminationReason.value = ''
  averageNetEarnings.value = ''
  pensionPeriods.value = [{ from: '', to: '' }]
  employmentType.value = 'omit'
  terminationReason.value = ''
  entitlement.value = 'omit'
  paidInFull.value = 'no'
  settlementAmountKind.value = 'replacement'
  settlementAmount.value = ''
  notStartedConfirmed.value = false
}

function eventOptionLabel(event: PayrollRegistrationEvent): string {
  const used = event.consumed
    ? ` · ${t('payroll.people.registration.event.consumed')}`
    : ''
  return `A${event.action_code} · ${formatDate(event.effective_on)} · ${t(`payroll.people.registration.interaction.${event.interaction}`)}${used}`
}

async function loadEvents(): Promise<void> {
  eventsBusy.value = true
  eventError.value = ''
  try {
    events.value = await payrollApi.employmentRegistrationEvents(
      props.employmentId,
      environment.value,
    )
    if (selectedEventId.value !== null
      && !events.value.some(event => event.id === selectedEventId.value)
    ) {
      selectedEventId.value = null
    }
  } catch (exception) {
    eventError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.event.load_failed'),
    )
  } finally {
    eventsBusy.value = false
  }
}

function addPensionPeriod(): void {
  pensionPeriods.value.push({ from: '', to: '' })
}

function removePensionPeriod(index: number): void {
  if (pensionPeriods.value.length > 1) pensionPeriods.value.splice(index, 1)
}

function optionalText(value: string): string | undefined {
  const trimmed = value.trim()
  return trimmed === '' ? undefined : trimmed
}

function deltaPayload(): Record<string, unknown> {
  if (deltaField.value === 'contact_address') {
    return {
      contact_address: {
        street: addressStreet.value.trim(),
        house_number: addressHouseNumber.value.trim(),
        postal_code: addressPostalCode.value.trim(),
        city: addressCity.value.trim(),
        country_code: addressCountryCode.value.trim().toUpperCase(),
        ...(optionalText(addressOrientationNumber.value) === undefined
          ? {}
          : { orientation_number: addressOrientationNumber.value.trim() }),
        ...(optionalText(addressRuianPoint.value) === undefined
          ? {}
          : { ruian_point: addressRuianPoint.value.trim() }),
      },
    }
  }
  if (deltaField.value === 'tax_residency') {
    return {
      tax_residency: {
        country_code: residencyCountryCode.value.trim().toUpperCase(),
        changed_on: residencyChangedOn.value,
      },
    }
  }
  return { [deltaField.value]: deltaValue.value.trim() }
}

function a2Payload(): Pick<PayrollRegistrationEventInput, 'ended_by_death' | 'unemployment'> {
  const result: Pick<PayrollRegistrationEventInput, 'ended_by_death' | 'unemployment'> = {}
  if (endedByDeath.value !== 'omit') result.ended_by_death = endedByDeath.value === 'yes'
  if (unemploymentMode.value === 'omit') return result
  if (unemploymentMode.value === 'spec_early') {
    result.unemployment = { early_termination_reason: earlyTerminationReason.value }
    return result
  }
  result.unemployment = { mode: unemploymentMode.value }
  if (unemploymentMode.value === 'not_provided_2') return result
  result.unemployment.average_net_earnings = averageNetEarnings.value
  result.unemployment.pension_periods = pensionPeriods.value.map(period => ({ ...period }))
  if (unemploymentMode.value !== 'provided' || employmentType.value === 'omit') return result
  result.unemployment.employment_type = employmentType.value
  if (employmentType.value === '1') {
    result.unemployment.termination_reason = terminationReason.value
  } else {
    result.unemployment.service_termination_reason = terminationReason.value
  }
  if (!settlementNeeded.value) return result
  result.unemployment.entitlement = entitlement.value === 'yes'
  if (entitlement.value !== 'yes') return result
  result.unemployment.paid_in_full = paidInFull.value === 'yes'
  result.unemployment[settlementAmountKind.value as 'replacement'] = settlementAmount.value
  return result
}

function foreignInsurancePayload(): NonNullable<PayrollRegistrationEventInput['foreign_insurance']> {
  const current = eventInteraction.value === 'czech_legislation_start' ? 'P' : 'S'
  return {
    current,
    name: foreignName.value.trim(),
    country_code: foreignCountryCode.value.trim().toUpperCase(),
    ...(optionalText(foreignIdentifier.value) === undefined
      ? {}
      : { identifier: foreignIdentifier.value.trim() }),
    ...(optionalText(foreignStreet.value) === undefined ? {} : { street: foreignStreet.value.trim() }),
    ...(optionalText(foreignHouseNumber.value) === undefined ? {} : { house_number: foreignHouseNumber.value.trim() }),
    ...(optionalText(foreignOrientationNumber.value) === undefined ? {} : { orientation_number: foreignOrientationNumber.value.trim() }),
    ...(optionalText(foreignPostalCode.value) === undefined ? {} : { postal_code: foreignPostalCode.value.trim() }),
    ...(optionalText(foreignCity.value) === undefined ? {} : { city: foreignCity.value.trim() }),
    ...(optionalText(foreignSector.value) === undefined ? {} : { sector: foreignSector.value.trim() }),
  }
}

function eventPayload(): PayrollRegistrationEventInput {
  const payload: PayrollRegistrationEventInput = {
    environment: environment.value,
    interaction: eventInteraction.value,
    effective_on: effectiveOn.value,
  }
  if (sourceReferenceRequired.value) payload.source_reference = sourceReference.value.trim()
  if (eventInteraction.value === 'termination') Object.assign(payload, a2Payload())
  if (eventInteraction.value === 'change') payload.changes = deltaPayload()
  if (eventInteraction.value === 'correction') {
    payload.corrections = deltaPayload()
    payload.discovered_on = discoveredOn.value
    payload.source_submission_id = sourceSubmissionId.value ?? undefined
  }
  if (eventInteraction.value === 'variable_symbol_transfer') {
    payload.new_variable_symbol = newVariableSymbol.value
  }
  if (['czech_legislation_start', 'czech_legislation_end'].includes(eventInteraction.value)) {
    payload.foreign_insurance = foreignInsurancePayload()
  }
  if (eventInteraction.value === 'cancellation') {
    payload.source_submission_id = sourceSubmissionId.value ?? undefined
    payload.not_started = true
  }
  return payload
}

async function saveEvent(): Promise<void> {
  if (!eventCanSave.value) return
  eventSaving.value = true
  eventError.value = ''
  try {
    const event = await payrollApi.approveEmploymentRegistrationEvent(
      props.employmentId,
      eventPayload(),
    )
    events.value = [event, ...events.value.filter(existing => existing.id !== event.id)]
    selectedEventId.value = event.id
    eventFormOpen.value = false
    resetEventForm()
    await run('preview')
  } catch (exception) {
    eventError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.event.save_failed'),
    )
  } finally {
    eventSaving.value = false
  }
}

/**
 * Detekce změn hlásitelných do registru pojištěnců.
 *
 * Přepočet běží při otevření karty a po přepnutí prostředí. Není to jen čtení:
 * zakládá návrhy povinností s běžící osmidenní lhůtou, což je přesně ten
 * okamžik, kdy se zaměstnavatel o změně dozvídá (§ 19 odst. 5 zákona
 * č. 323/2025 Sb.).
 */
async function loadChangeDetection(): Promise<void> {
  if (!props.canWrite) return
  changesBusy.value = true
  changeError.value = ''
  try {
    changeDetection.value = await payrollApi.detectEmploymentRegistrationChanges(
      props.employmentId,
      environment.value,
    )
  } catch (exception) {
    changeError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.changes.load_failed'),
    )
  } finally {
    changesBusy.value = false
  }
}

async function fileProposal(proposalId: number): Promise<void> {
  proposalBusy.value = proposalId
  changeError.value = ''
  try {
    await payrollApi.fileEmploymentRegistrationChange(
      props.employmentId,
      proposalId,
      environment.value,
    )
    await Promise.all([loadChangeDetection(), loadEvents()])
  } catch (exception) {
    changeError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.changes.file_failed'),
    )
  } finally {
    proposalBusy.value = null
  }
}

async function dismissProposal(proposalId: number): Promise<void> {
  const note = (dismissNotes.value[proposalId] ?? '').trim()
  if (note === '') return
  proposalBusy.value = proposalId
  changeError.value = ''
  try {
    await payrollApi.dismissEmploymentRegistrationChange(
      props.employmentId,
      proposalId,
      note,
      environment.value,
    )
    delete dismissNotes.value[proposalId]
    dismissOpenFor.value = null
    await loadChangeDetection()
  } catch (exception) {
    changeError.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.changes.dismiss_failed'),
    )
  } finally {
    proposalBusy.value = null
  }
}

function proposalTitle(proposal: PayrollRegistrationChangeProposal): string {
  return proposal.duty_kind === 'health_insurer_change'
    ? t('payroll.people.registration.changes.duty.health_insurer_change')
    : t('payroll.people.registration.changes.duty.regzec_change', {
        action: `A${proposal.action_code ?? 3}`,
      })
}

/** Souhrn „co se změnilo" — konkrétní položky, ne jen „něco". */
function proposalSummary(proposal: PayrollRegistrationChangeProposal): string {
  const groups = [...new Set(proposal.findings.map(finding => finding.group))]
  return groups
    .map(group => t(`payroll.people.registration.changes.group.${group}`))
    .join(', ')
}

function proposalActions(
  proposal: PayrollRegistrationChangeProposal,
): ActionItem[] {
  return [
    {
      key: `registration-change-file-${proposal.id}`,
      label: t('payroll.people.registration.changes.file'),
      icon: 'send',
      tier: 'primary',
      variant: 'primary',
      show: proposal.fileable,
      disabled: !props.canWrite || proposalBusy.value !== null,
      loading: proposalBusy.value === proposal.id,
      run: () => { void fileProposal(proposal.id) },
    },
    {
      key: `registration-change-dismiss-${proposal.id}`,
      label: t('payroll.people.registration.changes.dismiss'),
      icon: 'check',
      tier: 'secondary',
      disabled: !props.canWrite || proposalBusy.value !== null,
      run: () => {
        dismissOpenFor.value = dismissOpenFor.value === proposal.id
          ? null
          : proposal.id
      },
    },
  ]
}

watch(selectedEventId, resetPreparedFiling)
watch(eventInteraction, () => {
  resetEventForm()
  deltaField.value = deltaFieldOptions.value[0] ?? 'title_prefix'
})
watch(employmentType, value => {
  settlementAmountKind.value = value === '2' ? 'severance_pay' : 'replacement'
})
watch(environment, async () => {
  selectedEventId.value = null
  resetPreparedFiling()
  await Promise.all([loadEvents(), loadChangeDetection()])
})
onMounted(() => Promise.all([
  loadEvents(),
  loadA1Profile(),
  loadChangeDetection(),
]))

async function run(action: 'preview' | 'prepare'): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    if (action === 'preview') {
      submission.value = null
      transport.value = null
      transportMessage.value = ''
      preview.value = selectedEventId.value === null
        ? await payrollApi.previewEmploymentRegistration(
            props.employmentId,
            environment.value,
          )
        : await payrollApi.previewEmploymentRegistration(
            props.employmentId,
            environment.value,
            selectedEventId.value,
          )
    } else {
      submission.value = selectedEventId.value === null
        ? await payrollApi.prepareEmploymentRegistration(
            props.employmentId,
            environment.value,
          )
        : await payrollApi.prepareEmploymentRegistration(
            props.employmentId,
            environment.value,
            selectedEventId.value,
          )
      const status = await payrollApi.employmentRegistrationTransportStatus(
        submission.value.submission_id,
        environment.value,
      )
      transport.value = status.attempt === null ? null : {
        attempt: status.attempt,
        acknowledgement: null,
        settled: status.attempt.status === 'completed',
        report: null,
      }
    }
  } catch (exception) {
    // Hláška ze serveru jmenuje konkrétní chybějící údaj — nesmí ji přebít
    // obecný text, jinak uživatel neví, co doplnit.
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.failed'),
    )
  } finally {
    busy.value = false
  }
}

async function send(): Promise<void> {
  if (!submission.value || !props.canWrite) return
  transportBusy.value = 'send'
  error.value = ''
  transportMessage.value = ''
  try {
    const result = await payrollApi.sendEmploymentRegistrationTransport(
      submission.value.submission_id,
      environment.value,
      crypto.randomUUID(),
    )
    transport.value = {
      attempt: result.attempt,
      acknowledgement: result.acknowledgement,
      settled: result.settled,
      report: null,
    }
  } catch (exception) {
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.send_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function poll(): Promise<void> {
  if (!transportAttempt.value || !props.canWrite) return
  transportBusy.value = 'poll'
  error.value = ''
  transportMessage.value = ''
  try {
    transport.value = await payrollApi.pollEmploymentRegistrationTransportAttempt(
      transportAttempt.value.id,
      environment.value,
    )
  } catch (exception) {
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.poll_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function close(): Promise<void> {
  if (!transportAttempt.value || !props.canWrite) return
  transportBusy.value = 'close'
  error.value = ''
  transportMessage.value = ''
  try {
    const result = await payrollApi.closeEmploymentRegistrationTransportAttempt(
      transportAttempt.value.id,
      environment.value,
    )
    transport.value = {
      ...transport.value!,
      attempt: result.attempt,
    }
    transportMessage.value = t('payroll.people.registration.closed', {
      id: result.attempt.id,
    })
  } catch (exception) {
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.close_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function copyXml(): Promise<void> {
  if (preview.value?.xml) await navigator.clipboard.writeText(preview.value.xml)
}
</script>

<template>
  <section
    class="mt-4 border-t border-neutral-200 pt-4"
    data-test="employment-registration"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h4 class="text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.registration.title') }}
        </h4>
        <p class="mt-0.5 text-xs text-neutral-500">
          {{ t('payroll.people.registration.description') }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-2 text-xs text-neutral-600">
          <span>{{ t('payroll.people.registration.environment_label') }}</span>
          <select
            v-model="environment"
            class="rounded-md border border-neutral-300 bg-surface px-2 py-1.5 text-xs text-neutral-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
            :disabled="busy || submission !== null"
            data-test="registration-environment"
          >
            <option value="test">{{ t('payroll.people.registration.environment.test') }}</option>
            <option value="production">{{ t('payroll.people.registration.environment.production') }}</option>
          </select>
        </label>
        <button
          v-if="primaryAction !== 'preview'"
          type="button"
          :class="btnOutline('neutral')"
          :disabled="busy"
          data-test="registration-preview"
          @click="run('preview')"
        >
          {{ t('payroll.people.registration.preview') }}
        </button>
        <button
          v-if="primaryAction !== 'done'"
          type="button"
          :class="btnFilled('primary')"
          :disabled="busy || (primaryAction !== 'preview' && !canWrite)"
          :data-test="`registration-${primaryAction === 'preview' ? 'preview' : 'prepare'}`"
          @click="run(primaryAction === 'preview' ? 'preview' : 'prepare')"
        >
          <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path :d="ICONS.check" />
          </svg>
          {{ busy
            ? t('common.loading')
            : t(`payroll.people.registration.action_${primaryAction}`) }}
        </button>
      </div>
    </div>

    <div class="mt-4 rounded-lg border border-neutral-200 bg-surface p-3" data-test="registration-a1-profile">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h5 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.title') }}
          </h5>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.people.registration.a1.description') }}
          </p>
        </div>
        <button
          type="button"
          :class="btnOutline('neutral')"
          :disabled="a1ProfileLoading"
          data-test="registration-a1-toggle"
          @click="a1ProfileOpen = !a1ProfileOpen"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="a1ProfileOpen ? ICONS.x : ICONS.eye" />
          </svg>
          {{ t(a1ProfileOpen
            ? 'payroll.people.registration.a1.hide'
            : 'payroll.people.registration.a1.show') }}
        </button>
      </div>
      <div v-if="a1ProfileOpen" class="mt-3">
        <p class="mb-2 text-xs text-warning-700">
          {{ t('payroll.people.registration.a1.warning') }}
        </p>
        <textarea
          v-model="a1ProfileJson"
          rows="24"
          spellcheck="false"
          class="w-full rounded-md border border-neutral-300 bg-neutral-950 p-3 font-mono text-xs text-neutral-100 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
          :disabled="a1ProfileLoading || a1ProfileSaving || !canWrite"
          data-test="registration-a1-json"
        />
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button
            type="button"
            :class="btnFilled('success')"
            :disabled="!canWrite || a1ProfileLoading || a1ProfileSaving"
            data-test="registration-a1-save"
            @click="saveA1Profile"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ a1ProfileSaving
              ? t('common.loading')
              : t('payroll.people.registration.a1.save') }}
          </button>
          <span v-if="a1ProfileMessage" class="text-xs text-success-700" data-test="registration-a1-saved">
            {{ a1ProfileMessage }}
          </span>
        </div>
        <p v-if="a1ProfileError" class="mt-2 text-xs text-danger-700" data-test="registration-a1-error">
          {{ a1ProfileError }}
        </p>
      </div>
    </div>

    <div
      v-if="changeDetection && (changeDetection.proposals.length > 0 || changeError)"
      class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3"
      data-test="registration-changes"
    >
      <h4 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.people.registration.changes.title') }}
      </h4>
      <p class="mt-1 text-xs text-neutral-700">
        {{ t('payroll.people.registration.changes.description') }}
      </p>
      <ul class="mt-3 space-y-3">
        <li
          v-for="proposal in changeDetection.proposals"
          :key="proposal.id"
          class="rounded-md border border-neutral-200 bg-surface p-3"
          :data-test="`registration-change-${proposal.id}`"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-sm font-medium text-neutral-900">
              {{ proposalTitle(proposal) }}
            </span>
            <span class="text-xs text-neutral-600">
              {{ t('payroll.people.registration.changes.due', {
                date: formatDate(proposal.due_on),
              }) }}
            </span>
          </div>
          <p class="mt-1 text-xs text-neutral-700" data-test="registration-change-summary">
            {{ t('payroll.people.registration.changes.changed', {
              fields: proposalSummary(proposal),
            }) }}
          </p>
          <p class="mt-1 text-xs text-neutral-500">
            {{ proposal.deadline_source }}
          </p>
          <p
            v-if="!proposal.fileable"
            class="mt-1 text-xs text-warning-800"
            data-test="registration-change-manual"
          >
            {{ t('payroll.people.registration.changes.manual_only') }}
          </p>
          <ActionBar :actions="proposalActions(proposal)" />
          <div v-if="dismissOpenFor === proposal.id" class="mt-2 flex flex-wrap gap-2">
            <input
              v-model="dismissNotes[proposal.id]"
              type="text"
              maxlength="500"
              class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
              :placeholder="t('payroll.people.registration.changes.dismiss_note')"
              :data-test="`registration-change-note-${proposal.id}`"
            >
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="!(dismissNotes[proposal.id] ?? '').trim() || proposalBusy !== null"
              :data-test="`registration-change-dismiss-confirm-${proposal.id}`"
              @click="dismissProposal(proposal.id)"
            >
              {{ t('payroll.people.registration.changes.dismiss_confirm') }}
            </button>
          </div>
        </li>
      </ul>
      <p v-if="changesBusy" class="mt-2 text-xs text-neutral-500">
        {{ t('common.loading') }}
      </p>
      <p v-if="changeError" class="mt-2 text-xs text-danger-700" data-test="registration-changes-error">
        {{ changeError }}
      </p>
    </div>

    <div class="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3" data-test="registration-events">
      <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-0 flex-1 text-xs font-medium text-neutral-700">
          {{ t('payroll.people.registration.event.select') }}
          <select
            v-model="selectedEventId"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
            :disabled="eventsBusy || busy || submission !== null"
            data-test="registration-event-select"
          >
            <option :value="null">{{ t('payroll.people.registration.event.automatic') }}</option>
            <option v-for="event in events" :key="event.id" :value="event.id">
              {{ eventOptionLabel(event) }}
            </option>
          </select>
        </label>
        <button
          type="button"
          :class="btnOutline('primary')"
          :disabled="!canWrite || busy || submission !== null"
          data-test="registration-event-new"
          @click="eventFormOpen = !eventFormOpen"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="eventFormOpen ? ICONS.x : ICONS.plus" />
          </svg>
          {{ eventFormOpen
            ? t('payroll.people.registration.event.cancel_new')
            : t('payroll.people.registration.event.new') }}
        </button>
      </div>
      <p v-if="selectedEvent" class="mt-2 text-xs text-neutral-600" data-test="registration-event-selected">
        {{ t('payroll.people.registration.event.selected', {
          action: `A${selectedEvent.action_code}`,
          reference: selectedEvent.source_reference,
        }) }}
      </p>
      <p v-if="eventsBusy" class="mt-2 text-xs text-neutral-500">
        {{ t('common.loading') }}
      </p>

      <div
        v-if="eventFormOpen"
        class="mt-4 border-t border-neutral-200 pt-4"
        data-test="registration-event-form"
      >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.interaction') }}
            <select
              v-model="eventInteraction"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
              data-test="registration-event-interaction"
            >
              <option value="termination">A2 · {{ t('payroll.people.registration.interaction.termination') }}</option>
              <option value="change">A3 · {{ t('payroll.people.registration.interaction.change') }}</option>
              <option value="correction">A4 · {{ t('payroll.people.registration.interaction.correction') }}</option>
              <option value="variable_symbol_transfer">A5 · {{ t('payroll.people.registration.interaction.variable_symbol_transfer') }}</option>
              <option value="czech_legislation_start">A6 · {{ t('payroll.people.registration.interaction.czech_legislation_start') }}</option>
              <option value="czech_legislation_end">A7 · {{ t('payroll.people.registration.interaction.czech_legislation_end') }}</option>
              <option value="cancellation">A8 · {{ t('payroll.people.registration.interaction.cancellation') }}</option>
            </select>
          </label>
          <label class="text-xs font-medium text-neutral-700">
            {{ t(`payroll.people.registration.event.effective_on.${eventInteraction}`) }}
            <input
              v-model="effectiveOn"
              type="date"
              required
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
              data-test="registration-event-effective-on"
            />
          </label>
          <label v-if="sourceReferenceRequired" class="text-xs font-medium text-neutral-700 sm:col-span-2">
            {{ t('payroll.people.registration.event.source_reference') }}
            <input
              v-model="sourceReference"
              type="text"
              required
              maxlength="191"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
              :placeholder="t('payroll.people.registration.event.source_reference_hint')"
              data-test="registration-event-source-reference"
            />
          </label>
        </div>

        <div v-if="eventInteraction === 'termination'" class="mt-4 space-y-4" data-test="registration-event-a2">
          <p class="rounded-md border border-primary-200 bg-primary-50 p-3 text-xs text-primary-800">
            {{ t('payroll.people.registration.event.a2_hint') }}
          </p>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.ended_by_death') }}
              <select v-model="endedByDeath" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.not_applicable') }}</option>
                <option value="no">{{ t('common.no') }}</option>
                <option value="yes">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.unemployment_mode') }}
              <select v-model="unemploymentMode" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.unemployment.omit') }}</option>
                <option value="spec_early">{{ t('payroll.people.registration.event.unemployment.spec_early') }}</option>
                <option value="not_provided_2">{{ t('payroll.people.registration.event.unemployment.not_provided_2') }}</option>
                <option value="not_provided_3">{{ t('payroll.people.registration.event.unemployment.not_provided_3') }}</option>
                <option value="provided">{{ t('payroll.people.registration.event.unemployment.provided') }}</option>
              </select>
            </label>
          </div>
          <label v-if="unemploymentMode === 'spec_early'" class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.early_termination_reason') }}
            <input v-model="earlyTerminationReason" inputmode="numeric" maxlength="1" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-xs" />
          </label>
          <template v-if="unemploymentMode === 'not_provided_3' || unemploymentMode === 'provided'">
            <label class="block text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.average_net_earnings') }}
              <input v-model="averageNetEarnings" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-xs" />
            </label>
            <fieldset class="rounded-lg border border-neutral-200 bg-surface p-3">
              <legend class="px-1 text-xs font-medium text-neutral-700">
                {{ t('payroll.people.registration.event.pension_periods') }}
              </legend>
              <div v-for="(period, index) in pensionPeriods" :key="index" class="mt-2 flex flex-wrap items-end gap-2">
                <label class="min-w-36 flex-1 text-xs text-neutral-600">
                  {{ t('common.from') }}
                  <input v-model="period.from" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
                </label>
                <label class="min-w-36 flex-1 text-xs text-neutral-600">
                  {{ t('common.to') }}
                  <input v-model="period.to" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
                </label>
                <button type="button" :class="btnOutline('danger')" :disabled="pensionPeriods.length === 1" @click="removePensionPeriod(index)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                  {{ t('common.remove') }}
                </button>
              </div>
              <button type="button" class="mt-3" :class="btnOutline('neutral')" @click="addPensionPeriod">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.people.registration.event.add_period') }}
              </button>
            </fieldset>
          </template>
          <div v-if="unemploymentMode === 'provided'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.employment_type') }}
              <select v-model="employmentType" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.not_applicable') }}</option>
                <option value="1">{{ t('payroll.people.registration.event.employment_type_1') }}</option>
                <option value="2">{{ t('payroll.people.registration.event.employment_type_2') }}</option>
              </select>
            </label>
            <label v-if="employmentType !== 'omit'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.termination_reason') }}
              <input v-model="terminationReason" inputmode="numeric" maxlength="3" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
            </label>
            <label v-if="employmentType !== 'omit' && settlementNeeded" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.entitlement') }}
              <select v-model="entitlement" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.select_placeholder') }}</option>
                <option value="no">{{ t('common.no') }}</option>
                <option value="yes">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label v-if="entitlement === 'yes'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.paid_in_full') }}
              <select v-model="paidInFull" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="no">{{ t('common.no') }}</option>
                <option value="yes">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label v-if="entitlement === 'yes'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.settlement_kind') }}
              <select v-model="settlementAmountKind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <template v-if="employmentType === '1'">
                  <option value="replacement">{{ t('payroll.people.registration.event.settlement.replacement') }}</option>
                  <option value="golden_handshake">{{ t('payroll.people.registration.event.settlement.golden_handshake') }}</option>
                </template>
                <template v-else>
                  <option value="severance_pay">{{ t('payroll.people.registration.event.settlement.severance_pay') }}</option>
                  <option value="disposal">{{ t('payroll.people.registration.event.settlement.disposal') }}</option>
                </template>
              </select>
            </label>
            <label v-if="entitlement === 'yes'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.settlement_amount') }}
              <input v-model="settlementAmount" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
            </label>
          </div>
        </div>

        <div v-if="eventInteraction === 'change' || eventInteraction === 'correction'" class="mt-4 space-y-4" data-test="registration-event-delta">
          <div v-if="eventInteraction === 'correction'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.source_submission_id') }}
              <input v-model.number="sourceSubmissionId" type="number" min="1" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-event-source-submission-id" />
            </label>
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.discovered_on') }}
              <input v-model="discoveredOn" type="date" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
            </label>
          </div>
          <label class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.delta_field') }}
            <select v-model="deltaField" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md">
              <option v-for="field in deltaFieldOptions" :key="field" :value="field">
                {{ t(`payroll.people.registration.event.delta.${field}`) }}
              </option>
            </select>
          </label>
          <div v-if="deltaField === 'contact_address'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.street') }}<input v-model="addressStreet" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.house_number') }}<input v-model="addressHouseNumber" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.orientation_number') }}<input v-model="addressOrientationNumber" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.postal_code') }}<input v-model="addressPostalCode" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.city') }}<input v-model="addressCity" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.country_code') }}<input v-model="addressCountryCode" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700 sm:col-span-2">{{ t('payroll.people.registration.event.ruian_point') }}<input v-model="addressRuianPoint" maxlength="12" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          </div>
          <div v-else-if="deltaField === 'tax_residency'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.country_code') }}<input v-model="residencyCountryCode" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.residency_changed_on') }}<input v-model="residencyChangedOn" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          </div>
          <label v-else class="block text-xs font-medium text-neutral-700">
            {{ t(`payroll.people.registration.event.delta.${deltaField}`) }}
            <input v-model="deltaValue" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" />
          </label>
        </div>

        <label v-if="eventInteraction === 'variable_symbol_transfer'" class="mt-4 block text-xs font-medium text-neutral-700">
          {{ t('payroll.people.registration.event.new_variable_symbol') }}
          <input v-model="newVariableSymbol" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" data-test="registration-event-new-variable-symbol" />
        </label>

        <div v-if="eventInteraction === 'czech_legislation_start' || eventInteraction === 'czech_legislation_end'" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-test="registration-event-foreign-insurance">
          <label class="text-xs font-medium text-neutral-700 sm:col-span-2">{{ t('payroll.people.registration.event.foreign_name') }}<input v-model="foreignName" maxlength="100" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.country_code') }}<input v-model="foreignCountryCode" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.foreign_identifier') }}<input v-model="foreignIdentifier" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.street') }}<input v-model="foreignStreet" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.house_number') }}<input v-model="foreignHouseNumber" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.orientation_number') }}<input v-model="foreignOrientationNumber" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.postal_code') }}<input v-model="foreignPostalCode" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.city') }}<input v-model="foreignCity" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.foreign_sector') }}<input v-model="foreignSector" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
        </div>

        <div v-if="eventInteraction === 'cancellation'" class="mt-4 space-y-3" data-test="registration-event-a8">
          <label class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.source_submission_id') }}
            <input v-model.number="sourceSubmissionId" type="number" min="1" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" data-test="registration-event-source-submission-id" />
          </label>
          <label class="flex items-start gap-2 text-xs text-neutral-700">
            <input v-model="notStartedConfirmed" type="checkbox" class="mt-0.5 rounded border-neutral-300" data-test="registration-event-not-started" />
            <span>{{ t('payroll.people.registration.event.not_started_confirmation') }}</span>
          </label>
          <p class="text-xs text-warning-700">{{ t('payroll.people.registration.event.a8_hint') }}</p>
        </div>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="eventSaving" @click="eventFormOpen = false">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('primary')" :disabled="!eventCanSave" data-test="registration-event-save" @click="saveEvent">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ eventSaving ? t('common.loading') : t('payroll.people.registration.event.save_and_preview') }}
          </button>
        </div>
      </div>

      <p v-if="eventError" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-test="registration-event-error">
        {{ eventError }}
      </p>
    </div>

    <div
      v-if="deadline"
      class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-700"
      data-test="registration-deadline"
    >
      <p class="font-medium text-neutral-900">
        {{ agendaLabel }} · {{ interactionLabel }}
      </p>
      <p class="mt-1">
        {{ t('payroll.people.registration.window', {
          from: formatDate(deadline.earliest_registration_on),
          to: formatDate(deadline.due_on),
        }) }}
      </p>
      <p
        v-if="preview?.employer_registration"
        class="mt-1 text-warning-700"
        data-test="registration-employer-deadline"
      >
        {{ t('payroll.people.registration.employer_window', {
          to: formatDate(preview.employer_registration.due_on),
        }) }}
      </p>
    </div>

    <div
      v-if="submission"
      class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3"
      data-test="registration-prepared"
    >
      <p class="text-sm font-medium text-success-700">
        {{ t('payroll.people.registration.prepared', {
          agenda: agendaLabel,
        }) }}
      </p>
      <!--
        Záměrně NE „zaměstnanec je přihlášený": podání je připravené k odeslání
        a potvrzení od ČSSZ zatím žádné není.
      -->
      <p class="mt-1 text-xs text-success-700">
        {{ t('payroll.people.registration.not_sent_yet') }}
      </p>
      <p class="mt-1 break-all font-mono text-xs text-success-700">
        {{ submission.artifact_sha256.slice(0, 16) }}…
      </p>

      <div class="mt-3 border-t border-success-500/20 pt-3" data-test="registration-transport-actions">
        <ActionBar :actions="transportActions" />
      </div>
    </div>

    <div
      v-if="transportAttempt"
      class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
      data-test="registration-transport-result"
    >
      <p class="font-medium">
        {{ t('payroll.people.registration.attempt', {
          id: transportAttempt.id,
          status: t(`payroll.submissions.transport.status.${transportAttempt.status}`),
        }) }}
      </p>
      <p v-if="transportAttempt.status === 'awaiting_protocol'" class="mt-1 text-xs">
        {{ t('payroll.people.registration.awaiting_protocol') }}
      </p>
      <p v-if="transport?.report" class="mt-1 text-xs">
        {{ t('payroll.people.registration.protocol', {
          status: t(`payroll.submissions.transport.protocol_status.${transport.report.status}`),
          errors: transport.report.errors.length,
        }) }}
      </p>
      <p v-if="transportMessage" class="mt-1 text-xs text-success-800">
        {{ transportMessage }}
      </p>
    </div>

    <div v-if="preview && !submission" class="mt-3">
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnOutline('neutral')"
          data-test="registration-toggle-xml"
          @click="showXml = !showXml"
        >
          {{ showXml
            ? t('payroll.people.registration.hide_xml')
            : t('payroll.people.registration.show_xml') }}
        </button>
        <button
          type="button"
          :class="btnOutline('neutral')"
          @click="copyXml"
        >
          {{ t('payroll.people.registration.copy_xml') }}
        </button>
      </div>
      <pre
        v-if="showXml"
        class="mt-3 max-h-80 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100"
      >{{ preview.xml }}</pre>
      <p class="mt-2 text-xs text-neutral-500">
        {{ preview.official_submission.reason }}
      </p>
    </div>

    <p
      v-if="error"
      class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="registration-error"
    >
      {{ error }}
    </p>
  </section>
</template>
