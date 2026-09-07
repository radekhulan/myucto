import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import PdfDropzone from './PdfDropzone.vue'

describe('PdfDropzone', () => {
  it('přijme ISDOC jen v editoru nového dokladu', async () => {
    const file = new File(['<Invoice/>'], 'synthetic.isdoc', { type: 'application/xml' })
    const attachmentOnly = mount(PdfDropzone)
    await attachmentOnly.trigger('drop', { dataTransfer: { files: [file] } })

    expect(attachmentOnly.emitted('file-dropped')).toBeUndefined()
    expect(attachmentOnly.emitted('error')?.[0]?.[0]).toBe('invalid_pdf')
    expect(attachmentOnly.get('input').attributes('accept')).not.toContain('.isdoc')

    const structured = mount(PdfDropzone, { props: { acceptStructured: true } })
    await structured.trigger('drop', { dataTransfer: { files: [file] } })

    expect(structured.emitted('file-dropped')).toEqual([[file]])
    expect(structured.emitted('error')).toBeUndefined()
    expect(structured.get('input').attributes('accept')).toContain('.isdocx')
  })

  it('v dávkovém režimu vydá všechny platné soubory a jeden špatný zbytek nezahodí', async () => {
    const wrapper = mount(PdfDropzone, {
      props: { multiple: true, acceptStructured: true, extraExtensions: ['xml'] },
    })
    const pdf = new File(['%PDF-1.7'], 'a.pdf', { type: 'application/pdf' })
    const isdocXml = new File(['<Invoice/>'], 'b.xml', { type: '' })
    const rejected = new File(['MZ'], 'c.exe', { type: '' })

    await wrapper.trigger('drop', { dataTransfer: { files: [pdf, isdocXml, rejected] } })

    expect(wrapper.emitted('files-dropped')).toEqual([[[pdf, isdocXml]]])
    expect(wrapper.emitted('file-dropped')).toBeUndefined()
    expect(wrapper.emitted('error')?.[0]?.[0]).toBe('invalid_pdf')
    expect(wrapper.get('input').attributes('multiple')).toBeDefined()
  })

  it('jednosouborový režim zůstává jednosouborový i po dropu víc souborů', async () => {
    const wrapper = mount(PdfDropzone)
    const first = new File(['%PDF-1.7'], 'first.pdf', { type: 'application/pdf' })
    const second = new File(['%PDF-1.7'], 'second.pdf', { type: 'application/pdf' })

    await wrapper.trigger('drop', { dataTransfer: { files: [first, second] } })

    expect(wrapper.emitted('file-dropped')).toEqual([[first]])
    expect(wrapper.emitted('files-dropped')).toBeUndefined()
    expect(wrapper.get('input').attributes('multiple')).toBeUndefined()
  })

  it('ponechá PDF a fotografie dostupné i jako běžné přílohy', async () => {
    const wrapper = mount(PdfDropzone)
    const pdf = new File(['%PDF-1.7'], 'synthetic.pdf', { type: 'application/pdf' })
    const image = new File(['image'], 'synthetic.png', { type: 'image/png' })

    await wrapper.trigger('drop', { dataTransfer: { files: [pdf] } })
    await wrapper.trigger('drop', { dataTransfer: { files: [image] } })

    expect(wrapper.emitted('file-dropped')).toEqual([[pdf], [image]])
  })
})
