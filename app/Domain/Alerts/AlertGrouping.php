<?php

namespace App\Domain\Alerts;

use App\Models\Alert;
use App\Models\Bot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AlertGrouping
{
    public const WINDOW_DAYS = 30;

    public const PER_PAGE = 20;

    public const RECENT_LIMIT = 5;

    /**
     * @return LengthAwarePaginator<int, array{
     *     key: string,
     *     type: string,
     *     bot_id: int|null,
     *     bot: array{id: int, name: string, archived: bool}|null,
     *     total_count: int,
     *     unread_count: int,
     *     latest_message: string,
     *     latest_at: string,
     *     through: string,
     *     highest_severity: string
     * }>
     */
    public function groups(User $user, ?int $botId = null, ?CarbonImmutable $now = null): LengthAwarePaginator
    {
        $windowStart = ($now ?? CarbonImmutable::now())->subDays(self::WINDOW_DAYS);

        $paginator = Alert::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $windowStart)
            ->when($botId !== null, fn ($query) => $query->where('bot_id', $botId))
            ->select('type', 'bot_id')
            ->selectRaw('count(*) as total_count')
            ->selectRaw('count(*) filter (where read_at is null) as unread_count')
            ->selectRaw('max(created_at) as latest_at')
            ->selectRaw('(array_agg(message order by created_at desc, id desc))[1] as latest_message')
            ->selectRaw("(array_agg(severity order by case severity when 'critical' then 0 when 'warning' then 1 when 'info' then 2 else 3 end, created_at desc, id desc))[1] as highest_severity")
            ->groupBy('type', 'bot_id')
            ->orderByDesc('latest_at')
            ->orderByDesc('type')
            ->orderByRaw('bot_id DESC NULLS LAST')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $botIds = collect($paginator->items())
            ->pluck('bot_id')
            ->filter()
            ->unique()
            ->values();

        $bots = Bot::query()
            ->where('user_id', $user->id)
            ->withTrashed()
            ->whereIn('id', $botIds)
            ->get()
            ->keyBy('id');

        $paginator->through(function ($row) use ($bots): array {
            $type = (string) $row->getAttribute('type');
            $botId = $row->getAttribute('bot_id');
            $botId = $botId === null ? null : (int) $botId;
            $bot = $botId === null ? null : $bots->get($botId);
            $latestAt = CarbonImmutable::parse((string) $row->getAttribute('latest_at'))->toIso8601String();

            return [
                'key' => $this->groupKey($type, $botId),
                'type' => $type,
                'bot_id' => $botId,
                'bot' => $bot === null ? null : [
                    'id' => $bot->id,
                    'name' => $bot->name,
                    'archived' => $bot->trashed(),
                ],
                'total_count' => (int) $row->getAttribute('total_count'),
                'unread_count' => (int) $row->getAttribute('unread_count'),
                'latest_message' => (string) $row->getAttribute('latest_message'),
                'latest_at' => $latestAt,
                'through' => $latestAt,
                'highest_severity' => (string) $row->getAttribute('highest_severity'),
            ];
        });

        return $paginator;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function recentOccurrences(User $user, array $groups, ?CarbonImmutable $now = null): array
    {
        if ($groups === []) {
            return [];
        }

        $windowStart = ($now ?? CarbonImmutable::now())->subDays(self::WINDOW_DAYS);

        $ranked = DB::table('alerts')
            ->select('id', 'type', 'bot_id', 'severity', 'title', 'message', 'read_at', 'created_at')
            ->selectRaw('row_number() over (partition by type, bot_id order by created_at desc, id desc) as rn')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $windowStart)
            ->where(function ($query) use ($groups): void {
                foreach ($groups as $group) {
                    $query->orWhere(function ($query) use ($group): void {
                        $query->where('type', $group['type']);

                        if ($group['bot_id'] === null) {
                            $query->whereNull('bot_id');
                        } else {
                            $query->where('bot_id', $group['bot_id']);
                        }
                    });
                }
            });

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', self::RECENT_LIMIT)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $occurrences = [];

        foreach ($rows as $row) {
            $botId = $row->bot_id === null ? null : (int) $row->bot_id;
            $key = $this->groupKey((string) $row->type, $botId);
            $occurrences[$key][] = [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'bot_id' => $botId,
                'severity' => (string) $row->severity,
                'title' => (string) $row->title,
                'message' => (string) $row->message,
                'read_at' => $row->read_at === null ? null : CarbonImmutable::parse($row->read_at)->toIso8601String(),
                'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            ];
        }

        return $occurrences;
    }

    public function markRead(User $user, string $type, ?int $botId, CarbonImmutable|string $through): int
    {
        $throughAt = $through instanceof CarbonImmutable ? $through : CarbonImmutable::parse($through);
        $now = CarbonImmutable::now();

        if ($throughAt->greaterThan($now)) {
            $throughAt = $now;
        }

        return Alert::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->when($botId === null, fn ($query) => $query->whereNull('bot_id'), fn ($query) => $query->where('bot_id', $botId))
            ->whereNull('read_at')
            ->where('created_at', '<=', $throughAt)
            ->update(['read_at' => now()]);
    }

    public function groupKey(string $type, ?int $botId): string
    {
        return $type.'|'.($botId === null ? 'account' : (string) $botId);
    }
}
