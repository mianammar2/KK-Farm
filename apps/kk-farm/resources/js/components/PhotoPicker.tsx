import { Camera, ImagePlus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from './ui';

/**
 * Profile picture picker: "Open camera" uses the device camera (the C72's rear camera in the KK Farm app,
 * or a phone camera); "Choose from gallery" picks an existing picture. Shows a preview before saving.
 */
export default function PhotoPicker({ value, onChange, error }: { value: File | null; onChange: (file: File | null) => void; error?: string }) {
    const camera = useRef<HTMLInputElement>(null);
    const gallery = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!value) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(value);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [value]);

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        onChange(e.target.files?.[0] ?? null);
        e.target.value = '';
    };

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="grid size-28 shrink-0 place-items-center overflow-hidden rounded-tile border border-rule bg-tile">
                {preview ? <img src={preview} alt="New profile picture" className="size-full object-cover" /> : <Camera size={28} aria-hidden className="text-ink-faint" />}
            </div>
            <div className="flex flex-col gap-2">
                <Button type="button" onClick={() => camera.current?.click()}><Camera size={16} aria-hidden /> Open camera</Button>
                <Button type="button" variant="secondary" onClick={() => gallery.current?.click()}><ImagePlus size={16} aria-hidden /> Choose from gallery</Button>
                {value ? <button type="button" className="inline-flex items-center gap-1 text-sm text-ink-soft hover:underline" onClick={() => onChange(null)}><X size={14} aria-hidden /> Remove picture</button> : null}
            </div>
            <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={pick} />
            <input ref={gallery} type="file" accept="image/*" className="hidden" onChange={pick} />
            {error ? <p className="w-full text-sm font-medium text-crit-700">{error}</p> : null}
        </div>
    );
}
