<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckAlertSourceDuplicates extends Command
{
    protected $signature = 'alerts:check-source-duplicates';

    protected $description = 'Report duplicate alert source rows before enforcing source uniqueness';

    public function handle(): int
    {
        $duplicates = DB::table('alerts')
            ->selectRaw('source_type, source_id, count(*) as aggregate, min(id) as keep_id, max(id) as newest_id')
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->groupBy('source_type', 'source_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('source_type')
            ->orderBy('source_id')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicate alert sources found.');

            return self::SUCCESS;
        }

        $this->error('Duplicate alert sources found. Resolve them before running the ingestion migration.');

        $this->table(
            ['source_type', 'source_id', 'alerts', 'keep_id', 'newest_id'],
            $duplicates->map(fn (object $row): array => [
                $row->source_type,
                $row->source_id,
                $row->aggregate,
                $row->keep_id,
                $row->newest_id,
            ])->all(),
        );

        $this->line('Keep the oldest alert per source, preserve its message/context/source/delivery, and delete newer duplicates.');

        return self::FAILURE;
    }
}
