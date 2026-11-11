import { readFileSync, writeFileSync } from 'node:fs';
import { TOOLS } from './src/tools.mjs';
import { UPDATE_TOOL } from './src/update.mjs';
import { serializeManifest, serializeRouteManifest } from './src/api-contracts.mjs';
import { recordToolCalls } from './tool-recording.mjs';

const all = [...TOOLS, UPDATE_TOOL];
const targets = [
  [new URL('./src/tool-api.generated.json', import.meta.url), serializeManifest(all)],
  [new URL('./src/tool-routes.generated.json', import.meta.url),
    await serializeRouteManifest(all, (tool) => recordToolCalls(tool, { tolerant: true }))],
];
for (const [target, generated] of targets) {
  if (process.argv.includes('--check')) {
    let current;
    try { current = readFileSync(target, 'utf8'); } catch { /* Chybějící manifest je také neaktuální. */ }
    if (current !== generated) throw new Error('Manifest MCP neodpovídá katalogu. Spusťte npm run generate:api a přidejte výsledek do commitu.');
  } else {
    writeFileSync(target, generated);
  }
}
