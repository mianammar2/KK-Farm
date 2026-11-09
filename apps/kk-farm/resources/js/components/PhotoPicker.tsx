import { Camera, ImagePlus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from './ui';

/**
 * Profile picture picker: "Open camera" uses the device camera (the C72's rear camera in the KK Farm app,
 * or a phone camera); "Choose from gallery" picks an existing picture. Shows a preview before saving.
 */
export default function PhotoPicker({ value, onChange, error, compact = false }: { value: File | null; onChange: (file: File | null) => void; error?: string; compact?: boolean }) {
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

    const inputs = (
        <>
            <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={pick} />
            <input ref={gallery} type="file" accept="image/*" className="hidden" onChange={pick} />
        </>
    );

    // D-094: compact — a round photo with two small buttons, for the top of the register (not a whole card).
    if (compact) {
        return (
            <div className="flex items-center gap-4">
                <button type="button" onClick={() => camera.current?.click()} aria-label={preview ? 'Retake the profile picture' : 'Take a profile picture'}
                    className="group relative grid size-20 shrink-0 place-items-center overflow-hidden rounded-full border-2 border-dashed border-turq-600 bg-tile transition-colors hover:bg-turq-100">
                    {preview ? <img src={preview} alt="New profile picture" className="size-full object-cover" /> : <Camera size={26} aria-hidden className="text-turq-800" />}
                    {preview ? <span className="absolute inset-x-0 bottom-0 bg-cobalt-900/70 py-0.5 text-center text-[10px] font-semibold text-white">Change</span> : null}
                </button>
                <div className="min-w-0">
                    <div className="font-semibold text-cobalt-900">Profile picture <span className="font-normal text-ink-soft">(optional)</span></div>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                        <button type="button" disabled={busy} onClick={() => camera.current?.click()} className="inline-flex min-h-9 items-center gap-1.5 rounded-full bg-cobalt-800 px-3 text-sm font-semibold text-white disabled:opacity-50"><Camera size={15} aria-hidden /> {busy ? 'Preparing…' : 'Camera'}</button>
                        <button type="button" onClick={() => gallery.current?.click()} className="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-rule bg-tile px-3 text-sm font-semibold text-cobalt-900"><ImagePlus size={15} aria-hidden /> Gallery</button>
                        {value ? <button type="button" className="inline-flex items-center gap-1 text-sm text-ink-soft hover:underline" onClick={() => onChange(null)}><X size={14} aria-hidden /> Remove</button> : null}
                    </div>
                    {error ? <p className="mt-1 text-sm font-medium text-crit-700">{error}</p> : null}
                </div>
                {inputs}
            </div>
        );
    }

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
            {inputs}
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
