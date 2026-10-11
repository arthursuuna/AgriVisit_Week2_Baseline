<?php

namespace App\Services\Tools;

use App\Models\Visit;
use App\Support\FarmProfileRepository;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What approving a held call will change, in words an officer can judge.
 *
 * Built only from the arguments and read-only lookups; it never calls a tool.
 * A row whose lookup fails stays in the preview, marked "could not be
 * confirmed", because a preview that silently drops what it could not verify
 * misleads.
 *
 * Each row carries a level: 'normal', or 'warning' for something the officer
 * should weigh before deciding. A warning advises; it never blocks.
 */
class ApprovalPreview
{
    public const UNCONFIRMED = 'could not be confirmed';

    public const NORMAL  = 'normal';
    public const WARNING = 'warning';

    private const OUTCOMES = [
        'resolved'  => 'The issue is settled and needs no further visit.',
        'ongoing'   => 'The issue is still open and needs further follow-up.',
        'escalated' => 'The issue has been passed on to a specialist or supervisor.',
    ];

    public function __construct(private readonly FarmProfileRepository $farms)
    {
    }

    /** @return list<array{label: string, value: string, note: ?string, level: string}> */
    public function for(string $toolName, array $args): array
    {
        return match ($toolName) {
            'schedule_visit' => $this->scheduleVisit($args),
            'log_followup'   => $this->logFollowup($args),
            default          => $this->generic($toolName, $args),
        };
    }

    private function scheduleVisit(array $args): array
    {
        $farmId = $this->string($args, 'farm_id');
        $date   = $this->string($args, 'visit_date');

        return array_values(array_filter([
            $this->duplicateRow($farmId, $date),
            $this->row('Action', 'A new visit will be created'),
            $this->farmRow('Farm', $farmId),
            $this->dateRow('Date', $date),
            $this->row('Purpose', $this->string($args, 'purpose') ?? self::UNCONFIRMED),
            $this->existingVisitsRow($farmId),
            $this->row('Reversible', 'This can be cancelled later, but the record will remain'),
        ]));
    }

    /**
     * A visit already booked for this farm on exactly this date. Only an exact
     * match counts: flagging the day before would teach officers to ignore it.
     */
    private function duplicateRow(?string $farmId, ?string $date): ?array
    {
        if ($farmId === null || $date === null) {
            return null;
        }

        try {
            $refs = Visit::where('farm_id', $farmId)
                ->whereDate('visit_date', $date)
                ->orderBy('id')
                ->pluck('visit_ref')
                ->all();
        } catch (Throwable) {
            return null;
        }

        if ($refs === []) {
            return null;
        }

        return $this->row('Possible duplicate', sprintf(
            'A visit to this farm is already scheduled for this date (%s).', implode(', ', $refs)
        ), level: self::WARNING);
    }

    private function logFollowup(array $args): array
    {
        $outcome = $this->string($args, 'outcome');

        return [
            $this->row('Action', 'A follow-up note will be recorded'),
            $this->visitRow($this->string($args, 'visit_id'), $this->string($args, 'farm_id')),
            isset(self::OUTCOMES[$outcome])
                ? $this->row('Outcome', ucfirst($outcome), self::OUTCOMES[$outcome])
                : $this->row('Outcome', self::UNCONFIRMED, $outcome === null ? 'No outcome was given.' : "'{$outcome}' is not a known outcome."),
            $this->row('Notes', $this->string($args, 'notes') ?? self::UNCONFIRMED),
            $this->row('Reversible', 'A follow-up note cannot be edited once recorded'),
        ];
    }

    /** A write tool with no tailored preview: say so, and show what was given. */
    private function generic(string $toolName, array $args): array
    {
        $rows = [$this->row('Action', "The tool '{$toolName}' will run and may change records",
            'No detailed preview exists for this tool; check the arguments below.')];

        foreach ($args as $key => $value) {
            $rows[] = $this->row((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $rows;
    }

    private function farmRow(string $label, ?string $farmId): array
    {
        $farm = $this->farm($farmId);

        if ($farm === null) {
            return $this->row($label, self::UNCONFIRMED,
                $farmId === null ? 'No farm ID was given.' : "No farm with ID {$farmId} was found.");
        }

        $who   = isset($farm['farmer_alias']) ? "{$farm['farmer_alias']}'s farm" : 'Farm';
        $where = $farm['district'] ?? null;
        $crops = array_filter(array_map(fn ($c) => $this->crop($c), $farm['crops'] ?? []));

        $value = trim(sprintf('%s %s%s', $farm['farm_id'], $who === 'Farm' ? '' : "— {$who}",
            $where ? " in {$where}" : ''));

        return $this->row($label, $value, $crops === []
            ? 'No crops on record.'
            : 'Crops: ' . implode('; ', $crops) . '.');
    }

    private function dateRow(string $label, ?string $date): array
    {
        $parsed = $date === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return $this->row($label, self::UNCONFIRMED, "'{$date}' is not a date in YYYY-MM-DD form.");
        }

        $day  = Carbon::parse($date)->startOfDay();
        $diff = (int) now()->startOfDay()->diffInDays($day, false);

        $relative = match (true) {
            $diff === 0  => 'today',
            $diff === 1  => 'tomorrow',
            $diff > 1    => "in {$diff} days",
            $diff === -1 => 'yesterday',
            default      => abs($diff) . ' days ago',
        };

        return $this->row($label, $day->format('l j F Y'), ucfirst($relative) . '.');
    }

    private function existingVisitsRow(?string $farmId): array
    {
        if ($farmId === null) {
            return $this->row('Existing visits', self::UNCONFIRMED, 'No farm ID was given.');
        }

        // Past and upcoming are named separately: a future booking called "most
        // recent" reads as a visit that already happened.
        $today = now()->toDateString();

        try {
            $count = Visit::where('farm_id', $farmId)->count();
            $past  = Visit::where('farm_id', $farmId)->whereDate('visit_date', '<', $today)->max('visit_date');
            $next  = Visit::where('farm_id', $farmId)->whereDate('visit_date', '>=', $today)->min('visit_date');
        } catch (Throwable) {
            return $this->row('Existing visits', self::UNCONFIRMED, 'The visit records could not be read.');
        }

        if ($count === 0) {
            return $this->row('Existing visits', 'None booked through AgriVisit', $this->lastFieldVisit($farmId));
        }

        $parts = ["{$count} already booked"];

        if ($past !== null) {
            $parts[] = 'latest was on ' . Carbon::parse($past)->format('l j F Y');
        }

        if ($next !== null) {
            $parts[] = 'next is on ' . Carbon::parse($next)->format('l j F Y');
        }

        return $this->row('Existing visits', implode('; ', $parts), $this->lastFieldVisit($farmId));
    }

    private function visitRow(?string $visitRef, ?string $farmId): array
    {
        $visit = $visitRef === null ? null : Visit::where('visit_ref', $visitRef)->first();

        if ($visit === null) {
            return $this->row('Visit', self::UNCONFIRMED,
                $visitRef === null ? 'No visit ID was given.' : "No visit with ID {$visitRef} was found.");
        }

        $farm  = $this->farm($visit->farm_id);
        $where = $farm['district'] ?? null;
        $note  = $farmId !== null && $farmId !== $visit->farm_id
            ? "This visit belongs to {$visit->farm_id}, not {$farmId}."
            : 'Purpose: ' . $visit->purpose;

        return $this->row('Visit', sprintf('%s — %s%s, on %s', $visit->visit_ref, $visit->farm_id,
            $where ? " ({$where})" : '', $visit->visit_date->format('l j F Y')), $note);
    }

    private function lastFieldVisit(string $farmId): ?string
    {
        $last = $this->farm($farmId)['last_visit'] ?? null;

        return $last ? "Last field visit on the farm record: {$last}." : null;
    }

    private function farm(?string $farmId): ?array
    {
        if ($farmId === null) {
            return null;
        }

        try {
            return $this->farms->find($farmId);
        } catch (Throwable) {
            return null;
        }
    }

    private function crop(mixed $crop): ?string
    {
        if (! is_array($crop) || empty($crop['crop'])) {
            return null;
        }

        $acres  = isset($crop['area_acres']) ? rtrim(rtrim(number_format((float) $crop['area_acres'], 1), '0'), '.') : null;
        $detail = array_filter([
            $crop['variety'] ?? null,
            $acres === null ? null : $acres . ($acres === '1' ? ' acre' : ' acres'),
        ]);

        return $crop['crop'] . ($detail === [] ? '' : ' (' . implode(', ', $detail) . ')');
    }

    private function string(array $args, string $key): ?string
    {
        return isset($args[$key]) && is_string($args[$key]) && $args[$key] !== '' ? $args[$key] : null;
    }

    private function row(string $label, string $value, ?string $note = null, string $level = self::NORMAL): array
    {
        return ['label' => $label, 'value' => $value, 'note' => $note, 'level' => $level];
    }
}
