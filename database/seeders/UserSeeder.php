<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 初期データのアカウント3つ（docs/design/acceptance.md 0-2、data.md 4章）。
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['name' => '佐藤 太郎', 'email' => 'sato@example.com'],
            ['name' => '鈴木 花子', 'email' => 'suzuki@example.com'],
            ['name' => '高橋 健', 'email' => 'takahashi@example.com'],
        ];

        foreach ($accounts as $account) {
            User::firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => 'password'],
            );
        }
    }
}
