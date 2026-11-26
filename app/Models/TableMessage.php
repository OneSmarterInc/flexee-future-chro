<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One question a team asked at the table, and the answer it got.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property int $year
 * @property string $person
 * @property string $question
 * @property string $answer
 * @property string|null $revealed
 * @property string $source fixed or ai
 * @property string|null $dropped_reason Why an AI answer was not shown; for the instructor only.
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $model
 */
#[Fillable(['team_id', 'user_id', 'year', 'person', 'question', 'answer', 'revealed', 'source', 'dropped_reason', 'input_tokens', 'output_tokens', 'model'])]
class TableMessage extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer'];
    }
}
