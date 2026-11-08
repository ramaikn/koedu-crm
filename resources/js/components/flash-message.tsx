import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CircleCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const VISIBLE_MS = 5000;

/** Pesan sukses sekali tampil dari server (mis. "Jadwal dibuat."). Hilang sendiri setelah beberapa detik. */
export function FlashMessage() {
    const { flash } = usePage<SharedData>().props;
    const message = flash?.success ?? null;
    const [dismissed, setDismissed] = useState<string | null>(null);

    useEffect(() => {
        if (!message) return;
        setDismissed(null);
        const timer = setTimeout(() => setDismissed(message), VISIBLE_MS);
        return () => clearTimeout(timer);
    }, [message]);

    if (!message || dismissed === message) return null;

    return (
        <div role="status" className="fixed right-4 bottom-4 z-50 flex max-w-sm items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-900 shadow-md dark:border-green-900 dark:bg-green-950 dark:text-green-100">
            <CircleCheck className="mt-0.5 size-4 shrink-0" />
            <p className="flex-1">{message}</p>
            <button type="button" onClick={() => setDismissed(message)} aria-label="Tutup pesan" className="opacity-70 hover:opacity-100">
                <X className="size-4" />
            </button>
        </div>
    );
}
