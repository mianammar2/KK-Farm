import { router } from '@inertiajs/react';
import { Minus, Plus, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { inScannerApp } from '../lib/native';
import type { Cls } from '../lib/shedSim';
import type { ShedEngine } from '../lib/shedEngine';
import { hoverGoat, type HoverGoat } from './FarmScene';

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
    const [near, setNear] = useState(false);
    const [failed, setFailed] = useState(false);
    const [ready, setReady] = useState(false);
    const usable = useMemo(canDraw, []) && pens.length > 0 && !failed;

    const live = useRef({ pens, onPen, penHref });
    live.current = { pens, onPen, penHref };
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
                pick: (_goat, pen) => {
                    const p = live.current.pens.find((x) => x.id === pen);
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
            <div className="kk-shed3d">
                <div ref={host} className="kk-shed3d-view" role="img" aria-label={`${label}: ${pens.length} pens, ${goats} goats`} />
                <div className="kk-shed3d-tools">
                    <button type="button" onClick={() => engine.current?.view(1)} aria-label="Zoom in" title="Zoom in"><Plus className="size-4" aria-hidden /></button>
                    <button type="button" onClick={() => engine.current?.view(-1)} aria-label="Zoom out" title="Zoom out"><Minus className="size-4" aria-hidden /></button>
                    <button type="button" onClick={() => engine.current?.view(0)} aria-label="Reset the view" title="Reset the view"><RotateCcw className="size-4" aria-hidden /></button>
                </div>
                <span className="kk-shed3d-hint" aria-hidden>Drag to turn · pinch to zoom</span>
            </div>
            {children?.(true)}
        </>
    );
}
