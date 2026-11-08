import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { addDays, formatDayHeading, formatTime, todayIn } from '@/lib/datetime';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface SessionItem {
    id: number;
    day: string;
    starts_at: string;
    ends_at: string;
    title: string;
    type: string;
    type_label: string;
    status: { value: string; label: string };
    tutor: { id: number; name: string };
    clients: string[];
    meeting_mode: string;
}

interface Props {
    sessions: SessionItem[];
    filters: { from: string; to: string; tutor_id: string | number; status: string; q: string };
    statuses: { value: string; label: string }[];
    tutors: { id: number; name: string }[];
    is_tutor: boolean;
    can_create: boolean;
    truncated: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Jadwal', href: '/schedule' }];

export default function ScheduleIndex({ sessions, filters: initial, statuses, tutors, is_tutor, can_create, truncated }: Props) {
    const { timezone } = usePage<SharedData>().props;
    const { filters, set, setAll } = useFilters('schedule.index', initial);

    const today = todayIn(timezone);
    // Senin pada minggu berjalan: hari dalam seminggu dihitung dari tanggal polos supaya tidak terpengaruh zona waktu browser.
    const weekday = (new Date(`${today}T00:00:00Z`).getUTCDay() + 6) % 7;
    const thisMonday = addDays(today, -weekday);
    const ranges: { label: string; from: string; to: string }[] = [
        { label: 'Hari ini', from: today, to: today },
        { label: '7 hari ke depan', from: today, to: addDays(today, 6) },
        { label: 'Minggu ini', from: thisMonday, to: addDays(thisMonday, 6) },
        { label: 'Minggu depan', from: addDays(thisMonday, 7), to: addDays(thisMonday, 13) },
    ];

    const days = Array.from(new Set(sessions.map((s) => s.day)));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Jadwal" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={is_tutor ? 'Jadwal saya' : 'Jadwal'}
                    actions={
                        can_create && (
                            <Button asChild>
                                <Link href={route('schedule.create')}>
                                    <Plus /> Buat jadwal
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap gap-2">
                    {ranges.map((range) => (
                        <Button
                            key={range.label}
                            variant={filters.from === range.from && filters.to === range.to ? 'secondary' : 'outline'}
                            size="sm"
                            onClick={() => setAll((current) => ({ ...current, from: range.from, to: range.to }))}
                        >
                            {range.label}
                        </Button>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <label className="flex items-center gap-2 text-sm">
                        Dari
                        <Input type="date" value={filters.from} onChange={(e) => set('from', e.target.value)} className="w-40" />
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        Sampai
                        <Input type="date" value={filters.to} onChange={(e) => set('to', e.target.value)} className="w-40" />
                    </label>
                    {!is_tutor && (
                        <NativeSelect value={filters.tutor_id} onChange={(e) => set('tutor_id', e.target.value)} className="w-52" aria-label="Filter tutor">
                            <option value="">Semua tutor</option>
                            {tutors.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                    <NativeSelect value={filters.status} onChange={(e) => set('status', e.target.value)} className="w-40" aria-label="Filter status">
                        <option value="">Semua status</option>
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Input value={filters.q} onChange={(e) => set('q', e.target.value)} placeholder="Cari judul atau client" className="max-w-xs" aria-label="Cari jadwal" />
                </div>

                {sessions.length === 0 && <p className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Tidak ada sesi pada rentang ini.</p>}
                {truncated && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Hasil terlalu banyak, hanya sebagian yang ditampilkan. Persempit rentang tanggal atau filter.</p>}

                {days.map((day) => {
                    const items = sessions.filter((s) => s.day === day);
                    return (
                        <section key={day} className="flex flex-col gap-2">
                            <h2 className="text-sm font-semibold">
                                {formatDayHeading(day)}
                                {day === today && <span className="text-primary ml-2 text-xs font-medium">Hari ini</span>}
                                <span className="text-muted-foreground ml-2 text-xs font-normal">{items.length} sesi</span>
                            </h2>
                            <ul className="divide-y rounded-xl border">
                                {items.map((s) => (
                                    <li key={s.id}>
                                        <Link href={route('schedule.show', s.id)} className="hover:bg-muted/30 flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3">
                                            <span className="w-28 shrink-0 text-sm font-medium tabular-nums">
                                                {formatTime(s.starts_at, timezone)} - {formatTime(s.ends_at, timezone)}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">{s.title}</span>
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {is_tutor ? '' : `${s.tutor.name} · `}
                                                    {s.clients.length > 3 ? `${s.clients.slice(0, 3).join(', ')} +${s.clients.length - 3}` : s.clients.join(', ')} · {s.type_label} · {s.meeting_mode}
                                                </span>
                                            </span>
                                            <StatusBadge value={s.status.value} label={s.status.label} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}
            </div>
        </AppLayout>
    );
}
