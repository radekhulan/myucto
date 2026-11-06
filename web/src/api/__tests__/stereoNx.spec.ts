import { afterEach, describe, expect, it, vi } from 'vitest'

const { post, get, fetchImportJob, download } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), fetchImportJob: vi.fn(), download: vi.fn() }))
vi.mock('../client', () => ({ api: { post, get } }))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: download }))
vi.mock('../imports', () => ({ fetchImportJob }))

import { stereoNxApi } from '../stereoNx'

const job = (status: string) => ({ id: 8, status, total_items: 1, processed: 0, created_count: 0, skipped_count: 0, failed_count: 0, current_step: null, log_text: null, last_error: null })

describe('stereoNxApi.run', () => {
  it('loads countries without creating an import job and preserves the source-partner confirmations', async () => {
    post.mockResolvedValueOnce({ data: { partners: [] } })
    await expect(stereoNxApi.partnerCountries('synthetic-token', 0, false, true, { vendor: 'DE' })).resolves.toEqual([])
    expect(post).toHaveBeenLastCalledWith('/admin/imports/stereo-nx/uploads/synthetic-token/partner-countries', {
      company: 0, blank_country_is_cz: false, match_bank_account_series: true, partner_countries: { vendor: 'DE' },
    })
    expect(fetchImportJob).not.toHaveBeenCalled()
  })
  it.each(['cs', 'en'] as const)('downloads the server PDF for the exact report via the authenticated file helper (%s)', async language => {
    download.mockResolvedValue({})
    await stereoNxApi.downloadProtocol('synthetic-token', 17, language)
    expect(download).toHaveBeenCalledWith(`/admin/imports/stereo-nx/uploads/synthetic-token/runs/17/pdf?lang=${language}`, 'stereo-nx-protocol-17.pdf')
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.resetAllMocks()
  })

  it('spustí job, polluje jeho stav a po doběhnutí stáhne výsledek', async () => {
    vi.useFakeTimers()
    post.mockResolvedValueOnce({ data: { job_id: 8, mode: 'dry_run', status: 'queued' } })
    fetchImportJob.mockResolvedValueOnce(job('queued')).mockResolvedValueOnce(job('running')).mockResolvedValueOnce(job('completed'))
    get.mockResolvedValueOnce({ data: { mode: 'dry_run', report: { ok: true } } })
    const seen: string[] = []

    const done = stereoNxApi.run('a'.repeat(32), 0, 'dry_run', true, current => seen.push(current.status))
    await vi.advanceTimersByTimeAsync(4000)

    await expect(done).resolves.toEqual({ ok: true })
    expect(post).toHaveBeenCalledWith(`/admin/imports/stereo-nx/uploads/${'a'.repeat(32)}/run`, { company: 0, mode: 'dry_run', blank_country_is_cz: true, match_bank_account_series: false })
    expect(seen).toEqual(['queued', 'running', 'completed'])
    expect(get).toHaveBeenCalledWith(`/admin/imports/stereo-nx/uploads/${'a'.repeat(32)}/runs/8`)
  })

  it('sends explicit bank-series approval with a new run', async () => {
    post.mockResolvedValueOnce({ data: { job_id: 8 } })
    await expect(stereoNxApi.start('synthetic-token', 0, 'dry_run', false, true)).resolves.toBe(8)
    expect(post).toHaveBeenCalledWith('/admin/imports/stereo-nx/uploads/synthetic-token/run', {
      company: 0, mode: 'dry_run', blank_country_is_cz: false, match_bank_account_series: true,
    })
  })

  it.each(['dry_run', 'import'] as const)('sends explicit per-partner country confirmations to the %s job', async mode => {
    const countries = { 'synthetic-vendor': 'CZ', 'snapshot:purchase:synthetic': 'DE' }
    post.mockResolvedValueOnce({ data: { job_id: 8 } })
    await stereoNxApi.start('synthetic-token', 0, mode, false, false, undefined, countries)
    expect(post).toHaveBeenLastCalledWith('/admin/imports/stereo-nx/uploads/synthetic-token/run', {
      company: 0, mode, blank_country_is_cz: false, match_bank_account_series: false, partner_countries: countries,
    })
  })

  it('loads mapping suggestions with bank-series approval and sends the confirmed config unchanged to either run mode', async () => {
    const mapping = { accounts: { '501001': '501.001' }, bank_accounts: { SYN: '221.001' }, vat: { input: '343.100', output: '343.200', settlement: '343.900', unresolved: '343.999' } }
    post.mockResolvedValueOnce({ data: { ...mapping, used_accounts: [], banks: [], unresolved: [], vat_counts: { input: 0, output: 0, settlement: 0, unresolved: 0 } } })
    await stereoNxApi.accountMapping('synthetic-token', 0, true)
    expect(post).toHaveBeenLastCalledWith('/admin/imports/stereo-nx/uploads/synthetic-token/account-mapping', { company: 0, match_bank_account_series: true })
    for (const mode of ['dry_run', 'import'] as const) {
      post.mockResolvedValueOnce({ data: { job_id: 8 } })
      await stereoNxApi.start('synthetic-token', 0, mode, false, true, mapping)
      expect(post).toHaveBeenLastCalledWith('/admin/imports/stereo-nx/uploads/synthetic-token/run', {
        company: 0, mode, blank_country_is_cz: false, match_bank_account_series: true, account_mapping: mapping,
      })
    }
  })

  it('job bez výsledku vrátí chybu serveru', async () => {
    post.mockResolvedValueOnce({ data: { job_id: 8 } })
    fetchImportJob.mockResolvedValueOnce(job('failed'))
    const error = Object.assign(new Error('409'), { response: { status: 409, data: { error: { message: 'Nahraná záloha se změnila.' } } } })
    get.mockRejectedValueOnce(error)

    await expect(stereoNxApi.run('b'.repeat(32), 1, 'import', false)).rejects.toBe(error)
  })
})
