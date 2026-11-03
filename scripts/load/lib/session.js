import http from 'k6/http';
import { check, fail } from 'k6';

export const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');

export function ensureStaging(write = false) {
    if (!baseUrl || __ENV.TARGET_ENV !== 'staging') fail('BASE_URL wajib diisi dan TARGET_ENV wajib staging.');
    if (write && __ENV.ENABLE_WRITE_LOAD !== 'yes') fail('Write load memerlukan ENABLE_WRITE_LOAD=yes pada staging disposable.');
}

export function csrf(path) {
    const response = http.get(`${baseUrl}${path}`, { redirects: 0, tags: { flow: 'csrf' } });
    const cookie = response.cookies['XSRF-TOKEN']?.[0]?.value;
    check(response, { 'csrf cookie present': () => Boolean(cookie) });
    if (!cookie) fail(`Cookie CSRF tidak ditemukan pada ${path}.`);

    return decodeURIComponent(cookie);
}

export function login() {
    const token = csrf('/login');
    const response = http.post(`${baseUrl}/login`, {
        email: __ENV.EMAIL || '',
        password: __ENV.PASSWORD || '',
    }, { redirects: 0, headers: { 'X-XSRF-TOKEN': token }, tags: { flow: 'login' } });
    check(response, { 'login redirects': (result) => result.status === 302 });
    if (response.status !== 302) fail(`Login gagal dengan status ${response.status}.`);
}

export function thresholds() {
    const rules = { checks: ['rate==1'] };
    if (__ENV.P95_MS) rules.http_req_duration = [`p(95)<${Number(__ENV.P95_MS)}`];
    if (__ENV.MAX_ERROR_RATE) rules.http_req_failed = [`rate<${Number(__ENV.MAX_ERROR_RATE)}`];

    return rules;
}
