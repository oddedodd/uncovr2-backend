<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->unique()->after('id');
            $table->unsignedInteger('email_verification_version')->default(0);
        });

        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $user): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['public_id' => (string) Str::ulid()]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable(false)->change();
        });

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 100);
            $table->timestampsTz();
        });

        DB::table('users')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->eachById(function (object $user): void {
                DB::table('user_profiles')->insert([
                    'user_id' => $user->id,
                    'display_name' => $user->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->default('Uncovr user');
        });

        Schema::dropIfExists('user_profiles');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'email_verification_version']);
        });
    }
};
