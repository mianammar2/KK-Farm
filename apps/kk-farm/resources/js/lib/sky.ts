/**
 * D-246: where the sun and the moon are over the farm, for the 3D shed's light. Pakistan (PKT, UTC+5, no daylight
 * saving), central Punjab by default. Good to a few minutes, which is all a picture needs.
 *
 * Time comes from the farm's clock when the page has it ("HH:MM"), otherwise from the real time in Pakistan.
 */

export const FARM = { lat: 31.0, lon: 73.5, utcOffset: 5 }; // KK Farms, Punjab; PKT meridian is 75° E

export type Phase = 'dawn' | 'day' | 'dusk' | 'night' | 'midnight';
export type Sky = {
    minutes: number; // local minutes after midnight
    sunAlt: number; sunAz: number; // degrees; azimuth from south, west positive
    moonAlt: number; moonAz: number;
    moonAge: number; // 0 new … 0.5 full … 1 new
    moonLit: number; // 0..1 of the disc lit
    daylight: number; // 0 night … 1 full day (smooth through dawn and dusk)
    lampsOn: boolean; // the shed's lamps: on from dusk to dawn
    phase: Phase;
};

const rad = Math.PI / 180;
const deg = 180 / Math.PI;
const smooth = (a: number, b: number, x: number) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };

/** Local minutes after midnight in Pakistan right now. */
export function pakistanMinutes(now = new Date()): number {
    const utc = now.getUTCHours() * 60 + now.getUTCMinutes() + now.getUTCSeconds() / 60;
    return (utc + FARM.utcOffset * 60 + 1440) % 1440;
}

/** "HH:MM" (the farm's clock) to minutes, or Pakistan time when there is no clock. */
export function farmMinutes(clock: string | null | undefined, now = new Date()): number {
    const m = clock?.match(/^(\d{1,2}):(\d{2})/);
    return m ? Number(m[1]) * 60 + Number(m[2]) : pakistanMinutes(now);
}

function dayOfYear(d: Date): number {
    const start = Date.UTC(d.getUTCFullYear(), 0, 0);
    return Math.floor((d.getTime() + FARM.utcOffset * 3600e3 - start) / 86400e3);
}

/** Altitude and azimuth (degrees) of a body with this declination at this hour angle, seen from the farm. */
function altAz(declDeg: number, hourDeg: number): [number, number] {
    const φ = FARM.lat * rad, δ = declDeg * rad, H = hourDeg * rad;
    const alt = Math.asin(Math.sin(φ) * Math.sin(δ) + Math.cos(φ) * Math.cos(δ) * Math.cos(H));
    const az = Math.atan2(Math.sin(H), Math.cos(H) * Math.sin(φ) - Math.tan(δ) * Math.cos(φ));
    return [alt * deg, az * deg];
}

/** The moon's age as a fraction of a lunar month (0 = new moon), from a known new moon (6 Jan 2000, 18:14 UTC). */
export function moonAge(now = new Date()): number {
    const days = (now.getTime() - Date.UTC(2000, 0, 6, 18, 14)) / 86400e3;
    return (((days / 29.530588853) % 1) + 1) % 1;
}

export function skyAt(minutes: number, date = new Date()): Sky {
    const n = dayOfYear(date);
    const decl = 23.44 * Math.sin((2 * Math.PI * (284 + n)) / 365);
    const B = (2 * Math.PI * (n - 81)) / 364;
    const eot = 9.87 * Math.sin(2 * B) - 7.53 * Math.cos(B) - 1.5 * Math.sin(B); // equation of time, minutes
    const solar = minutes + 4 * (FARM.lon - FARM.utcOffset * 15) + eot; // local solar time, minutes
    const sunHour = (solar / 60 - 12) * 15;
    const [sunAlt, sunAz] = altAz(decl, sunHour);

    const age = moonAge(date);
    // the moon runs about 50 minutes a day behind the sun: at full moon it is opposite the sun
    const [moonAlt, moonAz] = altAz(decl * Math.cos(2 * Math.PI * age), sunHour - age * 360);
    const moonLit = (1 - Math.cos(2 * Math.PI * age)) / 2;

    const daylight = smooth(-7, 8, sunAlt);
    const morning = sunHour < 0;
    const phase: Phase = sunAlt > 6 ? 'day'
        : sunAlt > -8 ? (morning ? 'dawn' : 'dusk')
            : (minutes >= 23 * 60 || minutes < 60 * 1.5 ? 'midnight' : 'night');
    return { minutes, sunAlt, sunAz, moonAlt, moonAz, moonAge: age, moonLit, daylight, lampsOn: sunAlt < -2, phase };
}

/** A direction (unit vector) to a body: x east, y up, z south (the shed's long side faces south). */
export function direction(alt: number, az: number): [number, number, number] {
    const a = alt * rad, z = az * rad;
    return [-Math.cos(a) * Math.sin(z), Math.sin(a), Math.cos(a) * Math.cos(z)];
}

export const PHASE_LABEL: Record<Phase, string> = { dawn: 'Sunrise', day: 'Day', dusk: 'Sunset', night: 'Night', midnight: 'Midnight' };

export function clockLabel(minutes: number): string {
    const h = Math.floor(minutes / 60) % 24, m = Math.floor(minutes % 60);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}

/** Sky colour for a sun altitude: deep blue at midnight, orange at the horizon, pale blue by day. */
export function skyColor(s: Sky): [number, number, number] {
    const night: [number, number, number] = s.phase === 'midnight' ? [0.03, 0.05, 0.11] : [0.06, 0.1, 0.19];
    const glow: [number, number, number] = [0.96, 0.62, 0.38];
    const day: [number, number, number] = [0.86, 0.93, 0.95];
    const t = s.daylight;
    const horizon = 1 - Math.min(1, Math.abs(s.sunAlt) / 9); // near the horizon: the sky glows
    const base = night.map((v, i) => v + (day[i] - v) * t) as [number, number, number];
    const k = horizon * 0.65 * (s.sunAlt > -9 ? 1 : 0);
    return base.map((v, i) => v + (glow[i] - v) * k) as [number, number, number];
}

export type SkyView = {
    phase: string; time: string; night: boolean; lampsOn: boolean; day: number;
    color: string; // the sky's colour now (css)
    sun: { x: number; y: number } | null; // 0..1 across and down the sky band; null below the horizon
    moon: { x: number; y: number; age: number; lit: number } | null;
    stars: number; // 0..1
};

/** Where to draw the sun and the moon in the band of sky over the shed: east on the left, west on the right, higher at noon. */
export function skyView(s: Sky): SkyView {
    const [r, g, b] = skyColor(s);
    const place = (alt: number, az: number) => (alt < -3 ? null : {
        x: Math.min(0.94, Math.max(0.06, 0.5 + az / 260)),
        y: Math.min(0.66, Math.max(0.07, 0.64 - (Math.max(0, alt) / 70) * 0.57)), // D-248: high in the sky, well away from the roof
    });
    const moon = place(s.moonAlt, s.moonAz);
    return {
        phase: PHASE_LABEL[s.phase], time: clockLabel(s.minutes), night: s.phase === 'night' || s.phase === 'midnight', lampsOn: s.lampsOn, day: s.daylight,
        color: `rgb(${Math.round(r * 255)} ${Math.round(g * 255)} ${Math.round(b * 255)})`,
        sun: place(s.sunAlt, s.sunAz),
        moon: moon && s.daylight < 0.85 ? { ...moon, age: s.moonAge, lit: s.moonLit } : null,
        stars: Math.max(0, 1 - s.daylight * 1.6),
    };
}
