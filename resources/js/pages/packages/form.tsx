import { Field } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { FormEvent } from 'react';

type Option = { value: string; label: string };

interface Benefit {
    benefit_type: string;
    quantity: number | null;
}

interface PackageData {
    id: number;
    code: string;
    name: string;
    category: string;
    schedule_type: string;
    delivery: string;
    degree_level: string | null;
    duration_days: number;
    price: number;
    list_price: number | null;
    max_participants: number | null;
    description: string | null;
    is_active: boolean;
    benefits: Benefit[];
}

interface Props {
    package: PackageData | null;
    categories: Option[];
    schedule_types: Option[];
    deliveries: Option[];
    degrees: Option[];
    benefit_types: Option[];
}

export default function PackageForm({ package: pkg, categories, schedule_types, deliveries, degrees, benefit_types }: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        code: pkg?.code ?? '',
        category: pkg?.category ?? categories[0]?.value ?? '',
        name: pkg?.name ?? '',
        schedule_type: pkg?.schedule_type ?? 'flexible',
        delivery: pkg?.delivery ?? 'private',
        degree_level: pkg?.degree_level ?? '',
        duration_days: pkg?.duration_days ?? 30,
        price: pkg?.price ?? 0,
        list_price: pkg?.list_price ?? ('' as number | ''),
        max_participants: pkg?.max_participants ?? ('' as number | ''),
        description: pkg?.description ?? '',
        is_active: pkg?.is_active ?? true,
        benefits: pkg?.benefits ?? ([] as Benefit[]),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Paket', href: '/packages' },
        { title: pkg ? 'Ubah paket' : 'Tambah paket', href: '#' },
    ];
    const usedTypes = new Set(data.benefits.map((b) => b.benefit_type));
    const nextFreeType = benefit_types.find((t) => !usedTypes.has(t.value));
    const benefitError = Object.entries(errors).find(([key]) => key.startsWith('benefits'))?.[1];

    const updateBenefit = (index: number, patch: Partial<Benefit>) => setData('benefits', data.benefits.map((b, i) => (i === index ? { ...b, ...patch } : b)));

    function submit(e: FormEvent) {
        e.preventDefault();
        if (pkg) {
            put(route('packages.update', pkg.id));
        } else {
            post(route('packages.store'));
        }
    }

    const numberOrEmpty = (value: string): number | '' => (value === '' ? '' : Number(value));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={pkg ? 'Ubah paket' : 'Tambah paket'} />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={pkg ? 'Ubah paket' : 'Tambah paket'}
                    description={pkg ? 'Perubahan hanya berlaku untuk pembelian berikutnya. Paket yang sudah dibeli client tidak berubah.' : undefined}
                />

                <div className="grid gap-5 sm:grid-cols-2">
                    <Field label="Nama paket" htmlFor="name" error={errors.name} className="sm:col-span-2" required>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Kode" htmlFor="code" error={errors.code} hint="Huruf besar, angka, dan tanda minus. Contoh: PRM-1M" required>
                        <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} />
                    </Field>
                    <Field label="Kategori" htmlFor="category" error={errors.category} required>
                        <NativeSelect id="category" value={data.category} onChange={(e) => setData('category', e.target.value)}>
                            {categories.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Jadwal" htmlFor="schedule_type" error={errors.schedule_type} required>
                        <NativeSelect id="schedule_type" value={data.schedule_type} onChange={(e) => setData('schedule_type', e.target.value)}>
                            {schedule_types.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Bentuk" htmlFor="delivery" error={errors.delivery} required>
                        <NativeSelect id="delivery" value={data.delivery} onChange={(e) => setData('delivery', e.target.value)}>
                            {deliveries.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Jenjang" htmlFor="degree_level" error={errors.degree_level} hint="Isi bila harga berbeda per jenjang (mis. beasiswa S1 dan S2).">
                        <NativeSelect id="degree_level" value={data.degree_level} onChange={(e) => setData('degree_level', e.target.value)}>
                            <option value="">Semua jenjang</option>
                            {degrees.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label="Masa aktif (hari)" htmlFor="duration_days" error={errors.duration_days} required>
                        <Input id="duration_days" type="number" min={1} value={data.duration_days} onChange={(e) => setData('duration_days', Number(e.target.value))} />
                    </Field>
                    <Field label="Harga jual (Rp)" htmlFor="price" error={errors.price} required>
                        <Input id="price" type="number" min={0} value={data.price} onChange={(e) => setData('price', Number(e.target.value))} />
                    </Field>
                    <Field label="Harga coret (Rp)" htmlFor="list_price" error={errors.list_price} hint="Opsional. Harga sebelum diskon.">
                        <Input id="list_price" type="number" min={0} value={data.list_price} onChange={(e) => setData('list_price', numberOrEmpty(e.target.value))} />
                    </Field>
                    {data.delivery === 'group' && (
                        <Field label="Batas peserta" htmlFor="max_participants" error={errors.max_participants} required>
                            <Input
                                id="max_participants"
                                type="number"
                                min={1}
                                max={100}
                                value={data.max_participants}
                                onChange={(e) => setData('max_participants', numberOrEmpty(e.target.value))}
                            />
                        </Field>
                    )}
                </div>

                <div className="grid gap-3">
                    <div>
                        <h2 className="text-sm font-medium">Isi paket</h2>
                        <p className="text-muted-foreground text-xs">Kosongkan jumlah untuk "tanpa batas". Coaching WhatsApp diisi dalam hari.</p>
                    </div>
                    {data.benefits.map((benefit, index) => (
                        <div key={index} className="flex items-center gap-2">
                            <NativeSelect value={benefit.benefit_type} onChange={(e) => updateBenefit(index, { benefit_type: e.target.value })} className="flex-1" aria-label="Jenis kuota">
                                {benefit_types.map((t) => (
                                    <option key={t.value} value={t.value} disabled={usedTypes.has(t.value) && t.value !== benefit.benefit_type}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                type="number"
                                min={1}
                                placeholder="Tanpa batas"
                                value={benefit.quantity ?? ''}
                                onChange={(e) => updateBenefit(index, { quantity: e.target.value === '' ? null : Number(e.target.value) })}
                                className="w-32"
                                aria-label="Jumlah"
                            />
                            <Button type="button" variant="ghost" size="icon" onClick={() => setData('benefits', data.benefits.filter((_, i) => i !== index))} aria-label="Hapus">
                                <X />
                            </Button>
                        </div>
                    ))}
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={!nextFreeType}
                            onClick={() => nextFreeType && setData('benefits', [...data.benefits, { benefit_type: nextFreeType.value, quantity: 1 }])}
                        >
                            <Plus /> Tambah isi paket
                        </Button>
                    </div>
                    <InputError message={benefitError} />
                </div>

                <Field label="Deskripsi" htmlFor="description" error={errors.description}>
                    <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} />
                </Field>

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} className="size-4" />
                    Dijual (bisa dipilih saat menambah paket client)
                </label>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Simpan
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={route('packages.index')}>Batal</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
