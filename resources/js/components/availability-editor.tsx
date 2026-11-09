import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus, X } from 'lucide-react';

export interface AvailabilitySlot {
    day_of_week: number;
    start_time: string;
    end_time: string;
}

interface Props {
    days: { value: number; label: string }[];
    value: AvailabilitySlot[];
    onChange: (slots: AvailabilitySlot[]) => void;
    error?: string;
}

/** Editor jam tersedia per hari. Dipakai form profil tutor (admin) dan halaman jam tersedia milik tutor. */
export function AvailabilityEditor({ days, value, onChange, error }: Props) {
    const addSlot = (day: number) => onChange([...value, { day_of_week: day, start_time: '09:00', end_time: '12:00' }]);
    const updateSlot = (index: number, patch: Partial<AvailabilitySlot>) => onChange(value.map((slot, i) => (i === index ? { ...slot, ...patch } : slot)));
    const removeSlot = (index: number) => onChange(value.filter((_, i) => i !== index));

    return (
        <div className="grid gap-3">
            {days.map((day) => {
                const slots = value.map((slot, index) => ({ slot, index })).filter(({ slot }) => slot.day_of_week === day.value);
                return (
                    <div key={day.value} className="flex flex-wrap items-start gap-3 rounded-lg border p-3">
                        <div className="w-20 pt-2 text-sm font-medium">{day.label}</div>
                        <div className="flex min-w-0 flex-1 flex-col gap-2">
                            {slots.length === 0 && <span className="text-muted-foreground pt-2 text-sm">Tidak tersedia</span>}
                            {slots.map(({ slot, index }) => (
                                <div key={index} className="flex items-center gap-2">
                                    <Input type="time" value={slot.start_time} onChange={(e) => updateSlot(index, { start_time: e.target.value })} className="w-32" aria-label={`Mulai ${day.label}`} />
                                    <span className="text-muted-foreground">-</span>
                                    <Input type="time" value={slot.end_time} onChange={(e) => updateSlot(index, { end_time: e.target.value })} className="w-32" aria-label={`Selesai ${day.label}`} />
                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeSlot(index)} aria-label="Hapus jam">
                                        <X />
                                    </Button>
                                </div>
                            ))}
                        </div>
                        <Button type="button" variant="outline" size="sm" onClick={() => addSlot(day.value)}>
                            <Plus /> Tambah jam
                        </Button>
                    </div>
                );
            })}
            <InputError message={error} />
        </div>
    );
}

/** Pesan error server untuk jam tersedia datang dengan kunci "availabilities" atau "availabilities.N.xxx". */
export function availabilityError(errors: Record<string, string | undefined>): string | undefined {
    return Object.entries(errors).find(([key]) => key.startsWith('availabilities'))?.[1];
}
