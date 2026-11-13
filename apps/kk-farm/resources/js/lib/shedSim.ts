/**
 * D-244: how the goats move in the 3D shed. Pure maths (no drawing), so it can be tested on its own:
 * every goat stays inside its pen, goats do not overlap, and a goat that changes pen walks out through the gate,
 * along the feeding alley and into the new pen. The drawing (Shed3D) reads positions from here every frame.
 *
 * Units are metres. X runs along the shed (the ramp is at x < 0), Z across it; the feeding alley is the strip
 * |z| <= ALLEY, with pens on both sides. A heading of 0 faces +X.
 */
export type Cls = 'doe' | 'buck' | 'grower' | 'kid';
export type Pt = { x: number; z: number };
export type Rect = { x0: number; x1: number; z0: number; z1: number };

/** Body radius, walking speed (m/s), turn rate (rad/s) and drawn size of each class. */
/** The collar and ear tag colour of each class (the legend shows the same dots). */
export const COLLAR: Record<Cls, string> = { doe: '#e0559b', buck: '#2f6fd6', grower: '#f0a020', kid: '#8b5cf6' };

export const CLASS: Record<Cls, { r: number; speed: number; turn: number; scale: number }> = {
    doe: { r: 0.25, speed: 0.55, turn: 2.6, scale: 1 },
    buck: { r: 0.3, speed: 0.5, turn: 2.2, scale: 1.2 },
    grower: { r: 0.21, speed: 0.7, turn: 3.2, scale: 0.8 },
    kid: { r: 0.16, speed: 1.05, turn: 4.5, scale: 0.55 },
};

export const TICK = 1 / 12; // behaviour runs 12 times a second; the drawing interpolates in between
export const ALLEY = 1.1; // half width of the feeding alley (fence to fence)
export const LANE = 0.45; // half width of the walking lane in the middle of the alley
const KEEP = 0.3; // goats keep to one side of the lane by direction, so two can pass
export const GATE_W = 1.0;
export const PEN_D = 4.6;
export const RAMP_LEN = 4.4;
const SLOT = 0.62; // shoulder to shoulder at the trough
const GAP = 0.02;

export type PenLayout = {
    id: number; index: number; rect: Rect; side: 1 | -1;
    /** Centre of the gate on the alley-side fence, and its ends. */
    gateX: number; gate0: number; gate1: number;
    water: { x: number; z: number; r: number };
    /** X of each eating place along the trough fence. */
    slots: number[];
};
export type Layout = { pens: PenLayout[]; byId: Map<number, number>; penW: number; width: number; south: boolean; sig: string };

const clamp = (v: number, lo: number, hi: number) => (v < lo ? lo : v > hi ? hi : v);
const wrap = (a: number) => { while (a > Math.PI) a -= 2 * Math.PI; while (a < -Math.PI) a += 2 * Math.PI; return a; };

/** Pens sit in two rows either side of the alley, in the order given: 1st north, 2nd south, 3rd north … */
export function buildLayout(pens: Array<{ id: number; capacity: number | null; goats: number }>): Layout {
    const most = Math.max(10, ...pens.map((p) => Math.max(p.capacity ?? 0, p.goats)));
    const penW = clamp((Math.ceil(most / 10) * 10 * 0.55) / PEN_D, 4.5, 12);
    const out: PenLayout[] = pens.map((p, i) => {
        const col = pens.length > 1 ? Math.floor(i / 2) : 0;
        const side: 1 | -1 = pens.length > 1 && i % 2 === 1 ? -1 : 1;
        const x0 = col * penW, x1 = x0 + penW;
        const rect = side > 0 ? { x0, x1, z0: ALLEY, z1: ALLEY + PEN_D } : { x0, x1, z0: -(ALLEY + PEN_D), z1: -ALLEY };
        const gate0 = x0 + 0.3, gate1 = gate0 + GATE_W;
        const slots: number[] = [];
        for (let x = gate1 + 0.35; x <= x1 - 0.35; x += SLOT) slots.push(x);
        return { id: p.id, index: i, rect, side, gateX: (gate0 + gate1) / 2, gate0, gate1, slots, water: { x: x1 - 0.3, z: side * (ALLEY + PEN_D - 0.3), r: 0.3 } };
    });
    const cols = Math.max(1, Math.ceil(pens.length / 2));
    return { pens: out, byId: new Map(out.map((p) => [p.id, p.index])), penW, width: (pens.length > 1 ? cols : 1) * penW, south: pens.length > 1, sig: `${pens.map((p) => p.id).join(',')}|${penW.toFixed(2)}` };
}

/** Where a goat of radius r may stand in a pen: the fence rectangle shrunk by r. */
export function walkable(p: PenLayout, r: number): Rect {
    return { x0: p.rect.x0 + r, x1: p.rect.x1 - r, z0: p.rect.z0 + r, z1: p.rect.z1 - r };
}

/** True when a point is where a walking-between-pens goat may be: a pen, its gate, the alley lane or the ramp. */
export function onRoute(l: Layout, x: number, z: number, eps = 0.02): boolean {
    if (Math.abs(z) <= LANE + eps && x >= -RAMP_LEN - eps && x <= l.width + eps) return true;
    for (const p of l.pens) {
        if (x >= p.rect.x0 - eps && x <= p.rect.x1 + eps && z >= p.rect.z0 - eps && z <= p.rect.z1 + eps) return true;
        if (x >= p.gate0 - eps && x <= p.gate1 + eps && z * p.side >= -eps && z * p.side <= ALLEY + eps) return true;
    }
    return false;
}

export type State = 'walk' | 'pause' | 'look' | 'graze' | 'lie' | 'stand' | 'eat' | 'nuzzle' | 'transit' | 'exit';
const ACTIVE: ReadonlySet<State> = new Set<State>(['walk', 'graze', 'eat', 'nuzzle', 'transit', 'exit']);
const FIXED: ReadonlySet<State> = new Set<State>(['lie', 'stand', 'transit', 'exit']);

export type Goat = {
    key: string; cls: Cls; attention: boolean; r: number;
    /** Pen index it belongs to; zone is the pen it is standing in right now (-1 in the alley or on the ramp). */
    pen: number; zone: number;
    x: number; z: number; h: number;
    /** Position and heading one update ago, the sim time of this update and its length: the drawing interpolates. */
    px: number; pz: number; ph: number; ut: number; step: number;
    v: number; state: State; timer: number; tx: number; tz: number; then: State | null;
    path: Pt[] | null; dest: number;
    phase: number; lie: number; look: number; slot: number; fedAt: number;
    mate: Goat | null; follow: boolean;
    near: number; ax: number; az: number; gone: boolean; wait: number;
};

export type SyncPen = { id: number; goats: Array<{ key: string; cls: Cls; attention: boolean }> };

function mulberry(seed: number) {
    let a = seed >>> 0;
    return () => { a = (a + 0x6d2b79f5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

export class ShedSim {
    goats: Goat[] = [];
    time = 0;
    feeding = false;
    night = false;
    private byKey = new Map<string, Goat>();
    private rnd: () => number;
    private tickNo = 0;
    private taken: Array<Array<Goat | null>>;
    private buckets: Goat[][];
    private placed = false;
    private moving: Goat[] = [];
    layout: Layout;

    constructor(layout: Layout, seed = 1) {
        this.layout = layout;
        this.rnd = mulberry(seed);
        this.taken = layout.pens.map((p) => p.slots.map(() => null));
        this.buckets = layout.pens.map(() => []);
    }

    private rand(lo: number, hi: number) { return lo + (hi - lo) * this.rnd(); }

    /**
     * Bring the goats in line with the data. The first call stands every goat in its pen. Later calls never
     * teleport: a goat whose pen changed walks there, a new goat walks in from the ramp, a removed goat walks out.
     */
    sync(pens: SyncPen[]): void {
        const seen = new Set<string>();
        const fresh: Goat[] = [];
        for (const sp of pens) {
            const idx = this.layout.byId.get(sp.id);
            if (idx === undefined) continue;
            for (const d of sp.goats) {
                seen.add(d.key);
                let g = this.byKey.get(d.key);
                if (!g) {
                    g = this.make(d.key, d.cls, d.attention, idx);
                    this.byKey.set(d.key, g);
                    this.goats.push(g);
                    if (this.placed) this.enter(g); else fresh.push(g);
                    continue;
                }
                g.cls = d.cls; g.attention = d.attention; g.r = CLASS[d.cls].r;
                const target = g.state === 'transit' ? g.dest : g.pen;
                if (target !== idx) this.send(g, idx);
            }
        }
        for (const g of this.goats) if (!seen.has(g.key) && g.state !== 'exit') this.leave(g);
        if (!this.placed) {
            for (const g of fresh) this.place(g);
            for (const p of this.layout.pens) this.relax(this.goats.filter((g) => g.pen === p.index), p, 25, true);
            for (const g of this.goats) { g.px = g.x; g.pz = g.z; }
            this.placed = true;
        }
    }

    private make(key: string, cls: Cls, attention: boolean, pen: number): Goat {
        return { key, cls, attention, r: CLASS[cls].r, pen, zone: pen, x: 0, z: 0, h: this.rand(-Math.PI, Math.PI), px: 0, pz: 0, ph: 0, ut: 0, step: TICK, v: 0, state: 'pause', timer: 0, tx: 0, tz: 0, then: null,
            path: null, dest: pen, phase: this.rand(0, 6.28), lie: 0, look: 0, slot: -1, fedAt: -1e9, mate: null, follow: false, near: 0, ax: 0, az: 0, gone: false, wait: 0 };
    }

    /** First load: a free spot in the pen, some goats already resting, every timer different. */
    private place(g: Goat): void {
        const w = walkable(this.layout.pens[g.pen], g.r);
        for (let i = 0; i < 40; i++) {
            g.x = this.rand(w.x0, w.x1); g.z = this.rand(w.z0, w.z1);
            if (this.free(g, g.x, g.z, 0.05)) break;
        }
        g.ph = g.h;
        const rest = this.night ? 0.8 : g.attention ? 0.4 : 0.2;
        if (this.rnd() < rest && !this.atGate(g)) { g.state = 'lie'; g.lie = 1; g.timer = this.night ? this.rand(30, 600) : this.rand(5, 90); }
        else if (this.rnd() < 0.45) { g.state = 'graze'; g.timer = this.rand(1, 9); }
        else { g.state = 'pause'; g.timer = this.rand(0, 3); }
    }

    private free(g: Goat, x: number, z: number, margin: number): boolean {
        const p = this.layout.pens[g.pen];
        if (Math.hypot(x - p.water.x, z - p.water.z) < p.water.r + g.r + 0.05) return false;
        for (const o of this.goats) {
            if (o === g || o.pen !== g.pen) continue;
            if (Math.hypot(o.x - x, o.z - z) < o.r + g.r + margin) return false;
            if (o.state === 'walk' && Math.hypot(o.tx - x, o.tz - z) < o.r + g.r + margin) return false;
        }
        return true;
    }

    /** Goats do not lie down in the gateway, so a goat walking in or out is never blocked. */
    private atGate(g: Goat): boolean { const p = this.layout.pens[g.pen]; return Math.hypot(g.x - p.gateX, g.z - p.side * (ALLEY + 0.5)) < 1.1; }

    private gateIn(p: PenLayout, r: number): Pt { return { x: p.gateX, z: p.side * (ALLEY + r + 0.2) }; }

    private release(g: Goat): void {
        if (g.slot >= 0) { const row = this.taken[g.pen]; if (row[g.slot] === g) row[g.slot] = null; g.slot = -1; }
        g.mate = null; g.follow = false; g.then = null;
    }

    /** Walk from where it is to another pen: own gate, alley, the other gate. */
    private send(g: Goat, to: number): void {
        this.release(g);
        const b = this.layout.pens[to];
        const path: Pt[] = [];
        const zl = b.gateX > g.x + 0.5 ? -KEEP : b.gateX < g.x - 0.5 ? KEEP : 0;
        if (g.zone >= 0) { const a = this.layout.pens[g.zone]; path.push(this.gateIn(a, g.r), { x: a.gateX, z: Math.abs(b.gateX - a.gateX) > 0.5 ? zl : 0 }); }
        else if (Math.abs(g.z) > LANE) path.push({ x: g.x, z: zl }); // in a gateway: step into the lane first
        path.push({ x: b.gateX, z: path.length ? path[path.length - 1].z : clamp(g.z, -KEEP, KEEP) }, this.gateIn(b, g.r));
        g.path = path; g.dest = to; g.state = 'transit'; g.lie = Math.min(g.lie, 0.2);
    }

    /** A new goat comes up the ramp and walks to its pen. */
    private enter(g: Goat): void {
        const b = this.layout.pens[g.pen];
        g.x = g.px = -RAMP_LEN + 0.1; g.z = g.pz = -KEEP; g.h = g.ph = 0; g.zone = -1;
        g.path = [{ x: b.gateX, z: -KEEP }, this.gateIn(b, g.r)]; g.dest = g.pen; g.state = 'transit';
    }

    /** A goat no longer in the data walks out by the ramp and is then dropped. */
    private leave(g: Goat): void {
        this.release(g);
        this.byKey.delete(g.key);
        const path: Pt[] = [];
        if (g.zone >= 0) { const a = this.layout.pens[g.zone]; path.push(this.gateIn(a, g.r), { x: a.gateX, z: KEEP }); }
        else if (Math.abs(g.z) > LANE) path.push({ x: g.x, z: KEEP });
        path.push({ x: -RAMP_LEN + 0.1, z: KEEP });
        g.path = path; g.state = 'exit'; g.lie = Math.min(g.lie, 0.2);
    }

    /** Share of goats walking, grazing, eating or interacting right now. */
    activeShare(): number {
        return this.goats.length ? this.goats.filter((g) => ACTIVE.has(g.state)).length / this.goats.length : 0;
    }

    /**
     * One behaviour step. `far(pen)` marks pens far from the camera: they update every third tick (with a
     * three times longer step), which the drawing smooths over.
     */
    tick(far?: (pen: number) => boolean): void {
        this.tickNo++;
        this.time += TICK;
        for (const b of this.buckets) b.length = 0;
        const moving: Goat[] = [];
        for (const g of this.goats) (g.state === 'transit' || g.state === 'exit' ? moving : this.buckets[g.pen]).push(g);

        this.moving = moving;
        for (const g of moving) this.travel(g, TICK);
        for (const g of moving) if (g.zone >= 0 && !g.gone) this.buckets[g.zone].push(g);

        for (const p of this.layout.pens) {
            const slowed = far ? far(p.index) : false;
            if (slowed && (this.tickNo + p.index) % 3) continue;
            const dt = slowed ? TICK * 3 : TICK;
            const list = this.buckets[p.index];
            for (const g of list) if (g.state !== 'transit' && g.state !== 'exit') this.update(g, p, dt);
            this.relax(list, p, 3, false);
        }
        if (moving.some((g) => g.gone)) this.goats = this.goats.filter((g) => !g.gone);
    }

    private mark(g: Goat, dt: number): void { g.px = g.x; g.pz = g.z; g.ph = g.h; g.ut = this.time; g.step = dt; }

    /** Between pens: straight along the route (turning on the spot first), so the path never leaves the gate or alley. */
    private travel(g: Goat, dt: number): void {
        this.mark(g, dt);
        g.lie = Math.max(0, g.lie - 1.5 * dt);
        const p = g.path![0];
        const dx = p.x - g.x, dz = p.z - g.z, d = Math.hypot(dx, dz);
        const top = CLASS[g.cls].speed * (g.attention ? 0.5 : 1) * 1.15;
        if (d > 1e-3) {
            const diff = wrap(Math.atan2(dz, dx) - g.h);
            g.h = wrap(g.h + clamp(diff, -5 * dt, 5 * dt));
            // queue behind a goat that is in the way (give up waiting after a few seconds so nobody is stuck)
            let blocked = false;
            for (const o of this.moving) {
                if (o === g || o.gone) continue;
                const ox = o.x - g.x, oz = o.z - g.z, od = Math.hypot(ox, oz);
                if (od < g.r + o.r + 0.3 && ox * Math.cos(g.h) + oz * Math.sin(g.h) > 0.5 * od) { blocked = true; break; }
            }
            g.wait = blocked ? g.wait + dt : 0;
            if (g.wait > 4) blocked = false;
            const want = blocked || g.lie > 0.05 || Math.abs(diff) > 0.5 ? 0 : top;
            g.v += clamp(want - g.v, -3 * dt, 1.2 * dt);
            const s = Math.min(d, g.v * dt);
            g.x += (dx / d) * s; g.z += (dz / d) * s;
            g.phase += (s * 9) / CLASS[g.cls].scale;
        }
        if (Math.hypot(p.x - g.x, p.z - g.z) < 0.03) {
            g.x = p.x; g.z = p.z; g.path!.shift();
            if (!g.path!.length) {
                g.path = null;
                if (g.state === 'exit') { g.gone = true; return; }
                g.pen = g.dest; g.zone = g.pen; g.state = 'pause'; g.timer = 0;
            }
        }
        g.zone = -1;
        if (Math.abs(g.z) > ALLEY) for (const pen of this.layout.pens) if (g.x >= pen.rect.x0 && g.x <= pen.rect.x1 && g.z >= pen.rect.z0 && g.z <= pen.rect.z1) { g.zone = pen.index; break; }
    }

    private update(g: Goat, pen: PenLayout, dt: number): void {
        this.mark(g, dt);
        g.timer -= dt;
        const c = CLASS[g.cls];
        const still = () => { g.v = Math.max(0, g.v - 2 * dt); g.x += Math.cos(g.h) * g.v * dt; g.z += Math.sin(g.h) * g.v * dt; };
        switch (g.state) {
            case 'walk': {
                if (g.follow && g.mate) { // a kid trailing its doe
                    if (g.mate.gone || g.mate.pen !== g.pen || g.mate.state === 'transit') { g.follow = false; g.mate = null; g.timer = 0; }
                    else { g.tx = g.mate.x - Math.cos(g.mate.h) * 0.5; g.tz = g.mate.z - Math.sin(g.mate.h) * 0.5; }
                }
                const reach = g.follow || g.then === 'nuzzle' ? 0.32 : g.then === 'eat' ? 0.15 : 0.2;
                const d = this.steer(g, g.tx, g.tz, dt, c.speed * (g.attention ? 0.45 : 1) * (this.night ? 0.7 : 1));
                if (g.follow ? g.timer <= 0 : d < reach || g.timer <= 0) this.arrive(g, pen, d < reach + 0.25);
                break;
            }
            case 'eat': {
                const sx = pen.slots[g.slot] ?? g.x, sz = pen.side * (ALLEY + g.r);
                g.v = 0; g.x += (sx - g.x) * Math.min(1, 3 * dt); g.z += (sz - g.z) * Math.min(1, 3 * dt);
                g.h = wrap(g.h + clamp(wrap(-pen.side * Math.PI / 2 - g.h), -c.turn * dt, c.turn * dt));
                if (g.timer <= 0) { g.fedAt = this.time; this.release(g); this.decide(g, pen, true); }
                break;
            }
            case 'nuzzle': {
                still();
                if (g.mate) g.h = wrap(g.h + clamp(wrap(Math.atan2(g.mate.z - g.z, g.mate.x - g.x) - g.h), -c.turn * dt, c.turn * dt));
                if (g.timer <= 0) { g.mate = null; this.decide(g, pen, false); }
                break;
            }
            case 'lie':
                still(); g.lie = Math.min(1, g.lie + 1.4 * dt);
                if (g.timer <= 0) {
                    if (this.night && this.rnd() < 0.7) { g.timer = this.rand(60, 400); g.look = this.rand(-0.6, 0.6); } // shifts and settles again
                    else { g.state = 'stand'; g.timer = 1.4; }
                }
                break;
            case 'stand':
                still(); g.lie = Math.max(0, g.lie - 0.9 * dt);
                if (g.timer <= 0 && g.lie <= 0) this.decide(g, pen, false);
                break;
            default: // pause, look, graze
                still(); g.lie = Math.max(0, g.lie - 0.9 * dt);
                if (g.timer <= 0) this.decide(g, pen, false);
        }
    }

    /** Turn toward the target (limited turn rate), speed up and slow down, steer away from close neighbours. */
    private steer(g: Goat, tx: number, tz: number, dt: number, top: number): number {
        const c = CLASS[g.cls];
        let dx = tx - g.x, dz = tz - g.z;
        const d = Math.hypot(dx, dz);
        if (d > 1e-4) { dx /= d; dz /= d; }
        const diff = wrap(Math.atan2(dz + g.az * 1.3, dx + g.ax * 1.3) - g.h);
        g.h = wrap(g.h + clamp(diff, -c.turn * dt, c.turn * dt));
        const align = Math.max(0, Math.cos(diff));
        const want = top * align * align * (0.3 + 0.7 * Math.min(1, d / 0.6)) / (1 + 0.3 * g.near);
        g.v += clamp(want - g.v, -1.6 * dt, 1.0 * dt);
        g.x += Math.cos(g.h) * g.v * dt; g.z += Math.sin(g.h) * g.v * dt;
        g.phase += (g.v * dt * 9) / c.scale;
        return d;
    }

    private arrive(g: Goat, pen: PenLayout, reached: boolean): void {
        const then = g.then;
        g.then = null; g.follow = false;
        if (then === 'eat' && reached && g.slot >= 0) { g.state = 'eat'; g.timer = this.feeding ? this.rand(25, 60) : this.rand(6, 15); return; }
        if (then === 'nuzzle' && reached && g.mate) { g.state = 'nuzzle'; g.timer = this.rand(3, 8); return; }
        this.release(g);
        this.decide(g, pen, true);
    }

    /** Pick what to do next. Timers and choices are random per goat, so no two goats move in step. */
    private decide(g: Goat, pen: PenLayout, walked: boolean): void {
        const kid = g.cls === 'kid', buck = g.cls === 'buck';
        const rest = (g.attention ? 3 : 1) * (this.night ? 14 : 1);
        const hungry = this.feeding && this.time - g.fedAt > 70 && this.taken[g.pen].some((t) => t === null);
        const w: Array<[string, number]> = [
            ['walk', (walked ? 0.12 : 0.62) * (g.attention ? 0.6 : 1) * (this.night ? 0.3 : 1)],
            ['graze', (walked ? 0.5 : 0.1) * (this.night ? 0.3 : 1)],
            ['look', walked ? 0.14 : 0.06],
            ['pause', walked ? 0.2 : 0.04],
            ['lie', g.near <= 1 && !this.atGate(g) ? 0.1 * rest : 0],
            ['nuzzle', buck || this.night ? 0 : 0.07],
            ['follow', kid && !this.night ? 0.22 : 0],
            ['trough', hungry ? 4 : this.night ? 0 : 0.07],
        ];
        let roll = this.rnd() * w.reduce((n, x) => n + x[1], 0);
        let pick = 'walk';
        for (const [name, weight] of w) { roll -= weight; if (roll <= 0) { pick = name; break; } }

        g.then = null; g.follow = false;
        if (pick === 'trough') {
            const row = this.taken[g.pen];
            let best = -1;
            for (let i = 0; i < row.length; i++) if (row[i] === null && (best < 0 || Math.abs(pen.slots[i] - g.x) < Math.abs(pen.slots[best] - g.x))) best = i;
            if (best >= 0) { row[best] = g; g.slot = best; g.state = 'walk'; g.then = 'eat'; g.tx = pen.slots[best]; g.tz = pen.side * (ALLEY + g.r); g.timer = 20; return; }
            pick = 'walk';
        }
        if (pick === 'nuzzle' || pick === 'follow') {
            let mate: Goat | null = null, bestD = pick === 'follow' ? 4 : 2.5;
            for (const o of this.buckets[g.pen]) {
                if (o === g || o.cls === 'buck' || FIXED.has(o.state) || (pick === 'follow' && o.cls !== 'doe')) continue;
                const d = Math.hypot(o.x - g.x, o.z - g.z);
                if (d < bestD) { bestD = d; mate = o; }
            }
            if (mate) {
                g.mate = mate; g.state = 'walk';
                if (pick === 'follow') { g.follow = true; g.timer = this.rand(6, 16); g.tx = mate.x; g.tz = mate.z; }
                else { g.then = 'nuzzle'; g.timer = 8; const a = Math.atan2(g.z - mate.z, g.x - mate.x); g.tx = mate.x + Math.cos(a) * (mate.r + g.r + 0.08); g.tz = mate.z + Math.sin(a) * (mate.r + g.r + 0.08); }
                return;
            }
            pick = 'walk';
        }
        if (pick === 'walk') {
            const wk = walkable(pen, g.r);
            for (let i = 0; i < 8; i++) {
                g.tx = this.rand(wk.x0, wk.x1); g.tz = this.rand(wk.z0, wk.z1);
                if (!this.free(g, g.tx, g.tz, 0.2)) continue;
                if (buck && this.buckets[g.pen].some((o) => o !== g && o.cls === 'buck' && Math.hypot(o.x - g.tx, o.z - g.tz) < 1.6)) continue; // bucks keep apart
                break;
            }
            g.state = 'walk'; g.timer = 14;
            return;
        }
        g.state = pick as State;
        g.timer = pick === 'graze' ? this.rand(4, 10) : pick === 'look' ? this.rand(1.2, 3) : pick === 'pause' ? this.rand(0.8, 2.5) : this.night ? this.rand(120, 600) : this.rand(20, 90);
        if (pick === 'look') g.look = this.rand(-0.9, 0.9);
    }

    /**
     * Keep goats apart and inside the pen. A grid of cells keeps this cheap in a crowded pen. Resting goats and
     * goats passing through do not move; the others step aside. The last thing done is always the clamp to the
     * pen, so a goat can never end a step outside its fence, in the trough or in the water point.
     */
    private relax(list: Goat[], pen: PenLayout, rounds: number, all: boolean): void {
        if (!list.length) return;
        const CELL = 0.9, cols = Math.ceil((pen.rect.x1 - pen.rect.x0) / CELL) + 2;
        const grid = new Map<number, Goat[]>();
        const key = (g: Goat) => Math.floor((g.x - pen.rect.x0) / CELL + 1) + cols * Math.floor((g.z - pen.rect.z0) / CELL + 1);
        for (const g of list) { g.near = 0; g.ax = 0; g.az = 0; const k = key(g); const cell = grid.get(k); if (cell) cell.push(g); else grid.set(k, [g]); }
        // A goat on its way in or out, while it is on the leg inside this pen, walks round the others like anyone else.
        const crossing = new Set<Goat>();
        for (const g of list) {
            if (!g.path || Math.abs(g.path[0].z) <= ALLEY) continue;
            const w = walkable(pen, g.r);
            if (g.x >= w.x0 && g.x <= w.x1 && g.z >= w.z0 && g.z <= w.z1) crossing.add(g);
        }
        const fixed = (g: Goat) => !all && (g.path ? !crossing.has(g) : FIXED.has(g.state) && (g.state !== 'stand' || g.lie > 0.3));
        const heavy = (g: Goat) => (g.state === 'eat' || g.path ? 5 : 1);
        for (let round = 0; round < rounds; round++) {
            for (const [k, cell] of grid) {
                for (const dk of [0, 1, cols - 1, cols, cols + 1]) {
                    const other = dk === 0 ? cell : grid.get(k + dk);
                    if (!other) continue;
                    for (let i = 0; i < cell.length; i++) {
                        const a = cell[i];
                        for (let j = dk === 0 ? i + 1 : 0; j < other.length; j++) {
                            const b = other[j];
                            let dx = b.x - a.x, dz = b.z - a.z, d = Math.hypot(dx, dz);
                            const min = a.r + b.r + GAP;
                            const reach = min + (a.cls === 'buck' && b.cls === 'buck' ? 0.9 : 0.35);
                            if (d >= reach) continue;
                            if (d < 1e-4) { const t = this.rnd() * 6.28; dx = Math.cos(t); dz = Math.sin(t); d = 1; } else { dx /= d; dz /= d; }
                            if (round === 0) {
                                const push = 1 - Math.hypot(b.x - a.x, b.z - a.z) / reach;
                                a.ax -= dx * push; a.az -= dz * push; b.ax += dx * push; b.az += dz * push;
                                if (Math.hypot(b.x - a.x, b.z - a.z) < min + 0.12) { a.near++; b.near++; }
                            }
                            const over = min - Math.hypot(b.x - a.x, b.z - a.z);
                            if (over <= 0) continue;
                            const fa = fixed(a), fb = fixed(b);
                            if (fa && fb) { // two resting goats that ended up touching ease apart slowly
                                if (a.path || b.path) continue;
                                const e = Math.min(over, 0.01) / 2;
                                a.x -= dx * e; a.z -= dz * e; b.x += dx * e; b.z += dz * e;
                                continue;
                            }
                            const sa = fa ? 0 : fb ? 1 : heavy(b) / (heavy(a) + heavy(b));
                            a.x -= dx * over * sa; a.z -= dz * over * sa;
                            b.x += dx * over * (1 - sa); b.z += dz * over * (1 - sa);
                        }
                    }
                }
            }
            for (const g of list) {
                if (g.path && !crossing.has(g)) continue;
                const w = walkable(pen, g.r);
                g.x = clamp(g.x, w.x0, w.x1); g.z = clamp(g.z, w.z0, w.z1);
                // the water point fills the back corner: step out of it by the shorter way
                const bx = pen.water.x - pen.water.r - g.r, bz = Math.abs(pen.water.z) - pen.water.r - g.r;
                const ox = g.x - bx, oz = Math.abs(g.z) - bz;
                if (ox > 0 && oz > 0) { if (ox < oz) g.x = bx; else g.z = pen.side * bz; }
            }
        }
    }
}

/** Farm clock "HH:MM" → is it a feeding time (a meal started in the last 40 minutes) and is it night. */
export function clockState(clock: string | null | undefined, meals: string[] = []): { feeding: boolean; night: boolean } {
    const mins = (t: string) => { const [h, m] = t.split(':').map(Number); return h * 60 + (m || 0); };
    if (!clock || !/^\d{1,2}:\d{2}/.test(clock)) return { feeding: false, night: false };
    const now = mins(clock);
    return { feeding: meals.some((t) => { const d = now - mins(t); return d >= 0 && d < 40; }), night: now < 5 * 60 + 30 || now >= 20 * 60 };
}
