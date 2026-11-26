<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /** 削除済み（D-05） */
    public function deleted(): static
    {
        return $this->state(['deleted_at' => now()]);
    }

    /** 返信。元のメッセージと同じチャンネルに入れる（data.md 2-4） */
    public function replyTo(Message $parent): static
    {
        return $this->state(['channel_id' => $parent->channel_id, 'parent_id' => $parent->id]);
    }
}
