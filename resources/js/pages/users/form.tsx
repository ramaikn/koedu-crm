import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface UserData {
    id: number;
    name: string;
    email: string;
    role: string;
    phone: string | null;
    is_active: boolean;
    tutor_id: number | null;
}

interface Props {
    user: UserData | null;
    roles: { value: string; label: string }[];
    tutors: { id: number; name: string; phone: string }[];
    /** Director sedang mengubah akunnya sendiri: role dan status dikunci. */
    is_self: boolean;
}

export default function UserForm({ user, roles, tutors, is_self }: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? 'admin',
        phone: user?.phone ?? '',
        is_active: user?.is_active ?? true,
        password: '',
        tutor_id: user?.tutor_id ? String(user.tutor_id) : '',
    });

    const title = user ? 'Ubah akun' : 'Tambah akun';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pengguna', href: '/users' },
        { title, href: '#' },
    ];

    // Saat memilih profil tutor untuk akun baru, isi nama dan nomor yang masih kosong dari profil itu.
    function chooseTutor(id: string) {
        const tutor = tutors.find((t) => String(t.id) === id);
        setData((current) => ({
            ...current,
            tutor_id: id,
            name: current.name || tutor?.name || '',
            phone: current.phone || tutor?.phone || '',
        }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (user) {
            put(route('users.update', user.id));
        } else {
            post(route('users.store'));
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <form onSubmit={submit} className="flex max-w-2xl flex-col gap-6 p-4 md:p-6">
                <PageHeader title={title} />

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Role" htmlFor="role" error={errors.role} required>
                        <NativeSelect id="role" value={data.role} onChange={(e) => setData('role', e.target.value)} disabled={is_self}>
                            {roles.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>

                    {data.role === 'tutor' && (
                        <Field label="Profil tutor" htmlFor="tutor_id" error={errors.tutor_id} required>
                            <NativeSelect id="tutor_id" value={data.tutor_id} onChange={(e) => chooseTutor(e.target.value)}>
                                <option value="">Pilih profil tutor</option>
                                {tutors.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Nama" htmlFor="name" error={errors.name} required>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    </Field>
                    <Field label="Email" htmlFor="email" error={errors.email} hint="Dipakai untuk login." required>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field label="Nomor WhatsApp" htmlFor="phone" error={errors.phone}>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" />
                    </Field>
                    <Field
                        label={user ? 'Password baru' : 'Password'}
                        htmlFor="password"
                        error={errors.password}
                        hint={user ? 'Kosongkan bila tidak diganti.' : 'Minimal 8 karakter.'}
                        required={!user}
                    >
                        <Input id="password" type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} autoComplete="new-password" />
                    </Field>
                </div>

                <Field label="Status" error={errors.is_active}>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} disabled={is_self} className="size-4" />
                        Aktif (bisa login)
                    </label>
                </Field>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Simpan
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={route('users.index')}>Batal</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
