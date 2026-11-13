/**
 * D-246: goats bleating in the 3D shed. Made in the browser (no sound file): a buzzing voice with the goat's
 * wobbling "meh-eh-eh", shaped by two mouth resonances. Muted every time the page opens; the person turns it on
 * with the speaker button (browsers only allow sound after a tap, which that button is).
 */

let ctx: AudioContext | null = null;
function audio(): AudioContext | null {
    if (typeof window === 'undefined') return null;
    const A = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!A) return null;
    ctx ??= new A();
    if (ctx.state === 'suspended') void ctx.resume();
    return ctx;
}

/** One bleat. `kid` is higher and shorter; `volume` 0..1. */
export function bleat(kid = false, volume = 0.5): void {
    const a = audio();
    if (!a) return;
    const t = a.currentTime + 0.02;
    const len = (kid ? 0.55 : 0.85) * (0.85 + Math.random() * 0.3);
    const f0 = (kid ? 560 : 330) * (0.9 + Math.random() * 0.2);

    const voice = a.createOscillator();
    voice.type = 'sawtooth';
    voice.frequency.setValueAtTime(f0 * 0.92, t);
    voice.frequency.linearRampToValueAtTime(f0 * 1.06, t + len * 0.25);
    voice.frequency.linearRampToValueAtTime(f0 * 0.86, t + len);

    // the goat's wobble: the voice shakes about 20 times a second
    const wobble = a.createOscillator();
    wobble.frequency.value = (kid ? 24 : 18) + Math.random() * 4;
    const depth = a.createGain();
    depth.gain.value = 0.45;
    const shake = a.createGain();
    shake.gain.value = 0.55;
    wobble.connect(depth).connect(shake.gain);

    // two mouth resonances make it sound like "meh"
    const f1 = a.createBiquadFilter(); f1.type = 'bandpass'; f1.frequency.value = kid ? 900 : 700; f1.Q.value = 3;
    const f2 = a.createBiquadFilter(); f2.type = 'bandpass'; f2.frequency.value = kid ? 2300 : 1800; f2.Q.value = 5;
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
    mix.connect(env).connect(a.destination);
    voice.start(t); wobble.start(t);
    voice.stop(t + len + 0.05); wobble.stop(t + len + 0.05);
}

/**
 * Bleats now and then while the shed is on screen: more by day and at feeding time, few at night.
 * Returns a function that stops it.
 */
export function startBleating(opts: { goats: () => { kids: number; adults: number }; busy: () => number }): () => void {
    let timer = 0;
    const next = () => {
        const busy = Math.max(0.15, Math.min(1.6, opts.busy())); // 0.15 at midnight … 1.6 at a meal
        const { kids, adults } = opts.goats();
        const herd = kids + adults;
        timer = window.setTimeout(() => {
            if (herd > 0 && !document.hidden) {
                const kid = Math.random() < kids / herd;
                bleat(kid, 0.35 + Math.random() * 0.45);
                if (Math.random() < 0.25 * busy) window.setTimeout(() => bleat(!kid && kids > 0, 0.25 + Math.random() * 0.3), 500 + Math.random() * 900); // an answer
            }
            next();
        }, (5000 + Math.random() * 12000) / busy);
    };
    next();
    return () => window.clearTimeout(timer);
}
