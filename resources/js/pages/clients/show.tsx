import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateOnly, formatDateTime, todayIn } from '@/lib/datetime';
import { phoneLabel, rupiah } from '@/lib/format';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarPlus, MessageCircle, Minus, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Quota {
    id: number;
    label: string;
    session_based: boolean;
    day_based: boolean;
    total: number | null;
    used: number;
    remaining: number | null;
}

interface EnrollmentItem {
    id: number;
    package: string;
    category: string;
    status: { value: string; label: string };
    starts_on: string;
    ends_on: string;
    tutor: string | null;
    tutor_id: number | null;
    notes: string | null;
    price: number | null;
    payment: { status: string; label: string; paid_at: string | null; method: string | null; has_proof: boolean } | null;
    quotas: Quota[];
}

interface Props {
    client: {
        id: number;
        name: string;
        phone: string;
        email: string | null;
        university: string | null;
        study_program: string | null;
        expertise_field: string | null;
        degree_level: string | null;
        status: { value: string; label: string };
        source: string | null;
        notes: string | null;
    };
    enrollments: EnrollmentItem[];
    sessions: { id: number; title: string; tutor: string; starts_at: string; status: { value: string; label: string } }[];
    conversation_id: number | null;
    packages: { id: number; name: string; category: string; price: number; duration_days: number }[];
    tutors: { id: number; name: string }[];
    can: { update: boolean; delete: boolean; enroll: boolean; schedule: boolean; manage_payment: boolean; see_finance: boolean };
}

export default function ClientShow({ client, enrollments, sessions, conversation_id, packages, tutors, can }: Props) {
    const { timezone } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Client', href: '/clients' },
        { title: client.name, href: '#' },
    ];

    const info: [string, string | null][] = [
        ['Nomor WhatsApp', phoneLabel(client.phone)],
        ['Email', client.email],
        ['Universitas', client.university],
        ['Jurusan', client.study_program],
        ['Bidang', client.expertise_field],
        ['Jenjang', client.degree_level],
        ['Sumber', client.source],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={client.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={client.name}
                    description={undefined}
                    actions={
                        <>
                            <StatusBadge value={client.status.value} label={client.status.label} />
                            {conversation_id && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('inbox.show', conversation_id)}>
                                        <MessageCircle /> Buka chat
                                    </Link>
                                </Button>
                            )}
                            {can.schedule && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('schedule.create', { client_id: client.id })}>
                                        <CalendarPlus /> Jadwalkan
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('clients.edit', client.id)}>
                                        <Pencil /> Ubah
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            <Trash2 /> Hapus
                                        </Button>
                                    }
                                    title="Hapus client?"
                                    description="Client disembunyikan dari daftar. Riwayat sesi dan pembayarannya tetap tersimpan."
                                    confirmLabel="Hapus"
                                    destructive
                                    onConfirm={() => router.delete(route('clients.destroy', client.id))}
                                />
                            )}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle className="text-base">Informasi</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-3 text-sm">
                                {info.map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground text-xs">{label}</dt>
                                        <dd>{value || '-'}</dd>
                                    </div>
                                ))}
                                {client.notes && (
                                    <div>
                                        <dt className="text-muted-foreground text-xs">Catatan</dt>
                                        <dd className="whitespace-pre-line">{client.notes}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold">Paket</h2>
                            {can.enroll && <AddEnrollmentDialog clientId={client.id} packages={packages} tutors={tutors} timezone={timezone} />}
                        </div>

                        {enrollments.length === 0 && <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Belum ada paket.</p>}
                        {enrollments.map((enrollment) => (
                            <EnrollmentCard key={enrollment.id} enrollment={enrollment} tutors={tutors} can={can} timezone={timezone} />
                        ))}

                        <h2 className="mt-2 text-base font-semibold">Riwayat sesi</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <tbody className="divide-y">
                                    {sessions.length === 0 && (
                                        <tr>
                                            <td className="text-muted-foreground px-4 py-6 text-center">Belum ada sesi.</td>
                                        </tr>
                                    )}
                                    {sessions.map((s) => (
                                        <tr key={s.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 whitespace-nowrap">{formatDateTime(s.starts_at, timezone)}</td>
                                            <td className="px-4 py-3">
                                                <Link href={route('schedule.show', s.id)} className="font-medium hover:underline">
                                                    {s.title}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">{s.tutor}</div>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <StatusBadge value={s.status.value} label={s.status.label} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function EnrollmentCard({
    enrollment,
    tutors,
    can,
    timezone,
}: {
    enrollment: EnrollmentItem;
    tutors: { id: number; name: string }[];
    can: Props['can'];
    timezone: string;
}) {
    const adjust = (quotaId: number, delta: 1 | -1) => router.patch(route('enrollments.quota', quotaId), { delta }, { preserveScroll: true });

    return (
        <Card>
            <CardHeader className="gap-1">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-base">{enrollment.package}</CardTitle>
                        <p className="text-muted-foreground text-xs">
                            {enrollment.category} · {formatDateOnly(enrollment.starts_on)} - {formatDateOnly(enrollment.ends_on)}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <StatusBadge value={enrollment.status.value} label={enrollment.status.label} />
                        {enrollment.payment && <StatusBadge value={enrollment.payment.status} label={enrollment.payment.label} />}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="text-muted-foreground flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    {enrollment.price !== null && <span>Harga: {rupiah(enrollment.price)}</span>}
                    {can.enroll && enrollment.status.value === 'active' ? (
                        <label className="flex items-center gap-2">
                            Pembimbing:
                            <NativeSelect
                                className="h-8 w-52 text-sm"
                                value={enrollment.tutor_id ?? ''}
                                onChange={(e) =>
                                    router.put(route('enrollments.update', enrollment.id), { primary_tutor_id: e.target.value || null }, { preserveScroll: true })
                                }
                            >
                                <option value="">Belum ditentukan</option>
                                {tutors.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </label>
                    ) : (
                        <span>Pembimbing: {enrollment.tutor ?? '-'}</span>
                    )}
                </div>

                {enrollment.quotas.length > 0 && (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {enrollment.quotas.map((quota) => (
                            <QuotaRow
                                key={quota.id}
                                quota={quota}
                                editable={can.enroll && enrollment.status.value === 'active' && !quota.session_based && !quota.day_based}
                                onAdjust={adjust}
                            />
                        ))}
                    </div>
                )}

                {enrollment.payment && can.manage_payment && (
                    <div className="flex flex-wrap items-center gap-3 border-t pt-4 text-sm">
                        {enrollment.payment.status === 'paid' ? (
                            <>
                                <span className="text-muted-foreground">
                                    Dibayar {enrollment.payment.paid_at ? formatDate(enrollment.payment.paid_at, timezone) : ''}
                                    {enrollment.payment.method ? ` via ${enrollment.payment.method}` : ''}
                                </span>
                                {enrollment.payment.has_proof && (
                                    <a href={route('enrollments.proof', enrollment.id)} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                        Lihat bukti bayar
                                    </a>
                                )}
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            Batalkan pembayaran
                                        </Button>
                                    }
                                    title="Batalkan catatan pembayaran?"
                                    description="Status paket kembali menjadi belum lunas. Gunakan ini hanya bila pembayaran salah dicatat."
                                    confirmLabel="Batalkan pembayaran"
                                    destructive
                                    onConfirm={() => router.delete(route('enrollments.unpay', enrollment.id), { preserveScroll: true })}
                                />
                            </>
                        ) : (
                            <PaymentDialog enrollmentId={enrollment.id} timezone={timezone} />
                        )}
                    </div>
                )}

                {can.enroll && enrollment.status.value === 'active' && (
                    <div className="flex justify-end">
                        <ConfirmDialog
                            trigger={
                                <Button variant="ghost" size="sm" className="text-muted-foreground">
                                    Batalkan paket
                                </Button>
                            }
                            title="Batalkan paket ini?"
                            description="Paket tidak bisa dipakai lagi untuk sesi baru. Sesi yang sudah selesai tetap tercatat."
                            confirmLabel="Batalkan paket"
                            destructive
                            onConfirm={() => router.put(route('enrollments.update', enrollment.id), { status: 'cancelled' }, { preserveScroll: true })}
                        />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function QuotaRow({ quota, editable, onAdjust }: { quota: Quota; editable: boolean; onAdjust: (id: number, delta: 1 | -1) => void }) {
    const percent = quota.total ? Math.min(100, Math.round((quota.used / quota.total) * 100)) : 0;
    const exhausted = quota.total !== null && quota.used >= quota.total;

    return (
        <div className="rounded-lg border p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
                <span>{quota.label}</span>
                <span className="flex items-center gap-1 font-medium tabular-nums">
                    {editable && (
                        <Button variant="outline" size="icon" className="size-6" onClick={() => onAdjust(quota.id, -1)} disabled={quota.used <= 0} aria-label="Kurangi pemakaian">
                            <Minus />
                        </Button>
                    )}
                    {quota.total === null ? `${quota.used} dipakai` : `${quota.used}/${quota.total}`}
                    {editable && (
                        <Button variant="outline" size="icon" className="size-6" onClick={() => onAdjust(quota.id, 1)} disabled={exhausted} aria-label="Tambah pemakaian">
                            <Plus />
                        </Button>
                    )}
                </span>
            </div>
            {quota.total !== null && (
                <div className="bg-muted mt-2 h-1.5 overflow-hidden rounded-full">
                    <div className={exhausted ? 'h-full bg-red-500' : 'bg-primary h-full'} style={{ width: `${percent}%` }} />
                </div>
            )}
        </div>
    );
}

function AddEnrollmentDialog({
    clientId,
    packages,
    tutors,
    timezone,
}: {
    clientId: number;
    packages: Props['packages'];
    tutors: Props['tutors'];
    timezone: string;
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ package_id: '', starts_on: todayIn(timezone), primary_tutor_id: '', notes: '' });
    const categories = Array.from(new Set(packages.map((p) => p.category)));

    function submit(e: FormEvent) {
        e.preventDefault();
        post(route('enrollments.store', clientId), {
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
                <Button size="sm">
                    <Plus /> Tambah paket
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Tambah paket</DialogTitle>
                        <DialogDescription>Harga dan kuota disalin dari katalog saat ini. Perubahan katalog nanti tidak mengubah paket ini.</DialogDescription>
                    </DialogHeader>
                    <Field label="Paket" htmlFor="package_id" error={errors.package_id} required>
                        <NativeSelect id="package_id" value={data.package_id} onChange={(e) => setData('package_id', e.target.value)}>
                            <option value="">Pilih paket</option>
                            {categories.map((category) => (
                                <optgroup key={category} label={category}>
                                    {packages
                                        .filter((p) => p.category === category)
                                        .map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name} ({rupiah(p.price)})
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Mulai tanggal" htmlFor="starts_on" error={errors.starts_on} required>
                        <Input id="starts_on" type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                    </Field>
                    <Field label="Tutor pembimbing" htmlFor="primary_tutor_id" error={errors.primary_tutor_id} hint="Boleh dikosongkan dan ditentukan nanti.">
                        <NativeSelect id="primary_tutor_id" value={data.primary_tutor_id} onChange={(e) => setData('primary_tutor_id', e.target.value)}>
                            <option value="">Belum ditentukan</option>
                            {tutors.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Catatan" htmlFor="enroll_notes" error={errors.notes}>
                        <Textarea id="enroll_notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                    </Field>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing || !data.package_id}>
                            Tambah
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const PAYMENT_METHODS = ['Transfer BCA', 'Transfer BRI', 'Transfer Mandiri', 'QRIS', 'Tunai'];

function PaymentDialog({ enrollmentId, timezone }: { enrollmentId: number; timezone: string }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<{ payment_method: string; paid_on: string; proof: File | null }>({
        payment_method: PAYMENT_METHODS[0],
        paid_on: todayIn(timezone),
        proof: null,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(route('enrollments.payment', enrollmentId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setOpen(false);
                reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Catat pembayaran
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Catat pembayaran</DialogTitle>
                        <DialogDescription>Paket ini akan ditandai lunas. Pembayaran dicatat satu kali, tanpa DP atau cicilan.</DialogDescription>
                    </DialogHeader>
                    <Field label="Metode" htmlFor="payment_method" error={errors.payment_method} required>
                        <Input id="payment_method" list="payment-methods" value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)} />
                        <datalist id="payment-methods">
                            {PAYMENT_METHODS.map((m) => (
                                <option key={m} value={m} />
                            ))}
                        </datalist>
                    </Field>
                    <Field label="Tanggal bayar" htmlFor="paid_on" error={errors.paid_on} required>
                        <Input id="paid_on" type="date" max={todayIn(timezone)} value={data.paid_on} onChange={(e) => setData('paid_on', e.target.value)} />
                    </Field>
                    <Field label="Bukti bayar" htmlFor="proof" error={errors.proof} hint="Gambar (JPG/PNG) atau PDF, maksimal 5 MB. Boleh dikosongkan.">
                        <Input id="proof" type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => setData('proof', e.target.files?.[0] ?? null)} />
                    </Field>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            Simpan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
