<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $constraints = [
        'organizations_status_check' => "organizations CHECK (status IN ('active', 'suspended'))",
        'organization_memberships_role_check' => "organization_memberships CHECK (role IN ('label_admin', 'label_user'))",
        'organization_memberships_status_check' => "organization_memberships CHECK (status IN ('active', 'suspended'))",
        'organization_invitations_role_check' => "organization_invitations CHECK (role IN ('label_admin', 'label_user'))",
        'organization_invitations_send_count_check' => 'organization_invitations CHECK (send_count >= 1)',
        'artists_status_check' => "artists CHECK (status IN ('active', 'suspended'))",
        'artist_memberships_role_check' => "artist_memberships CHECK (role IN ('artist_admin', 'artist_user'))",
        'artist_memberships_status_check' => "artist_memberships CHECK (status IN ('active', 'suspended'))",
        'organization_artist_relationships_type_check' => "organization_artist_relationships CHECK (relationship_type IN ('managing_label', 'distributor'))",
        'organization_artist_relationships_dates_check' => 'organization_artist_relationships CHECK (ended_at IS NULL OR ended_at >= started_at)',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints as $name => $definition) {
            [$table, $check] = explode(' ', $definition, 2);
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$check}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints as $name => $definition) {
            [$table] = explode(' ', $definition, 2);
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        }
    }
};
