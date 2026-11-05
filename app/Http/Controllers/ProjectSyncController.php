<?php

namespace App\Http\Controllers;

use App\Models\SourceSyncRun;
use App\Services\AuthoritativeProjectSourceService;
use App\Services\AuthoritativeProjectSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ProjectSyncController extends Controller
{
    public function index(
        AuthoritativeProjectSourceService $source,
        AuthoritativeProjectSyncService $sync,
    ): View {
        $this->authorizeSync();
        $preview = null;
        $connectionError = null;

        try {
            $preview = $sync->preview($source->operationalProjects(), $source->projectStatuses());
        } catch (Throwable $exception) {
            report($exception);
            $connectionError = 'The Projects database could not be read. Check the read-only PROJECTS_DB connection and try again.';
        }

        return view('administration.project-sync', [
            'preview' => $preview,
            'connectionError' => $connectionError,
            'runs' => SourceSyncRun::query()->with('user')->latest('started_at')->limit(20)->get(),
        ]);
    }

    public function store(
        Request $request,
        AuthoritativeProjectSourceService $source,
        AuthoritativeProjectSyncService $sync,
    ): RedirectResponse {
        $this->authorizeSync();
        $startedAt = now();
        $run = null;

        try {
            $sourceProjects = $source->operationalProjects();
            $sourceStatuses = $source->projectStatuses();
            abort_if($sourceProjects->isEmpty(), 422, 'The Projects database returned no active projects. No local data was changed.');

            $snapshotHash = $source->snapshotHash($sourceProjects, $sourceStatuses);
            $preview = $sync->preview($sourceProjects, $sourceStatuses);
            $run = SourceSyncRun::create([
                'user_id' => $request->user()->id,
                'source' => 'projects database',
                'snapshot_hash' => $snapshotHash,
                'status' => $preview['blocked'] ? 'blocked' : 'running',
                'summary' => $preview,
                'started_at' => $startedAt,
                'completed_at' => $preview['blocked'] ? now() : null,
                'is_demo' => false,
            ]);

            if ($preview['blocked']) {
                throw ValidationException::withMessages([
                    'sync' => 'Synchronization was blocked because the preview contains an unresolved mapping, possible duplicate, or project missing from the source. Review the highlighted items; no local project data was changed.',
                ]);
            }

            $statistics = $sync->sync($sourceProjects);
            $statistics['status_updates'] = $sync->reconcileStatuses($sourceStatuses);
            $summary = [...$preview, 'applied' => $statistics];
            $run->update([
                'status' => 'completed',
                'summary' => $summary,
                'completed_at' => now(),
            ]);

            return back()->with(
                'success',
                "Project synchronization completed: {$statistics['created']} added, {$statistics['updated']} updated, and {$statistics['status_updates']} status change(s). The source database was not modified.",
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($run) {
                $run->update([
                    'status' => 'failed',
                    'error' => str($exception->getMessage())->limit(2000),
                    'completed_at' => now(),
                ]);
            }
            report($exception);

            throw ValidationException::withMessages([
                'sync' => 'The Projects database could not be synchronized. No source data was modified. Review the connection and application logs before retrying.',
            ]);
        }
    }

    private function authorizeSync(): void
    {
        Gate::authorize('configure');
        abort_unless(config('impact.authoritative_project_sync_enabled'), 404);
    }
}
