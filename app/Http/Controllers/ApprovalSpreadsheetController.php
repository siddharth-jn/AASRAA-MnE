<?php

namespace App\Http\Controllers;

use App\Services\ApprovalSpreadsheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApprovalSpreadsheetController extends Controller
{
    public function download(Request $request, ApprovalSpreadsheetService $spreadsheets): StreamedResponse
    {
        Gate::authorize('bulk-approve-excel');
        $filters = $request->validate([
            'programme' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'indicator' => ['nullable', 'integer'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
        ]);

        return response()->streamDownload(
            function () use ($request, $spreadsheets, $filters): void {
                $spreadsheets->write($request->user(), $filters, 'php://output');
            },
            'pending-approvals-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function upload(Request $request, ApprovalSpreadsheetService $spreadsheets): RedirectResponse
    {
        Gate::authorize('bulk-approve-excel');
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);
        $result = $spreadsheets->approve($request->user(), $data['file']);

        return back()->with(
            'success',
            "{$result['approved']} submission(s) approved at their current stage from the spreadsheet. {$result['skipped']} skipped because they changed or no longer met the approval requirements.",
        );
    }
}
