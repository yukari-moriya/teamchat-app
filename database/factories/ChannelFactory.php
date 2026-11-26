<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('channel-??????'),
            'description' => null,
            'is_private' => false,
            'creator_id' => User::factory(),
        ];
    }

    /** プライベートチャンネル。作成者もメンバーの1行にする（D-07） */
    public function private(): static
    {
        return $this->state(['is_private' => true])
            ->afterCreating(fn (Channel $channel) => $channel->members()->attach($channel->creator_id));
    }
}
