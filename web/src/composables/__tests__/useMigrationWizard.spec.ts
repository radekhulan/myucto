import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useMigrationWizard, type MigrationWizardRun } from '@/composables/useMigrationWizard'

const m = vi.hoisted(() => ({ fetchImportJob: vi.fn(), cancelImportJob: vi.fn(), toastError: vi.fn(), toastSuccess: vi.fn() }))

vi.mock('@/api/imports', () => ({ fetchImportJob: m.fetchImportJob, cancelImportJob: m.cancelImportJob }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

interface Upload { token: string; agendas: number[] }
interface Pending { token: string; status: 'uploading' | 'processing' | 'failed'; error: string | null }
type Run = MigrationWizardRun & { agenda_year: number }

function mountWizard(multiYear: boolean, api: Record<string, any>, extra: Record<string, unknown> = {}) {
  let wizard!: ReturnType<typeof useMigrationWizard<Upload, Pending, Run, { mode: string }>>
  const Host = defineComponent({
    setup() {
      wizard = useMigrationWizard<Upload, Pending, Run, { mode: string }>({
        api: api as any,
        tokenKey: () => 'test.migration.token',
        isReady: (u): u is Upload => 'agendas' in u,
        text: key => `src.${key}`,
        multiYear,
        ...extra,
      })
      return () => h('div')
    },
  })
  const wrapper = mount(Host)
  return { wrapper, wizard: () => wizard }
}

function api(runs: Run[]) {
  return {
    uploadChunked: vi.fn(),
    show: vi.fn(),
    start: vi.fn().mockResolvedValue({ job_id: 7, status: 'queued', mode: 'dry_run' }),
    runs: vi.fn().mockResolvedValue({ items: runs }),
    run: vi.fn(async (id: number) => runs.find(r => r.id === id)),
    deleteRun: vi.fn().mockResolvedValue({ ok: true }),
  }
}

beforeEach(() => {
  sessionStorage.clear()
  vi.resetAllMocks()
})
afterEach(() => vi.useRealTimers())

describe('useMigrationWizard', () => {
  it('u převodu víc roků načte všechny běhy jobu vzestupně a úspěch bere ze stavu jobu', async () => {
    const runs: Run[] = [
      { id: 12, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 2025 },
      { id: 11, job_id: 7, mode: 'dry_run', status: 'completed', agenda_year: 2024 },
      { id: 5, job_id: 3, mode: 'import', status: 'completed', agenda_year: 2023 },
    ]
    const client = api(runs)
    const { wizard } = mountWizard(true, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'completed_with_warnings', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(client.start).toHaveBeenCalledWith('tok', { mode: 'dry_run' })
    expect(wizard().jobRuns.value.map(r => r.id)).toEqual([11, 12])
    expect(wizard().run.value?.id).toBe(12)
    expect(wizard().dryRunPassed.value).toBe(true)
    expect(wizard().currentStep.value).toBe(3)
  })

  it('s refreshPreview po doběhnutí úlohy a při návratu na náhled načte náhled znovu beze změny kroku', async () => {
    const client = api([{ id: 11, job_id: 7, mode: 'dry_run', status: 'completed', agenda_year: 2024 }])
    client.show.mockResolvedValue({ token: 'tok', agendas: [2024, 2025] })
    const { wizard } = mountWizard(true, client, { refreshPreview: true })
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'completed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [2024] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(client.show).toHaveBeenCalledWith('tok')
    expect(wizard().upload.value?.agendas).toEqual([2024, 2025])
    expect(wizard().currentStep.value).toBe(3)
    expect(wizard().dryRunPassed.value).toBe(true)

    wizard().goTo(2)
    await flushPromises()
    expect(client.show).toHaveBeenCalledTimes(2)
  })

  it('bez refreshPreview náhled po úloze znovu nenačítá', async () => {
    const client = api([{ id: 11, job_id: 7, mode: 'dry_run', status: 'completed', agenda_year: 2024 }])
    const { wizard } = mountWizard(true, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'completed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [2024] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(client.show).not.toHaveBeenCalled()
  })

  it('u převodu jednoho běhu bere úspěch ze stavu běhu', async () => {
    const client = api([{ id: 4, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 0 }])
    const { wizard } = mountWizard(false, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'completed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(wizard().run.value?.id).toBe(4)
    expect(wizard().dryRunPassed.value).toBe(false)
  })

  it('zkouška, která selhala jen na rozdílech k přijetí, pustí ostrý převod až po zaškrtnutí a pošle accept_differences', async () => {
    const difference = { level: 'error' as const, code: 'unknown_vat_rate', text: 'Doklad FP1 nepřevzat.', context: { document_no: 'FP1' }, acceptable: true }
    const runs: Run[] = [
      { id: 21, job_id: 7, mode: 'dry_run', status: 'completed_with_warnings', agenda_year: 2024, protocol: { acceptable_only: false, steps: [] } },
      { id: 22, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 2025, protocol: { acceptable_only: true, steps: [
        { key: 'purchase_invoices', messages: [difference, { level: 'warning', code: 'number_taken', text: 'Obsazené číslo.', context: {} }] },
      ] } },
    ]
    const client = api(runs)
    const { wizard } = mountWizard(true, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'failed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(wizard().dryRunPassed.value).toBe(false)
    expect(wizard().differencesAcceptable.value).toBe(true)
    expect(wizard().differences.value).toEqual([{ ...difference, step: 'purchase_invoices', runId: 22 }])
    expect(wizard().canGoTo(4)).toBe(false)

    wizard().acceptDifferences.value = true
    expect(wizard().canGoTo(4)).toBe(true)

    client.start.mockResolvedValue({ job_id: 8, status: 'queued', mode: 'import' })
    await wizard().start('import', { mode: 'import' })
    expect(client.start).toHaveBeenLastCalledWith('tok', { mode: 'import', accept_differences: true })
  })

  it('tvrdá chyba v kterémkoli roce přijetí rozdílů nenabídne', async () => {
    const runs: Run[] = [
      { id: 31, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 2024, protocol: { acceptable_only: false, steps: [] } },
      { id: 32, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 2025, protocol: { acceptable_only: true, steps: [] } },
    ]
    const client = api(runs)
    const { wizard } = mountWizard(true, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'failed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })
    wizard().acceptDifferences.value = true

    expect(wizard().differencesAcceptable.value).toBe(false)
    expect(wizard().canGoTo(4)).toBe(false)
    await wizard().start('import', { mode: 'import' })
    expect(client.start).toHaveBeenLastCalledWith('tok', { mode: 'import' })
  })

  it('u převodu jednoho běhu bere přijatelnost rozdílů z jeho protokolu', async () => {
    const client = api([{ id: 4, job_id: 7, mode: 'dry_run', status: 'failed', agenda_year: 0, protocol: { acceptable_only: true, steps: [
      { key: 'reconciliation', messages: [{ level: 'error', code: 'reconciliation_failed', text: 'Rok 2025 nesedí (K4).', context: { year: 2025 }, acceptable: true }] },
    ] } }])
    const { wizard } = mountWizard(false, client)
    await flushPromises()
    m.fetchImportJob.mockResolvedValue({ id: 7, status: 'failed', processed: 1, total_items: 1 })
    wizard().upload.value = { token: 'tok', agendas: [] }

    await wizard().start('dry_run', { mode: 'dry_run' })

    expect(wizard().differencesAcceptable.value).toBe(true)
    expect(wizard().differences.value.map(d => d.code)).toEqual(['reconciliation_failed'])
    wizard().resetUpload()
    expect(wizard().differencesAcceptable.value).toBe(false)
    expect(wizard().differences.value).toEqual([])
  })

  it('po obnovení stránky čeká na zpracování nahraného souboru a pak přejde na náhled', async () => {
    vi.useFakeTimers()
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show
      .mockResolvedValueOnce({ token: 'tok', status: 'processing', error: null })
      .mockResolvedValueOnce({ token: 'tok', agendas: [2024] })
    const onReady = vi.fn()
    const { wizard } = mountWizard(true, client, { onReady })
    await flushPromises()
    expect(wizard().processing.value).toBe(true)
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()

    expect(onReady).toHaveBeenCalledWith({ token: 'tok', agendas: [2024] })
    expect(wizard().currentStep.value).toBe(2)
    expect(wizard().processing.value).toBe(false)
  })

  it('chybu zpracování ukáže a token zapomene', async () => {
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show.mockResolvedValueOnce({ token: 'tok', status: 'failed', error: null })
    mountWizard(true, client)
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith('src.upload_failed')
    expect(sessionStorage.getItem('test.migration.token')).toBeNull()
  })

  it('průběh zpracování na serveru předá stránce', async () => {
    vi.useFakeTimers()
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show.mockResolvedValueOnce({ token: 'tok', status: 'processing', error: null, progress: { step: 'Připravuji náhled', processed: 1, total: 3 } })
    const { wizard } = mountWizard(true, client)
    await flushPromises()

    expect(wizard().processing.value).toBe(true)
    expect(wizard().processingProgress.value).toEqual({ step: 'Připravuji náhled', processed: 1, total: 3 })
  })

  it('chyba požadavku na náhled token nezahodí a nabídne zkusit znovu', async () => {
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show
      .mockRejectedValueOnce({ response: { status: 500, data: {} } })
      .mockResolvedValueOnce({ token: 'tok', agendas: [2026] })
    const { wizard } = mountWizard(true, client, { inlineLoadError: true })
    await flushPromises()

    expect(wizard().loadError.value).toEqual({ message: 'src.preview_failed' })
    expect(wizard().processing.value).toBe(false)
    expect(sessionStorage.getItem('test.migration.token')).toBe('tok')
    expect(m.toastError).not.toHaveBeenCalled()

    await wizard().retryUpload()

    expect(client.show).toHaveBeenLastCalledWith('tok', { retry: true })
    expect(wizard().loadError.value).toBeNull()
    expect(wizard().currentStep.value).toBe(2)
  })

  it('selhání přípravy náhledu, které jde zopakovat, ukáže bez zahození exportu', async () => {
    sessionStorage.setItem('test.migration.token', 'tok')
    const client = api([])
    client.show.mockResolvedValueOnce({ token: 'tok', status: 'failed', error: 'Náhled selhal.', retryable: true })
    const { wizard } = mountWizard(true, client)
    await flushPromises()

    expect(wizard().loadError.value).toEqual({ message: 'Náhled selhal.' })
    expect(sessionStorage.getItem('test.migration.token')).toBe('tok')
    // Stránka bez vlastního bloku chyby ji dostane upozorněním.
    expect(m.toastError).toHaveBeenCalledWith('Náhled selhal.')

    wizard().abandonUpload()
    expect(wizard().loadError.value).toBeNull()
    expect(sessionStorage.getItem('test.migration.token')).toBeNull()
  })

  it('smaže protokol zkoušky po potvrzení a přehled načte znovu', async () => {
    const run: Run = { id: 9, job_id: 1, mode: 'dry_run', status: 'completed', agenda_year: 2024 }
    const client = api([run])
    const { wizard } = mountWizard(true, client, { filterRuns: (items: Run[]) => items.filter(r => r.agenda_year === 2024) })
    await flushPromises()
    vi.stubGlobal('confirm', vi.fn(() => true))
    wizard().run.value = run

    await wizard().deleteRun(run)

    expect(client.deleteRun).toHaveBeenCalledWith(9)
    expect(wizard().run.value).toBeNull()
    expect(client.runs).toHaveBeenCalledTimes(2)
    expect(m.toastSuccess).toHaveBeenCalledWith('src.run_deleted')
    vi.unstubAllGlobals()
  })
})
