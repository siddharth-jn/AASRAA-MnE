<?php

namespace App\Support;

class WorkflowStatus
{
    public const Draft = 'DRAFT';

    public const PendingSeniorProgrammeLead = 'PENDING SENIOR PL';

    public const PendingMneHead = 'PENDING M&E HEAD';

    public const FinalApproved = 'FINAL APPROVED';

    public const ReturnedForCorrection = 'RETURNED FOR CORRECTION';

    public const Rejected = 'REJECTED';

    public const ReEntryRequired = 'RE-ENTRY REQUIRED';

    public const TargetRemoved = 'TARGET REMOVED';

    /** @return array<int, string> */
    public static function pending(): array
    {
        return [self::PendingSeniorProgrammeLead, self::PendingMneHead];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PendingSeniorProgrammeLead => 'Pending Senior Programme Lead',
            self::PendingMneHead => 'Pending M&E Head',
            self::FinalApproved => 'Final approved',
            self::ReturnedForCorrection => 'Returned for correction',
            self::ReEntryRequired => 'Re-entry required',
            self::TargetRemoved => 'Target removed',
            default => str($status)->lower()->headline()->toString(),
        };
    }
}
