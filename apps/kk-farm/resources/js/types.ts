export type Health = 'healthy' | 'watch' | 'treatment' | 'critical';

export type GoatCard = {
    id: string;
    animalId?: string | null; // D-208: permanent Animal ID
    number: string;
    name: string | null;
    sex: 'male' | 'female';
    coat: string;
    breed?: string;
    stage?: string | null; // D-148
    age?: string | null;
    teeth?: string | null;
    ageCode?: string | null; // D-221
    roles?: string[]; // D-230: BD, PD, DD …
    ageMonths?: number | null;
    pen?: string | null;
    penId?: number | null;
    status?: string;
    health: Health;
    repro?: string | null;
    monitorUntil?: string | null; // D-162
    weight?: number | null;
    photo?: number | null;
    tag?: string | null;
};

export type SharedProps = {
    auth: { name: string; role: string; roleLabel: string; modules: string[]; gates: string[] } | null;
    roles: Record<string, string>;
    demo: boolean;
    demoLogin: boolean;
    flash: string | null;
    errors: Record<string, string>;
};

export type Option = { id: string | number; farm_number?: string; name?: string | null };
