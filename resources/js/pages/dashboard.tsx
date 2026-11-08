import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDayMonth, formatTime } from '@/lib/datetime';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface SessionItem {
    id: number;
    title: string;
    starts_at: string;
    ends_at: string;
    tutor: string;
    clients: string[];
}

interface Props {
    role: 'director' | 'admin' | 'tutor';
    today: SessionItem[];
    upcoming: SessionItem[];
    stats?: { sessions_today: number; unpaid_enrollments: number; unread_conversations: number; pending_drafts: number; new_clients_7d: number };
    expiring?: { id: number; client_id: number; client: string; package: string; ends_on: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

export default function Dashboard({ role, today, upcoming, stats, expiring }: Props) {
    const { timezone, auth } = usePage<SharedData>().props;
    const isTutor = role === 'tutor';

    const cards = stats
        ? [
              { label: 'Sesi hari ini', value: stats.sessions_today, href: route('schedule.index') },
              { label: 'Paket belum lunas', value: stats.unpaid_enrollments, href: route('clients.index') },
              { label: 'Chat belum dibaca', value: stats.unread_conversations, href: route('inbox.index') },
              { label: 'Draf jadwal menunggu', value: stats.pending_drafts, href: route('inbox.index') },
              { label: 'Client baru (7 hari)', value: stats.new_clients_7d, href: route('clients.index') },
          ]
        : [];

    const sessionList = (items: SessionItem[], withDate: boolean) =>
        items.length === 0 ? (
            <p className="text-muted-foreground text-sm">Tidak ada sesi.</p>
        ) : (
            <ul className="divide-y text-sm">
                {items.map((s) => (
                    <li key={s.id}>
                        <Link href={route('schedule.show', s.id)} className="hover:bg-muted/30 -mx-2 flex items-start gap-4 rounded-md px-2 py-3">
                            <span className="w-28 shrink-0 font-medium tabular-nums">
                                {withDate && <span className="text-muted-foreground block text-xs font-normal">{formatDayMonth(s.starts_at, timezone)}</span>}
                                {formatTime(s.starts_at, timezone)} - {formatTime(s.ends_at, timezone)}
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate font-medium">{s.title}</span>
                                <span className="text-muted-foreground block truncate text-xs">
                                    {isTutor ? '' : `${s.tutor} · `}
                                    {s.clients.slice(0, 3).join(', ')}
                                    {s.clients.length > 3 ? ` +${s.clients.length - 3}` : ''}
                                </span>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader title={`Halo, ${auth.user.name.split(' ')[0]}`} description={isTutor ? 'Jadwal bimbingan Anda.' : 'Ringkasan hari ini.'} />

                {cards.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        {cards.map((card) => (
                            <Link key={card.label} href={card.href} className="hover:bg-muted/30 rounded-xl border p-4">
                                <div className="text-muted-foreground text-sm">{card.label}</div>
                                <div className="mt-1 text-3xl font-semibold tabular-nums">{card.value}</div>
                            </Link>
                        ))}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Sesi hari ini</CardTitle>
                        </CardHeader>
                        <CardContent>{sessionList(today, false)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">7 hari ke depan</CardTitle>
                        </CardHeader>
                        <CardContent>{sessionList(upcoming, true)}</CardContent>
                    </Card>
                </div>

                {expiring && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Paket hampir berakhir (7 hari)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {expiring.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Tidak ada.</p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {expiring.map((e) => (
                                        <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                            <div>
                                                <Link href={route('clients.show', e.client_id)} className="font-medium hover:underline">
                                                    {e.client}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">{e.package}</div>
                                            </div>
                                            <span className="text-muted-foreground">Berakhir {formatDateOnly(e.ends_on)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
