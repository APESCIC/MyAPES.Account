<?php

use App\Support\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('preferred_name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('date_of_birth')->nullable()->after('last_name');
        });

        $users = DB::table('users')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->select([
                'users.id as user_id',
                'users.name as display_name',
                'user_profiles.id as profile_id',
            ])
            ->orderBy('users.id')
            ->get();

        foreach ($users as $row) {
            $split = PersonName::split((string) ($row->display_name ?? ''));

            if ($row->profile_id === null) {
                DB::table('user_profiles')->insert([
                    'user_id' => $row->user_id,
                    'first_name' => $split['first_name'],
                    'last_name' => $split['last_name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('user_profiles')
                ->where('id', $row->profile_id)
                ->update([
                    'first_name' => $split['first_name'],
                    'last_name' => $split['last_name'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'date_of_birth']);
        });
    }
};
