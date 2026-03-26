<?php declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Queries\Anchors\AnchorType;
use App\Models\Query;
use App\Models\QueryAnchor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QueryAnchor>
 */
class QueryAnchorFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<QueryAnchor>
     */
    protected $model = QueryAnchor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_id' => Query::factory(),
            'type' => $this->faker->randomElement([AnchorType::TABLE, AnchorType::PAGINATION_BLOCKS]),
            'message_ts' => $this->faker->numerify('###################.######'),
            'blocks_json' => null,
            'attachments_json' => null,
        ];
    }

    /**
     * Create a table anchor.
     */
    public function table(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AnchorType::TABLE,
        ]);
    }

    /**
     * Create a pagination blocks anchor.
     */
    public function paginationBlocks(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AnchorType::PAGINATION_BLOCKS,
        ]);
    }
}
