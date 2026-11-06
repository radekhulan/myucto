import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import PdfDropzone from './PdfDropzone.vue'

/**
 * Ořez fotky se v testu zastupuje: skutečný dialog potřebuje canvas a dekodér
 * obrázků. Tady jde o zapojení — co dropzona pošle do ořezu a co z něj vydá dál.
 */
const CropStub = defineComponent({
  name: 'PhotoCropDialog',
  props: {
    file: { type: Object, required: true },
    keepName: { type: Boolean, default: false },
    step: { type: Number, default: 1 },
    steps: { type: Number, default: 1 },
  },
  emits: ['done', 'cancel', 'retake'],
  template: '<div data-test="crop-stub" />',
})

const withCropStub = { global: { stubs: { PhotoCropDialog: CropStub } } }

function photo(name = 'doklad.jpg'): File {
  return new File(['photo'], name, { type: 'image/jpeg' })
}

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

  it('bez crop-photos jde fotka rovnou dál, ořez se neotevře', async () => {
    const wrapper = mount(PdfDropzone, withCropStub)
    const image = photo()

    await wrapper.trigger('drop', { dataTransfer: { files: [image] } })

    expect(wrapper.emitted('file-dropped')).toEqual([[image]])
    expect(wrapper.find('[data-test="crop-stub"]').exists()).toBe(false)
  })

  it('s crop-photos vydá fotku teprve po ořezu a nechá si základ názvu', async () => {
    const wrapper = mount(PdfDropzone, { props: { cropPhotos: true }, ...withCropStub })
    const image = photo('FA-2026-114.heic')

    await wrapper.trigger('drop', { dataTransfer: { files: [image] } })
    expect(wrapper.emitted('file-dropped')).toBeUndefined()

    const crop = wrapper.findComponent(CropStub)
    expect((crop.props('file') as File).name).toBe('FA-2026-114.heic')
    expect(crop.props('keepName')).toBe(true)

    const cropped = new File(['cropped'], 'FA-2026-114.jpg', { type: 'image/jpeg' })
    crop.vm.$emit('done', cropped)
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('file-dropped')).toEqual([[cropped]])
    expect(wrapper.find('[data-test="crop-stub"]').exists()).toBe(false)
  })

  it('v dávce jdou do ořezu jen fotky, po jedné, a výřezy se vrátí na svá místa', async () => {
    const wrapper = mount(PdfDropzone, {
      props: { cropPhotos: true, multiple: true, acceptStructured: true },
      ...withCropStub,
    })
    const first = photo('a.jpg')
    const pdf = new File(['%PDF-1.7'], 'b.pdf', { type: 'application/pdf' })
    const second = photo('c.heic')

    await wrapper.trigger('drop', { dataTransfer: { files: [first, pdf, second] } })
    expect(wrapper.emitted('files-dropped')).toBeUndefined()

    const firstCrop = wrapper.findComponent(CropStub)
    expect((firstCrop.props('file') as File).name).toBe('a.jpg')
    expect(firstCrop.props('steps')).toBe(2)
    expect(firstCrop.props('step')).toBe(1)

    const firstCropped = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
    firstCrop.vm.$emit('done', firstCropped)
    await wrapper.vm.$nextTick()

    // Druhá fotka se otevře teprve teď, PDF se do ořezu nedostane vůbec.
    const secondCrop = wrapper.findComponent(CropStub)
    expect((secondCrop.props('file') as File).name).toBe('c.heic')
    expect(secondCrop.props('step')).toBe(2)
    expect(wrapper.emitted('files-dropped')).toBeUndefined()

    const secondCropped = new File(['y'], 'c.jpg', { type: 'image/jpeg' })
    secondCrop.vm.$emit('done', secondCropped)
    await wrapper.vm.$nextTick()

    // PDF zůstalo na svém místě, fotky nahradily výřezy — dávka drží pořadí výběru.
    expect(wrapper.emitted('files-dropped')).toEqual([[[firstCropped, pdf, secondCropped]]])
  })

  it('zrušení ořezu zahodí celý výběr, nic se nenahraje', async () => {
    const wrapper = mount(PdfDropzone, {
      props: { cropPhotos: true, multiple: true },
      ...withCropStub,
    })

    await wrapper.trigger('drop', { dataTransfer: { files: [photo(), photo('b.jpg')] } })
    wrapper.findComponent(CropStub).vm.$emit('cancel')
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('files-dropped')).toBeUndefined()
    expect(wrapper.find('[data-test="crop-stub"]').exists()).toBe(false)
  })
})
