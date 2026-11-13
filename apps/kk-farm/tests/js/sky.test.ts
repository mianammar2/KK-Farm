// D-246: the sun and the moon over the farm (run: npm run test:sim)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { farmMinutes, moonAge, pakistanMinutes, skyAt, skyView } from '../../resources/js/lib/sky.ts';

const riseSet = (d: Date) => {
    let rise = -1, set = -1;
    for (let m = 0; m < 1439; m++) {
        const a = skyAt(m, d).sunAlt, b = skyAt(m + 1, d).sunAlt;
        if (a < -0.83 && b >= -0.83) rise = m;
        if (a >= -0.83 && b < -0.83) set = m;
    }
    return [rise, set];
};

test('sunrise and sunset in Punjab are right to within 10 minutes', () => {
    const [r1, s1] = riseSet(new Date(Date.UTC(2026, 10, 13, 7))); // 13 Nov: about 6:30 and 17:09
    assert.ok(Math.abs(r1 - (6 * 60 + 30)) <= 10, `rise ${r1}`);
    assert.ok(Math.abs(s1 - (17 * 60 + 9)) <= 10, `set ${s1}`);
    const [r2, s2] = riseSet(new Date(Date.UTC(2026, 5, 21, 7))); // 21 Jun: about 5:00 and 19:10
    assert.ok(Math.abs(r2 - 300) <= 10, `rise ${r2}`);
    assert.ok(Math.abs(s2 - (19 * 60 + 10)) <= 10, `set ${s2}`);
});

test('the phases of the day, and the shed lamps from dusk to dawn', () => {
    const d = new Date(Date.UTC(2026, 10, 13, 7));
    assert.equal(skyAt(12 * 60, d).phase, 'day');
    assert.equal(skyAt(6 * 60 + 25, d).phase, 'dawn');
    assert.equal(skyAt(17 * 60 + 10, d).phase, 'dusk');
    assert.equal(skyAt(21 * 60, d).phase, 'night');
    assert.equal(skyAt(23 * 60 + 40, d).phase, 'midnight');
    assert.equal(skyAt(12 * 60, d).lampsOn, false);
    assert.equal(skyAt(21 * 60, d).lampsOn, true);
    assert.equal(skyAt(12 * 60, d).daylight, 1);
    assert.equal(skyAt(0, d).daylight, 0);
});

test('the sun rises on the left (east) and sets on the right (west) of the sky band', () => {
    const d = new Date(Date.UTC(2026, 10, 13, 7));
    const morning = skyView(skyAt(7 * 60 + 30, d)).sun!, noon = skyView(skyAt(12 * 60 + 5, d)).sun!, evening = skyView(skyAt(16 * 60 + 30, d)).sun!;
    assert.ok(morning.x < noon.x && noon.x < evening.x);
    assert.ok(noon.y < morning.y && noon.y < evening.y, 'highest at noon');
    assert.equal(skyView(skyAt(0, d)).sun, null);
    assert.ok(skyView(skyAt(0, d)).stars > 0.9);
});

test('the moon: full moon of 5 Nov 2025 is full, the new moon of 21 Oct 2025 is new', () => {
    assert.ok(Math.abs(moonAge(new Date(Date.UTC(2025, 10, 5, 13, 19))) - 0.5) < 0.03);
    const age = moonAge(new Date(Date.UTC(2025, 9, 21, 12, 25)));
    assert.ok(age < 0.03 || age > 0.97, `age ${age}`);
});

test('the farm clock wins over the device clock; without it, Pakistan time (UTC+5)', () => {
    assert.equal(farmMinutes('23:40'), 23 * 60 + 40);
    assert.equal(Math.round(pakistanMinutes(new Date(Date.UTC(2026, 0, 1, 20, 0)))), 60); // 20:00 UTC = 01:00 PKT
    assert.equal(Math.round(farmMinutes(null, new Date(Date.UTC(2026, 0, 1, 7, 30)))), 12 * 60 + 30);
});
