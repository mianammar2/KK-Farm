import { Link } from '@inertiajs/react';
import GoatPortrait from './GoatPortrait';
import { cx } from './ui';
import { BreedBadge } from './GoatBadges';

export type PedigreeNode = { id: string; number: string; name: string | null; sex: string; coat: string; breed?: string; status?: string; sire?: PedigreeNode | null; dam?: PedigreeNode | null };

function Node({ node, parent, root }: { node: PedigreeNode | null | undefined; parent: 'Sire' | 'Dam' | null; root?: boolean }) {
    if (!node) {
        return (
            <div className="w-40 rounded-tile border border-dashed border-rule-strong px-2.5 py-2 text-sm text-ink-faint">
                {parent} not recorded
            </div>
        );
    }
    return (
        <Link href={`/goats/${node.id}`} className={cx('flex w-44 items-center gap-2 rounded-tile border bg-tile px-2 py-1.5 transition-colors hover:border-turq-600', root ? 'border-cobalt-700 shadow-tile' : 'border-rule-strong')}>
            <GoatPortrait number={node.number} coat={node.coat} sex={node.sex} size={34} />
            <span className="min-w-0 leading-tight">
                <span className="flex min-w-0 items-center gap-1"><span className="truncate text-sm font-semibold text-cobalt-900">{node.name ?? node.number}</span><BreedBadge breed={node.breed} size={16} /></span>
                <span className="block truncate text-xs text-ink-soft">{parent ?? node.breed ?? ''}{node.name ? ` · ${node.number}` : ''}</span>
            </span>
        </Link>
    );
}

function Branch({ node, parent, depth, root }: { node: PedigreeNode | null | undefined; parent: 'Sire' | 'Dam' | null; depth: number; root?: boolean }) {
    const hasChildren = depth > 0 && node && (node.sire || node.dam || depth > 1);
    return (
        <div className="flex items-center">
            <Node node={node} parent={parent} root={root} />
            {hasChildren ? (
                <div className="ml-6 flex flex-col gap-2">
                    {(['sire', 'dam'] as const).map((key, i) => (
                        <div key={key} className="relative">
                            <span className={cx('absolute -left-6 w-3 border-rule-strong', i === 0 ? 'top-1/2 bottom-[-4px] border-l' : '-top-1 bottom-1/2 border-l')} aria-hidden style={{ left: -12 }} />
                            <span className="absolute top-1/2 w-3 border-t border-rule-strong" style={{ left: -12 }} aria-hidden />
                            <Branch node={node[key]} parent={key === 'sire' ? 'Sire' : 'Dam'} depth={depth - 1} />
                        </div>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

export default function PedigreeTree({ tree }: { tree: PedigreeNode }) {
    return (
        <div className="overflow-x-auto pb-2">
            <div className="w-max">
                <Branch node={tree} parent={null} depth={3} root />
            </div>
        </div>
    );
}
