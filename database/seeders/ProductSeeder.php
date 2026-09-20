<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed the product registry. Existing rows are left untouched so
     * re-running never clobbers issued keys or ops edits.
     */
    public function run(): void
    {
        foreach ($this->defaults() as $attributes) {
            Product::query()->firstOrCreate(
                ['slug' => $attributes['slug']],
                $attributes
            );
        }
    }

    /**
     * @return list<array{name: string, slug: string, active: bool}>
     */
    protected function defaults(): array
    {
        return [
            ['name' => 'FlowEdu', 'slug' => 'flowedu', 'active' => true],
            ['name' => 'SHSDesk', 'slug' => 'shsdesk', 'active' => false],
            ['name' => 'EduRecords GH', 'slug' => 'edurecordsgh', 'active' => false],
        ];
    }
}
