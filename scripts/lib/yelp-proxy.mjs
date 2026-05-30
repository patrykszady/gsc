/**
 * Wrap an upstream proxy URL (with embedded basic-auth credentials) in a
 * local auth-injecting forwarder so Chromium's `--proxy-server=` flag — which
 * does NOT support `user:pass@host` syntax for HTTPS-via-CONNECT and fails
 * with `ERR_PROXY_AUTH_UNSUPPORTED` — sees an auth-free target.
 *
 * Returns null when no proxy URL is supplied.
 *
 * Usage:
 *   const wrapped = await wrapProxyForChromium('http://user:pass@host:port');
 *   if (wrapped) launchArgs.push(`--proxy-server=${wrapped.localUrl}`);
 *   // wrapped.upstream  → original URL (still needed for 2captcha submission)
 *   // wrapped.localUrl  → http://127.0.0.1:RANDOM (no auth, forwards upstream)
 *   // wrapped.username, wrapped.password, wrapped.hostname, wrapped.port
 */
export async function wrapProxyForChromium(proxyUrl) {
  if (!proxyUrl) return null;
  let parsed;
  try {
    parsed = new URL(proxyUrl);
  } catch {
    return null;
  }
  const username = decodeURIComponent(parsed.username || '');
  const password = decodeURIComponent(parsed.password || '');
  const hostNoAuth = `${parsed.protocol}//${parsed.hostname}:${parsed.port}`;

  if (!username && !password) {
    return {
      upstream: proxyUrl,
      localUrl: hostNoAuth,
      host: hostNoAuth,
      hostname: parsed.hostname,
      port: parsed.port,
      username: '',
      password: '',
    };
  }

  const { anonymizeProxy } = await import('proxy-chain');
  const localUrl = await anonymizeProxy(proxyUrl);
  return {
    upstream: proxyUrl,
    localUrl,
    host: hostNoAuth,
    hostname: parsed.hostname,
    port: parsed.port,
    username,
    password,
  };
}
