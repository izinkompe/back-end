<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\{Category, User};

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Sayuran', 'Buah', 'Umbi', 'Biji-bijian', 'Rempah', 'Lainnya'] as $name) { Category::firstOrCreate(['name' => $name]); }
        $email = env('ADMIN_EMAIL'); $password = env('ADMIN_PASSWORD');
        if ($email && $password) {
            if (strlen($password) < 12) { throw new \RuntimeException('ADMIN_PASSWORD minimal 12 karakter.'); }
            // Never promote or overwrite an existing public account through the seeder.
            if (!User::where('email', $email)->exists()) { User::create(['name' => 'Administrator', 'email' => $email, 'password' => $password, 'role' => 'admin']); }
        }
    }
}
