import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { phoneLabel } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

interface Props {
    tutor: {
        id: number;
        name: string;
        phone: string;
        degree: string;
        max_weekly_sessions: number;
        is_active: boolean;
        bio: string | null;
        fields: { name: string; group: string }[];
        availability: { day: string; slots: string[] }[];
    };
    upcoming: { id: number; title: string; starts_at: string; clients: string[] }[];
    can_update: boolean;
}

export default function TutorShow({ tutor, upcoming, can_update }: Props) {
    const { timezone } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tutor', href: '/tutors' },
        { title: tutor.name, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={tutor.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={tutor.name}
                    actions={
                        <>
                            <StatusBadge value={tutor.is_active ? 'active' : 'inactive'} label={tutor.is_active ? 'Aktif' : 'Nonaktif'} />
                            {can_update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('tutors.edit', tutor.id)}>
                                        <Pencil /> Ubah
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Profil</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm">
                            <dl className="grid grid-cols-2 gap-3">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Nomor WhatsApp</dt>
                                    <dd>{phoneLabel(tutor.phone)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Jenjang tertinggi</dt>
                                    <dd>{tutor.degree}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">Batas sesi per minggu</dt>
                                    <dd>{tutor.max_weekly_sessions}</dd>
                                </div>
                            </dl>
                            <div>
                                <div className="text-muted-foreground mb-2 text-xs">Bidang keahlian</div>
                                <div className="flex flex-wrap gap-2">
                                    {tutor.fields.map((f) => (
                                        <Badge key={f.name} variant="secondary" title={f.group}>
                                            {f.name}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                            {tutor.bio && <p className="whitespace-pre-line">{tutor.bio}</p>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Jam tersedia</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-2 text-sm">
                                {tutor.availability.map((day) => (
                                    <div key={day.day} className="flex gap-4">
                                        <dt className="w-20 shrink-0 font-medium">{day.day}</dt>
                                        <dd className={day.slots.length ? '' : 'text-muted-foreground'}>{day.slots.length ? day.slots.join(', ') : 'Tidak tersedia'}</dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Sesi mendatang</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {upcoming.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Belum ada sesi terjadwal.</p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {upcoming.map((s) => (
                                    <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                        <div>
                                            <Link href={route('schedule.show', s.id)} className="font-medium hover:underline">
                                                {s.title}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">{s.clients.join(', ')}</div>
                                        </div>
                                        <span className="text-muted-foreground">{formatDateTime(s.starts_at, timezone)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
