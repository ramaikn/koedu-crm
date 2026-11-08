import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface TutorRow {
    id: number;
    name: string;
    degree: string;
    fields: string[];
    weekly_load: number;
    max_weekly_sessions: number;
    is_active: boolean;
}

interface Props {
    tutors: {
        data: TutorRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { q: string; field: string | number; degree: string; active: string };
    fields: { id: number; name: string }[];
    can_create: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tutor', href: '/tutors' }];

export default function TutorsIndex({ tutors, filters: initial, fields, can_create }: Props) {
    const { filters, set } = useFilters('tutors.index', initial);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tutor" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Tutor"
                    actions={
                        can_create && (
                            <Button asChild>
                                <Link href={route('tutors.create')}>
                                    <Plus /> Tambah tutor
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap gap-3">
                    <Input value={filters.q} onChange={(e) => set('q', e.target.value)} placeholder="Cari nama tutor" className="max-w-xs" aria-label="Cari tutor" />
                    <NativeSelect value={filters.field} onChange={(e) => set('field', e.target.value)} className="w-56" aria-label="Filter bidang">
                        <option value="">Semua bidang</option>
                        {fields.map((f) => (
                            <option key={f.id} value={f.id}>
                                {f.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect value={filters.degree} onChange={(e) => set('degree', e.target.value)} className="w-36" aria-label="Filter jenjang">
                        <option value="">Semua jenjang</option>
                        <option value="S2">S2</option>
                        <option value="S3">S3</option>
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
                                <th className="px-4 py-3 font-medium">Jenjang</th>
                                <th className="px-4 py-3 font-medium">Bidang</th>
                                <th className="px-4 py-3 font-medium">Sesi minggu ini</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {tutors.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-8 text-center">
                                        Tidak ada tutor yang cocok.
                                    </td>
                                </tr>
                            )}
                            {tutors.data.map((tutor) => {
                                const full = tutor.weekly_load >= tutor.max_weekly_sessions;
                                return (
                                    <tr key={tutor.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <Link href={route('tutors.show', tutor.id)} className="font-medium hover:underline">
                                                {tutor.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">{tutor.degree}</td>
                                        <td className="px-4 py-3">
                                            {tutor.fields.slice(0, 2).join(', ')}
                                            {tutor.fields.length > 2 && <span className="text-muted-foreground"> +{tutor.fields.length - 2} lagi</span>}
                                        </td>
                                        <td className={cn('px-4 py-3 tabular-nums', full && 'font-medium text-red-600')}>
                                            {tutor.weekly_load}/{tutor.max_weekly_sessions}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge value={tutor.is_active ? 'active' : 'inactive'} label={tutor.is_active ? 'Aktif' : 'Nonaktif'} />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <Pagination links={tutors.links} from={tutors.from} to={tutors.to} total={tutors.total} />
            </div>
        </AppLayout>
    );
}
