import { Field } from '@/components/form-field';
import { AvailabilityEditor, availabilityError, type AvailabilitySlot } from '@/components/availability-editor';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface TutorData {
    id: number;
    name: string;
    phone: string;
    highest_degree: string;
    max_weekly_sessions: number;
    is_active: boolean;
    bio: string | null;
    expertise_field_ids: number[];
    availabilities: AvailabilitySlot[];
}

interface Props {
    tutor: TutorData | null;
    fields: { id: number; name: string; group: string }[];
    days: { value: number; label: string }[];
}

export default function TutorForm({ tutor, fields, days }: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: tutor?.name ?? '',
        phone: tutor?.phone ?? '',
        highest_degree: tutor?.highest_degree ?? 'S2',
        max_weekly_sessions: tutor?.max_weekly_sessions ?? 15,
        is_active: tutor?.is_active ?? true,
        bio: tutor?.bio ?? '',
        expertise_field_ids: tutor?.expertise_field_ids ?? ([] as number[]),
        availabilities: tutor?.availabilities ?? ([] as AvailabilitySlot[]),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tutor', href: '/tutors' },
        { title: tutor ? 'Ubah tutor' : 'Tambah tutor', href: '#' },
    ];
    const groups = Array.from(new Set(fields.map((f) => f.group)));

    const toggleField = (id: number) =>
        setData('expertise_field_ids', data.expertise_field_ids.includes(id) ? data.expertise_field_ids.filter((f) => f !== id) : [...data.expertise_field_ids, id]);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (tutor) {
            put(route('tutors.update', tutor.id));
        } else {
            post(route('tutors.store'));
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={tutor ? 'Ubah tutor' : 'Tambah tutor'} />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title={tutor ? 'Ubah tutor' : 'Tambah tutor'} />

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Nama" htmlFor="name" error={errors.name} required>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Nomor WhatsApp" htmlFor="phone" error={errors.phone} hint="Dipakai untuk notifikasi jadwal. Contoh: 0812 3456 7890" required>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" />
                    </Field>
                    <Field label="Jenjang tertinggi" htmlFor="highest_degree" error={errors.highest_degree} required>
                        <NativeSelect id="highest_degree" value={data.highest_degree} onChange={(e) => setData('highest_degree', e.target.value)}>
                            <option value="S2">S2</option>
                            <option value="S3">S3</option>
                        </NativeSelect>
                    </Field>
                    <Field label="Batas sesi per minggu" htmlFor="max_weekly_sessions" error={errors.max_weekly_sessions} hint="Melebihi batas ini hanya memunculkan peringatan." required>
                        <Input
                            id="max_weekly_sessions"
                            type="number"
                            min={1}
                            max={60}
                            value={data.max_weekly_sessions}
                            onChange={(e) => setData('max_weekly_sessions', Number(e.target.value))}
                        />
                    </Field>
                </div>

                <Field label="Status" error={errors.is_active}>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="size-4" />
                        Aktif (bisa dipilih untuk jadwal baru)
                    </label>
                </Field>

                <Field label="Bidang keahlian" error={errors.expertise_field_ids} required>
                    <div className="max-h-72 overflow-y-auto rounded-md border p-3">
                        {groups.map((group) => (
                            <fieldset key={group} className="mb-3 last:mb-0">
                                <legend className="text-muted-foreground mb-1 text-xs font-medium">{group}</legend>
                                <div className="grid gap-1 sm:grid-cols-2">
                                    {fields
                                        .filter((f) => f.group === group)
                                        .map((f) => (
                                            <label key={f.id} className="flex items-center gap-2 text-sm">
                                                <input type="checkbox" checked={data.expertise_field_ids.includes(f.id)} onChange={() => toggleField(f.id)} className="size-4" />
                                                {f.name}
                                            </label>
                                        ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                </Field>

                <div className="grid gap-3">
                    <div>
                        <h2 className="text-sm font-medium">Jam tersedia</h2>
                        <p className="text-muted-foreground text-xs">Dipakai untuk saran tutor dan peringatan jadwal. Hari tanpa jam berarti tidak tersedia.</p>
                    </div>
                    <AvailabilityEditor days={days} value={data.availabilities} onChange={(slots) => setData('availabilities', slots)} error={availabilityError(errors)} />
                </div>

                <Field label="Catatan" htmlFor="bio" error={errors.bio}>
                    <Textarea id="bio" value={data.bio} onChange={(e) => setData('bio', e.target.value)} rows={3} />
                </Field>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Simpan
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={tutor ? route('tutors.show', tutor.id) : route('tutors.index')}>Batal</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
