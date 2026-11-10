import { cx } from './ui';

/** D-152: the round KK Farms logo badge (green with a gold ring), which reads on green or white. */
export default function BrandMark({ size = 36, className }: { size?: number; className?: string }) {
    return (
        <span className={cx('grid shrink-0 place-items-center rounded-full shadow-sm', className)} style={{ width: size, height: size }}>
            <img src="/brand/kk-farms-mark.png" alt="" width={size} height={size} className="size-full object-contain" decoding="async" />
        </span>
    );
}
