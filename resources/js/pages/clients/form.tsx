import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface ClientData {
    id: number;
    name: string;
    phone: string;
    email: string | null;
    university: string | null;
    study_program: string | null;
    expertise_field_id: number | null;
    degree_level: string | null;
    status: string;
    source: string | null;
    notes: string | null;
}

interface Props {
    client: ClientData | null;
    fields: { id: number; name: string; group: string }[];
    degrees: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
}

const SOURCES = ['whatsapp', 'instagram', 'website', 'referral'];

export default function ClientForm({ client, fields, degrees, statuses }: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: client?.name ?? '',
        phone: client?.phone ?? '',
        email: client?.email ?? '',
        university: client?.university ?? '',
        study_program: client?.study_program ?? '',
        expertise_field_id: client?.expertise_field_id ? String(client.expertise_field_id) : '',
        degree_level: client?.degree_level ?? '',
        status: client?.status ?? 'lead',
        source: client?.source ?? '',
        notes: client?.notes ?? '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Client', href: '/clients' },
        { title: client ? 'Ubah client' : 'Tambah client', href: '#' },
    ];

    const groups = Array.from(new Set(fields.map((f) => f.group)));

    function submit(e: FormEvent) {
        e.preventDefault();
        if (client) {
            put(route('clients.update', client.id));
        } else {
            post(route('clients.store'));
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={client ? 'Ubah client' : 'Tambah client'} />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title={client ? 'Ubah client' : 'Tambah client'} />

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Nama" htmlFor="name" error={errors.name} required>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Nomor WhatsApp" htmlFor="phone" error={errors.phone} hint="Contoh: 0812 3456 7890" required>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" />
                    </Field>
                    <Field label="Email" htmlFor="email" error={errors.email}>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    </Field>
                    <Field label="Status" htmlFor="status" error={errors.status} required>
                        <NativeSelect id="status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                            {statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Universitas" htmlFor="university" error={errors.university}>
                        <Input id="university" value={data.university} onChange={(e) => setData('university', e.target.value)} />
                    </Field>
                    <Field label="Jurusan" htmlFor="study_program" error={errors.study_program}>
                        <Input id="study_program" value={data.study_program} onChange={(e) => setData('study_program', e.target.value)} />
                    </Field>
                    <Field label="Bidang" htmlFor="expertise_field_id" error={errors.expertise_field_id} hint="Dipakai untuk saran tutor.">
                        <NativeSelect id="expertise_field_id" value={data.expertise_field_id} onChange={(e) => setData('expertise_field_id', e.target.value)}>
                            <option value="">Belum dipilih</option>
                            {groups.map((group) => (
                                <optgroup key={group} label={group}>
                                    {fields
                                        .filter((f) => f.group === group)
                                        .map((f) => (
                                            <option key={f.id} value={f.id}>
                                                {f.name}
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Jenjang" htmlFor="degree_level" error={errors.degree_level}>
                        <NativeSelect id="degree_level" value={data.degree_level} onChange={(e) => setData('degree_level', e.target.value)}>
                            <option value="">Belum dipilih</option>
                            {degrees.map((d) => (
                                <option key={d.value} value={d.value}>
                                    {d.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Sumber" htmlFor="source" error={errors.source}>
                        <Input id="source" list="client-sources" value={data.source} onChange={(e) => setData('source', e.target.value)} />
                        <datalist id="client-sources">
                            {SOURCES.map((s) => (
                                <option key={s} value={s} />
                            ))}
                        </datalist>
                    </Field>
                </div>

                <Field label="Catatan" htmlFor="notes" error={errors.notes}>
                    <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={4} />
                </Field>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Simpan
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={client ? route('clients.show', client.id) : route('clients.index')}>Batal</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
