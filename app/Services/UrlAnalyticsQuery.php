<?php

namespace App\Services;

use App\Rules\AnalyticsRange;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class UrlAnalyticsQuery
{
    public function __construct(private readonly UrlManagementService $manager) {}

    /**
     * @return array{
     *     range: string,
     *     timezone: string,
     *     start_date: string,
     *     end_date: string,
     *     total_redirects: int,
     *     series: array<int, array{date: string, redirect_count: int}>
     * }
     */
    public function forUrl(string $shortCode, ?string $token, int $days): array
    {
        if ($days < 1 || $days > AnalyticsRange::maximumDays()) {
            throw new InvalidArgumentException('Analytics range is outside the configured bounds.');
        }

        $url = $this->manager->inspect($shortCode, $token);
        $end = CarbonImmutable::now('UTC')->startOfDay();
        $start = $end->subDays($days - 1);
        $counts = $url->dailyAnalytics()
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->addDay()->toDateString())
            ->orderBy('date')
            ->get(['date', 'redirect_count'])
            ->mapWithKeys(fn ($bucket): array => [
                $bucket->date->format('Y-m-d') => $bucket->redirect_count,
            ]);
        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $series[] = [
                'date' => $date,
                'redirect_count' => (int) $counts->get($date, 0),
            ];
        }

        return [
            'range' => "{$days}d",
            'timezone' => 'UTC',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_redirects' => array_sum(array_column($series, 'redirect_count')),
            'series' => $series,
        ];
    }
}
