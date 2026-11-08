import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { phoneLabel } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface ClientRow {
    id: number;
    name: string;
    phone: string;
    university: string | null;
    study_program: string | null;
    degree_level: string | null;
    status: { value: string; label: string };
    active_packages: number;
}

interface Paginator<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    clients: Paginator<ClientRow>;
    filters: { q: string; status: string };
    statuses: { value: string; label: string }[];
    can_create: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Client', href: '/clients' }];

export default function ClientsIndex({ clients, filters: initial, statuses, can_create }: Props) {
    const { filters, set } = useFilters('clients.index', initial);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Client" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Client"
                    actions={
                        can_create && (
                            <Button asChild>
                                <Link href={route('clients.create')}>
                                    <Plus /> Tambah client
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap gap-3">
                    <Input
                        value={filters.q}
                        onChange={(e) => set('q', e.target.value)}
                        placeholder="Cari nama, nomor, email, atau universitas"
                        className="max-w-sm"
                        aria-label="Cari client"
                    />
                    <NativeSelect value={filters.status} onChange={(e) => set('status', e.target.value)} className="w-44" aria-label="Filter status">
                        <option value="">Semua status</option>
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nama</th>
                                <th className="px-4 py-3 font-medium">Universitas</th>
                                <th className="px-4 py-3 font-medium">Jenjang</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Paket aktif</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {clients.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-8 text-center">
                                        Tidak ada client yang cocok.
                                    </td>
                                </tr>
                            )}
                            {clients.data.map((client) => (
                                <tr key={client.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">
                                        <Link href={route('clients.show', client.id)} className="font-medium hover:underline">
                                            {client.name}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">{phoneLabel(client.phone)}</div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div>{client.university ?? '-'}</div>
                                        <div className="text-muted-foreground text-xs">{client.study_program}</div>
                                    </td>
                                    <td className="px-4 py-3">{client.degree_level ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge value={client.status.value} label={client.status.label} />
                                    </td>
                                    <td className="px-4 py-3">{client.active_packages}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination links={clients.links} from={clients.from} to={clients.to} total={clients.total} />
            </div>
        </AppLayout>
    );
}
