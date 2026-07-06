<?php

namespace Webkul\Zadarma\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Webkul\User\Models\User;
use Webkul\Zadarma\Models\CallRecord;

class ReportController
{
    /**
     * Number of trailing days shown in the daily charts.
     */
    protected const DAYS = 30;

    public function index(): View
    {
        return view('zadarma::reports.index', [
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Aggregated daily call stats (count and total duration, split by
     * direction), optionally filtered to a single user.
     */
    public function data(Request $request): JsonResponse
    {
        $end = now()->endOfDay();
        $start = $end->copy()->subDays(self::DAYS - 1)->startOfDay();

        $query = CallRecord::query()->whereBetween('started_at', [$start, $end]);

        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }

        $rowsByDay = $query
            ->selectRaw('DATE(started_at) as day, direction, COUNT(*) as calls, SUM(duration) as duration')
            ->groupBy('day', 'direction')
            ->get()
            ->groupBy('day');

        $labels = [];
        $calls = ['inbound' => [], 'outbound' => [], 'unknown' => []];
        $durationMinutes = ['inbound' => [], 'outbound' => [], 'unknown' => []];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $day = $date->toDateString();
            $labels[] = $day;

            $dayRows = $rowsByDay->get($day, collect());

            foreach (['inbound', 'outbound', 'unknown'] as $direction) {
                $row = $dayRows->firstWhere('direction', $direction);

                $calls[$direction][] = (int) ($row->calls ?? 0);
                $durationMinutes[$direction][] = round(($row->duration ?? 0) / 60, 1);
            }
        }

        return response()->json([
            'labels' => $labels,
            'calls' => $calls,
            'duration_minutes' => $durationMinutes,
        ]);
    }
}
