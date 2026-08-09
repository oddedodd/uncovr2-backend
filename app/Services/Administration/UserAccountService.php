<?php

namespace App\Services\Administration;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\DeviceSessionRevocationService;
use App\Services\Auth\SecurityAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UserAccountService
{
    public function __construct(
        private readonly DeviceSessionRevocationService $revocationService,
        private readonly SecurityAuditLogger $auditLogger,
    ) {}

    public function updateStatus(
        User $target,
        UserStatus $status,
        string $reason,
        User $actor,
        Request $request,
    ): User {
        return DB::transaction(function () use ($target, $status, $reason, $actor, $request): User {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($locked->status === $status) {
                throw new ConflictHttpException('The account already has the requested status.');
            }

            if ($status === UserStatus::Suspended) {
                $this->guardSuspension($locked, $actor);
            }

            $previousStatus = $locked->status->value;
            $suspended = $status === UserStatus::Suspended;
            $locked->forceFill([
                'status' => $status->value,
                'suspended_at' => $suspended ? now()->startOfSecond() : null,
                'suspension_reason' => $suspended ? $reason : null,
            ])->save();

            $revokedSessions = $suspended
                ? $this->revocationService->revokeAll($locked, 'account_suspended')
                : 0;

            $this->auditLogger->record(
                $suspended ? 'user.account_suspended' : 'user.account_restored',
                $actor,
                $request,
                metadata: [
                    'target_user_id' => $locked->public_id,
                    'previous_status' => $previousStatus,
                    'new_status' => $status->value,
                    'reason' => $reason,
                    'revoked_sessions' => $revokedSessions,
                ],
            );

            return $locked->load('profile');
        }, attempts: 3);
    }

    private function guardSuspension(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            throw new ConflictHttpException('A superadministrator cannot suspend their own account.');
        }

        if (! $target->is_superadmin) {
            return;
        }

        $otherActiveSuperadmin = User::query()
            ->whereKeyNot($target->getKey())
            ->where('is_superadmin', true)
            ->where('status', UserStatus::Active->value)
            ->lockForUpdate()
            ->exists();

        if (! $otherActiveSuperadmin) {
            throw new ConflictHttpException('The last active superadministrator cannot be suspended.');
        }
    }
}
