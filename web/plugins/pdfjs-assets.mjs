import { readdirSync, readFileSync } from 'node:fs'
import { join, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const DIRS = ['cmaps', 'standard_fonts', 'wasm']
const SKIP = /^(quickjs-|LICENSE)|\.wasm$/

const TYPES = {
  '.js': 'text/javascript',
}

export default function pdfjsAssets() {
  const root = fileURLToPath(new URL('../node_modules/pdfjs-dist/', import.meta.url))
  const version = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8')).version
  const base = `assets/pdfjs-${version}/`
  const files = () => DIRS.flatMap((dir) =>
    readdirSync(join(root, dir), { recursive: true, withFileTypes: true })
      .filter((entry) => entry.isFile() && !SKIP.test(entry.name))
      .map((entry) => relative(root, join(entry.parentPath, entry.name)).split(sep).join('/')),
  )

  return {
    name: 'myucto-pdfjs-assets',
    configureServer(server) {
      const known = new Set(files())
      server.middlewares.use(`/${base}`, (req, res, next) => {
        const rel = decodeURIComponent((req.url ?? '').split('?')[0]).replace(/^\/+/, '')
        if (!known.has(rel)) return next()
        const ext = rel.slice(rel.lastIndexOf('.'))
        res.setHeader('Content-Type', TYPES[ext] ?? 'application/octet-stream')
        res.end(readFileSync(join(root, rel)))
      })
    },
    generateBundle() {
      for (const rel of files()) {
        this.emitFile({ type: 'asset', fileName: base + rel, source: readFileSync(join(root, rel)) })
      }
    },
  }
}
