import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '../components/ui';
import type { SharedProps } from '../types';

/** D-241: RBAC §8.1.10: a closed page says so and links to the person's own home. */
export default function ErrorPage({ status }: { status: number }) {
    const { home } = usePage<SharedProps & { home?: string }>().props;
    const forbidden = status === 403;
    return (
        <div className="mx-auto max-w-md py-16 text-center">
            <Head title={forbidden ? 'No access' : 'Not found'} />
            <h1 className="text-2xl font-bold text-cobalt-900">{forbidden ? "You don't have access to this page" : 'This page does not exist'}</h1>
            <p className="mt-2 text-ink-soft">{forbidden ? 'Your role does not include this page. Ask the administrator if you need it for your work.' : 'Check the address, or go back to your home page.'}</p>
            <Link href={home ?? '/'} className="mt-5 inline-block"><Button>Go to my home page</Button></Link>
        </div>
    );
}
