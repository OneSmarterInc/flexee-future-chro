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
 */
#[Fillable(['team_id', 'user_id', 'year', 'person', 'question', 'answer', 'revealed'])]
class TableMessage extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer'];
    }
}
