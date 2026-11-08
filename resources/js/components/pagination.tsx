import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationProps {
    links: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
}

/** Label bawaan Laravel berbahasa Inggris ("&laquo; Previous"), diganti ke bahasa Indonesia. */
function labelOf(label: string): string {
    return label.replace('&laquo; Previous', 'Sebelumnya').replace('Next &raquo;', 'Berikutnya');
}

export function Pagination({ links, from, to, total }: PaginationProps) {
    if (links.length <= 3) {
        return total > 0 ? <p className="text-muted-foreground text-sm">{total} data</p> : null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-muted-foreground text-sm">
                {from}-{to} dari {total}
            </p>
            <nav className="flex flex-wrap gap-1" aria-label="Halaman">
                {links.map((link, index) => {
                    const className = cn(
                        'rounded-md border px-3 py-1.5 text-sm',
                        link.active ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-accent',
                        !link.url && 'text-muted-foreground pointer-events-none opacity-50',
                    );

                    return link.url ? (
                        <Link key={index} href={link.url} preserveScroll className={className}>
                            {labelOf(link.label)}
                        </Link>
                    ) : (
                        <span key={index} className={className}>
                            {labelOf(link.label)}
                        </span>
                    );
                })}
            </nav>
        </div>
    );
}
