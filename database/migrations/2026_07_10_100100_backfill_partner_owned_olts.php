<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Konversi OLT yang lama di-assign admin ke SATU partner (dan tak dibagikan ke
 * operator manapun) menjadi OLT PRIVAT milik partner tsb — hilang dari "global"
 * admin/operator, hanya terlihat partner pemilik. Menutup transisi dari model
 * "assignment" lama ke model "kepemilikan" baru.
 *
 * Aturan aman: sebuah OLT hanya di-claim bila assignment-nya HANYA ke tepat satu
 * partner dan NOL operator. OLT yang dibagikan (partner + operator, atau banyak
 * partner) tetap global. Di DB fresh (test) query kosong → no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partnerIds = DB::table('users')->where('role', 'partner')->pluck('id')->all();
        $operatorIds = DB::table('users')->where('role', 'operator')->pluck('id')->all();

        if ($partnerIds === []) {
            return;
        }

        $oltIds = DB::table('olt_user')->distinct()->pluck('snmp_olt_id');

        foreach ($oltIds as $oltId) {
            $assignees = DB::table('olt_user')->where('snmp_olt_id', $oltId)->pluck('user_id')->all();

            $partnerAssignees = array_values(array_intersect($assignees, $partnerIds));
            $operatorAssignees = array_intersect($assignees, $operatorIds);

            if (count($operatorAssignees) === 0 && count($partnerAssignees) === 1) {
                DB::table('snmp_olts')
                    ->where('id', $oltId)
                    ->whereNull('owner_user_id')
                    ->update(['owner_user_id' => $partnerAssignees[0]]);
            }
        }
    }

    public function down(): void
    {
        DB::table('snmp_olts')->update(['owner_user_id' => null]);
    }
};
