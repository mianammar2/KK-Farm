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

    const [busy, setBusy] = useState(false);
    const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] ?? null;
        e.target.value = '';
        if (!file) return;
        setBusy(true);
        try {
            onChange(await shrink(file));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="grid size-28 shrink-0 place-items-center overflow-hidden rounded-tile border border-rule bg-tile">
                {preview ? <img src={preview} alt="New profile picture" className="size-full object-cover" /> : <Camera size={28} aria-hidden className="text-ink-faint" />}
            </div>
            <div className="flex flex-col gap-2">
                <Button type="button" disabled={busy} onClick={() => camera.current?.click()}><Camera size={16} aria-hidden /> {busy ? 'Preparing photo…' : 'Open camera'}</Button>
                <Button type="button" variant="secondary" onClick={() => gallery.current?.click()}><ImagePlus size={16} aria-hidden /> Choose from gallery</Button>
                {value ? <button type="button" className="inline-flex items-center gap-1 text-sm text-ink-soft hover:underline" onClick={() => onChange(null)}><X size={14} aria-hidden /> Remove picture</button> : null}
            </div>
            <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={pick} />
            <input ref={gallery} type="file" accept="image/*" className="hidden" onChange={pick} />
            {error ? <p className="w-full text-sm font-medium text-crit-700">{error}</p> : null}
        </div>
    );
}

/**
 * Camera photos are several megabytes. Resize to at most 1600 px on the long side and save as JPEG, so uploads
 * are quick on farm Wi-Fi and stay far below server limits. Falls back to the original if the browser cannot.
 */
async function shrink(file: File, maxSide = 1600, quality = 0.85): Promise<File> {
    if (!file.type.startsWith('image/')) return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' } as ImageBitmapOptions);
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && file.size < 1_500_000) return file;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch {
        return file;
    }
}
