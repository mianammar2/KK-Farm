/** D-293: a typed Tag ID picks the goat in any goat field. Run with: npm run test:sim */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { matchGoat, tagOf } from '../../resources/js/lib/goatMatch.ts';

const goats = [
    { value: 'a', text: 'MC-101026P-01 · Rani' },
    { value: 'b', text: 'MC-101026P-10' },
    { value: 'c', text: 'TD-141126P-03 (Buck)' },
];

test('the whole Tag ID picks its goat, in any letter case and with spaces around it', () => {
    assert.equal((matchGoat(goats, ' mc-101026p-01 ') as { value: string }).value, 'a');
    assert.equal((matchGoat(goats, 'TD-141126P-03') as { value: string }).value, 'c');
});

test('a part that fits one goat picks it; a part that fits several asks for more', () => {
    assert.equal((matchGoat(goats, 'P-03') as { value: string }).value, 'c');
    assert.equal(matchGoat(goats, 'MC-101026P'), 'many');
    assert.equal((matchGoat(goats, 'RANI') as { value: string }).value, 'a');
});

test('nothing typed or nothing found gives no goat (the field then asks the server, e.g. for an RFID chip)', () => {
    assert.equal(matchGoat(goats, ''), null);
    assert.equal(matchGoat(goats, 'E280117000000208'), null);
    assert.equal(tagOf('MC-101026P-01 · Rani'), 'MC-101026P-01');
});
