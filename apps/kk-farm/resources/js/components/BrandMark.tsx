import { cx } from './ui';

/** D-068: the KK Farms emblem (the circle from the logo) on a white disc, so it reads on green or white. */
export default function BrandMark({ size = 36, className }: { size?: number; className?: string }) {
    return (
        <span className={cx('grid shrink-0 place-items-center rounded-full bg-white shadow-sm ring-1 ring-black/5', className)} style={{ width: size, height: size }}>
            <img src="/brand/kk-farms-mark.png" alt="" width={size} height={size} className="size-[92%] object-contain" decoding="async" />
        </span>
    );
}
