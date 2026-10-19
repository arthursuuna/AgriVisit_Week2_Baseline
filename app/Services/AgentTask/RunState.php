<?php

namespace App\Services\AgentTask;

use App\Services\Agent\AgentRun;
use App\Services\Agent\StopReason;

/**
 * The lifecycle of an agent run (Week 6 Phase 1): the states, the legal moves
 * between them, and the one place that answers "may this run move from X to Y?".
 *
 * A run's status column holds 'running' or a StopReason; the state is derived
 * from it. Every stop reason except AWAITING_APPROVAL closes the run. A run
 * that exists in memory but has not yet put its goal into the conversation has
 * no state (—): that is where the guard refuses a goal.
 *
 * Illegal moves throw. A caller that attempts one has a bug, and a quiet false
 * would hide it. The state model document (agrivisit:state-model) is generated
 * from this class, so it cannot describe a lifecycle the code no longer has.
 */
final class RunState
{
    public const RUNNING = 'RUNNING';

    public const AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    public const CLOSED = 'CLOSED';

    /** "No state": the run has not started, or there is no row for it yet. */
    public const NONE = '—';

    /** @return array<string, string> each state and what is true while a run is in it */
    public static function states(): array
    {
        return [
            self::RUNNING           => 'A request is executing iterations. Held in memory only: the agent_runs row is written when the run stops or suspends, so no row is ever found in this state. A crash mid-resume leaves the row as it was before the resume (AWAITING_APPROVAL, its token already consumed).',
            self::AWAITING_APPROVAL => 'A write tool returned APPROVAL_REQUIRED. A pending call exists, an approval token is live in the Week 4 store, nothing has been written, and the conversation ends with the assistant turn carrying that tool_use.',
            self::CLOSED            => 'Terminal. A stop reason other than AWAITING_APPROVAL is set, a trace has been written, and no further model call will be made for this run.',
        ];
    }

    /**
     * The legal moves. 'reasons' lists the stop reasons that may make the move
     * (null where the target is not a stop). Everything not here is illegal.
     *
     * @return list<array{from: string, to: string, trigger: string, condition: string, reasons: list<string>|null}>
     */
    public static function transitions(): array
    {
        $closing = array_values(array_diff(StopReason::all(), [StopReason::AWAITING_APPROVAL, StopReason::REJECTED]));

        return [
            ['from' => self::NONE, 'to' => self::RUNNING, 'reasons' => null,
             'trigger'   => 'A goal is submitted and passes the restricted-topic guard.',
             'condition' => 'The goal becomes the first user turn (AgentRun::begin).'],
            ['from' => self::NONE, 'to' => self::CLOSED, 'reasons' => [StopReason::GUARD_REFUSED],
             'trigger'   => 'The guard refuses the goal.',
             'condition' => 'Stop reason GUARD_REFUSED only. No model call; zero iterations.'],
            ['from' => self::RUNNING, 'to' => self::AWAITING_APPROVAL, 'reasons' => [StopReason::AWAITING_APPROVAL],
             'trigger'   => 'A write tool returns APPROVAL_REQUIRED.',
             'condition' => 'The pending call is recorded on the run and held in the Week 4 approval flow.'],
            ['from' => self::RUNNING, 'to' => self::CLOSED, 'reasons' => $closing,
             'trigger'   => 'Any other stop.',
             'condition' => 'Stop reason one of ' . implode(', ', $closing) . '. Not REJECTED: there is no held call to reject.'],
            ['from' => self::AWAITING_APPROVAL, 'to' => self::RUNNING, 'reasons' => null,
             'trigger'   => 'The officer approves within the window.',
             'condition' => 'The held token is this run\'s and is consumed by the decision. A new segment opens; budgets carry over.'],
            ['from' => self::AWAITING_APPROVAL, 'to' => self::CLOSED, 'reasons' => [StopReason::REJECTED],
             'trigger'   => 'The officer rejects, or the approval expires or is no longer on file.',
             'condition' => 'Stop reason REJECTED only. The model is not called again.'],
        ];
    }

    /** @return array<string, string> every (from → to) pair not in transitions(), and why it is forbidden */
    public static function illegal(): array
    {
        $why = [
            self::NONE . ' → ' . self::AWAITING_APPROVAL              => 'A run can only propose a write by running. Nothing is held for a run that never called a tool.',
            self::RUNNING . ' → ' . self::RUNNING                     => 'A resumed run continues; it does not begin again. Starting a run that is already running would reset nothing and run it twice.',
            self::AWAITING_APPROVAL . ' → ' . self::AWAITING_APPROVAL => 'One decision per held call. A run waiting on a call cannot be suspended again on top of it.',
            self::CLOSED . ' → ' . self::RUNNING                      => 'Closed is terminal. A closed run is never resumed: a rejection is final and a refusal ends the run.',
            self::CLOSED . ' → ' . self::AWAITING_APPROVAL            => 'Closed is terminal. A closed run cannot hold a new call for approval.',
            self::CLOSED . ' → ' . self::CLOSED                       => 'Closed is terminal. Closing it again would overwrite how it ended (a second rejection, or a lazy expiry racing a decision).',
        ];

        $legal   = array_map(fn (array $t) => "{$t['from']} → {$t['to']}", self::transitions());
        $illegal = [];

        foreach ([self::NONE, self::RUNNING, self::AWAITING_APPROVAL, self::CLOSED] as $from) {
            foreach ([self::RUNNING, self::AWAITING_APPROVAL, self::CLOSED] as $to) {
                if (! in_array($pair = "{$from} → {$to}", $legal, true)) {
                    $illegal[$pair] = $why[$pair] ?? '(no reason documented)';
                }
            }
        }

        return $illegal;
    }

    /** The state a status string ('running' or a StopReason) puts a run in. */
    public static function forStatus(?string $status): string
    {
        return match ($status) {
            null                          => self::NONE,
            AgentRun::RUNNING             => self::RUNNING,
            StopReason::AWAITING_APPROVAL => self::AWAITING_APPROVAL,
            default                       => self::CLOSED,
        };
    }

    /** The state of an in-memory run: none until its goal enters the conversation. */
    public static function of(AgentRun $run): string
    {
        return $run->status === AgentRun::RUNNING && $run->messages() === [] ? self::NONE : self::forStatus($run->status);
    }

    /**
     * Throws unless a run in $from may move to $toStatus ('running' or a StopReason).
     *
     * @throws IllegalRunTransition
     */
    public static function check(string $from, string $toStatus, string $runId): void
    {
        $to = self::forStatus($toStatus);

        foreach (self::transitions() as $t) {
            if ($t['from'] === $from && $t['to'] === $to) {
                if ($t['reasons'] !== null && ! in_array($toStatus, $t['reasons'], true)) {
                    throw IllegalRunTransition::between($runId, $from, $toStatus, $t['condition']);
                }

                return;
            }
        }

        throw IllegalRunTransition::between($runId, $from, $toStatus, self::illegal()["{$from} → {$to}"] ?? '');
    }
}
