import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatLongDate, formatTime } from '@/lib/datetime';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Pencil, X } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Participant {
    id: number;
    client: { id: number; name: string; phone: string };
    attendance: { value: string; label: string };
    package: string | null;
    quota_remaining: number | null;
    quota_unlimited: boolean;
    quota_deducted: boolean;
}

interface Props {
    consultation: {
        id: number;
        title: string;
        topic: string | null;
        type: string;
        starts_at: string;
        ends_at: string;
        status: { value: string; label: string };
        meeting_mode: string;
        meeting_url: string | null;
        location: string | null;
        benefit: string | null;
        tutor: { id: number; name: string };
        tutor_notes: string | null;
        cancel_reason: string | null;
        created_by: string | null;
        capacity: number;
    };
    participants: Participant[];
    can: { edit: boolean; cancel: boolean; complete: boolean; complete_blocked_until_start: boolean };
}

export default function ScheduleShow({ consultation, participants, can }: Props) {
    const { timezone, auth } = usePage<SharedData>().props;
    const isStaff = auth.user.role !== 'tutor';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Jadwal', href: '/schedule' },
        { title: consultation.title, href: '#' },
    ];

    const details: [string, string | null][] = [
        ['Tanggal', formatLongDate(consultation.starts_at, timezone)],
        ['Jam', `${formatTime(consultation.starts_at, timezone)} - ${formatTime(consultation.ends_at, timezone)}`],
        ['Jenis', consultation.type],
        ['Layanan dipakai', consultation.benefit],
        ['Cara pertemuan', consultation.meeting_mode],
        ['Lokasi', consultation.location],
        ['Topik', consultation.topic],
        ['Dibuat oleh', consultation.created_by],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={consultation.title} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={consultation.title}
                    actions={
                        <>
                            <StatusBadge value={consultation.status.value} label={consultation.status.label} />
                            {can.edit && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('schedule.edit', consultation.id)}>
                                        <Pencil /> Ubah
                                    </Link>
                                </Button>
                            )}
                            {can.cancel && <CancelDialog consultationId={consultation.id} />}
                        </>
                    }
                />

                {consultation.status.value === 'cancelled' && (
                    <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        Sesi dibatalkan{consultation.cancel_reason ? `: ${consultation.cancel_reason}` : '.'}
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle className="text-base">Detail</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground text-xs">Tutor</dt>
                                    <dd>
                                        {isStaff ? (
                                            <Link href={route('tutors.show', consultation.tutor.id)} className="hover:underline">
                                                {consultation.tutor.name}
                                            </Link>
                                        ) : (
                                            consultation.tutor.name
                                        )}
                                    </dd>
                                </div>
                                {details.map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-xs">{label}</dt>
                                        <dd className="whitespace-pre-line">{value || '-'}</dd>
                                    </div>
                                ))}
                                {consultation.meeting_url && (
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Link meeting</dt>
                                        <dd>
                                            <a href={consultation.meeting_url} target="_blank" rel="noreferrer" className="text-primary break-all hover:underline">
                                                {consultation.meeting_url}
                                            </a>
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <div className="lg:col-span-2">
                        {can.complete ? (
                            <CompleteForm consultationId={consultation.id} participants={participants} />
                        ) : (
                            <ParticipantsCard participants={participants} notes={consultation.tutor_notes} blockedUntilStart={can.complete_blocked_until_start} />
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function quotaLabel(p: Participant): string {
    if (!p.package) return 'Tanpa paket';
    if (p.quota_unlimited) return `${p.package}: tanpa batas`;
    return `${p.package}: sisa ${p.quota_remaining ?? 0}`;
}

function ParticipantsCard({ participants, notes, blockedUntilStart }: { participants: Participant[]; notes: string | null; blockedUntilStart: boolean }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Peserta ({participants.length})</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
                <ul className="divide-y text-sm">
                    {participants.map((p) => (
                        <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                            <div>
                                <Link href={route('clients.show', p.client.id)} className="font-medium hover:underline">
                                    {p.client.name}
                                </Link>
                                <div className="text-muted-foreground text-xs">
                                    {quotaLabel(p)}
                                    {p.quota_deducted && ' · kuota terpotong'}
                                </div>
                            </div>
                            <StatusBadge value={p.attendance.value} label={p.attendance.label} />
                        </li>
                    ))}
                </ul>
                {blockedUntilStart && <p className="text-muted-foreground text-sm">Sesi bisa diselesaikan setelah waktu mulai tiba.</p>}
                {notes && (
                    <div>
                        <div className="text-muted-foreground mb-1 text-xs">Catatan tutor</div>
                        <p className="text-sm whitespace-pre-line">{notes}</p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function CompleteForm({ consultationId, participants }: { consultationId: number; participants: Participant[] }) {
    const { data, setData, post, processing, errors } = useForm<{ attendance: Record<string, string>; tutor_notes: string }>({
        attendance: Object.fromEntries(participants.map((p) => [String(p.id), p.attendance.value === 'absent' ? 'absent' : 'present'])),
        tutor_notes: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(route('schedule.complete', consultationId), { preserveScroll: true });
    }

    return (
        <form onSubmit={submit}>
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Selesaikan sesi</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-5">
                    <ul className="divide-y text-sm">
                        {participants.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <div className="font-medium">{p.client.name}</div>
                                    <div className="text-muted-foreground text-xs">{quotaLabel(p)}</div>
                                </div>
                                <NativeSelect
                                    className="h-9 w-40"
                                    value={data.attendance[String(p.id)]}
                                    onChange={(e) => setData('attendance', { ...data.attendance, [String(p.id)]: e.target.value })}
                                    aria-label={`Kehadiran ${p.client.name}`}
                                >
                                    <option value="present">Hadir</option>
                                    <option value="absent">Tidak hadir</option>
                                </NativeSelect>
                            </li>
                        ))}
                    </ul>
                    <Field label="Catatan sesi" htmlFor="tutor_notes" error={errors.tutor_notes} hint="Ringkasan progres dan tugas berikutnya.">
                        <Textarea id="tutor_notes" rows={4} value={data.tutor_notes} onChange={(e) => setData('tutor_notes', e.target.value)} />
                    </Field>
                    {/* "status" dikirim server untuk sesi yang belum dimulai atau sudah dibatalkan; bukan isian form. */}
                    {(errors as Record<string, string | undefined>).status && (
                        <p className="text-sm text-red-600">{(errors as Record<string, string | undefined>).status}</p>
                    )}
                    <div>
                        <Button type="submit" disabled={processing}>
                            Selesaikan sesi
                        </Button>
                        <p className="text-muted-foreground mt-2 text-xs">Kuota client yang hadir akan terpotong satu kali.</p>
                    </div>
                </CardContent>
            </Card>
        </form>
    );
}

function CancelDialog({ consultationId }: { consultationId: number }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(route('schedule.cancel', consultationId), {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <X /> Batalkan
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Batalkan sesi?</DialogTitle>
                        <DialogDescription>Slot tutor dan client dibebaskan. Kuota paket tidak terpotong.</DialogDescription>
                    </DialogHeader>
                    <Field label="Alasan" htmlFor="reason" error={errors.reason} required>
                        <Textarea id="reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoFocus />
                    </Field>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Kembali
                            </Button>
                        </DialogClose>
                        <Button type="submit" variant="destructive" disabled={processing || !data.reason.trim()}>
                            Batalkan sesi
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
