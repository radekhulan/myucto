import { readFileSync, writeFileSync } from 'node:fs';
import { TOOLS } from './src/tools.mjs';
import { UPDATE_TOOL } from './src/update.mjs';
import { serializeManifest } from './src/api-contracts.mjs';

const target = new URL('./src/tool-api.generated.json', import.meta.url);
const generated = serializeManifest([...TOOLS, UPDATE_TOOL]);
if (process.argv.includes('--check')) {
  let current;
  try { current = readFileSync(target, 'utf8'); } catch { /* Chybějící manifest je také neaktuální. */ }
  if (current !== generated) throw new Error('Manifest MCP neodpovídá katalogu. Spusťte npm run generate:api a přidejte výsledek do commitu.');
} else {
  writeFileSync(target, generated);
}
