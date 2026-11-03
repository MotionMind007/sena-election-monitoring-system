import http from 'k6/http';
import { check, sleep } from 'k6';
import { baseUrl, csrf, ensureStaging, thresholds } from './lib/session.js';

export const options = {
    vus: Number(__ENV.VUS || 1),
    duration: __ENV.DURATION || '30s',
    thresholds: thresholds(),
};

export default function () {
    ensureStaging();
    http.cookieJar().clear(baseUrl);
    const token = csrf('/login');
    const response = http.post(`${baseUrl}/login`, { email: __ENV.EMAIL || '', password: __ENV.PASSWORD || '' }, { redirects: 0, headers: { 'X-XSRF-TOKEN': token }, tags: { flow: 'login' } });
    check(response, { 'login redirects': (result) => result.status === 302 });
    sleep(Number(__ENV.THINK_TIME || 1));
}
