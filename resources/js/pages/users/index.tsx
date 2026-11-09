import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    tutor_name: string | null;
    is_active: boolean;
}

interface Props {
    users: {
        data: UserRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { q: string; role: string; active: string };
    roles: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pengguna', href: '/users' }];

export default function UsersIndex({ users, filters: initial, roles }: Props) {
    const { filters, set } = useFilters('users.index', initial);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pengguna" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Pengguna"
                    actions={
                        <Button asChild>
                            <Link href={route('users.create')}>
                                <Plus /> Tambah akun
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap gap-3">
                    <Input value={filters.q} onChange={(e) => set('q', e.target.value)} placeholder="Cari nama atau email" className="max-w-xs" aria-label="Cari pengguna" />
                    <NativeSelect value={filters.role} onChange={(e) => set('role', e.target.value)} className="w-40" aria-label="Filter role">
                        <option value="">Semua role</option>
                        {roles.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.active} onChange={(e) => set('active', e.target.value)} className="w-36" aria-label="Filter status">
                        <option value="">Semua status</option>
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </NativeSelect>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="px-4 py-3 font-medium">Nama</th>
                                <th className="px-4 py-3 font-medium">Role</th>
                                <th className="px-4 py-3 font-medium">Profil tutor</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-8 text-center">
                                        Tidak ada akun yang cocok.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr key={user.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">
                                        <div className="font-medium">{user.name}</div>
                                        <div className="text-muted-foreground text-xs">{user.email}</div>
                                    </td>
                                    <td className="px-4 py-3">{user.role_label}</td>
                                    <td className="px-4 py-3">{user.tutor_name ?? <span className="text-muted-foreground">-</span>}</td>
                                    <td className="px-4 py-3">
                                        <StatusBadge value={user.is_active ? 'active' : 'inactive'} label={user.is_active ? 'Aktif' : 'Nonaktif'} />
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Button variant="ghost" size="icon" asChild>
                                            <Link href={route('users.edit', user.id)} aria-label={`Ubah ${user.name}`}>
                                                <Pencil />
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination links={users.links} from={users.from} to={users.to} total={users.total} />
            </div>
        </AppLayout>
    );
}
