import { stdin, stdout } from 'node:process';
import { spawn } from 'node:child_process';

import { HOSTED_MAX_ENVELOPE_BYTES } from './client.mjs';
import { decodeBody, encodeBody } from './hosted-body.mjs';
import { hostedError, runHosted } from './hosted-core.mjs';

const chunks = [];
for await (const chunk of stdin) chunks.push(chunk);

function internalFetch(input, url, options) {
  return new Promise((resolve, reject) => {
    const child = spawn(input.phpBinary, ['-d', 'opcache.file_cache=', input.apiScript], {
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    const chunks = [];
    let size = 0;
    let error = '';
    child.stdout.on('data', (chunk) => {
      size += chunk.length;
      if (size > HOSTED_MAX_ENVELOPE_BYTES) child.kill();
      else chunks.push(chunk);
    });
    child.stderr.on('data', (chunk) => { error += chunk.toString().slice(0, 1024); });
    child.on('error', reject);
    child.on('close', (code) => {
      if (code !== 0 || size > HOSTED_MAX_ENVELOPE_BYTES) {
        reject(new Error(`Interní PHP API selhalo${error ? `: ${error.slice(0, 500)}` : ''}.`));
        return;
      }
      try {
        const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        resolve(new Response(decodeBody(result), {
          status: result.status,
          headers: result.headers,
        }));
      } catch (cause) {
        reject(cause);
      }
    });
    options.signal?.addEventListener('abort', () => child.kill(), { once: true });
    child.stdin.end(JSON.stringify({
      url: String(url), method: options.method, headers: options.headers,
      ...encodeBody(options.body), serverParams: input.serverParams, mcpResource: input.mcpResource,
    }));
  });
}

let result;
try {
  const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  result = await runHosted(input, (url, options) => internalFetch(input, url, options));
} catch (error) {
  result = hostedError(error);
}
stdout.write(JSON.stringify(result));
