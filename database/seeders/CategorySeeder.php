<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Продукти', 'Транспорт', 'Житло', 'Здоров’я', 'Дозвілля', 'Інше'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}
