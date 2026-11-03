<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Election;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
            'currentEvent' => function () use ($request): ?array {
                if (! $request->user()) {
                    return null;
                }

                $query = Election::query()->orderByDesc('year')->latest('id');
                if (! $request->user()->hasRole(UserRole::SuperAdmin)) {
                    $query->whereHas('eventAssignments', fn ($assignment) => $assignment->where('user_id', $request->user()->id));
                }

                $event = $query->first(['id', 'name', 'year', 'status']);

                return $event ? ['id' => $event->id, 'name' => $event->name, 'year' => $event->year, 'status' => $event->status->value] : null;
            },
        ];
    }
}
