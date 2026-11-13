/**
 * D-244: the 3D shed, drawn like the real one: a raised steel platform with a green slatted floor, steel-rail pens
 * either side of a feeding alley with blue troughs, a green roof, white water tanks and the loading ramp.
 * The goats come from shedSim (where they are and what they are doing); this file only draws them.
 * It is loaded on demand, so pages without a shed never download three.js.
 */
import * as THREE from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { direction, farmMinutes, skyAt, skyColor, skyView, type Sky, type SkyView } from './sky';
import { ALLEY, buildLayout, CLASS, clockState, COLLAR, GATE_W, PEN_D, RAMP_LEN, ShedSim, TICK, type Cls, type Goat, type Layout, type PenLayout } from './shedSim';

export type EngineGoat = { key: string; cls: Cls; attention: boolean };
export type EnginePen = { id: number; code: string; capacity: number | null; goats: EngineGoat[] };
export type EngineData = { pens: EnginePen[]; meals: string[]; clock: string | null; selected: number | null };
export type EngineEvents = { hover(goat: string | null, pen: number | null): void; pick(goat: string | null, pen: number | null): void };

const FLOOR_H = 1.4; // the floor stands this high above the ground
const SIZE = 0.85; // drawn size against the simulation's metres
// D-245: the camera orbits a target, as in the Office 3D Model: turn (az), tilt (el), distance (x the shed's longest side).
const AZ0 = -0.62, EL0 = 0.58, DIST0 = 1.75, EL_MIN = 0.06, EL_MAX = 1.45, DIST_MIN = 0.23, DIST_MAX = 4.5, PAN_W = 0.62, PAN_D = 0.75;
const STEEL = 0xb4bdc2, DARK_STEEL = 0x7f8a90, GREEN = 0x1f7a40, BLUE = 0x2f7fd0;
const CREAMS = [0xfaf6ec, 0xf4eddd, 0xefe6d2, 0xf8f2e5, 0xece0c8, 0xf6f0e2];

type Box = { x: number; y: number; z: number; sx: number; sy: number; sz: number; color: number; rx?: number; rz?: number };
type Vis = { x: number; z: number; phase: number; move: number; lie: number; pitch: number; yaw: number; seed: number };

function tinted(g: THREE.BufferGeometry, hex: number): THREE.BufferGeometry {
    const c = new THREE.Color(hex), n = g.attributes.position.count, a = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) { a[i * 3] = c.r; a[i * 3 + 1] = c.g; a[i * 3 + 2] = c.b; }
    g.setAttribute('color', new THREE.BufferAttribute(a, 3));
    return g;
}
const box = (sx: number, sy: number, sz: number, x: number, y: number, z: number, hex = 0xffffff, rz = 0, rx = 0) => {
    const g = new THREE.BoxGeometry(sx, sy, sz);
    if (rz) g.rotateZ(rz);
    if (rx) g.rotateX(rx);
    g.translate(x, y, z);
    return tinted(g, hex);
};
const hash = (s: string) => { let h = 2166136261; for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619); return h >>> 0; };
const clamp = (v: number, lo: number, hi: number) => (v < lo ? lo : v > hi ? hi : v);

/** A soft round spot (the shadow under a goat, the red glow under one that needs attention). */
function spotTexture(inner: string, outer: string): THREE.CanvasTexture {
    const c = document.createElement('canvas'); c.width = c.height = 64;
    const g = c.getContext('2d')!, r = g.createRadialGradient(32, 32, 4, 32, 32, 32);
    r.addColorStop(0, inner); r.addColorStop(0.55, inner); r.addColorStop(1, outer);
    g.fillStyle = r; g.fillRect(0, 0, 64, 64);
    return new THREE.CanvasTexture(c);
}

function warnTexture(): THREE.CanvasTexture {
    const c = document.createElement('canvas'); c.width = c.height = 96;
    const g = c.getContext('2d')!;
    g.lineJoin = 'round'; g.lineWidth = 10; g.strokeStyle = '#ffffff'; g.fillStyle = '#e02d1b';
    g.beginPath(); g.moveTo(48, 12); g.lineTo(86, 80); g.lineTo(10, 80); g.closePath(); g.stroke(); g.fill();
    g.fillStyle = '#ffffff'; g.fillRect(44, 34, 8, 24); g.beginPath(); g.arc(48, 68, 5, 0, 6.3); g.fill();
    const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace;
    return t;
}

function slatTexture(): THREE.CanvasTexture {
    const c = document.createElement('canvas'); c.width = c.height = 64;
    const g = c.getContext('2d')!;
    g.fillStyle = '#2fa35a'; g.fillRect(0, 0, 64, 64);
    for (let y = 0; y < 64; y += 16) { g.fillStyle = '#1c6f3c'; g.fillRect(0, y, 64, 3); g.fillStyle = '#48b871'; g.fillRect(0, y + 3, 64, 2); }
    for (let x = 0; x < 64; x += 32) { g.fillStyle = 'rgba(20,80,45,.55)'; g.fillRect(x, 0, 2, 64); }
    const t = new THREE.CanvasTexture(c); t.wrapS = t.wrapT = THREE.RepeatWrapping; t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 4;
    return t;
}

export class ShedEngine {
    private renderer: THREE.WebGLRenderer;
    private scene = new THREE.Scene();
    private camera = new THREE.PerspectiveCamera(38, 1, 0.3, 400);
    private hemi = new THREE.HemisphereLight(0xffffff, 0x9aa58a, 1.25);
    private sun = new THREE.DirectionalLight(0xfff4e0, 1.5);
    private lamps: THREE.PointLight[] = [];
    // D-246: the sky over the farm: a real sun and moon (Pakistan time), stars at night, lamps on from dusk to dawn
    private moonLight = new THREE.DirectionalLight(0xb9c8ff, 0);
    private bulbs: THREE.Mesh[] = [];
    private sky: Sky | null = null; private skyAge = 99;
    private shedGroup: THREE.Group | null = null;
    private goatGroup: THREE.Group | null = null;
    private sim: ShedSim | null = null;
    private data: EngineData = { pens: [], meals: [], clock: null, selected: null };
    private signs = new Map<number, { sprite: THREE.Sprite; canvas: HTMLCanvasElement; text: string }>();
    private highlight: THREE.Mesh | null = null;
    private vis = new WeakMap<Goat, Vis>();
    private penOf = new Map<string, number>();

    private cap = 0;
    private body!: THREE.InstancedMesh; private head!: THREE.InstancedMesh; private horns!: THREE.InstancedMesh; private collar!: THREE.InstancedMesh;
    private tail!: THREE.InstancedMesh; private legs!: THREE.InstancedMesh; private shadow!: THREE.InstancedMesh; private ring!: THREE.InstancedMesh; private warn!: THREE.InstancedMesh;
    private colorsFor = -1;

    // one posed goat, reused for every goat every frame
    private root = new THREE.Object3D(); private dBody = new THREE.Object3D(); private dHead = new THREE.Object3D(); private dTail = new THREE.Object3D();
    private dLegs = [new THREE.Object3D(), new THREE.Object3D(), new THREE.Object3D(), new THREE.Object3D()];
    private m = new THREE.Matrix4(); private v3 = new THREE.Vector3(); private s3 = new THREE.Vector3(); private col = new THREE.Color();

    private az = AZ0; private el = EL0; private dist = 20; private panX = 0; private panZ = 0;
    private long = 12; private shedW = 12; private shedD = 12; private centre = new THREE.Vector3(); private target = new THREE.Vector3();
    private raf = 0; private last = 0; private acc = 0; private clock = 0; private dirty = true; private running = false;
    private frameMs = 16; private farLimit = 22; private farPen: boolean[] = [];
    private pointers = new Map<number, { x: number; y: number }>(); private dragged = false; private panning = false; private gap = 0; private mid = { x: 0, y: 0 };
    private hoverAt: { x: number; y: number } | null = null; private hoverKey: string | null = null; private hoverPen: number | null = null;
    private ro: ResizeObserver; private night = false;
    private stop: Array<() => void> = [];

    constructor(private host: HTMLElement, private events: EngineEvents, private reduced: boolean) {
        this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        const el = this.renderer.domElement;
        el.style.cssText = 'display:block;width:100%;height:100%;touch-action:none;outline:none;cursor:grab';
        host.appendChild(el);

        this.sun.position.set(-8, 14, 9);
        this.scene.add(this.hemi, this.sun, this.moonLight, this.sun.target, this.moonLight.target);
        this.dBody.add(this.dHead, this.dTail, ...this.dLegs);
        this.root.add(this.dBody);

        this.ro = new ResizeObserver(() => this.resize());
        this.ro.observe(host);
        this.resize();
        this.listen(el);
        this.updateSky();
    }

    // ---------------------------------------------------------------- data

    setData(data: EngineData): void {
        this.data = data;
        const layout = buildLayout(data.pens.map((p) => ({ id: p.id, capacity: p.capacity, goats: p.goats.length })));
        const changed = !this.sim || this.sim.layout.sig !== layout.sig;
        if (changed) this.buildShed(layout);
        // With reduced motion the goats stand still, so they are simply placed again whenever the data changes.
        if (changed || this.reduced) { this.sim = new ShedSim(layout, 20240 + data.pens.length); this.vis = new WeakMap(); }
        const sim = this.sim!;
        const state = clockState(data.clock, data.meals);
        sim.feeding = state.feeding; sim.night = state.night;
        this.night = state.night; // goats lie down at night (the clock's rule); the light follows the real sun
        this.updateSky();
        sim.sync(data.pens.map((p) => ({ id: p.id, goats: p.goats })));
        this.penOf.clear();
        for (const p of data.pens) for (const g of p.goats) this.penOf.set(g.key, p.id);
        this.colorsFor = -1;
        for (const p of data.pens) this.drawSign(p);
        this.select(data.selected);
        this.dirty = true;
    }

    private select(id: number | null): void {
        const pen = id === null ? undefined : this.sim?.layout.pens.find((p) => p.id === id);
        if (!this.highlight) return;
        this.highlight.visible = !!pen;
        if (pen) {
            this.highlight.position.set((pen.rect.x0 + pen.rect.x1) / 2, 0.012, (pen.rect.z0 + pen.rect.z1) / 2);
            this.highlight.scale.set(pen.rect.x1 - pen.rect.x0, pen.rect.z1 - pen.rect.z0, 1);
        }
    }

    /** D-246: light, sky, sun, moon, stars and lamps for the farm's time of day. */
    private updateSky(): void {
        const sky = skyAt(farmMinutes(this.data.clock));
        this.sky = sky; this.skyAge = 0;
        const [r, g, b] = skyColor(sky);
        const color = new THREE.Color().setRGB(r, g, b, THREE.SRGBColorSpace);
        this.renderer.setClearColor(color);
        if (this.scene.fog instanceof THREE.Fog) this.scene.fog.color.copy(color); else this.scene.fog = new THREE.Fog(color, this.long * 5, this.long * 14);
        const d = sky.daylight;
        const low = 1 - Math.min(1, Math.max(0, sky.sunAlt) / 25); // low sun: warmer light
        this.hemi.intensity = 0.32 + 0.95 * d;
        this.hemi.color.setRGB(0.62 + 0.38 * d, 0.7 + 0.3 * d, 1);
        this.sun.intensity = 1.6 * d;
        this.sun.color.setRGB(1, 0.95 - 0.25 * low, 0.88 - 0.42 * low);
        const moonUp = Math.max(0, Math.min(1, (sky.moonAlt + 2) / 12));
        this.moonLight.intensity = 0.45 * moonUp * (0.25 + 0.75 * sky.moonLit) * (1 - d);
        // the light comes from where the sun and the moon really are
        const aim = (light: THREE.DirectionalLight, alt: number, az: number) => {
            const [x, y, z] = direction(Math.max(alt, 4), az);
            const far = this.long * 2;
            light.position.set(this.centre.x + x * far, this.centre.y + y * far, this.centre.z + z * far);
            light.target.position.copy(this.centre);
        };
        aim(this.sun, sky.sunAlt, sky.sunAz);
        aim(this.moonLight, sky.moonAlt, sky.moonAz);
        for (const l of this.lamps) l.intensity = sky.lampsOn ? 14 : 0;
        for (const b of this.bulbs) (b.material as THREE.MeshBasicMaterial).color.set(sky.lampsOn ? 0xfff1c4 : 0x9aa09a);
        this.dirty = true;
    }

    /** What the sky shows now: the label, and the sun and moon for the sky band over the shed. */
    skyInfo(): SkyView | null {
        return this.sky ? skyView(this.sky) : null;
    }

    // ---------------------------------------------------------------- the building

    private buildShed(l: Layout): void {
        if (this.shedGroup) { this.scene.remove(this.shedGroup); disposeTree(this.shedGroup); }
        const group = this.shedGroup = new THREE.Group();
        this.signs.clear(); this.lamps = [];
        const half = ALLEY + PEN_D, W = l.width;
        const zA = l.south ? -half : -ALLEY, zB = half; // the floor runs from zA to zB
        const boxes: Box[] = [];
        const add = (x: number, y: number, z: number, sx: number, sy: number, sz: number, color: number, rot?: { rx?: number; rz?: number }) => boxes.push({ x, y, z, sx, sy, sz, color, ...rot });

        // the green slatted floor on its steel frame and stilts
        const tex = slatTexture(); tex.repeat.set((W + 0.6) / 0.8, (zB - zA + 0.6) / 0.8);
        const floor = new THREE.Mesh(new THREE.PlaneGeometry(W + 0.6, zB - zA + 0.6).rotateX(-Math.PI / 2), new THREE.MeshLambertMaterial({ map: tex }));
        floor.position.set(W / 2, 0, (zA + zB) / 2);
        group.add(floor);
        add(W / 2, -0.11, zA - 0.3, W + 0.7, 0.2, 0.1, DARK_STEEL); add(W / 2, -0.11, zB + 0.3, W + 0.7, 0.2, 0.1, DARK_STEEL);
        add(-0.3, -0.11, (zA + zB) / 2, 0.1, 0.2, zB - zA + 0.6, DARK_STEEL); add(W + 0.3, -0.11, (zA + zB) / 2, 0.1, 0.2, zB - zA + 0.6, DARK_STEEL);
        const rowsZ = l.south ? [zA - 0.25, -ALLEY, ALLEY, zB + 0.25] : [zA - 0.25, ALLEY, zB + 0.25];
        const colsX: number[] = []; for (let x = -0.25; x < W + 0.3; x += (W + 0.5) / Math.max(1, Math.round((W + 0.5) / 2.6))) colsX.push(x); colsX.push(W + 0.25);
        for (const x of colsX) for (const z of rowsZ) add(x, -FLOOR_H / 2 - 0.1, z, 0.13, FLOOR_H - 0.2, 0.13, DARK_STEEL);
        const ground = new THREE.Mesh(new THREE.CircleGeometry(Math.max(W, 20) * 3, 40).rotateX(-Math.PI / 2), new THREE.MeshLambertMaterial({ color: 0xcdbd95 }));
        ground.position.set(W / 2, -FLOOR_H, 0);
        group.add(ground);

        // steel post-and-rail fences
        const fence = (x0: number, z0: number, x1: number, z1: number, bars = false, color = STEEL) => {
            const alongX = Math.abs(x1 - x0) > Math.abs(z1 - z0), len = alongX ? Math.abs(x1 - x0) : Math.abs(z1 - z0);
            if (len < 0.05) return;
            const cx = (x0 + x1) / 2, cz = (z0 + z1) / 2;
            for (const y of bars ? [0.3, 1.0] : [0.22, 0.48, 0.74, 1.0]) add(cx, y, cz, alongX ? len : 0.035, 0.035, alongX ? 0.035 : len, color);
            const n = Math.max(1, Math.round(len / (bars ? 0.31 : 1.3)));
            for (let i = 0; i <= n; i++) {
                const t = i / n, post = !bars || i === 0 || i === n;
                add(x0 + (x1 - x0) * t, post ? 0.52 : 0.65, z0 + (z1 - z0) * t, post ? 0.06 : 0.024, post ? 1.04 : 0.7, post ? 0.06 : 0.024, color);
            }
        };
        const cols = Math.max(1, Math.round(W / l.penW));
        for (let c = 0; c < cols; c++) {
            for (const side of (l.south ? [1, -1] : [1]) as Array<1 | -1>) {
                const x0 = c * l.penW, x1 = x0 + l.penW, zf = side * ALLEY, zb = side * half;
                const pen = l.pens.find((p) => p.side === side && Math.abs(p.rect.x0 - x0) < 0.01);
                fence(x0, zb, x1, zb);
                fence(x0, zf, x0, zb);
                if (c === cols - 1) fence(x1, zf, x1, zb);
                if (!pen) { fence(x0, zf, x1, zf); continue; }
                fence(x0, zf, pen.gate0, zf);
                fence(pen.gate1, zf, x1, zf, true); // bars the goats put their heads through to eat
                for (const gx of [pen.gate0, pen.gate1]) add(gx, 0.65, zf, 0.08, 1.3, 0.08, GREEN);
                // the gate, slid open along the fence
                const gz = side * (ALLEY - 0.07);
                for (const y of [0.25, 0.6, 0.95]) add(pen.gate1 + GATE_W / 2, y, gz, GATE_W, 0.04, 0.04, GREEN);
                for (const gx of [pen.gate1 + 0.02, pen.gate1 + GATE_W - 0.02]) add(gx, 0.6, gz, 0.04, 0.78, 0.04, GREEN);
                // the blue feed trough in the alley, with fodder in it
                const t0 = pen.gate1 + GATE_W + 0.1 > x1 - 0.6 ? pen.gate1 + 0.15 : pen.gate1 + 0.15, tl = x1 - 0.1 - t0, tz = side * (ALLEY - 0.26);
                add(t0 + tl / 2, 0.36, tz, tl, 0.2, 0.32, BLUE); add(t0 + tl / 2, 0.47, tz, tl - 0.08, 0.03, 0.24, 0xc7b257);
                for (const lx of [t0 + 0.15, t0 + tl - 0.15]) add(lx, 0.13, tz, 0.06, 0.26, 0.26, DARK_STEEL);
                // the water point in the back corner
                const water = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.21, 0.32, 14), new THREE.MeshLambertMaterial({ color: 0x3f7fb6 }));
                water.position.set(pen.water.x, 0.16, pen.water.z);
                const top = new THREE.Mesh(new THREE.CircleGeometry(0.22, 14).rotateX(-Math.PI / 2), new THREE.MeshLambertMaterial({ color: 0xa9dcf5 }));
                top.position.set(pen.water.x, 0.3, pen.water.z);
                group.add(water, top);
                this.addSign(group, pen, zb);
            }
        }
        if (!l.south) fence(0, -ALLEY, W, -ALLEY);
        fence(W, -ALLEY, W, ALLEY);

        // the roof: steel columns and rafters, translucent green sheets so the goats stay in view
        const rA = zA - 0.6, rB = zB + 0.6, rM = (rA + rB) / 2, yE = 2.75, yR = yE + (rB - rA) * 0.11;
        const roofY = (z: number) => yE + (yR - yE) * (1 - Math.abs(z - rM) / (rB - rM));
        for (let c = 0; c <= cols; c++) {
            const x = c * l.penW;
            for (const z of l.south ? [zA, -ALLEY, ALLEY, zB] : [zA, ALLEY, zB]) add(x, roofY(z) / 2, z, 0.09, roofY(z), 0.09, DARK_STEEL);
            const len = Math.hypot(rM - rA, yR - yE), tilt = Math.atan2(yR - yE, rM - rA);
            add(x, (yE + yR) / 2, (rA + rM) / 2, 0.07, 0.07, len, DARK_STEEL, { rx: -tilt });
            add(x, (yE + yR) / 2, (rM + rB) / 2, 0.07, 0.07, len, DARK_STEEL, { rx: tilt });
        }
        add(W / 2, yR, rM, W + 1.2, 0.08, 0.08, DARK_STEEL);
        const roof = new THREE.BufferGeometry();
        const xa = -0.6, xb = W + 0.6;
        roof.setAttribute('position', new THREE.Float32BufferAttribute([xa, yE, rA, xb, yE, rA, xb, yR, rM, xa, yR, rM, xa, yR, rM, xb, yR, rM, xb, yE, rB, xa, yE, rB], 3));
        roof.setIndex([0, 2, 1, 0, 3, 2, 4, 6, 5, 4, 7, 6]);
        roof.computeVertexNormals();
        const sheet = new THREE.Mesh(roof, new THREE.MeshLambertMaterial({ color: 0x1fa055, transparent: true, opacity: 0.26, side: THREE.DoubleSide, depthWrite: false }));
        sheet.renderOrder = 5;
        group.add(sheet);

        // the far end wall: green below, white gable above
        const gable = new THREE.Shape([new THREE.Vector2(rA + 0.6, 1.2), new THREE.Vector2(rB - 0.6, 1.2), new THREE.Vector2(rB - 0.6, roofY(rB - 0.6)), new THREE.Vector2(rM, yR), new THREE.Vector2(rA + 0.6, roofY(rA + 0.6))]);
        const wall = new THREE.Mesh(new THREE.ShapeGeometry(gable).rotateY(-Math.PI / 2), new THREE.MeshLambertMaterial({ color: 0xf1f4ee, side: THREE.DoubleSide }));
        wall.position.x = W + 0.36;
        group.add(wall);
        add(W + 0.36, 0.6, (zA + zB) / 2, 0.06, 1.2, zB - zA, GREEN);

        // white water tanks on a steel stand, on the far side
        for (const tx of [1.4, 3.3]) {
            if (tx > W) continue;
            const tank = new THREE.Mesh(new THREE.CylinderGeometry(0.78, 0.78, 1.35, 20), new THREE.MeshLambertMaterial({ color: 0xf5f6f2 }));
            tank.position.set(tx, 0.9, zA - 1.5);
            const lid = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.42, 0.14, 14), new THREE.MeshLambertMaterial({ color: 0xe2e5df }));
            lid.position.set(tx, 1.64, zA - 1.5);
            group.add(tank, lid);
            add(tx, 0.16, zA - 1.5, 1.7, 0.1, 1.7, DARK_STEEL);
            for (const dx of [-0.75, 0.75]) for (const dz of [-0.75, 0.75]) add(tx + dx, -FLOOR_H / 2 + 0.05, zA - 1.5 + dz, 0.1, FLOOR_H + 0.1, 0.1, DARK_STEEL);
        }

        // the loading ramp: red deck, green rails
        const rl = Math.hypot(RAMP_LEN, FLOOR_H), slope = Math.atan2(FLOOR_H, RAMP_LEN);
        add(-RAMP_LEN / 2, -FLOOR_H / 2 - 0.05, 0, rl, 0.08, 1.5, 0xc23a2b, { rz: slope });
        for (const z of [-0.75, 0.75]) {
            for (const up of [0.45, 0.85]) add(-RAMP_LEN / 2, -FLOOR_H / 2 + up, z, rl, 0.045, 0.045, GREEN, { rz: slope });
            for (let i = 0; i <= 4; i++) { const x = -RAMP_LEN * (i / 4); add(x, FLOOR_H * (x / RAMP_LEN) + 0.43, z, 0.055, 0.9, 0.055, GREEN); }
        }

        const mesh = new THREE.InstancedMesh(new THREE.BoxGeometry(1, 1, 1), new THREE.MeshLambertMaterial(), boxes.length);
        const q = new THREE.Quaternion(), e = new THREE.Euler();
        boxes.forEach((b, i) => {
            e.set(b.rx ?? 0, 0, b.rz ?? 0); q.setFromEuler(e);
            mesh.setMatrixAt(i, this.m.compose(this.v3.set(b.x, b.y, b.z), q, this.s3.set(b.sx, b.sy, b.sz)));
            mesh.setColorAt(i, this.col.set(b.color));
        });
        mesh.frustumCulled = false;
        group.add(mesh);

        // the selected pen (Sheds page)
        this.highlight = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ color: 0xffd84a, transparent: true, opacity: 0.3, depthWrite: false }));
        this.highlight.rotation.x = -Math.PI / 2; this.highlight.visible = false;
        group.add(this.highlight);

        // lamps under the ridge for the night
        for (let i = 0; i < Math.min(3, cols); i++) {
            const lamp = new THREE.PointLight(0xffd9a0, 0, 14, 1.6);
            lamp.position.set(W * ((i + 0.5) / Math.min(3, cols)), yR - 0.5, rM);
            this.lamps.push(lamp); group.add(lamp);
        }
        // a bulb under each lamp: lit from dusk to dawn
        this.bulbs = this.lamps.map((lamp) => {
            const bulb = new THREE.Mesh(new THREE.SphereGeometry(0.16, 12, 8), new THREE.MeshBasicMaterial({ color: 0x9aa09a }));
            bulb.position.copy(lamp.position); group.add(bulb); return bulb;
        });

        this.scene.add(group);
        // the camera looks at the middle of the floor, at about 40% of the roof's height
        this.centre.set(W / 2, yR * 0.4, (zA + zB) / 2);
        this.shedW = W; this.shedD = zB - zA; this.long = Math.max(W, zB - zA);
        this.camera.far = this.long * 16;
        if (this.scene.fog instanceof THREE.Fog) { this.scene.fog.near = this.long * 5; this.scene.fog.far = this.long * 14; }
        this.resize();
        this.view(0);
        this.updateSky();
    }

    private addSign(group: THREE.Group, pen: PenLayout, zb: number): void {
        const canvas = document.createElement('canvas'); canvas.width = 320; canvas.height = 176;
        const tex = new THREE.CanvasTexture(canvas); tex.colorSpace = THREE.SRGBColorSpace;
        const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, depthWrite: false }));
        sprite.scale.set(2.3, 1.265, 1); sprite.position.set((pen.rect.x0 + pen.rect.x1) / 2, 2.2, zb); sprite.renderOrder = 6;
        group.add(sprite);
        this.signs.set(pen.id, { sprite, canvas, text: '' });
    }

    /** The pen's board: its code, goats of capacity, and how many need attention (the same number as the red goats). */
    private drawSign(p: EnginePen): void {
        const sign = this.signs.get(p.id);
        if (!sign) return;
        const attention = p.goats.filter((g) => g.attention).length;
        const text = `${p.code}|${p.goats.length}|${p.capacity ?? ''}|${attention}`;
        if (text === sign.text) return;
        sign.text = text;
        const g = sign.canvas.getContext('2d')!;
        g.clearRect(0, 0, 320, 176);
        const pill = (x: number, y: number, w: number, h: number, fill: string) => { g.fillStyle = fill; g.beginPath(); g.roundRect(x, y, w, h, 18); g.fill(); };
        pill(6, 6, 308, 104, '#0b5d3b');
        g.strokeStyle = 'rgba(255,255,255,.85)'; g.lineWidth = 4; g.beginPath(); g.roundRect(10, 10, 300, 96, 15); g.stroke();
        g.textBaseline = 'middle'; g.fillStyle = '#ffffff'; g.font = '800 56px system-ui, sans-serif'; g.textAlign = 'left';
        g.fillText(p.code, 26, 60, 150);
        g.textAlign = 'right'; g.font = '800 46px system-ui, sans-serif';
        const over = p.capacity !== null && p.goats.length > p.capacity;
        g.fillStyle = over ? '#ffc9c2' : '#d8f5e3';
        g.fillText(p.capacity !== null ? `${p.goats.length}/${p.capacity}` : `${p.goats.length}`, 296, 60, 130);
        if (attention) {
            pill(30, 118, 260, 52, '#d92d20');
            g.fillStyle = '#ffffff'; g.textAlign = 'center'; g.font = '700 30px system-ui, sans-serif';
            g.fillText(`${attention} need attention`, 160, 145, 240);
        }
        (sign.sprite.material.map as THREE.CanvasTexture).needsUpdate = true;
    }

    // ---------------------------------------------------------------- the goats

    private buildGoats(need: number): void {
        if (this.goatGroup) { this.scene.remove(this.goatGroup); disposeTree(this.goatGroup); }
        const cap = this.cap = Math.max(64, Math.ceil((need + 24) / 64) * 64);
        const group = this.goatGroup = new THREE.Group();
        const coat = new THREE.MeshLambertMaterial({ vertexColors: true });
        const make = (geo: THREE.BufferGeometry, mat: THREE.Material, n = cap) => {
            const mesh = new THREE.InstancedMesh(geo, mat, n);
            mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage); mesh.frustumCulled = false; mesh.count = 0;
            group.add(mesh);
            return mesh;
        };
        const bodyGeo = mergeGeometries([
            tinted(new THREE.CapsuleGeometry(0.135, 0.36, 3, 10).rotateZ(Math.PI / 2), 0xffffff),
            box(0.1, 0.06, 0.12, -0.08, -0.13, 0, 0xf3d9d2), // udder / belly
        ])!;
        const headGeo = mergeGeometries([
            box(0.2, 0.105, 0.1, 0.06, 0.06, 0, 0xffffff, 0.8), // neck
            box(0.2, 0.11, 0.115, 0.17, 0.165, 0, 0xffffff, -0.25), // head
            box(0.05, 0.06, 0.085, 0.265, 0.13, 0, 0xd3aa9c, -0.25), // muzzle
            box(0.02, 0.024, 0.122, 0.19, 0.185, 0, 0x2b2420, -0.25), // eyes
            box(0.035, 0.1, 0.05, 0.1, 0.15, 0.078, 0xdcc6a6, 0, 0.5), box(0.035, 0.1, 0.05, 0.1, 0.15, -0.078, 0xdcc6a6, 0, -0.5), // ears
        ])!;
        const horn = (z: number) => { const g = new THREE.ConeGeometry(0.024, 0.19, 6); g.rotateZ(1.0); g.translate(0.07, 0.275, z); return g; };
        const collarGeo = mergeGeometries([
            new THREE.TorusGeometry(0.075, 0.022, 6, 12).rotateY(Math.PI / 2).rotateZ(0.8).translate(0.035, 0.035, 0),
            new THREE.BoxGeometry(0.014, 0.06, 0.045).translate(0.1, 0.1, 0.108), // ear tag
        ])!;
        const legGeo = mergeGeometries([box(0.05, 0.27, 0.05, 0, -0.135, 0, 0xffffff), box(0.056, 0.04, 0.056, 0, -0.285, 0, 0x4a4038)])!;
        const flat = (size: number) => new THREE.PlaneGeometry(size, size).rotateX(-Math.PI / 2);

        this.body = make(bodyGeo, coat); this.head = make(headGeo, coat); this.legs = make(legGeo, coat, cap * 4);
        this.tail = make(box(0.04, 0.11, 0.04, 0, 0.05, 0, 0xffffff), coat);
        this.horns = make(mergeGeometries([horn(0.035), horn(-0.035)])!, new THREE.MeshLambertMaterial({ color: 0x74624c }));
        this.collar = make(collarGeo, new THREE.MeshLambertMaterial());
        this.shadow = make(flat(1), new THREE.MeshBasicMaterial({ map: spotTexture('rgba(0,0,0,.36)', 'rgba(0,0,0,0)'), transparent: true, depthWrite: false }));
        this.ring = make(flat(1), new THREE.MeshBasicMaterial({ map: spotTexture('rgba(235,40,25,.5)', 'rgba(235,40,25,0)'), transparent: true, depthWrite: false }));
        this.warn = make(new THREE.PlaneGeometry(0.34, 0.34), new THREE.MeshBasicMaterial({ map: warnTexture(), transparent: true, alphaTest: 0.3 }));
        this.shadow.renderOrder = 1; this.ring.renderOrder = 2; this.warn.renderOrder = 7;
        this.scene.add(group);
        this.colorsFor = -1;
    }

    private paint(goats: Goat[]): void {
        goats.forEach((g, i) => {
            const h = hash(g.key);
            this.col.set(h % 13 === 0 ? 0xe3d0ac : CREAMS[h % CREAMS.length]);
            this.body.setColorAt(i, this.col); this.head.setColorAt(i, this.col); this.tail.setColorAt(i, this.col);
            for (let k = 0; k < 4; k++) this.legs.setColorAt(i * 4 + k, this.col);
            this.collar.setColorAt(i, this.col.set(COLLAR[g.cls]));
        });
        for (const mesh of [this.body, this.head, this.tail, this.legs, this.collar]) if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
    }

    /** Write every goat's pose into the instance matrices. `dt` moves the animation on; `a` smooths between ticks. */
    private pose(dt: number): void {
        const sim = this.sim;
        if (!sim) return;
        const goats = sim.goats, n = goats.length;
        if (n > this.cap || !this.goatGroup) this.buildGoats(n);
        if (this.colorsFor !== n) { this.paint(goats); this.colorsFor = n; }
        const now = sim.time + this.acc, t = this.reduced ? 0 : this.clock, ease = (rate: number) => (this.reduced ? 1 : Math.min(1, dt * rate));
        let horns = 0, flagged = 0;
        for (let i = 0; i < n; i++) {
            const g = goats[i], c = CLASS[g.cls], sc = c.scale * SIZE;
            const a = this.reduced ? 1 : clamp((now - g.ut) / g.step, 0, 1);
            const x = g.px + (g.x - g.px) * a, z = g.pz + (g.z - g.pz) * a;
            let dh = g.h - g.ph; if (dh > Math.PI) dh -= 2 * Math.PI; else if (dh < -Math.PI) dh += 2 * Math.PI;
            const h = g.ph + dh * a;
            let v = this.vis.get(g);
            if (!v) { v = { x, z, phase: (hash(g.key) % 628) / 100, move: 0, lie: g.lie, pitch: 0, yaw: 0, seed: (hash(g.key) % 1000) / 100 }; this.vis.set(g, v); }
            const speed = dt > 0 ? Math.hypot(x - v.x, z - v.z) / dt : 0;
            v.x = x; v.z = z;
            const far = g.zone >= 0 ? this.farPen[g.zone] : false;
            v.move += (clamp(speed / 0.22, 0, 1) - v.move) * ease(8);
            v.phase += (speed * dt * 10) / sc;
            v.lie += (g.lie - v.lie) * ease(5);
            const lie = v.lie, moving = far ? 0 : v.move; // far from the camera: no leg animation
            const kid = g.cls === 'kid';

            // head: down to graze or eat, round to look, low when asleep
            let pitch = 0.05 * Math.sin(t * 0.8 + v.seed), yaw = 0;
            if (g.state === 'graze') pitch = -1.0 + 0.06 * Math.sin(t * 5 + v.seed);
            else if (g.state === 'eat') pitch = -0.7 + 0.07 * Math.sin(t * 6 + v.seed);
            else if (g.state === 'look') { pitch = 0.15; yaw = g.look; }
            else if (g.state === 'nuzzle') pitch = -0.25 + 0.12 * Math.sin(t * 3 + v.seed);
            else if (g.state === 'lie') { pitch = this.night ? -0.55 : -0.12; yaw = g.look * 0.5; }
            else if (g.state === 'walk' || g.state === 'transit' || g.state === 'exit') pitch = 0.07 * Math.sin(v.phase * 2) * moving;
            v.pitch += (pitch - v.pitch) * ease(5); v.yaw += (yaw - v.yaw) * ease(4);

            const onRamp = x < 0 ? FLOOR_H * Math.max(-1, x / RAMP_LEN) : 0;
            const hop = kid ? 0.05 * Math.abs(Math.sin(v.phase)) * moving : 0;
            const bodyY = 0.385 - 0.25 * lie + 0.012 * Math.abs(Math.sin(v.phase * 2)) * moving + hop;
            this.root.position.set(x, onRamp, z);
            this.root.rotation.set(0, -h, 0);
            this.root.scale.setScalar(sc);
            this.dBody.position.set(0, bodyY, 0);
            this.dBody.rotation.set(0.22 * lie * (v.seed > 5 ? 1 : -1), 0, 0.03 * Math.sin(v.phase) * moving);
            this.dHead.position.set(0.27, 0.05, 0); this.dHead.rotation.set(0, v.yaw, v.pitch);
            const flick = this.reduced || far ? 0 : Math.pow(Math.max(0, Math.sin(t * 0.9 + v.seed * 7)), 14) * Math.sin(t * 20) * 0.7;
            this.dTail.position.set(-0.3, 0.08, 0); this.dTail.rotation.set(flick, 0, 0.7 - 0.5 * lie);
            for (let k = 0; k < 4; k++) {
                const leg = this.dLegs[k], front = k < 2, left = k % 2 === 0;
                const swing = 0.55 * Math.sin(v.phase + (front === left ? 0 : Math.PI)) * moving;
                leg.position.set(front ? 0.19 : -0.19, -0.085, left ? 0.075 : -0.075);
                leg.rotation.set(0, 0, swing + (front ? 1.35 : 1.2) * lie);
                leg.scale.set(1, 1 - 0.3 * lie, 1);
            }
            this.root.updateMatrixWorld(true);
            this.body.setMatrixAt(i, this.dBody.matrixWorld);
            this.head.setMatrixAt(i, this.dHead.matrixWorld);
            this.collar.setMatrixAt(i, this.dHead.matrixWorld);
            this.tail.setMatrixAt(i, this.dTail.matrixWorld);
            for (let k = 0; k < 4; k++) this.legs.setMatrixAt(i * 4 + k, this.dLegs[k].matrixWorld);
            if (g.cls === 'buck') this.horns.setMatrixAt(horns++, this.dHead.matrixWorld);
            this.shadow.setMatrixAt(i, this.m.makeScale(sc * 1.05, 1, sc * 1.05).setPosition(x, onRamp + 0.014, z));
            if (g.attention) {
                const pulse = this.reduced ? 1 : 1 + 0.1 * Math.sin(t * 3 + v.seed);
                this.ring.setMatrixAt(flagged, this.m.makeScale(1.25 * pulse, 1, 1.25 * pulse).setPosition(x, onRamp + 0.018, z));
                this.v3.set(x, onRamp + (0.95 - 0.3 * lie) * sc + 0.3 + (this.reduced ? 0 : 0.04 * Math.sin(t * 2.4 + v.seed)), z);
                this.warn.setMatrixAt(flagged++, this.m.compose(this.v3, this.camera.quaternion, this.s3.set(1, 1, 1)));
            }
        }
        for (const mesh of [this.body, this.head, this.collar, this.tail, this.shadow]) { mesh.count = n; mesh.instanceMatrix.needsUpdate = true; }
        this.legs.count = n * 4; this.legs.instanceMatrix.needsUpdate = true;
        this.horns.count = horns; this.horns.instanceMatrix.needsUpdate = true;
        for (const mesh of [this.ring, this.warn]) { mesh.count = flagged; mesh.instanceMatrix.needsUpdate = true; }
    }

    // ---------------------------------------------------------------- frames

    start(): void {
        if (this.running) return;
        this.running = true; this.last = performance.now(); this.dirty = true;
        const loop = (time: number) => {
            if (!this.running) return;
            this.raf = requestAnimationFrame(loop);
            const dt = Math.min(0.1, (time - this.last) / 1000);
            this.last = time;
            this.frame(dt);
        };
        this.raf = requestAnimationFrame(loop);
    }

    pause(): void { this.running = false; cancelAnimationFrame(this.raf); }

    private frame(dt: number): void {
        const sim = this.sim;
        if (!sim) return;
        if (this.reduced) { // static poses: draw only when something changed
            if (!this.dirty && !this.hoverAt) return;
        } else {
            // keep the frame cheap: when frames run slow, pens further away update less often
            this.frameMs += (dt * 1000 - this.frameMs) * 0.05;
            if (this.frameMs > 24) this.farLimit = Math.max(5, this.farLimit * 0.985); else if (this.frameMs < 18) this.farLimit = Math.min(60, this.farLimit * 1.01);
            this.clock += dt; this.acc += dt;
            let steps = 0;
            while (this.acc >= TICK && steps < 4) { sim.tick((pen) => this.farPen[pen]); this.acc -= TICK; steps++; }
            if (this.acc >= TICK) this.acc = 0;
        }
        this.skyAge += dt;
        if (this.skyAge > 20) this.updateSky();
        const pens = sim.layout.pens;
        this.farPen.length = pens.length;
        for (let i = 0; i < pens.length; i++) {
            const r = pens[i].rect;
            this.farPen[i] = this.camera.position.distanceTo(this.v3.set((r.x0 + r.x1) / 2, 0, (r.z0 + r.z1) / 2)) > this.farLimit;
        }
        this.pose(dt);
        if (this.hoverAt) this.hover();
        this.renderer.render(this.scene, this.camera);
        this.dirty = false;
    }

    /** Every input ends here: keep the camera inside its limits, put it in place and ask for a new picture. */
    private apply(): void {
        this.el = clamp(this.el, EL_MIN, EL_MAX); // never under the floor, never over the top
        this.dist = clamp(this.dist, DIST_MIN * this.long, DIST_MAX * this.long);
        this.panX = clamp(this.panX, -PAN_W * this.shedW, PAN_W * this.shedW); // the shed cannot be lost off-screen
        this.panZ = clamp(this.panZ, -PAN_D * this.shedD, PAN_D * this.shedD);
        this.target.set(this.centre.x + this.panX, this.centre.y, this.centre.z + this.panZ);
        const ce = Math.cos(this.el);
        this.camera.position.set(this.target.x - this.dist * ce * Math.sin(this.az), this.target.y + this.dist * Math.sin(this.el), this.target.z + this.dist * ce * Math.cos(this.az));
        this.camera.lookAt(this.target);
        this.camera.updateMatrixWorld();
        this.dirty = true;
    }

    private resize(): void {
        const w = Math.max(1, this.host.clientWidth), h = Math.max(1, this.host.clientHeight);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        this.renderer.setSize(w, h, false);
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
        this.apply();
    }

    /** The buttons: closer (+1), further (-1) or back to the opening view (0). */
    view(step: number): void {
        if (step === 0) { this.az = AZ0; this.el = EL0; this.dist = DIST0 * this.long; this.panX = 0; this.panZ = 0; } else this.dist *= step > 0 ? 0.8 : 1.25;
        this.apply();
    }

    /** Slide the target along the floor by a drag of (dx, dy) pixels, as seen from the camera. Further away slides faster. */
    private pan(dx: number, dy: number): void {
        const k = (this.dist * 2 * Math.tan((this.camera.fov * Math.PI) / 360)) / Math.max(1, this.host.clientHeight);
        const sin = Math.sin(this.az), cos = Math.cos(this.az); // right = (cos, sin), forward = (sin, -cos)
        this.panX += (-dx * cos + dy * sin) * k;
        this.panZ += (-dx * sin - dy * cos) * k;
    }

    // ---------------------------------------------------------------- pointer

    private listen(el: HTMLCanvasElement): void {
        const on = <K extends keyof HTMLElementEventMap>(type: K, fn: (e: HTMLElementEventMap[K]) => void, opts?: AddEventListenerOptions) => {
            el.addEventListener(type, fn as EventListener, opts);
            this.stop.push(() => el.removeEventListener(type, fn as EventListener, opts));
        };
        // two fingers: how far apart they are, and their middle
        const pair = () => {
            const p = [...this.pointers.values()];
            if (p.length < 2) { this.gap = 0; return; }
            this.gap = Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y);
            this.mid = { x: (p[0].x + p[1].x) / 2, y: (p[0].y + p[1].y) / 2 };
        };
        on('pointerdown', (e) => {
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (this.pointers.size === 1) { this.dragged = false; this.panning = e.button === 2 || e.button === 1 || e.shiftKey; }
            pair();
            this.hoverAt = null;
            el.style.cursor = 'grabbing';
            try { el.setPointerCapture(e.pointerId); } catch { /* not capturable */ }
        });
        on('pointermove', (e) => {
            const p = this.pointers.get(e.pointerId);
            if (!p) { if (e.pointerType === 'mouse') this.hoverAt = { x: e.clientX, y: e.clientY }; return; }
            const dx = e.clientX - p.x, dy = e.clientY - p.y;
            if (this.pointers.size > 1) { // two fingers: pinch zooms, moving together pans
                p.x = e.clientX; p.y = e.clientY;
                const gap = this.gap, mid = this.mid;
                pair();
                if (gap > 0 && this.gap > 0) this.dist *= gap / this.gap;
                this.pan(this.mid.x - mid.x, this.mid.y - mid.y);
                this.dragged = true;
                this.apply();
                return;
            }
            if (!this.dragged && Math.hypot(dx, dy) < 3) return; // a click, not a drag
            p.x = e.clientX; p.y = e.clientY;
            this.dragged = true;
            if (this.panning) this.pan(dx, dy); else { this.az += dx * 0.008; this.el += dy * 0.006; }
            this.apply();
        });
        const up = (e: PointerEvent) => {
            const had = this.pointers.delete(e.pointerId);
            pair();
            if (this.pointers.size === 0) el.style.cursor = 'grab';
            if (had && !this.dragged && e.type === 'pointerup' && e.button === 0 && this.pointers.size === 0) {
                const hit = this.pickAt(e.clientX, e.clientY);
                this.events.pick(hit.goat, hit.pen);
            }
        };
        on('pointerup', up); on('pointercancel', up);
        on('pointerleave', () => { this.hoverAt = null; this.setHover(null, null); });
        on('contextmenu', (e) => e.preventDefault()); // right-drag pans
        on('wheel', (e) => { // zoom by a percentage for each notch; the page does not scroll
            e.preventDefault();
            const notches = e.deltaMode === 0 ? clamp(e.deltaY / 100, -1, 1) : Math.sign(e.deltaY);
            this.dist *= Math.pow(1.1, notches);
            this.apply();
        }, { passive: false });
    }

    private ray = new THREE.Raycaster(); private ndc = new THREE.Vector2(); private plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), -0.3); private hit = new THREE.Vector3();

    private pickAt(cx: number, cy: number): { goat: string | null; pen: number | null } {
        const sim = this.sim, r = this.renderer.domElement.getBoundingClientRect();
        if (!sim || !r.width) return { goat: null, pen: null };
        this.ndc.set(((cx - r.left) / r.width) * 2 - 1, -((cy - r.top) / r.height) * 2 + 1);
        this.ray.setFromCamera(this.ndc, this.camera);
        if (!this.ray.ray.intersectPlane(this.plane, this.hit)) return { goat: null, pen: null };
        let goat: string | null = null, best = 0.42;
        for (const g of sim.goats) { const d = Math.hypot(g.x - this.hit.x, g.z - this.hit.z); if (d < best) { best = d; goat = g.key; } }
        let pen: number | null = goat ? this.penOf.get(goat) ?? null : null;
        if (pen === null) for (const p of sim.layout.pens) if (this.hit.x >= p.rect.x0 && this.hit.x <= p.rect.x1 && this.hit.z >= p.rect.z0 && this.hit.z <= p.rect.z1) pen = p.id;
        return { goat: goat && this.penOf.has(goat) ? goat : null, pen };
    }

    private hover(): void {
        const at = this.hoverAt!;
        const hit = this.pickAt(at.x, at.y);
        if (this.reduced) this.hoverAt = null;
        this.setHover(hit.goat, hit.pen);
    }

    private setHover(goat: string | null, pen: number | null): void {
        if (goat === this.hoverKey && pen === this.hoverPen) return;
        this.hoverKey = goat; this.hoverPen = pen;
        this.events.hover(goat, pen);
    }

    /** For checks: how many goats are drawn and how many carry the warning sign. */
    stats(): { goats: number; attention: number; moving: number; frameMs: number } {
        const goats = this.sim?.goats ?? [];
        return { goats: goats.length, attention: goats.filter((g) => g.attention).length, moving: Math.round((this.sim?.activeShare() ?? 0) * 100), frameMs: Math.round(this.frameMs * 10) / 10 };
    }

    dispose(): void {
        this.pause();
        this.ro.disconnect();
        for (const off of this.stop) off();
        this.events.hover(null, null);
        disposeTree(this.scene);
        this.renderer.dispose();
        this.renderer.forceContextLoss();
        this.renderer.domElement.remove();
    }
}

function disposeTree(root: THREE.Object3D): void {
    root.traverse((o) => {
        const mesh = o as THREE.Mesh & { material?: THREE.Material | THREE.Material[] };
        mesh.geometry?.dispose?.();
        for (const mat of Array.isArray(mesh.material) ? mesh.material : mesh.material ? [mesh.material] : []) {
            (mat as THREE.Material & { map?: THREE.Texture | null }).map?.dispose();
            mat.dispose();
        }
    });
}

