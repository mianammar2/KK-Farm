import { router } from '@inertiajs/react';
import { Maximize, Minus, Moon, Plus, RotateCcw, Sun, Sunrise, Sunset, Volume2, VolumeX } from 'lucide-react';
import { bleat, startBleating, voiceOf } from '../lib/goatSound';
import type { SkyView } from '../lib/sky';
import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { inScannerApp } from '../lib/native';
import type { Cls } from '../lib/shedSim';
import type { ShedEngine } from '../lib/shedEngine';
import { hoverGoat, type HoverGoat } from './FarmScene';
import { Link } from '@inertiajs/react';
import GoatPortrait from './GoatPortrait';
import { X } from 'lucide-react';

export type ShedGoat = { key: string; cls: Cls; attention: boolean; info?: HoverGoat };
export type ShedPen = { id: number; code: string; name: string; capacity: number | null; goats: ShedGoat[] };

/** The class a goat is drawn as: Kid, Grower, Female (Doe) or Male (Buck). */
export const goatClass = (female: boolean, stage?: string | null): Cls => (stage === 'kid' ? 'kid' : stage === 'grower' ? 'grower' : female ? 'doe' : 'buck');

let webgl: boolean | null = null;
/** The 3D shed needs WebGL; the RFID gun's app keeps the light 2D pens. */
function canDraw(): boolean {
    if (typeof window === 'undefined' || inScannerApp()) return false;
    if (webgl === null) {
        try { const c = document.createElement('canvas'); webgl = !!(c.getContext('webgl2') ?? c.getContext('webgl')); } catch { webgl = false; }
    }
    return webgl;
}

/**
 * D-244: one shed in 3D with every goat of its pens walking about inside its own pen.
 * `children(ok)` is drawn under it: `ok` is false when 3D cannot be shown, so the page can show the 2D pens instead.
 */
export default function Shed3D({ pens, meals, clock, selected = null, onPen, penHref, label, children }: {
    pens: ShedPen[]; meals?: string[]; clock?: string | null; selected?: number | null;
    onPen?: (id: number) => void; penHref?: (pen: ShedPen) => string; label: string; children?: (ok: boolean) => ReactNode;
}) {
    const host = useRef<HTMLDivElement>(null);
    const engine = useRef<ShedEngine | null>(null);
    const frame = useRef<HTMLDivElement>(null);
    // D-245: the whole screen for the shed, and back (where the browser allows it)
    const full = () => { if (document.fullscreenElement) void document.exitFullscreen(); else void frame.current?.requestFullscreen?.().catch(() => undefined); };
    const [near, setNear] = useState(false);
    const [failed, setFailed] = useState(false);
    const [ready, setReady] = useState(false);
    const usable = useMemo(canDraw, []) && pens.length > 0 && !failed;
    // D-246: the time of day over the shed, and the goats' voices (muted until the person turns them on)
    const [sky, setSky] = useState<SkyView | null>(null);
    const [sound, setSound] = useState(false);
    useEffect(() => {
        if (!ready) return;
        const read = () => setSky(engine.current?.skyInfo() ?? null);
        read();
        const t = window.setInterval(read, 15000);
        return () => window.clearInterval(t);
    }, [ready, clock]);
    const herd = useRef({ kids: 0, adults: 0 });
    herd.current = pens.reduce((h, p) => { p.goats.forEach((g) => { if (g.cls === 'kid') h.kids++; else h.adults++; }); return h; }, { kids: 0, adults: 0 });
    useEffect(() => {
        if (!sound || !near) return;
        return startBleating({ goats: () => herd.current, busy: () => 0.15 + (engine.current?.skyInfo()?.day ?? 1) * 1.0 });
    }, [sound, near]);
    const toggleSound = () => { // turning it on: a few goats answer at once
        const on = !sound; setSound(on);
        if (on) for (let k = 0; k < 3; k++) bleat(voiceOf(Math.random() < herd.current.kids / Math.max(1, herd.current.kids + herd.current.adults)), 0.45, k * 0.28);
    };

    // D-248: a tapped goat opens its card right there
    const [card, setCard] = useState<{ key: string; x: number; y: number; info: HoverGoat; pen: string } | null>(null);
    const live = useRef({ pens, onPen, penHref, setCard });
    live.current = { pens, onPen, penHref, setCard };
    const data = useMemo(() => ({
        pens: pens.map((p) => ({ id: p.id, code: p.code, capacity: p.capacity, goats: p.goats.map((g) => ({ key: g.key, cls: g.cls, attention: g.attention })) })),
        meals: meals ?? [], clock: clock ?? null, selected,
    }), [pens, meals, clock, selected]);
    // The page refreshes every 10 seconds with the same goats: only a real change is sent to the shed.
    const sig = useMemo(() => JSON.stringify(data), [data]);
    const latest = useRef(data);
    latest.current = data;

    // Only a shed on (or near) the screen is drawn.
    useEffect(() => {
        const el = host.current;
        if (!usable || !el) return;
        const io = new IntersectionObserver(([e]) => setNear(e.isIntersecting), { rootMargin: '300px' });
        io.observe(el);
        return () => io.disconnect();
    }, [usable]);

    useEffect(() => {
        const el = host.current;
        if (!usable || !near || !el) return;
        let dead = false, made: ShedEngine | null = null;
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        const seen = () => { if (document.hidden) made?.pause(); else made?.start(); };
        import('../lib/shedEngine').then(({ ShedEngine }) => {
            if (dead) return;
            made = new ShedEngine(el, {
                hover: (goat, pen) => {
                    const p = live.current.pens.find((x) => x.id === pen);
                    const g = goat ? p?.goats.find((x) => x.key === goat) : undefined;
                    hoverGoat(g?.info && p ? { ...g.info, pen: p.name } : null);
                },
                pick: (goat, pen, at) => {
                    const p = live.current.pens.find((x) => x.id === pen);
                    const g = goat ? p?.goats.find((x) => x.key === goat) : undefined;
                    if (g?.info?.id !== undefined && p) { live.current.setCard({ key: g.key, x: at.x, y: at.y, info: g.info, pen: p.name }); return; }
                    live.current.setCard(null);
                    if (!p) return;
                    if (live.current.onPen) live.current.onPen(p.id); else if (live.current.penHref) router.visit(live.current.penHref(p));
                },
            }, reduced.matches);
            made.setData(latest.current);
            engine.current = made;
            (el as HTMLDivElement & { shed?: ShedEngine }).shed = made;
            seen();
            setReady(true);
        }).catch(() => { if (!dead) setFailed(true); });
        document.addEventListener('visibilitychange', seen);
        return () => {
            dead = true;
            document.removeEventListener('visibilitychange', seen);
            made?.dispose();
            engine.current = null;
            setReady(false);
        };
    }, [usable, near]);

    useEffect(() => {
        try { engine.current?.setData(latest.current); } catch { setFailed(true); }
    }, [sig, ready]);

    if (!usable) return <>{children?.(false)}</>;
    const goats = pens.reduce((n, p) => n + p.goats.length, 0);
    return (
        <>
            <div className="kk-shed3d" ref={frame}>
                <div ref={host} className="kk-shed3d-view" role="img" aria-label={`${label}: ${pens.length} PENs, ${goats} goats`} />
                {sky ? <SkyBand sky={sky} /> : null}
                {card ? <GoatCard card={card} onClose={() => setCard(null)} /> : null}
                {sky ? (
                    <span className="kk-shed3d-sky" aria-live="polite">
                        {sky.phase === 'Sunrise' ? <Sunrise className="size-3.5" aria-hidden /> : sky.phase === 'Sunset' ? <Sunset className="size-3.5" aria-hidden /> : sky.phase === 'Day' ? <Sun className="size-3.5" aria-hidden /> : <Moon className="size-3.5" aria-hidden />}
                        {sky.phase} · {sky.time}{sky.lampsOn ? ' · shed lights on' : ''}
                    </span>
                ) : null}
                <div className="kk-shed3d-tools">
                    <button type="button" onClick={toggleSound} aria-pressed={sound} aria-label={sound ? 'Mute the goats' : 'Hear the goats'} title={sound ? 'Mute the goats' : 'Hear the goats'}>{sound ? <Volume2 className="size-4" aria-hidden /> : <VolumeX className="size-4" aria-hidden />}</button>
                    <button type="button" onClick={() => engine.current?.view(1)} aria-label="Zoom in" title="Zoom in"><Plus className="size-4" aria-hidden /></button>
                    <button type="button" onClick={() => engine.current?.view(-1)} aria-label="Zoom out" title="Zoom out"><Minus className="size-4" aria-hidden /></button>
                    <button type="button" onClick={() => engine.current?.view(0)} aria-label="Reset the view" title="Reset the view"><RotateCcw className="size-4" aria-hidden /></button>
                    {typeof document !== 'undefined' && document.fullscreenEnabled ? <button type="button" onClick={full} aria-label="Full screen" title="Full screen"><Maximize className="size-4" aria-hidden /></button> : null}
                </div>
                <span className="kk-shed3d-hint" aria-hidden>Drag to turn · scroll or pinch to zoom · right-drag or two fingers to move</span>
            </div>
            {children?.(true)}
        </>
    );
}

/** D-246: the band of sky over the shed: its colour, the sun or the moon (in its phase) where they are, stars at night. */
const STARS = Array.from({ length: 46 }, (_, i) => ({ x: ((i * 37) % 100) / 100, y: ((i * 53) % 70) / 100, r: i % 5 === 0 ? 1.6 : 1 }));
function SkyBand({ sky }: { sky: SkyView }) {
    const at = (p: { x: number; y: number }) => ({ left: `${p.x * 100}%`, top: `${p.y * 100}%` });
    const low = sky.sun ? Math.min(1, Math.max(0, (sky.sun.y - 0.4) / 0.26)) : 0; // near the horizon: orange and larger
    // D-248: the sun lights the sky around it; far away, it is a small bright core inside a wide soft glow
    const halo = sky.sun ? `radial-gradient(circle at ${sky.sun.x * 100}% ${sky.sun.y * 100}%, rgb(255 ${low > 0.5 ? 196 : 244} ${low > 0.5 ? 140 : 205} / ${0.55 + low * 0.2}) 0, rgb(255 ${low > 0.5 ? 170 : 230} ${low > 0.5 ? 110 : 170} / 0.22) 9%, transparent 30%), ` : '';
    return (
        <div className="kk-shed3d-skyband" style={{ background: `${halo}linear-gradient(${sky.color}, ${sky.color} 45%, transparent)` }} aria-hidden>
            {sky.stars > 0.02 ? STARS.map((s, i) => <i key={i} className="kk-star" style={{ left: `${s.x * 100}%`, top: `${s.y * 100}%`, width: s.r * 2, height: s.r * 2, opacity: sky.stars * (0.5 + ((i * 7) % 5) / 10) }} />) : null}
            {sky.sun ? (
                <span className="kk-sun" style={{ ...at(sky.sun), ['--sun' as string]: low > 0.5 ? '#ffb46a' : '#fff3c4', ['--sun-size' as string]: `${30 + low * 10}px` }}>
                    <i className="kk-sun-glow" /><i className="kk-sun-core" />
                </span>
            ) : null}
            {sky.moon ? <Moon3 style={at(sky.moon)} age={sky.moon.age} /> : null}
        </div>
    );
}
function Moon3({ age, style }: { age: number; style: CSSProperties }) {
    const R = 10, k = Math.cos(2 * Math.PI * age), waxing = age < 0.5, rx = Math.abs(k) * R;
    const lit = `M 0 ${-R} A ${R} ${R} 0 0 ${waxing ? 1 : 0} 0 ${R} A ${rx} ${R} 0 0 ${waxing ? (k > 0 ? 0 : 1) : (k > 0 ? 1 : 0)} 0 ${-R} Z`;
    return (
        <svg className="kk-moon" style={style} viewBox="-14 -14 28 28" width={30} height={30}>
            <circle r={13} fill="rgb(200 215 255 / 0.16)" /><circle r={R} fill="rgb(120 135 170 / 0.25)" /><path d={lit} fill="#f4f1e1" />
        </svg>
    );
}

type Peek = {
    id: string; number: string; name: string | null; sex: string; breed: string | null; age: string | null; ageCode: string | null; roles: string[]; pen: string | null;
    status: string; health: string | null; repro: string | null; weight: number | null; weighedOn: string | null; bodyScore: number | null; pregnancyDay: number | null;
    withdrawal: { meat: string | null; milk: string | null } | null; stage: string | null; openCases: string[]; seeHealth: boolean;
};
/** D-248: the card of a goat tapped in the 3D shed: what is known at once, then its record from the server. */
function GoatCard({ card, onClose }: { card: { x: number; y: number; info: HoverGoat; pen: string }; onClose: () => void }) {
    const [d, setD] = useState<Peek | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        setD(null); setFailed(false);
        const c = new AbortController();
        fetch(`/goats/${card.info.id}/peek`, { headers: { Accept: 'application/json' }, signal: c.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status))).then(setD).catch((e) => { if (e?.name !== 'AbortError') setFailed(true); });
        const key = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', key);
        return () => { c.abort(); window.removeEventListener('keydown', key); };
    }, [card.info.id]); // eslint-disable-line react-hooks/exhaustive-deps
    const i = card.info;
    const female = d ? d.sex === 'female' : i.female;
    const row = (label: string, value: ReactNode) => (value === null || value === undefined || value === '' ? null : <div className="flex justify-between gap-3"><dt className="text-ink-soft">{label}</dt><dd className="text-end font-semibold text-cobalt-900">{value}</dd></div>);
    return (
        <div className="kk-goat-card" role="dialog" aria-label={`Goat ${i.number ?? ''}`} style={{ ['--x' as string]: `${card.x}px`, ['--y' as string]: `${card.y}px` }} onPointerDown={(e) => e.stopPropagation()}>
            <div className="flex items-start gap-2">
                <GoatPortrait number={d?.number ?? i.number ?? ''} sex={female ? 'female' : 'male'} stage={d?.stage ?? i.stage} repro={d?.repro} size={40} />
                <div className="min-w-0 flex-1">
                    <p className="truncate font-mono text-base font-bold text-cobalt-900">{d?.number ?? i.number}</p>
                    <p className="truncate text-xs text-ink-soft">{[d?.name ?? i.name, d?.breed ?? i.breed, female ? 'Female' : 'Male'].filter(Boolean).join(' · ')}</p>
                </div>
                <button type="button" onClick={onClose} aria-label="Close" className="grid size-8 place-items-center rounded-full text-ink-soft hover:bg-glaze"><X className="size-4" aria-hidden /></button>
            </div>
            <dl className="mt-2 space-y-1 text-xs">
                {row('PEN', d?.pen ?? card.pen)}
                {d ? <>
                    {row('Age', [d.age, d.ageCode].filter(Boolean).join(' · ') || null)}
                    {row('Roles', d.roles?.length ? d.roles.join(', ') : null)}
                    {row('Weight', d.weight ? `${Number(d.weight)} KG${d.bodyScore ? ` · BCS ${d.bodyScore}` : ''}${d.weighedOn ? ` (${d.weighedOn})` : ''}` : 'No weight yet')}
                    {row('Pregnancy', d.pregnancyDay ? `Day ${d.pregnancyDay}` : null)}
                    {row('Status', d.repro && d.repro !== 'open' ? d.repro.replace(/_/g, ' ') : null)}
                    {d.seeHealth ? row('Health', d.openCases.length ? d.openCases.join(', ') : d.health ? d.health.charAt(0).toUpperCase() + d.health.slice(1).replace(/_/g, ' ') : 'Healthy') : row('Health', i.health && i.health !== 'healthy' ? 'Needs attention' : 'OK')}
                    {d.withdrawal ? row('Withdrawal', [d.withdrawal.meat ? `meat to ${d.withdrawal.meat}` : null, d.withdrawal.milk ? `milk to ${d.withdrawal.milk}` : null].filter(Boolean).join(' · ')) : null}
                </> : failed ? <p className="text-ink-soft">The record could not be loaded.</p> : <div className="space-y-1.5" aria-busy="true">{[0, 1, 2].map((k) => <div key={k} className="h-3 animate-pulse rounded bg-glaze" />)}</div>}
            </dl>
            <Link href={`/goats/${i.id}`} className="mt-3 flex min-h-10 items-center justify-center rounded-tile bg-cobalt-800 text-sm font-semibold text-white hover:bg-cobalt-900">Open goat record</Link>
        </div>
    );
}
