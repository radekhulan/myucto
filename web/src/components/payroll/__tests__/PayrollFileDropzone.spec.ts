import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PayrollFileDropzone from '@/components/payroll/PayrollFileDropzone.vue'

function mountDropzone(props: Record<string, unknown> = {}) {
  return mount(PayrollFileDropzone, {
    props: {
      dropHint: 'drop',
      dropActiveHint: 'drop-active',
      fileHint: 'hint',
      chooseFileText: 'choose',
      ...props,
    },
  })
}

function dropEvent(files: File[]): Partial<DragEvent> {
  return {
    preventDefault: () => {},
    dataTransfer: { files: files as unknown as FileList } as DataTransfer,
  }
}

function file(name: string, size: number): File {
  const item = new File(['x'], name)
  Object.defineProperty(item, 'size', { value: size })
  return item
}

describe('PayrollFileDropzone', () => {
  /**
   * Stránka umí říct jen obecné „Nepodporovaný soubor." — komponenta jediná
   * ví, KTERÝ soubor to byl a jaké přípony přijímá.
   */
  it('u nepodporované přípony řekne, který soubor a co se přijímá', async () => {
    const wrapper = mountDropzone()

    await wrapper.get('[role="group"]').trigger('drop', dropEvent([file('mzdy.pdf', 10)]))

    expect(wrapper.emitted('rejected')?.[0]?.[0]).toBe('unsupported_file')
    const notice = wrapper.get('[data-testid="payroll-file-notice"]').text()
    expect(notice).toContain('payroll.file_dropzone.unsupported_detail')
    expect(notice).toContain('mzdy.pdf')
    expect(notice).toContain('csv, xlsx')
    // A hlavně: cesta ven, ne jen konstatování.
    expect(wrapper.find('[data-testid="payroll-file-retry"]').exists()).toBe(true)
  })

  /** „Moc velký" bez čísel se nedá napravit: o kolik a jaký je vlastně limit? */
  it('u velkého souboru řekne jeho velikost i limit', async () => {
    const wrapper = mountDropzone({ maxSizeBytes: 1_048_576 })

    await wrapper.get('[role="group"]').trigger('drop', dropEvent([file('velky.csv', 3_145_728)]))

    expect(wrapper.emitted('rejected')?.[0]?.[0]).toBe('file_too_large')
    const notice = wrapper.get('[data-testid="payroll-file-notice"]').text()
    expect(notice).toContain('payroll.file_dropzone.too_large_detail')
    expect(notice).toContain('3 MB')
    expect(notice).toContain('1 MB')
  })

  /** Z víc přetažených souborů se bral první — beze slova. */
  it('nepřebere víc souborů mlčky', async () => {
    const wrapper = mountDropzone()

    await wrapper.get('[role="group"]').trigger('drop', dropEvent([
      file('prvni.csv', 10),
      file('druhy.csv', 10),
    ]))

    expect(wrapper.emitted('selected')).toHaveLength(1)
    const notice = wrapper.get('[data-testid="payroll-file-notice"]').text()
    expect(notice).toContain('payroll.file_dropzone.multiple')
    expect(notice).toContain('prvni.csv')
  })

  /** Přetažená složka nemá `files` — dřív se prostě nestalo NIC. */
  it('přetažení bez souboru nekončí mlčením', async () => {
    const wrapper = mountDropzone()

    await wrapper.get('[role="group"]').trigger('drop', dropEvent([]))

    expect(wrapper.emitted('selected')).toBeUndefined()
    expect(wrapper.emitted('rejected')).toBeUndefined()
    expect(wrapper.get('[data-testid="payroll-file-notice"]').text())
      .toContain('payroll.file_dropzone.no_file')
  })

  it('po úspěšném výběru už žádné hlášení nezůstává', async () => {
    const wrapper = mountDropzone()

    await wrapper.get('[role="group"]').trigger('drop', dropEvent([file('mzdy.pdf', 10)]))
    expect(wrapper.find('[data-testid="payroll-file-notice"]').exists()).toBe(true)

    await wrapper.get('[role="group"]').trigger('drop', dropEvent([file('mzdy.csv', 10)]))

    expect(wrapper.emitted('selected')).toHaveLength(1)
    expect(wrapper.find('[data-testid="payroll-file-notice"]').exists()).toBe(false)
  })
})
