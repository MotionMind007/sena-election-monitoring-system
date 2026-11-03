import http from 'k6/http';
import encoding from 'k6/encoding';
import { check, sleep } from 'k6';
import { baseUrl, csrf, ensureStaging, login, thresholds } from './lib/session.js';

export const options = {
    vus: Number(__ENV.VUS || 1),
    iterations: Number(__ENV.ITERATIONS || 1),
    thresholds: thresholds(),
};

const syntheticPng = encoding.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'std');
let authenticated = false;

export default function () {
    ensureStaging(true);
    if (!authenticated) {
        login();
        authenticated = true;
    }
    const participantIds = (__ENV.PARTICIPANT_IDS || '').split(',').filter(Boolean);
    if (!__ENV.EVENT_ID || !__ENV.CONTEST_ID || !__ENV.EVENT_TPS_ID || participantIds.length === 0) throw new Error('EVENT_ID, CONTEST_ID, EVENT_TPS_ID, dan PARTICIPANT_IDS wajib diisi.');
    const payload = {
        election_id: __ENV.EVENT_ID,
        election_contest_id: __ENV.CONTEST_ID,
        election_polling_station_id: __ENV.EVENT_TPS_ID,
        intent: 'submit',
        idempotency_key: `${__VU}-${__ITER}-${Date.now()}`,
        invalid_votes: '1',
        'evidence[0]': http.file(syntheticPng, 'synthetic-load-fixture.png', 'image/png'),
    };
    participantIds.forEach((id, index) => {
        payload[`items[${index}][participant_id]`] = id;
        payload[`items[${index}][votes]`] = String(10 + index);
    });
    if (__ENV.SOURCE_NAME) payload.source_name = __ENV.SOURCE_NAME;
    if (__ENV.SOURCE_METHOD) payload.source_method = __ENV.SOURCE_METHOD;

    const token = csrf(`/reports/create?event=${__ENV.EVENT_ID}&contest=${__ENV.CONTEST_ID}&tps=${__ENV.EVENT_TPS_ID}`);
    const response = http.post(`${baseUrl}/reports`, payload, { redirects: 0, headers: { 'X-XSRF-TOKEN': token }, tags: { flow: 'report-upload' } });
    check(response, { 'report accepted': (result) => result.status === 302 });
    sleep(Number(__ENV.THINK_TIME || 1));
}
