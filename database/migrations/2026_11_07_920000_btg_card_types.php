<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $find = function (string $f) {
            $ids = DB::table('credit_cards')->whereNull('deleted_at')->where('last_four', $f)
                ->where('name', 'like', '%BTG%')->pluck('id');
            return $ids->count() === 1 ? $ids->first() : null;
        };
        $main = $find('8292');
        if (! $main) return;
        DB::table('credit_cards')->where('id', $main)->update(['card_type' => 'principal', 'parent_card_id' => null]);
        foreach (['0052' => 'virtual', '2043' => 'adicional', '4676' => 'adicional', '4920' => 'adicional'] as $f => $t) {
            if ($id = $find($f)) {
                DB::table('credit_cards')->where('id', $id)->update(['card_type' => $t, 'parent_card_id' => $main]);
            }
        }
    }
    public function down(): void {}
};
