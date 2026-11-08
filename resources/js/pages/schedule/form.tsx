import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useDebounced } from '@/hooks/use-debounced';
import AppLayout from '@/layouts/app-layout';
import { addDays, todayIn } from '@/lib/datetime';
import { getJson, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, TriangleAlert, X } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';

type Option = { value: string; label: string };

interface ClientLite {
    id: number;
    name: string;
    phone: string;
}

interface ConsultationData {
    id: number;
    type: string;
    tutor_id: number;
    title: string;
    topic: string | null;
    date: string;
    start_time: string;
    duration_minutes: number;
    meeting_mode: string;
    meeting_url: string | null;
    location: string | null;
    capacity: number;
    benefit_type: string | null;
}

interface Suggestion {
    tutor_id: number;
    name: string;
    degree: string;
    fields: string[];
    score: number;
    reasons: string[];
    free: boolean;
    weekly_load: number;
    weekly_max: number;
}

interface CheckResult {
    ready: boolean;
    errors: string[];
    warnings: string[];
}

interface Props {
    consultation: ConsultationData | null;
    preset_clients: ClientLite[];
    tutors: { id: number; name: string; degree: string; fields: string[] }[];
    types: Option[];
    meeting_modes: Option[];
    benefit_types: Option[];
}

const DURATIONS = [30, 45, 60, 90, 120];
const DEFAULT_BENEFIT: Record<string, string> = { private: 'zoom_session', group: 'group_meeting' };

export default function ScheduleForm({ consultation, preset_clients, tutors, types, meeting_modes, benefit_types }: Props) {
    const { timezone } = usePage<SharedData>().props;
    const editing = consultation !== null;

    const [clients, setClients] = useState<ClientLite[]>(preset_clients);
    const { data, setData, post, put, processing, errors } = useForm({
        type: consultation?.type ?? 'private',
        client_ids: preset_clients.map((c) => c.id),
        benefit_type: consultation?.benefit_type ?? DEFAULT_BENEFIT.private,
        date: consultation?.date ?? addDays(todayIn(timezone), 1),
        start_time: consultation?.start_time ?? '10:00',
        duration_minutes: consultation?.duration_minutes ?? 60,
        tutor_id: consultation?.tutor_id ? String(consultation.tutor_id) : '',
        title: consultation?.title ?? '',
        topic: consultation?.topic ?? '',
        meeting_mode: consultation?.meeting_mode ?? 'zoom',
        meeting_url: consultation?.meeting_url ?? '',
        location: consultation?.location ?? '',
        capacity: consultation?.capacity ?? 20,
        confirm_warnings: false,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Jadwal', href: '/schedule' },
        { title: editing ? 'Ubah jadwal' : 'Buat jadwal', href: '#' },
    ];

    function changeType(type: string) {
        setData((current) => ({
            ...current,
            type,
            // Pilihan layanan mengikuti jenis sesi, kecuali admin sudah memilih layanan lain.
            benefit_type: current.benefit_type === DEFAULT_BENEFIT[current.type] ? DEFAULT_BENEFIT[type] : current.benefit_type,
        }));
        if (type === 'private' && clients.length > 1) {
            const first = clients.slice(0, 1);
            setClients(first);
            setData('client_ids', first.map((c) => c.id));
        }
    }

    function addClient(client: ClientLite) {
        if (clients.some((c) => c.id === client.id)) return;
        const next = data.type === 'private' ? [client] : [...clients, client];
        setClients(next);
        setData('client_ids', next.map((c) => c.id));
    }

    function removeClient(id: number) {
        const next = clients.filter((c) => c.id !== id);
        setClients(next);
        setData('client_ids', next.map((c) => c.id));
    }

    // Pemeriksaan jadwal dan saran tutor dijalankan di server tiap isian berubah (setelah jeda singkat),
    // supaya admin melihat bentrok dan peringatan sebelum menyimpan.
    const checkPayload = useMemo(
        () => ({
            consultation_id: consultation?.id ?? null,
            type: data.type,
            tutor_id: data.tutor_id,
            date: data.date,
            start_time: data.start_time,
            duration_minutes: data.duration_minutes,
            client_ids: data.client_ids,
            benefit_type: data.benefit_type,
            capacity: data.capacity,
        }),
        [consultation?.id, data.type, data.tutor_id, data.date, data.start_time, data.duration_minutes, data.client_ids, data.benefit_type, data.capacity],
    );
    const debouncedCheck = useDebounced(checkPayload, 400);
    const [check, setCheck] = useState<CheckResult>({ ready: false, errors: [], warnings: [] });
    const [suggestions, setSuggestions] = useState<{ eligible: Suggestion[]; blocked: Suggestion[] } | null>(null);
    const warningKey = useRef('');

    useEffect(() => {
        const controller = new AbortController();
        postJson<CheckResult>(route('schedule.check'), debouncedCheck, controller.signal)
            .then((result) => {
                setCheck(result);
                // Peringatan yang berubah harus dikonfirmasi ulang.
                const key = result.warnings.join('|');
                if (key !== warningKey.current) {
                    warningKey.current = key;
                    setData('confirm_warnings', false);
                }
            })
            .catch(() => undefined);
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedCheck]);

    useEffect(() => {
        if (!debouncedCheck.date || !debouncedCheck.start_time || !debouncedCheck.duration_minutes) {
            setSuggestions(null);
            return;
        }
        const controller = new AbortController();
        postJson<{ eligible: Suggestion[]; blocked: Suggestion[] }>(
            route('schedule.suggest'),
            {
                date: debouncedCheck.date,
                start_time: debouncedCheck.start_time,
                duration_minutes: debouncedCheck.duration_minutes,
                client_id: debouncedCheck.client_ids[0] ?? null,
                consultation_id: debouncedCheck.consultation_id,
            },
            controller.signal,
        )
            .then(setSuggestions)
            .catch(() => undefined);
        return () => controller.abort();
    }, [debouncedCheck.date, debouncedCheck.start_time, debouncedCheck.duration_minutes, debouncedCheck.client_ids, debouncedCheck.consultation_id]);

    const durations = DURATIONS.includes(data.duration_minutes) ? DURATIONS : [...DURATIONS, data.duration_minutes].sort((a, b) => a - b);
    const blocked = check.errors.length > 0;
    const needsConfirm = check.warnings.length > 0 && !data.confirm_warnings;
    const canSubmit = !processing && !blocked && !needsConfirm && data.client_ids.length > 0 && data.tutor_id !== '';

    function submit(e: FormEvent) {
        e.preventDefault();
        if (editing) {
            put(route('schedule.update', consultation.id));
        } else {
            post(route('schedule.store'));
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? 'Ubah jadwal' : 'Buat jadwal'} />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title={editing ? 'Ubah jadwal' : 'Buat jadwal'} />

                <Field label="Jenis sesi" error={errors.type}>
                    <div className="inline-flex rounded-md border p-1" role="radiogroup" aria-label="Jenis sesi">
                        {types.map((t) => (
                            <button
                                key={t.value}
                                type="button"
                                role="radio"
                                aria-checked={data.type === t.value}
                                onClick={() => changeType(t.value)}
                                className={cn('rounded px-4 py-1.5 text-sm', data.type === t.value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </Field>

                <Field label={data.type === 'group' ? 'Peserta' : 'Client'} error={errors.client_ids} required>
                    <ClientPicker clients={clients} onAdd={addClient} onRemove={removeClient} multiple={data.type === 'group'} />
                </Field>

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Layanan yang dipakai" htmlFor="benefit_type" error={errors.benefit_type} hint="Kuota ini dipotong dari paket client saat sesi selesai.">
                        <NativeSelect id="benefit_type" value={data.benefit_type ?? ''} onChange={(e) => setData('benefit_type', e.target.value)}>
                            {benefit_types.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    {data.type === 'group' && (
                        <Field label="Kapasitas kelas" htmlFor="capacity" error={errors.capacity}>
                            <Input id="capacity" type="number" min={1} max={100} value={data.capacity} onChange={(e) => setData('capacity', Number(e.target.value))} />
                        </Field>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-3">
                    <Field label="Tanggal" htmlFor="date" error={errors.date} required>
                        <Input id="date" type="date" value={data.date} onChange={(e) => setData('date', e.target.value)} />
                    </Field>
                    <Field label="Jam mulai" htmlFor="start_time" error={errors.start_time} required>
                        <Input id="start_time" type="time" value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} />
                    </Field>
                    <Field label="Durasi" htmlFor="duration_minutes" error={errors.duration_minutes} required>
                        <NativeSelect id="duration_minutes" value={data.duration_minutes} onChange={(e) => setData('duration_minutes', Number(e.target.value))}>
                            {durations.map((d) => (
                                <option key={d} value={d}>
                                    {d} menit
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                </div>

                <Field label="Tutor" htmlFor="tutor_id" error={errors.tutor_id} required>
                    <SuggestionList
                        suggestions={suggestions}
                        selectedId={data.tutor_id}
                        hasClient={data.client_ids.length > 0}
                        onSelect={(id) => setData('tutor_id', String(id))}
                    />
                    <NativeSelect id="tutor_id" value={data.tutor_id} onChange={(e) => setData('tutor_id', e.target.value)}>
                        <option value="">Pilih tutor</option>
                        {tutors.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.name} ({t.degree}) - {t.fields.slice(0, 2).join(', ')}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Cara pertemuan" htmlFor="meeting_mode" error={errors.meeting_mode} required>
                        <NativeSelect id="meeting_mode" value={data.meeting_mode} onChange={(e) => setData('meeting_mode', e.target.value)}>
                            {meeting_modes.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    {data.meeting_mode === 'offline' ? (
                        <Field label="Lokasi" htmlFor="location" error={errors.location}>
                            <Input id="location" value={data.location} onChange={(e) => setData('location', e.target.value)} />
                        </Field>
                    ) : (
                        <Field label="Link meeting" htmlFor="meeting_url" error={errors.meeting_url} hint="Boleh dikosongkan dan diisi nanti.">
                            <Input id="meeting_url" type="url" placeholder="https://" value={data.meeting_url} onChange={(e) => setData('meeting_url', e.target.value)} />
                        </Field>
                    )}
                    <Field label="Judul" htmlFor="title" error={errors.title} hint="Kosongkan untuk judul otomatis." className="sm:col-span-2">
                        <Input id="title" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    </Field>
                    <Field label="Topik / catatan" htmlFor="topic" error={errors.topic} className="sm:col-span-2">
                        <Textarea id="topic" value={data.topic} onChange={(e) => setData('topic', e.target.value)} rows={3} />
                    </Field>
                </div>

                <CheckPanel
                    check={check}
                    serverError={(errors as Record<string, string | undefined>).schedule}
                    confirmed={data.confirm_warnings}
                    onConfirm={(value) => setData('confirm_warnings', value)}
                    confirmError={errors.confirm_warnings}
                />

                <div className="flex gap-2">
                    <Button type="submit" disabled={!canSubmit}>
                        {editing ? 'Simpan perubahan' : 'Buat jadwal'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={editing ? route('schedule.show', consultation.id) : route('schedule.index')}>Batal</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}

function ClientPicker({ clients, onAdd, onRemove, multiple }: { clients: ClientLite[]; onAdd: (c: ClientLite) => void; onRemove: (id: number) => void; multiple: boolean }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ClientLite[]>([]);
    const [open, setOpen] = useState(false);
    const debounced = useDebounced(query, 250);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        getJson<ClientLite[]>(`${route('clients.search')}?q=${encodeURIComponent(debounced)}`, controller.signal)
            .then(setResults)
            .catch(() => undefined);
        return () => controller.abort();
    }, [debounced, open]);

    const showInput = multiple || clients.length === 0;

    return (
        <div className="grid gap-2">
            {clients.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {clients.map((c) => (
                        <span key={c.id} className="bg-secondary inline-flex items-center gap-2 rounded-full py-1 pr-1 pl-3 text-sm">
                            {c.name}
                            <button type="button" onClick={() => onRemove(c.id)} className="hover:bg-background rounded-full p-1" aria-label={`Hapus ${c.name}`}>
                                <X className="size-3" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            {showInput && (
                <div className="relative">
                    <Input
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onBlur={() => setTimeout(() => setOpen(false), 150)}
                        placeholder={multiple ? 'Cari dan tambah peserta' : 'Cari nama atau nomor client'}
                        aria-label="Cari client"
                        autoComplete="off"
                    />
                    {open && (
                        <ul className="bg-popover absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-md border shadow-md" role="listbox">
                            {results.length === 0 && <li className="text-muted-foreground px-3 py-2 text-sm">Client tidak ditemukan.</li>}
                            {results.map((c) => (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        className="hover:bg-accent flex w-full flex-col px-3 py-2 text-left text-sm"
                                        // onMouseDown agar terpilih sebelum kolom kehilangan fokus.
                                        onMouseDown={(e) => {
                                            e.preventDefault();
                                            onAdd(c);
                                            setQuery('');
                                            setOpen(false);
                                        }}
                                    >
                                        <span className="font-medium">{c.name}</span>
                                        <span className="text-muted-foreground text-xs">+{c.phone}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

function SuggestionList({
    suggestions,
    selectedId,
    hasClient,
    onSelect,
}: {
    suggestions: { eligible: Suggestion[]; blocked: Suggestion[] } | null;
    selectedId: string;
    hasClient: boolean;
    onSelect: (id: number) => void;
}) {
    if (!suggestions) return null;

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <div className="text-sm font-medium">Saran tutor</div>
            {!hasClient && <p className="text-muted-foreground text-xs">Pilih client dulu supaya saran mengikuti bidang dan jenjangnya.</p>}
            {suggestions.eligible.length === 0 && <p className="text-muted-foreground text-sm">Tidak ada tutor yang kosong di jam ini.</p>}
            <ul className="grid gap-2">
                {suggestions.eligible.map((s) => (
                    <li key={s.tutor_id} className={cn('flex flex-wrap items-start justify-between gap-3 rounded-md border p-3', String(s.tutor_id) === selectedId && 'border-primary bg-primary/5')}>
                        <div className="min-w-0">
                            <div className="text-sm font-medium">
                                {s.name} <span className="text-muted-foreground font-normal">({s.degree})</span>
                            </div>
                            <div className="text-muted-foreground text-xs">{s.fields.slice(0, 3).join(', ')}</div>
                            <div className="mt-1 flex flex-wrap gap-1.5">
                                {s.reasons.map((reason) => (
                                    <span key={reason} className={cn('rounded bg-muted px-1.5 py-0.5 text-[11px]', reason === 'Di luar jam tersedia' && 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300')}>
                                        {reason}
                                    </span>
                                ))}
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="text-muted-foreground text-xs tabular-nums">Skor {s.score}</span>
                            <Button type="button" size="sm" variant={String(s.tutor_id) === selectedId ? 'secondary' : 'outline'} onClick={() => onSelect(s.tutor_id)}>
                                {String(s.tutor_id) === selectedId ? 'Dipilih' : 'Pilih'}
                            </Button>
                        </div>
                    </li>
                ))}
            </ul>
            {suggestions.blocked.length > 0 && (
                <p className="text-muted-foreground text-xs">
                    Cocok tapi sudah ada sesi di jam ini: {suggestions.blocked.map((s) => s.name).join(', ')}.
                </p>
            )}
        </div>
    );
}

function CheckPanel({
    check,
    serverError,
    confirmed,
    onConfirm,
    confirmError,
}: {
    check: CheckResult;
    serverError?: string;
    confirmed: boolean;
    onConfirm: (value: boolean) => void;
    confirmError?: string;
}) {
    const errors = check.errors.length > 0 ? check.errors : serverError ? serverError.split('\n') : [];

    if (!check.ready && errors.length === 0) {
        return <p className="text-muted-foreground text-sm">Lengkapi client, tutor, tanggal, dan jam untuk memeriksa jadwal.</p>;
    }

    return (
        <div className="grid gap-3" aria-live="polite">
            {errors.length > 0 && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    <div className="mb-1 flex items-center gap-2 font-medium">
                        <CircleAlert className="size-4" /> Jadwal tidak bisa disimpan
                    </div>
                    <ul className="list-disc pl-6">
                        {errors.map((e) => (
                            <li key={e}>{e}</li>
                        ))}
                    </ul>
                </div>
            )}
            {check.warnings.length > 0 && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <div className="mb-1 flex items-center gap-2 font-medium">
                        <TriangleAlert className="size-4" /> Perlu diperhatikan
                    </div>
                    <ul className="list-disc pl-6">
                        {check.warnings.map((w) => (
                            <li key={w}>{w}</li>
                        ))}
                    </ul>
                    <label className="mt-3 flex items-center gap-2 font-medium">
                        <input type="checkbox" checked={confirmed} onChange={(e) => onConfirm(e.target.checked)} className="size-4" />
                        Saya sudah memeriksa, tetap simpan jadwal ini
                    </label>
                    {confirmError && !confirmed && <p className="mt-1 text-red-700 dark:text-red-300">{confirmError}</p>}
                </div>
            )}
            {check.ready && errors.length === 0 && check.warnings.length === 0 && (
                <p className="text-sm text-green-700 dark:text-green-400">Tidak ada bentrok atau peringatan. Jadwal siap disimpan.</p>
            )}
        </div>
    );
}
