import http from 'k6/http';
import { check, sleep } from 'k6';
import { baseUrl, csrf, ensureStaging, login, thresholds } from './lib/session.js';

export const options = {
    vus: Number(__ENV.VUS || 2),
    iterations: Number(__ENV.ITERATIONS || 2),
    thresholds: thresholds(),
};

let authenticated = false;

export default function () {
    ensureStaging(true);
    if (!authenticated) {
        login();
        authenticated = true;
    }
    const submissionIds = (__ENV.SUBMISSION_IDS || '').split(',').filter(Boolean);
    if (submissionIds.length === 0) throw new Error('SUBMISSION_IDS wajib diisi dengan fixture staging yang belum diproses.');
    const submissionId = submissionIds[__ITER % submissionIds.length];
    const token = csrf(`/reports/${submissionId}`);
    const response = http.post(`${baseUrl}/verification/${submissionId}/decision`, {
        action: 'approve',
    }, { redirects: 0, headers: { 'X-XSRF-TOKEN': token }, tags: { flow: 'approval' } });
    check(response, { 'approval request handled': (result) => [302, 409, 422].includes(result.status) });
    sleep(Number(__ENV.THINK_TIME || 1));
}
