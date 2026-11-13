/**
 * D-244: tests of the goat movement in the 3D shed. Run with: npm run test:sim
 * (Node runs the TypeScript directly; there is nothing to install.)
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ALLEY, buildLayout, CLASS, clockState, LANE, onRoute, RAMP_LEN, ShedSim, walkable } from '../../resources/js/lib/shedSim.ts';

type Cls = 'doe' | 'buck' | 'grower' | 'kid';
const herd = (pen: number, n: number, attention = 0) => Array.from({ length: n }, (_, i) => ({
    key: `p${pen}-g${i}`,
    cls: (i % 13 === 0 ? 'buck' : i % 4 === 1 ? 'kid' : i % 4 === 2 ? 'grower' : 'doe') as Cls,
    attention: i < attention,
}));

/** Every goat that is living in a pen (not walking between pens) must be inside it and clear of the others. */
function check(sim: ShedSim, tolerance: number): number {
    let worst = 0;
    for (const g of sim.goats) {
        if (g.state === 'transit' || g.state === 'exit') continue;
        const pen = sim.layout.pens[g.pen];
        const w = walkable(pen, g.r);
        const e = 1e-9;
        assert.ok(g.x >= w.x0 - e && g.x <= w.x1 + e && g.z >= w.z0 - e && g.z <= w.z1 + e, `${g.key} left its pen at ${g.x.toFixed(2)}, ${g.z.toFixed(2)} (${g.state})`);
        // the trough and the alley fence are at |z| <= ALLEY: the body must stay wholly on the pen side
        assert.ok(Math.abs(g.z) - g.r >= ALLEY - e, `${g.key} is in the trough or fence`);
        assert.ok(Math.hypot(g.x - pen.water.x, g.z - pen.water.z) >= pen.water.r + g.r - 0.02, `${g.key} is in the water point`);
    }
    for (let i = 0; i < sim.goats.length; i++) {
        const a = sim.goats[i];
        for (let j = i + 1; j < sim.goats.length; j++) {
            const b = sim.goats[j];
            const over = a.r + b.r - Math.hypot(a.x - b.x, a.z - b.z);
            if (over > worst) worst = over;
            assert.ok(over <= tolerance, `${a.key} (${a.state}) and ${b.key} (${b.state}) overlap by ${over.toFixed(3)} m`);
        }
    }
    return worst;
}

test('a crowded pen: 10,000 ticks, every goat stays in its pen, off the trough and fence, and clear of the others', () => {
    const layout = buildLayout([{ id: 1, capacity: 65, goats: 52 }, { id: 2, capacity: 65, goats: 8 }]);
    const sim = new ShedSim(layout, 7);
    sim.sync([{ id: 1, goats: herd(1, 52, 4) }, { id: 2, goats: herd(2, 8) }]);
    let worst = 0, active = 0;
    for (let t = 0; t < 10000; t++) {
        sim.feeding = t > 3000 && t < 5000; // a feeding time in the middle
        sim.night = t > 8000; // and the night at the end
        sim.tick();
        worst = Math.max(worst, check(sim, 0.07));
        if (t < 3000) active += sim.activeShare();
    }
    assert.equal(sim.goats.length, 60);
    const share = active / 3000;
    assert.ok(share > 0.45 && share < 0.8, `about 60% should be active by day, got ${(share * 100).toFixed(0)}%`);
    console.log(`  worst overlap ${(worst * 100).toFixed(1)} cm, active by day ${(share * 100).toFixed(0)}%`);
});

test('at feeding time goats line up at the trough; at night most lie down', () => {
    const sim = new ShedSim(buildLayout([{ id: 1, capacity: 40, goats: 30 }]), 3);
    sim.sync([{ id: 1, goats: herd(1, 30) }]);
    sim.feeding = true;
    let eating = 0;
    for (let t = 0; t < 600; t++) { sim.tick(); eating = Math.max(eating, sim.goats.filter((g) => g.state === 'eat').length); }
    const places = sim.layout.pens[0].slots.length;
    assert.ok(places >= 5 && eating >= places - 1, `only ${eating} goats ate at the ${places} places of the trough`);
    for (const g of sim.goats.filter((x) => x.state === 'eat')) assert.ok(Math.abs(Math.abs(g.z) - (ALLEY + g.r)) < 0.25, 'eaters stand at the trough fence');
    sim.feeding = false; sim.night = true;
    for (let t = 0; t < 12 * 60 * 12; t++) sim.tick();
    const lying = sim.goats.filter((g) => g.state === 'lie').length / sim.goats.length;
    assert.ok(lying > 0.6, `at night most should lie down, got ${(lying * 100).toFixed(0)}%`);
});

test('a goat moved to another pen walks out by the gate, along the alley and into the new pen', () => {
    const layout = buildLayout([1, 2, 3, 4, 5, 6].map((id) => ({ id, capacity: 30, goats: 12 })));
    const sim = new ShedSim(layout, 11);
    const data = [1, 2, 3, 4, 5, 6].map((id) => ({ id, goats: herd(id, 12) }));
    sim.sync(data);
    for (let t = 0; t < 200; t++) sim.tick();

    // move one goat from pen 1 to pen 6 (far end, other side), add a new goat to pen 3, remove one from pen 2
    const mover = data[0].goats.shift()!;
    data[5].goats.push(mover);
    data[2].goats.push({ key: 'new-goat', cls: 'doe', attention: false });
    const gone = data[1].goats.pop()!;
    sim.sync(data);

    const from = layout.pens[0], to = layout.pens[5];
    const watched = [mover.key, 'new-goat', gone.key];
    let sawAlley = false, arrived = -1;
    for (let t = 0; t < 12 * 120; t++) {
        const before = new Map(sim.goats.map((g) => [g.key, { x: g.x, z: g.z }]));
        sim.tick();
        for (const g of sim.goats) {
            if (!watched.includes(g.key)) continue;
            const was = before.get(g.key)!;
            assert.ok(Math.hypot(g.x - was.x, g.z - was.z) < 0.25, `${g.key} jumped (teleported)`);
            assert.ok(onRoute(layout, g.x, g.z), `${g.key} left the route at ${g.x.toFixed(2)}, ${g.z.toFixed(2)}`);
            if (g.key !== mover.key) continue;
            if (Math.abs(g.z) < ALLEY) { // between the two fences: only in a gateway or in the walking lane
                sawAlley = true;
                const inGate = [from, to].some((p) => g.x >= p.gate0 - 0.01 && g.x <= p.gate1 + 0.01);
                assert.ok(inGate || Math.abs(g.z) <= LANE + 0.01, `the mover crossed a fence or trough at ${g.x.toFixed(2)}, ${g.z.toFixed(2)}`);
                assert.ok(g.x + g.r <= layout.width && g.x >= -RAMP_LEN);
            }
            if (arrived < 0 && g.state !== 'transit') arrived = t;
        }
        check(sim, 0.07);
    }
    assert.ok(sawAlley, 'the mover never used the alley');
    assert.ok(arrived > 0, 'the mover never arrived');
    const g = sim.goats.find((x) => x.key === mover.key)!;
    const w = walkable(to, g.r);
    assert.equal(g.pen, to.index);
    assert.ok(g.x >= w.x0 && g.x <= w.x1 && g.z >= w.z0 && g.z <= w.z1, 'the mover is inside the new pen');
    const fresh = sim.goats.find((x) => x.key === 'new-goat')!;
    const w3 = walkable(layout.pens[2], fresh.r);
    assert.ok(fresh.state !== 'transit' && fresh.x >= w3.x0 && fresh.x <= w3.x1 && fresh.z >= w3.z0 && fresh.z <= w3.z1, 'the new goat walked in from the ramp');
    assert.ok(!sim.goats.some((x) => x.key === gone.key), 'the removed goat walked out');
    assert.equal(sim.goats.length, 72);
});

test('frame budget: 250 goats cost well under a millisecond a frame', () => {
    const sizes = [52, 48, 45, 40, 35, 30];
    const layout = buildLayout(sizes.map((n, i) => ({ id: i + 1, capacity: 65, goats: n })));
    const sim = new ShedSim(layout, 5);
    sim.sync(sizes.map((n, i) => ({ id: i + 1, goats: herd(i + 1, n, 2) })));
    assert.equal(sim.goats.length, 250);
    for (let t = 0; t < 200; t++) sim.tick(); // warm up
    const t0 = performance.now();
    for (let t = 0; t < 1200; t++) sim.tick();
    const full = (performance.now() - t0) / 1200;
    const t1 = performance.now();
    for (let t = 0; t < 1200; t++) sim.tick((pen) => pen > 1); // only two pens near the camera
    const far = (performance.now() - t1) / 1200;
    console.log(`  ${full.toFixed(3)} ms per tick, ${far.toFixed(3)} ms with far pens slowed (12 ticks a second)`);
    // 12 ticks a second: 4 ms a tick is under 5% of one 60 fps frame's time per second
    assert.ok(full < 4, `a tick took ${full.toFixed(2)} ms`);
    assert.ok(far < 4, `a tick with far pens slowed took ${far.toFixed(2)} ms`);
    check(sim, 0.07);
});

test('the farm clock gives feeding time and night', () => {
    const meals = ['07:00', '12:00', '17:00'];
    assert.deepEqual(clockState('07:10', meals), { feeding: true, night: false });
    assert.deepEqual(clockState('09:00', meals), { feeding: false, night: false });
    assert.deepEqual(clockState('23:30', meals), { feeding: false, night: true });
    assert.deepEqual(clockState(null, meals), { feeding: false, night: false });
    assert.ok(CLASS.kid.speed > CLASS.doe.speed && CLASS.buck.r > CLASS.doe.r);
});
