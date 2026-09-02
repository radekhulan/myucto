import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import PayrollFileDropzone from '@/components/payroll/PayrollFileDropzone.vue'

function mountDropzone(maxSizeBytes = 5_000_000) {
  return mount(PayrollFileDropzone, {
    props: {
      dropHint: 'Drop file',
      dropActiveHint: 'Release file',
      fileHint: 'CSV or XLSX',
      chooseFileText: 'Choose file',
      maxSizeBytes,
      dropzoneTestId: 'dropzone',
      inputTestId: 'input',
      selectedTestId: 'selected',
    },
  })
}

describe('PayrollFileDropzone', () => {
  it('emits an accepted file from drag and drop', async () => {
    const wrapper = mountDropzone()
    const file = new File(['employment_code\nSYN-HPP'], 'attendance.csv', {
      type: 'text/csv',
    })

    await wrapper.get('[data-testid="dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    expect(wrapper.emitted('selected')).toEqual([[file]])
    expect(wrapper.emitted('rejected')).toBeUndefined()
  })

  it('opens the native picker from the visible primary button or drop surface', async () => {
    const wrapper = mountDropzone()
    const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {})

    const button = wrapper.get('button')
    expect(button.text()).toBe('Choose file')
    expect(button.classes()).toContain('bg-primary-600')
    await button.trigger('click')
    await wrapper.get('[data-testid="dropzone"]').trigger('click')

    expect(click).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="dropzone"]').classes())
      .toContain('focus:ring-payroll-500/30')
    expect(button.attributes('aria-describedby')).toContain('payroll-file-description-')
    click.mockRestore()
  })

  it('emits the file selected in the native picker and resets the input', async () => {
    const wrapper = mountDropzone()
    const input = wrapper.get('[data-testid="input"]')
    const file = new File(['employment_code\nSYN-HPP'], 'attendance.csv', {
      type: 'text/csv',
    })

    Object.defineProperty(input.element, 'files', {
      configurable: true,
      value: [file],
    })
    await input.trigger('change')

    expect(wrapper.emitted('selected')).toEqual([[file]])
    expect((input.element as HTMLInputElement).value).toBe('')
  })

  it('shows the active payroll drop state while a file is dragged over it', async () => {
    const wrapper = mountDropzone()
    const dropzone = wrapper.get('[data-testid="dropzone"]')

    await dropzone.trigger('dragenter', {
      dataTransfer: { dropEffect: 'none' },
    })

    expect(dropzone.classes()).toContain('border-payroll-500')
    expect(dropzone.text()).toContain('Release file')

    await dropzone.trigger('dragleave')
    expect(dropzone.classes()).not.toContain('border-payroll-500')
  })

  it('rejects unsupported and oversized files before the page reads them', async () => {
    const wrapper = mountDropzone(4)
    const unsupported = new File(['data'], 'attendance.txt', { type: 'text/plain' })
    const oversized = new File(['12345'], 'attendance.csv', { type: 'text/csv' })

    await wrapper.get('[data-testid="dropzone"]').trigger('drop', {
      dataTransfer: { files: [unsupported] },
    })
    await wrapper.get('[data-testid="dropzone"]').trigger('drop', {
      dataTransfer: { files: [oversized] },
    })

    expect(wrapper.emitted('rejected')).toEqual([
      ['unsupported_file', unsupported],
      ['file_too_large', oversized],
    ])
    expect(wrapper.emitted('selected')).toBeUndefined()
  })

  it('renders a selected file and disables all interaction while busy', async () => {
    const wrapper = mount(PayrollFileDropzone, {
      props: {
        dropHint: 'Drop file',
        dropActiveHint: 'Release file',
        fileHint: 'CSV or XLSX',
        chooseFileText: 'Choose file',
        selectedFileName: 'attendance.csv',
        selectedText: 'Selected: attendance.csv',
        selectedTestId: 'selected',
        disabled: true,
      },
    })

    expect(wrapper.get('[data-testid="selected"]').text()).toBe('Selected: attendance.csv')
    expect(wrapper.attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()

    const file = new File(['data'], 'other.csv', { type: 'text/csv' })
    await wrapper.trigger('drop', { dataTransfer: { files: [file] } })
    expect(wrapper.emitted('selected')).toBeUndefined()
  })

  it('exposes a visible and accessible error state', () => {
    const wrapper = mount(PayrollFileDropzone, {
      props: {
        dropHint: 'Drop file',
        dropActiveHint: 'Release file',
        fileHint: 'CSV or XLSX',
        chooseFileText: 'Choose file',
        error: 'Unsupported file',
      },
    })

    expect(wrapper.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('[role="alert"]').text()).toBe('Unsupported file')
    expect(wrapper.classes()).toContain('border-danger-500')
  })
})
