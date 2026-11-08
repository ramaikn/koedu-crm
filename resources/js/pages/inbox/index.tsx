import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatListTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, MessageCircle, Send } from 'lucide-react';
import { FormEvent, useEffect, useRef } from 'react';

interface ConversationItem {
    id: number;
    name: string;
    phone: string;
    is_client: boolean;
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
                                        <span className="truncate font-medium">{c.name}</span>
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
