import { VERSION } from './version.mjs';

export const RELEASE_API_URL = 'https://api.github.com/repos/radekhulan/myucto/releases/latest';

const SEMVER = /^(?:v)?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/;

class UpdateCheckError extends Error {}

function parseVersion(value, label) {
  const match = SEMVER.exec(String(value ?? '').trim());
  if (!match) {
    throw new Error(`GitHub release nemá platnou ${label} verzi.`);
  }
  const parts = match.slice(1).map(Number);
  if (!parts.every(Number.isSafeInteger)) {
    throw new Error(`GitHub release nemá platnou ${label} verzi.`);
  }
  return {
    version: `${match[1]}.${match[2]}.${match[3]}`,
    parts,
  };
}

function compareVersions(a, b) {
  for (let i = 0; i < 3; i += 1) {
    if (a[i] !== b[i]) return a[i] - b[i];
  }
  return 0;
}

function validGithubUrl(value, expectedPath) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:'
      && url.hostname === 'github.com'
      && url.port === ''
      && url.username === ''
      && url.password === ''
      && url.pathname === expectedPath
      && url.search === ''
      && url.hash === ''
      ? url.href
      : null;
  } catch {
    return null;
  }
}

export async function checkUpdate({
  currentVersion = VERSION,
  fetchImpl = globalThis.fetch,
  timeoutMs = 5_000,
} = {}) {
  const currentInfo = parseVersion(currentVersion, 'aktuální');
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetchImpl(RELEASE_API_URL, {
      headers: {
        Accept: 'application/vnd.github+json',
        'User-Agent': `myucto-mcp/${currentInfo.version}`,
        'X-GitHub-Api-Version': '2022-11-28',
      },
      signal: controller.signal,
    });

    if (!response.ok) {
      throw new UpdateCheckError(
        `Kontrolu aktualizací nelze dokončit: GitHub API vrátilo HTTP ${response.status}.`,
      );
    }

    let release;
    try {
      release = await response.json();
    } catch (error) {
      if (controller.signal.aborted) throw error;
      throw new UpdateCheckError(
        'Kontrolu aktualizací nelze dokončit: GitHub API nevrátilo platný JSON.',
      );
    }

    if (!release || release.draft !== false || release.prerelease !== false) {
      throw new UpdateCheckError('GitHub API nevrátilo poslední stabilní vydání MyÚčta.');
    }

    const latestInfo = parseVersion(release.tag_name, 'release');
    const assetName = `myucto-mcp-${latestInfo.version}.mjs`;
    const asset = Array.isArray(release.assets)
      ? release.assets.find((candidate) => candidate && candidate.name === assetName)
      : null;
    const downloadUrl = validGithubUrl(
      asset?.browser_download_url,
      `/radekhulan/myucto/releases/download/v${latestInfo.version}/${assetName}`,
    );
    const releaseUrl = validGithubUrl(
      release.html_url,
      `/radekhulan/myucto/releases/tag/v${latestInfo.version}`,
    );

    if (!downloadUrl) {
      throw new UpdateCheckError(`V release v${latestInfo.version} chybí platný asset ${assetName}.`);
    }
    if (!releaseUrl) {
      throw new UpdateCheckError(`Release v${latestInfo.version} nemá platnou veřejnou adresu.`);
    }

    return {
      current: currentInfo.version,
      latest: latestInfo.version,
      updateAvailable: compareVersions(latestInfo.parts, currentInfo.parts) > 0,
      downloadUrl,
      releaseUrl,
    };
  } catch (error) {
    if (controller.signal.aborted) {
      const duration = timeoutMs === 5_000 ? '5 sekundách' : `${timeoutMs} ms`;
      throw new UpdateCheckError(`Kontrola aktualizací vypršela po ${duration}.`);
    }
    if (error instanceof UpdateCheckError) throw error;
    throw new UpdateCheckError(`Kontrolu aktualizací nelze dokončit: ${error.message}`);
  } finally {
    clearTimeout(timeout);
  }
}

export const UPDATE_TOOL = {
  name: 'check_update',
  api: false,
  apiReason: 'Lokální kontrola vydání na GitHubu; nevolá API této firmy.',
  title: 'Zkontrolovat aktualizaci MCP serveru',
  description: 'Porovná verzi tohoto MCP serveru s posledním stabilním vydáním MyÚčta na GitHubu a vrátí odkaz na nový jednosouborový build.',
  write: false,
  inputSchema: { type: 'object', properties: {}, additionalProperties: false },
  run: () => checkUpdate(),
};
