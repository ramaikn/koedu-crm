<?php

namespace App\Http\Controllers;

use App\Enums\BenefitType;
use App\Enums\DegreeLevel;
use App\Enums\DeliveryMode;
use App\Enums\PackageCategory;
use App\Enums\ScheduleType;
use App\Http\Requests\PackageRequest;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/** Katalog paket dan harga. Admin hanya melihat, director yang mengubah. */
class PackageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Package::class);

        $packages = Package::query()->with('benefits')->orderBy('category')->orderBy('name')->get();

        return Inertia::render('packages/index', [
            'groups' => collect(PackageCategory::cases())->map(fn (PackageCategory $category) => [
                'category' => $category->label(),
                'packages' => $packages->where('category', $category)->map(fn (Package $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'schedule_type' => $p->schedule_type->label(),
                    'delivery' => $p->delivery->label(),
                    'degree_level' => $p->degree_level?->value,
                    'duration_days' => $p->duration_days,
                    'price' => $p->price,
                    'list_price' => $p->list_price,
                    'max_participants' => $p->max_participants,
                    'is_active' => $p->is_active,
                    'benefits' => $p->benefits->map(fn ($b) => [
                        'label' => $b->benefit_type->label(),
                        'quantity' => $b->quantity,
                    ])->values(),
                ])->values(),
            ])->filter(fn ($g) => $g['packages']->isNotEmpty())->values(),
            'can_edit' => $request->user()->can('create', Package::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Package::class);

        return Inertia::render('packages/form', ['package' => null, ...$this->formOptions()]);
    }

    public function store(PackageRequest $request): RedirectResponse
    {
        Gate::authorize('create', Package::class);

        $package = DB::transaction(function () use ($request) {
            $package = Package::create($request->safe()->except('benefits'));
            $this->syncBenefits($package, $request->validated('benefits'));

            return $package;
        });
        Log::info('Paket dibuat', ['package_id' => $package->id, 'by' => $request->user()->id]);

        return to_route('packages.index')->with('success', 'Paket ditambahkan.');
    }

    public function edit(Package $package): Response
    {
        Gate::authorize('update', $package);

        $package->load('benefits');

        return Inertia::render('packages/form', [
            'package' => [
                ...$package->only(['id', 'code', 'name', 'duration_days', 'price', 'list_price', 'max_participants', 'description', 'is_active']),
                'category' => $package->category->value,
                'schedule_type' => $package->schedule_type->value,
                'delivery' => $package->delivery->value,
                'degree_level' => $package->degree_level?->value,
                'benefits' => $package->benefits->map(fn ($b) => ['benefit_type' => $b->benefit_type->value, 'quantity' => $b->quantity])->values(),
            ],
            ...$this->formOptions(),
        ]);
    }

    /** Mengubah paket tidak mengubah paket yang sudah dibeli client: harga dan kuota mereka sudah disalin. */
    public function update(PackageRequest $request, Package $package): RedirectResponse
    {
        Gate::authorize('update', $package);

        DB::transaction(function () use ($request, $package) {
            $package->update($request->safe()->except('benefits'));
            $this->syncBenefits($package, $request->validated('benefits'));
        });
        Log::info('Paket diubah', ['package_id' => $package->id, 'by' => $request->user()->id]);

        return to_route('packages.index')->with('success', 'Paket diperbarui. Paket yang sudah dibeli client tidak berubah.');
    }

    /** @param  list<array{benefit_type: string, quantity: int|null}>  $benefits */
    private function syncBenefits(Package $package, array $benefits): void
    {
        $package->benefits()->delete();
        $package->benefits()->createMany(array_map(fn ($b) => [
            'benefit_type' => $b['benefit_type'],
            'quantity' => $b['quantity'] ?? null,
        ], $benefits));
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'categories' => PackageCategory::options(),
            'schedule_types' => ScheduleType::options(),
            'deliveries' => DeliveryMode::options(),
            'degrees' => collect(DegreeLevel::options())->all(),
            'benefit_types' => BenefitType::options(),
        ];
    }
}
