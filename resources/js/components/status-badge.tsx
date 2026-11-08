import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Tone = 'green' | 'blue' | 'amber' | 'red' | 'gray';

const TONES: Record<Tone, string> = {
    green: 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    blue: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    amber: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    red: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    gray: 'border-transparent bg-muted text-muted-foreground',
};

/** Nilai status dari server (enum) ke warna. Nilai yang tidak dikenal tampil abu-abu. */
const TONE_BY_VALUE: Record<string, Tone> = {
    active: 'green',
    paid: 'green',
    completed: 'green',
    present: 'green',
    scheduled: 'blue',
    lead: 'amber',
    unpaid: 'amber',
    pending: 'amber',
    absent: 'red',
    cancelled: 'red',
    expired: 'gray',
    inactive: 'gray',
    alumni: 'gray',
};

export function StatusBadge({ value, label, className }: { value: string; label: string; className?: string }) {
    return <Badge className={cn('font-medium', TONES[TONE_BY_VALUE[value] ?? 'gray'], className)}>{label}</Badge>;
}
