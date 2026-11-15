<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::query()->firstOrNew(['email' => env('ADMIN_EMAIL', 'admin@example.com')]);
        $password = env('ADMIN_PASSWORD');

        // Rodar o seed de novo sem ADMIN_PASSWORD não pode devolver o admin
        // existente à senha padrão: a senha só é gravada na criação ou quando
        // vem explícita no .env.
        if (! $admin->exists || filled($password)) {
            $admin->password = Hash::make(filled($password) ? $password : 'secmon');
        }

        $admin->forceFill([
            'name' => env('ADMIN_NAME', 'Admin'),
            'is_admin' => true,
            'email_verified_at' => $admin->email_verified_at ?? now(),
        ])->save();

        $this->call(SourceSeeder::class);
        $this->call(TechCatalogSeeder::class);
        $this->call(InterviewQuestionSeeder::class);
    }
}
