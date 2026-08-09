<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReleaseStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SuperadminRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Artist;
use App\Models\Organization;
use App\Models\Release;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PlatformController extends Controller
{
    public function overview(SuperadminRequest $request): JsonResponse
    {
        return ApiResponse::success([
            'users' => [
                'total' => User::query()->count(),
                'by_status' => $this->statusCounts('users', array_column(UserStatus::cases(), 'value')),
                'superadmins' => User::query()->where('is_superadmin', true)->count(),
            ],
            'organizations' => [
                'total' => Organization::query()->count(),
                'by_status' => $this->statusCounts('organizations', ['active', 'suspended']),
            ],
            'artists' => [
                'total' => Artist::query()->count(),
                'by_status' => $this->statusCounts('artists', ['active', 'suspended']),
            ],
            'releases' => [
                'total' => Release::query()->count(),
                'by_status' => $this->statusCounts('releases', array_column(ReleaseStatus::cases(), 'value'), true),
            ],
        ]);
    }

    /**
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function statusCounts(string $table, array $statuses, bool $excludeSoftDeleted = false): array
    {
        $query = DB::table($table);

        if ($excludeSoftDeleted) {
            $query->whereNull('deleted_at');
        }

        $counts = $query->select('status', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect($statuses)
            ->mapWithKeys(fn (string $status): array => [$status => (int) $counts->get($status, 0)])
            ->all();
    }
}
