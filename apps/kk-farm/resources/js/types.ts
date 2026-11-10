export type Health = 'healthy' | 'watch' | 'treatment' | 'critical';

export type GoatCard = {
    id: string;
    number: string;
    name: string | null;
    sex: 'male' | 'female';
    coat: string;
    breed?: string;
    stage?: string | null; // D-148
    age?: string | null;
    teeth?: string | null;
    ageMonths?: number | null;
    pen?: string | null;
    penId?: number | null;
    status?: string;
    health: Health;
    repro?: string | null;
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
