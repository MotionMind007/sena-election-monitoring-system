import http from 'k6/http';
import { check, sleep } from 'k6';
import { baseUrl, ensureStaging, login, thresholds } from './lib/session.js';

export const options = {
    vus: Number(__ENV.VUS || 1),
    duration: __ENV.DURATION || '30s',
    thresholds: thresholds(),
};

let authenticated = false;

export default function () {
    ensureStaging();
    if (!authenticated) {
        login();
        authenticated = true;
    }
    const parameters = [];
    if (__ENV.EVENT_ID) parameters.push(`event=${encodeURIComponent(__ENV.EVENT_ID)}`);
    if (__ENV.CONTEST_ID) parameters.push(`contest=${encodeURIComponent(__ENV.CONTEST_ID)}`);
    if (__ENV.REGION_ID) parameters.push(`region=${encodeURIComponent(__ENV.REGION_ID)}`);
    const response = http.get(`${baseUrl}/dashboard${parameters.length ? `?${parameters.join('&')}` : ''}`, { tags: { flow: 'dashboard' } });
    check(response, { 'dashboard 200': (result) => result.status === 200, 'dashboard is inertia page': (result) => result.body.includes('data-page') });
    sleep(Number(__ENV.THINK_TIME || 1));
}
