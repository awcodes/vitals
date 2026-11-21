<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Awcodes\Vitals\Database\Seeders\VitalsDemoSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Runs only in the local environment, which testbench.yaml sets.
        $this->call(VitalsDemoSeeder::class);
    }
}
