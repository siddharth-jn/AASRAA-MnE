<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\CompletenessService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('impact:send-deadline-reminders')]
#[Description('Send in-app reminders for incomplete reporting periods approaching their deadline')]
class SendDeadlineReminders extends Command
{
    public function handle(CompletenessService $completeness): int
    {
        $today = CarbonImmutable::today();
        $deadline = $today->setDay(min((int) config('impact.reporting_deadline_day', 5), $today->daysInMonth));
        if ($today->gt($deadline)) {
            $deadline = $deadline->addMonthNoOverflow();
        }

        $daysUntilDeadline = $today->diffInDays($deadline, false);
        if ($daysUntilDeadline < 0 || $daysUntilDeadline > (int) config('impact.deadline_reminder_days', 3)) {
            $this->info('No reporting deadline reminder is due today.');

            return self::SUCCESS;
        }

        $period = $deadline->subMonth()->startOfMonth();
        $kind = 'deadline_'.$period->format('Y_m');
        $notified = 0;
        User::query()
            ->where('active', true)
            ->whereIn('role', ['ADMIN', 'SENIOR PROGRAM LEAD', 'PROGRAM LEAD', 'CENTRE COORDINATOR'])
            ->each(function (User $user) use ($completeness, $period, $deadline, $kind, &$notified): void {
                $summary = $completeness->summary($user, $period, $period->endOfMonth());
                if ($summary['missing'] === 0 || $user->notifications()->where('data->kind', $kind)->exists()) {
                    return;
                }

                $user->notify(new WorkflowNotification(
                    'Reporting deadline approaching',
                    "{$summary['missing']} required record(s) for {$period->format('F Y')} are still missing. The deadline is {$deadline->format('d M Y')}.",
                    route('entries.index', ['period' => $period->format('Y-m')]),
                    $kind,
                ));
                $notified++;
            });

        $this->info("Sent {$notified} reporting deadline reminder(s).");

        return self::SUCCESS;
    }
}
