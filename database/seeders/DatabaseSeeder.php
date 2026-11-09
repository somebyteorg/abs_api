<?php

namespace Database\Seeders;

use App\Models;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['book' => '有声书', 'podcast' => '播客'] as $type => $name) {
            if (! Models\Library::query()->where('type', $type)->exists()) {
                Models\Library::create(['name' => $name, 'type' => $type, 'abs_id' => (string) Str::uuid(), 'abs_folder_id' => (string) Str::uuid()]);
            }
        }
    }
}
