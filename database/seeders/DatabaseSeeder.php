<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // A conta do dono desta instalação: a mesma que o primeiro acesso criaria (não há login nem senha).
        User::localOwner();

        $this->call(SourceSeeder::class);
        $this->call(TechCatalogSeeder::class);
        $this->call(InterviewQuestionSeeder::class);
    }
}
