<?php

namespace Database\Factories;

use App\Models\SessionTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionTemplate>
 */
class SessionTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => 'Natation seuil',
            'kind' => 'training',
            'discipline_id' => null,
            'day_of_week' => 1, // lundi (ISO)
            'start_time_of_day' => '19:00',
            'duration_min' => 90,
            'location_id' => null,
            'location_text' => 'Piscine Olympique',
            'capacity' => 16,
            'quota_tag_id' => null,
            'generation_start_date' => '2026-09-01',
            'generation_end_date' => '2026-12-31',
            'created_by' => User::factory()->admin(),
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'archived']);
    }
}
