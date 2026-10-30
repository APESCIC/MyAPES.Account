<?php

namespace App\Console\Commands;

use App\Core\Accounts\User;
use App\Core\Attachments\SupportAttachment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Tickets\Models\SupportTicket;

/**
 * Removes walkthrough and QA seed data from a live install (#457).
 *
 * Dry run by default. With --force, every matched record (and its messages, updates
 * and attachments) is written to a JSON archive before it is deleted.
 */
class ArchiveQaData extends Command
{
    public const QA_EMAIL_SUFFIX = '@myapes.local';

    /** @var list<string> */
    public const TEST_TITLE_PREFIXES = ['Walkthrough test', 'QA Seed:'];

    protected $signature = 'myapes:qa-data:archive
        {--force : Archive and delete the matched records instead of listing them}';

    protected $description = 'List, or archive and delete, walkthrough and QA seed data';

    public function handle(): int
    {
        $matches = [
            'tickets' => $this->testRecords(SupportTicket::query(), 'subject'),
            'cases' => $this->testRecords(ShelterCase::query(), 'title'),
            'consultations' => $this->testRecords(PetCareConsultation::query(), 'subject'),
            'users' => User::query()->where('email', 'like', '%'.self::QA_EMAIL_SUFFIX)->orderBy('id')->get(),
        ];

        $this->table(['Type', 'ID', 'Label', 'Owner'], $this->rows($matches));

        $total = collect($matches)->sum(static fn (Collection $records): int => $records->count());
        if ($total === 0) {
            $this->components->info('No walkthrough or QA seed data found.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->components->warn("Dry run: {$total} record(s) would be archived and deleted. Re-run with --force to apply.");

            return self::SUCCESS;
        }

        $path = 'qa-data-archive/'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($this->archive($matches), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($matches): void {
            foreach (['tickets', 'cases', 'consultations'] as $type) {
                foreach ($matches[$type] as $record) {
                    $this->deleteAttachments($record);
                    $record->delete();
                }
            }

            foreach ($matches['users'] as $user) {
                $user->delete();
            }
        });

        $this->components->info("Archived {$total} record(s) to storage/app/private/{$path} and removed them.");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Collection<int, Model>
     */
    private function testRecords(Builder $query, string $titleColumn): Collection
    {
        return $query
            ->with('user')
            ->where(function (Builder $match) use ($titleColumn): void {
                foreach (self::TEST_TITLE_PREFIXES as $prefix) {
                    $match->orWhere($titleColumn, 'like', $prefix.'%');
                }
                $match->orWhereHas('user', static fn (Builder $owner): Builder => $owner
                    ->where('email', 'like', '%'.self::QA_EMAIL_SUFFIX));
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, Collection<int, Model>>  $matches
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    private function rows(array $matches): array
    {
        $rows = [];
        foreach ($matches as $type => $records) {
            foreach ($records as $record) {
                $rows[] = [
                    $type,
                    (int) $record->getKey(),
                    (string) ($record->subject ?? $record->title ?? $record->email),
                    $record instanceof User ? '' : (string) ($record->user?->email ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, Collection<int, Model>>  $matches
     * @return array<string, mixed>
     */
    private function archive(array $matches): array
    {
        $archive = ['archived_at' => now()->toIso8601String()];

        foreach (['tickets' => 'messages', 'cases' => 'updates', 'consultations' => null] as $type => $children) {
            $archive[$type] = $matches[$type]->map(function (Model $record) use ($children): array {
                $row = $record->attributesToArray();
                if ($children !== null) {
                    $row[$children] = $record->{$children}()->get()->map->attributesToArray()->all();
                }
                $row['attachments'] = $this->attachmentsFor($record)->map->attributesToArray()->all();

                return $row;
            })->all();
        }

        $archive['users'] = $matches['users']->map->attributesToArray()->all();

        return $archive;
    }

    /**
     * @return Collection<int, SupportAttachment>
     */
    private function attachmentsFor(Model $record): Collection
    {
        return SupportAttachment::query()
            ->where('attachable_type', $record->getMorphClass())
            ->where('attachable_id', $record->getKey())
            ->get();
    }

    private function deleteAttachments(Model $record): void
    {
        foreach ($this->attachmentsFor($record) as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
        }
    }
}
