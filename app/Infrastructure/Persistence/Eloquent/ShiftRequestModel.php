<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** DBマッピング専用。業務ルールはDomain側に置く。 */
class ShiftRequestModel extends Model
{
    use SoftDeletes;

    protected $table = 't_shift_requests';

    protected $fillable = [
        'requested_by', 'period_start', 'period_end',
        'submission_deadline', 'notice', 'status',
    ];
}
