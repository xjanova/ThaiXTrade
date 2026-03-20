<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TPIX TRADE — Translation Seeder
 * Seed translation keys จาก i18n JSON files เข้า DB
 * ให้ admin แก้ไขได้ผ่านหน้า Languages → Translations.
 */
class TranslationSeeder extends Seeder
{
    public function run(): void
    {
        $languages = Language::all();

        if ($languages->isEmpty()) {
            $this->command->info('No languages found. Creating TH + EN...');
            $th = Language::create(['code' => 'th', 'name' => 'Thai', 'native_name' => 'ไทย', 'flag_emoji' => '🇹🇭', 'is_active' => true, 'is_default' => true, 'sort_order' => 1]);
            $en = Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'flag_emoji' => '🇺🇸', 'is_active' => true, 'is_default' => false, 'sort_order' => 2]);
            $languages = collect([$th, $en]);
        }

        foreach ($languages as $lang) {
            $jsonPath = resource_path("js/i18n/{$lang->code}.json");

            if (! file_exists($jsonPath)) {
                $this->command->warn("No JSON file for {$lang->code}");

                continue;
            }

            $data = json_decode(file_get_contents($jsonPath), true);

            if (! $data) {
                continue;
            }

            $rows = [];
            $this->flattenKeys($data, '', $rows);

            $this->command->info("Seeding {$lang->code}: ".count($rows).' keys');

            foreach ($rows as $row) {
                DB::table('translations')->updateOrInsert(
                    [
                        'language_id' => $lang->id,
                        'group' => $row['group'],
                        'key' => $row['key'],
                    ],
                    [
                        'value' => $row['value'],
                        'updated_at' => now(),
                    ]
                );
            }
        }

        $this->command->info('Translation seeding complete!');
    }

    /**
     * Flatten nested JSON to group.key = value format.
     */
    private function flattenKeys(array $data, string $prefix, array &$rows): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                // First level = group name
                $this->flattenKeys($value, $fullKey, $rows);
            } else {
                // Split "nav.home" → group="nav", key="home"
                $parts = explode('.', $fullKey, 2);
                $group = $parts[0];
                $subKey = $parts[1] ?? $key;

                $rows[] = [
                    'group' => $group,
                    'key' => $subKey,
                    'value' => (string) $value,
                ];
            }
        }
    }
}
