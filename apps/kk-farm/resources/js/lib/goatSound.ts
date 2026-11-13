/**
 * D-246: goats bleating in the 3D shed. Made in the browser (no sound file): a buzzing voice with the goat's
 * wobbling "meh-eh-eh", shaped by two mouth resonances. Muted every time the page opens; the person turns it on
 * with the speaker button (browsers only allow sound after a tap, which that button is).
 */

let ctx: AudioContext | null = null;
let out: AudioNode | null = null; // D-248: every voice goes through one limiter, so a whole herd calling never gets harsh
function audio(): AudioContext | null {
    if (typeof window === 'undefined') return null;
    const A = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!A) return null;
    if (!ctx) {
        ctx = new A();
        const limit = ctx.createDynamicsCompressor();
        limit.threshold.value = -18; limit.ratio.value = 8; limit.attack.value = 0.005; limit.release.value = 0.25;
        const master = ctx.createGain(); master.gain.value = 0.9;
        limit.connect(master).connect(ctx.destination);
        out = limit;
    }
    if (ctx.state === 'suspended') void ctx.resume();
    return ctx;
}

/** A goat's own voice: pitch, wobble and mouth, kept so the same goat sounds the same when it calls again. */
export type Voice = { kid: boolean; pitch: number; wobble: number; mouth: number; pan: number };
export function voiceOf(kid: boolean, seed = Math.random()): Voice {
    const r = (k: number) => ((Math.sin(seed * 9301 + k * 49297) + 1) / 2);
    return { kid, pitch: (kid ? 540 : 300) * (0.82 + r(1) * 0.4), wobble: (kid ? 22 : 16) + r(2) * 8, mouth: 0.85 + r(3) * 0.3, pan: r(4) * 1.6 - 0.8 };
}

/** One bleat in this goat's voice (or a fresh one), `delay` seconds from now; `volume` 0..1. */
export function bleat(kid: boolean | Voice = false, volume = 0.5, delay = 0): void {
    const a = audio();
    if (!a || !out) return;
    const v = typeof kid === 'boolean' ? voiceOf(kid) : kid;
    kid = v.kid;
    const t = a.currentTime + 0.02 + delay;
    const len = (kid ? 0.55 : 0.85) * (0.8 + Math.random() * 0.45);
    const f0 = v.pitch * (0.95 + Math.random() * 0.1);

    const voice = a.createOscillator();
    voice.type = 'sawtooth';
    voice.frequency.setValueAtTime(f0 * 0.92, t);
    voice.frequency.linearRampToValueAtTime(f0 * 1.06, t + len * 0.25);
    voice.frequency.linearRampToValueAtTime(f0 * 0.86, t + len);

    // the goat's wobble: the voice shakes about 20 times a second
    const wobble = a.createOscillator();
    wobble.frequency.value = v.wobble;
    const depth = a.createGain();
    depth.gain.value = 0.45;
    const shake = a.createGain();
    shake.gain.value = 0.55;
    wobble.connect(depth).connect(shake.gain);

    // two mouth resonances make it sound like "meh"
    const f1 = a.createBiquadFilter(); f1.type = 'bandpass'; f1.frequency.value = (kid ? 900 : 700) * v.mouth; f1.Q.value = 3;
    const f2 = a.createBiquadFilter(); f2.type = 'bandpass'; f2.frequency.value = (kid ? 2300 : 1800) * v.mouth; f2.Q.value = 5;
    const mix = a.createGain(); mix.gain.value = 1;

    const env = a.createGain();
    const peak = Math.max(0, Math.min(1, volume)) * 0.35;
    env.gain.setValueAtTime(0.0001, t);
    env.gain.exponentialRampToValueAtTime(peak, t + 0.06);
    env.gain.setValueAtTime(peak, t + len * 0.7);
    env.gain.exponentialRampToValueAtTime(0.0001, t + len);

    voice.connect(shake);
    shake.connect(f1).connect(mix);
    shake.connect(f2).connect(mix);
    const pan = a.createStereoPanner ? a.createStereoPanner() : null; // each goat stands somewhere in the shed
    if (pan) { pan.pan.value = v.pan; mix.connect(env).connect(pan).connect(out); } else mix.connect(env).connect(out);
    voice.start(t); wobble.start(t);
    voice.stop(t + len + 0.05); wobble.stop(t + len + 0.05);
}

/**
 * D-248: a herd, not one goat. Goats call now and then, often several at once and answering each other: a bigger herd
 * calls more and in bigger groups; more by day and at meals, few at night. Every goat keeps its own voice.
 * Returns a function that stops it.
 */
export function startBleating(opts: { goats: () => { kids: number; adults: number }; busy: () => number }): () => void {
    let timer = 0;
    const voices = new Map<number, Voice>();
    const goat = (kid: boolean, n: number) => { const id = Math.floor(Math.random() * Math.max(1, n)) * 2 + (kid ? 1 : 0); if (!voices.has(id)) voices.set(id, voiceOf(kid)); return voices.get(id)!; };
    const next = () => {
        const busy = Math.max(0.15, Math.min(1.6, opts.busy())); // 0.15 at midnight … 1.6 at a meal
        const { kids, adults } = opts.goats();
        const herd = kids + adults;
        const crowd = Math.min(2.2, Math.max(1, Math.sqrt(herd / 25))); // 25 goats = 1, 100 = 2, 120+ = 2.2
        timer = window.setTimeout(() => {
            if (herd > 0 && !document.hidden) {
                // how many call together: one, a few, now and then a whole chorus
                const roll = Math.random();
                const group = Math.max(1, Math.round((roll < 0.35 ? 1 : roll < 0.8 ? 2 + Math.random() * 2 : 4 + Math.random() * 5) * Math.min(1.6, crowd * busy * 0.8)));
                let at = 0;
                for (let k = 0; k < Math.min(group, herd, 9); k++) {
                    const kid = Math.random() < kids / herd;
                    bleat(goat(kid, kid ? kids : adults), (0.3 + Math.random() * 0.5) / Math.sqrt(1 + k * 0.3), at);
                    at += 0.08 + Math.random() * (k < 2 ? 0.5 : 0.35); // they overlap and answer
                }
            }
            next();
        }, ((4000 + Math.random() * 9000) / busy) / crowd);
    };
    next();
    return () => window.clearTimeout(timer);
}
