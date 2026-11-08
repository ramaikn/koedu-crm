import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDayHeading, formatListTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarPlus, MessageCircle, Send, UserPlus } from 'lucide-react';
import { FormEvent, useEffect, useRef } from 'react';

interface DraftFields {
    client_name: string | null;
    degree_level: string | null;
    field_of_study: string | null;
    service_note: string | null;
    requested_date: string | null;
    requested_time: string | null;
    duration_minutes: number | null;
    session_type: string | null;
}

interface Draft {
    id: number;
    summary: string;
    confidence: number | null;
    fields: DraftFields;
    client_registered: boolean;
}

interface ConversationItem {
    id: number;
    name: string;
    phone: string;
    is_client: boolean;
    has_draft: boolean;
    unread_count: number;
    last_message_at: string | null;
    preview: string;
}

interface MessageItem {
    id: number;
    direction: 'inbound' | 'outbound';
    body: string | null;
    status: string;
    status_label: string;
    error: string | null;
    at: string;
}

interface ActiveConversation {
    id: number;
    name: string;
    phone: string;
    client_id: number | null;
    draft: Draft | null;
    messages: MessageItem[];
}

interface Props {
    conversations: ConversationItem[];
    active: ActiveConversation | null;
    can_reply: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inbox WhatsApp', href: '/inbox' }];

const REFRESH_MS = 5000;

export default function Inbox({ conversations, active, can_reply }: Props) {
    const { timezone } = usePage<SharedData>().props;

    // Muat ulang daftar dan chat tiap beberapa detik selama tab terbuka, supaya pesan baru muncul sendiri.
    useEffect(() => {
        const timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['conversations', 'active'] });
            }
        }, REFRESH_MS);
        return () => clearInterval(timer);
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inbox WhatsApp" />
            <div className="flex h-[calc(100vh-6rem)] min-h-96 gap-4 p-4">
                {/* Di layar kecil hanya satu panel tampil: daftar, atau chat bila ada yang dipilih. */}
                <aside
                    className={cn(
                        'border-sidebar-border/70 dark:border-sidebar-border w-full flex-col overflow-hidden rounded-xl border md:flex md:w-80 md:max-w-xs',
                        active ? 'hidden' : 'flex',
                    )}
                >
                    <div className="border-sidebar-border/70 dark:border-sidebar-border border-b px-4 py-3 text-sm font-semibold">Percakapan</div>
                    <ul className="flex-1 overflow-y-auto">
                        {conversations.length === 0 && <li className="text-muted-foreground p-4 text-sm">Belum ada percakapan.</li>}
                        {conversations.map((c) => (
                            <li key={c.id}>
                                <Link
                                    href={route('inbox.show', c.id)}
                                    preserveScroll
                                    className={cn(
                                        'hover:bg-accent flex flex-col gap-0.5 border-b px-4 py-3 text-sm',
                                        active?.id === c.id && 'bg-accent',
                                    )}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="flex min-w-0 items-center gap-2">
                                            <span className="truncate font-medium">{c.name}</span>
                                            {c.has_draft && (
                                                <Badge variant="secondary" className="shrink-0 px-1.5 py-0 text-[10px]">
                                                    Draf jadwal
                                                </Badge>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground shrink-0 text-xs">{formatListTime(c.last_message_at, timezone)}</span>
                                    </div>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground truncate text-xs">{c.preview}</span>
                                        {c.unread_count > 0 && <Badge className="shrink-0">{c.unread_count}</Badge>}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>

                <section
                    className={cn(
                        'border-sidebar-border/70 dark:border-sidebar-border min-w-0 flex-1 flex-col overflow-hidden rounded-xl border md:flex',
                        active ? 'flex' : 'hidden',
                    )}
                >
                    {active ? <Thread active={active} canReply={can_reply} /> : <EmptyThread />}
                </section>
            </div>
        </AppLayout>
    );
}

function EmptyThread() {
    return (
        <div className="text-muted-foreground flex flex-1 flex-col items-center justify-center gap-2 text-sm">
            <MessageCircle className="size-8" />
            Pilih percakapan di sebelah kiri.
        </div>
    );
}

function Thread({ active, canReply }: { active: ActiveConversation; canReply: boolean }) {
    const { timezone } = usePage<SharedData>().props;
    const { data, setData, post, processing, reset, errors } = useForm({ body: '' });
    const bottomRef = useRef<HTMLDivElement>(null);
    const lastId = active.messages.at(-1)?.id;

    // Gulir ke pesan terbaru saat pindah percakapan atau ada pesan baru.
    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [active.id, lastId]);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!data.body.trim()) return;
        post(route('inbox.reply', active.id), { preserveScroll: true, onSuccess: () => reset('body') });
    }

    return (
        <>
            <header className="border-sidebar-border/70 dark:border-sidebar-border flex items-center gap-3 border-b px-4 py-3">
                <Link href={route('inbox.index')} className="text-muted-foreground hover:text-foreground md:hidden" aria-label="Kembali ke daftar">
                    <ArrowLeft className="size-4" />
                </Link>
                <div>
                    <div className="text-sm font-semibold">{active.name}</div>
                    <div className="text-muted-foreground text-xs">+{active.phone}</div>
                </div>
            </header>

            {active.draft && <DraftCard draft={active.draft} phone={active.phone} canReview={canReply} />}

            <div className="flex-1 space-y-2 overflow-y-auto p-4">
                {active.messages.map((m) => (
                    <div key={m.id} className={cn('flex', m.direction === 'outbound' ? 'justify-end' : 'justify-start')}>
                        <div
                            className={cn(
                                'max-w-[75%] rounded-lg px-3 py-2 text-sm',
                                m.direction === 'outbound' ? 'bg-primary text-primary-foreground' : 'bg-muted',
                            )}
                        >
                            <p className="break-words whitespace-pre-wrap">{m.body}</p>
                            <div
                                className={cn(
                                    'mt-1 flex items-center justify-end gap-2 text-[11px]',
                                    m.direction === 'outbound' ? 'text-primary-foreground/70' : 'text-muted-foreground',
                                )}
                            >
                                {m.direction === 'outbound' && <span>{m.status_label}</span>}
                                <span>{formatTime(m.at, timezone)}</span>
                            </div>
                            {m.status === 'failed' && <p className="mt-1 text-xs font-medium text-red-200">Gagal terkirim. Coba kirim ulang nanti.</p>}
                        </div>
                    </div>
                ))}
                <div ref={bottomRef} />
            </div>

            {canReply ? (
                <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex gap-2 border-t p-3">
                    <textarea
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        onKeyDown={(e) => {
                            // Enter mengirim, Shift+Enter baris baru.
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                e.currentTarget.form?.requestSubmit();
                            }
                        }}
                        rows={2}
                        placeholder="Tulis balasan..."
                        className="border-input bg-background focus-visible:ring-ring/50 min-w-0 flex-1 resize-none rounded-md border px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                    />
                    <Button type="submit" disabled={processing || !data.body.trim()} className="self-end">
                        <Send className="size-4" />
                        Kirim
                    </Button>
                    {errors.body && <p className="text-xs text-red-500">{errors.body}</p>}
                </form>
            ) : (
                <div className="text-muted-foreground border-t px-4 py-3 text-xs">Anda hanya bisa membaca percakapan ini.</div>
            )}
        </>
    );
}

const SESSION_TYPE_LABEL: Record<string, string> = { private: 'Privat', group: 'Grup' };

/**
 * Draf jadwal hasil bacaan AI dari chat client. AI hanya mengusulkan: admin memeriksa rinciannya,
 * lalu membuka form jadwal yang sudah terisi untuk memilih tutor dan menyimpan.
 */
function DraftCard({ draft, phone, canReview }: { draft: Draft; phone: string; canReview: boolean }) {
    const f = draft.fields;
    const rows: [string, string | null][] = [
        ['Tanggal', f.requested_date ? formatDayHeading(f.requested_date) : null],
        ['Jam', f.requested_time],
        ['Durasi', f.duration_minutes ? `${f.duration_minutes} menit` : null],
        ['Jenis', f.session_type ? (SESSION_TYPE_LABEL[f.session_type] ?? f.session_type) : null],
        ['Layanan', f.service_note],
        ['Jenjang', f.degree_level],
        ['Bidang', f.field_of_study],
    ];

    return (
        <section className="border-b bg-amber-50/60 px-4 py-3 text-sm dark:bg-amber-950/20" aria-label="Draf jadwal dari AI">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 font-medium">
                        Draf jadwal dari chat
                        {draft.confidence !== null && <span className="text-muted-foreground text-xs font-normal">keyakinan {Math.round(draft.confidence * 100)}%</span>}
                    </div>
                    {draft.summary && <p className="text-muted-foreground mt-0.5">{draft.summary}</p>}
                </div>
                {canReview && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" asChild>
                            <Link href={route('schedule.create', { draft_id: draft.id })}>
                                <CalendarPlus /> Atur jadwal
                            </Link>
                        </Button>
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" variant="outline">
                                    Tolak
                                </Button>
                            }
                            title="Tolak draf ini?"
                            description="Draf dihapus dari daftar tunggu. Chat tetap ada di inbox."
                            confirmLabel="Tolak draf"
                            destructive
                            onConfirm={() => router.post(route('drafts.reject', draft.id), {}, { preserveScroll: true })}
                        />
                    </div>
                )}
            </div>

            <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1">
                {rows
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                        <div key={label} className="flex gap-1.5">
                            <dt className="text-muted-foreground">{label}:</dt>
                            <dd className="font-medium">{value}</dd>
                        </div>
                    ))}
            </dl>

            {!draft.client_registered && (
                <p className="mt-2 flex flex-wrap items-center gap-2 text-amber-900 dark:text-amber-200">
                    Nomor ini belum terdaftar sebagai client.
                    {canReview && (
                        <Link
                            href={route('clients.create', {
                                name: f.client_name ?? undefined,
                                phone,
                                degree_level: f.degree_level ?? undefined,
                                study_program: f.field_of_study ?? undefined,
                            })}
                            className="inline-flex items-center gap-1 font-medium underline"
                        >
                            <UserPlus className="size-3.5" /> Daftarkan client
                        </Link>
                    )}
                </p>
            )}
        </section>
    );
}
