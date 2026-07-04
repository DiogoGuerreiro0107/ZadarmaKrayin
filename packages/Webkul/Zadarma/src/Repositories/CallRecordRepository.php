<?php

namespace Webkul\Zadarma\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Webkul\Core\Eloquent\Repository;
use Webkul\Zadarma\Models\CallRecord;

class CallRecordRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return CallRecord::class;
    }

    /**
     * Most recent call records for a person, newest first.
     */
    public function findRecentForPerson(int $personId, int $limit = 20): Collection
    {
        return $this->model
            ->where('person_id', $personId)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }
}
