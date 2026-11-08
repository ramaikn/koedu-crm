import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { durationLabel, rupiah } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';

interface PackageItem {
    id: number;
    code: string;
    name: string;
    schedule_type: string;
    delivery: string;
    degree_level: string | null;
    duration_days: number;
    price: number;
    list_price: number | null;
    max_participants: number | null;
    is_active: boolean;
    benefits: { label: string; quantity: number | null }[];
}

interface Props {
    groups: { category: string; packages: PackageItem[] }[];
    can_edit: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Paket', href: '/packages' }];

export default function PackagesIndex({ groups, can_edit }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Paket" />
            <div className="flex flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title="Paket"
                    description={can_edit ? 'Mengubah paket tidak mengubah paket yang sudah dibeli client.' : undefined}
                    actions={
                        can_edit && (
                            <Button asChild>
                                <Link href={route('packages.create')}>
                                    <Plus /> Tambah paket
                                </Link>
                            </Button>
                        )
                    }
                />

                {groups.map((group) => (
                    <section key={group.category} className="flex flex-col gap-3">
                        <h2 className="text-base font-semibold">{group.category}</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Paket</th>
                                        <th className="px-4 py-3 font-medium">Masa aktif</th>
                                        <th className="px-4 py-3 font-medium">Harga</th>
                                        <th className="px-4 py-3 font-medium">Isi paket</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        {can_edit && <th className="px-4 py-3" />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {group.packages.map((p) => (
                                        <tr key={p.id} className="align-top">
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{p.name}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {p.code} · {p.schedule_type} · {p.delivery}
                                                    {p.degree_level ? ` · ${p.degree_level}` : ''}
                                                    {p.max_participants ? ` · maks. ${p.max_participants} peserta` : ''}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">{durationLabel(p.duration_days)}</td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="font-medium">{rupiah(p.price)}</div>
                                                {p.list_price && <div className="text-muted-foreground text-xs line-through">{rupiah(p.list_price)}</div>}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {p.benefits.map((b) => (
                                                        <Badge key={b.label} variant="secondary" className="font-normal">
                                                            {b.label}: {b.quantity ?? 'tanpa batas'}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge value={p.is_active ? 'active' : 'inactive'} label={p.is_active ? 'Dijual' : 'Nonaktif'} />
                                            </td>
                                            {can_edit && (
                                                <td className="px-4 py-3 text-right">
                                                    <Button variant="ghost" size="icon" asChild>
                                                        <Link href={route('packages.edit', p.id)} aria-label={`Ubah ${p.name}`}>
                                                            <Pencil />
                                                        </Link>
                                                    </Button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
