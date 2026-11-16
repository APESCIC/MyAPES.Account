<?php

namespace App\Core\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared query-string filters for plugin list pages (#530).
 */
final class ListFilterQuery
{
    /**
     * @param  list<string>  $searchColumns  columns on the primary table to LIKE against
     * @param  list<string>  $statusOptions  allowed status values (empty = any non-empty string)
     * @return array{
     *     q: ?string,
     *     status: ?string,
     *     assignee: ?int,
     *     date_from: ?string,
     *     date_to: ?string
     * }
     */
    public static function fromRequest(
        Request $request,
        array $statusOptions = [],
        bool $supportsAssignee = true,
    ): array {
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : null;
        if ($q === '') {
            $q = null;
        }

        $status = $request->query('status');
        $status = is_string($status) ? trim($status) : null;
        if ($status === '') {
            $status = null;
        }
        if ($status !== null && $statusOptions !== [] && ! in_array($status, $statusOptions, true)) {
            abort(404);
        }

        $assignee = null;
        if ($supportsAssignee) {
            $raw = $request->query('assignee');
            if (is_numeric($raw) && (int) $raw > 0) {
                $assignee = (int) $raw;
            }
        }

        $dateFrom = self::dateOrNull($request->query('date_from'));
        $dateTo = self::dateOrNull($request->query('date_to'));

        return [
            'q' => $q,
            'status' => $status,
            'assignee' => $assignee,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * @param  array{
     *     q: ?string,
     *     status: ?string,
     *     assignee: ?int,
     *     date_from: ?string,
     *     date_to: ?string
     * }  $filters
     * @param  list<string>  $searchColumns
     * @param  Closure(Builder, string): void|null  $searchExtra  optional orWhereHas callback
     */
    public static function apply(
        Builder $query,
        array $filters,
        array $searchColumns,
        string $dateColumn = 'created_at',
        string $assigneeColumn = 'assigned_to',
        ?\Closure $searchExtra = null,
    ): Builder {
        if ($filters['q'] !== null) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(static function (Builder $inner) use ($like, $searchColumns, $searchExtra): void {
                foreach ($searchColumns as $index => $column) {
                    if ($index === 0) {
                        $inner->where($column, 'like', $like);
                    } else {
                        $inner->orWhere($column, 'like', $like);
                    }
                }

                if ($searchExtra !== null) {
                    $searchExtra($inner, $like);
                }
            });
        }

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['assignee'] !== null) {
            $query->where($assigneeColumn, $filters['assignee']);
        }

        if ($filters['date_from'] !== null) {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== null) {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }

        return $query;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            abort(404);
        }

        return $value;
    }
}
