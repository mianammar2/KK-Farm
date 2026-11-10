import { cx } from './ui';

/** D-152: the round KK Farms logo badge (green with a gold ring), which reads on green or white. */
export default function BrandMark({ size = 36, className }: { size?: number; className?: string }) {
    return (
        <span className={cx('grid shrink-0 place-items-center rounded-full bg-[#013624] shadow-[0_0_0_2px_#f3b526,0_0_14px_rgb(243_181_38/0.45)]', className)} style={{ width: size, height: size }}>
            <img src="/brand/kk-farms-mark-v2.png" alt="" width={size} height={size} className="size-full object-contain" decoding="async" />
        </span>
    );
}
