<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('configure');
        $request->validate([
            'group' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:500'],
        ]);

        $disk = Storage::disk('local');
        $documentGroups = collect(config('impact.document_folders'))->map(function (array $group, string $groupKey) use ($disk): array {
            return [
                'key' => $groupKey,
                'label' => $group['label'],
                'description' => $group['description'],
                'folder_count' => $this->foldersForGroup($disk, $groupKey)->count(),
                'file_count' => count($disk->allFiles("document-folders/{$groupKey}")),
            ];
        })->values();

        $groupKey = $request->string('group')->value() ?: null;
        $folderKey = $request->string('folder')->value() ?: null;
        $selectedGroup = null;
        $folders = collect();
        $selectedFolder = null;
        $files = collect();
        $breadcrumbs = collect();

        if ($groupKey !== null) {
            $groupDefinition = $this->groupDefinition($groupKey);
            $selectedGroup = [
                'key' => $groupKey,
                'label' => $groupDefinition['label'],
                'description' => $groupDefinition['description'],
            ];
            $folders = $this->foldersForGroup($disk, $groupKey);
        }

        if ($folderKey !== null) {
            abort_unless($groupKey !== null, 404);
            $folderKey = $this->normaliseFolderPath($folderKey);
            $this->assertFolderExists($groupKey, $folderKey);
            $selectedFolder = $this->folderDetails($disk, $groupKey, $folderKey);
            $folders = $this->foldersForPath($disk, $groupKey, $folderKey);
            $files = $this->filesForFolder($disk, $groupKey, $folderKey);
            $breadcrumbs = $this->breadcrumbs($groupKey, $folderKey);
        }

        return view('documents.index', compact('documentGroups', 'selectedGroup', 'folders', 'selectedFolder', 'files', 'breadcrumbs'));
    }

    public function createFolder(Request $request, string $group): RedirectResponse
    {
        Gate::authorize('configure');
        $this->groupDefinition($group);
        $validated = $request->validate([
            'folder_name' => ['required', 'string', 'max:80'],
            'parent_path' => ['nullable', 'string', 'max:500'],
        ]);
        $folderKey = Str::slug($validated['folder_name']);
        if ($folderKey === '') {
            throw ValidationException::withMessages([
                'folder_name' => 'Enter a folder name containing letters or numbers.',
            ]);
        }

        $disk = Storage::disk('local');
        $parentPath = filled($validated['parent_path'] ?? null)
            ? $this->normaliseFolderPath($validated['parent_path'])
            : null;
        if ($parentPath !== null) {
            $this->assertFolderExists($group, $parentPath);
        }
        $newFolderPath = $parentPath === null ? $folderKey : "{$parentPath}/{$folderKey}";
        if ($this->folderExists($disk, $group, $newFolderPath)) {
            throw ValidationException::withMessages([
                'folder_name' => 'A folder with this name already exists.',
            ]);
        }

        $disk->makeDirectory($this->folderPath($group, $newFolderPath));
        if ($parentPath === null) {
            $this->restoreConfiguredFolder($disk, $group, $folderKey);
        }

        return redirect()->route('documents.index', array_filter([
            'group' => $group,
            'folder' => $parentPath,
        ]))
            ->with('success', 'Folder created.');
    }

    public function upload(Request $request, string $group): RedirectResponse
    {
        Gate::authorize('configure');
        $folder = $this->normaliseFolderPath((string) $request->input('folder'));
        $this->assertFolderExists($group, $folder);
        $extensions = implode(',', config('impact.document_extensions'));
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:500'],
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', "mimes:{$extensions}", 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        foreach ($validated['files'] as $file) {
            $file->storeAs(
                $this->folderPath($group, $folder),
                Str::uuid().'--'.$this->safeFileName($file),
                'local',
            );
        }

        return redirect()->route('documents.index', ['group' => $group, 'folder' => $folder])
            ->with('success', count($validated['files']).' '.Str::plural('document', count($validated['files'])).' uploaded.');
    }

    public function download(Request $request, string $group, string $file): StreamedResponse
    {
        Gate::authorize('configure');
        $folder = $this->normaliseFolderPath((string) $request->query('folder'));
        $this->assertFolderExists($group, $folder);
        abort_unless(basename($file) === $file, 404);
        $path = $this->folderPath($group, $folder).'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $this->displayName($file));
    }

    public function destroyFiles(Request $request, string $group): RedirectResponse
    {
        Gate::authorize('configure');
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:500'],
            'files' => ['required', 'array', 'min:1', 'max:100'],
            'files.*' => ['required', 'string', 'max:255'],
        ]);
        $folder = $this->normaliseFolderPath($validated['folder']);
        $this->assertFolderExists($group, $folder);
        $disk = Storage::disk('local');
        $files = collect($validated['files'])->unique()->values();
        foreach ($files as $file) {
            abort_unless(basename($file) === $file, 404);
            $path = $this->folderPath($group, $folder).'/'.$file;
            abort_unless($disk->exists($path), 404);
            abort_unless($disk->delete($path), 500, 'A selected document could not be deleted.');
        }

        return redirect()->route('documents.index', ['group' => $group, 'folder' => $folder])
            ->with('success', $files->count().' '.Str::plural('document', $files->count()).' deleted.');
    }

    public function destroyFolder(Request $request, string $group): RedirectResponse
    {
        Gate::authorize('configure');
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:500'],
        ]);
        $folder = $this->normaliseFolderPath($validated['folder']);
        $this->assertFolderExists($group, $folder);
        $disk = Storage::disk('local');
        $folderDefinition = $this->folderDetails($disk, $group, $folder);
        $fileCount = count($disk->allFiles($this->folderPath($group, $folder)));

        $disk->deleteDirectory($this->folderPath($group, $folder));
        if (! str_contains($folder, '/') && array_key_exists($folder, $this->groupDefinition($group)['folders'])) {
            $this->hideConfiguredFolder($disk, $group, $folder);
        }

        $parentPath = str_contains($folder, '/') ? Str::beforeLast($folder, '/') : null;

        return redirect()->route('documents.index', array_filter([
            'group' => $group,
            'folder' => $parentPath,
        ]))
            ->with('success', $folderDefinition['label'].' and '.$fileCount.' '.Str::plural('file', $fileCount).' deleted.');
    }

    /** @return array{label: string, description: string, folders: array<string, string>} */
    private function groupDefinition(string $group): array
    {
        $groupDefinition = config("impact.document_folders.{$group}");
        abort_unless(is_array($groupDefinition), 404);

        return $groupDefinition;
    }

    private function assertFolderExists(string $group, string $folder): void
    {
        $this->groupDefinition($group);
        abort_unless($this->folderExists(Storage::disk('local'), $group, $folder), 404);
    }

    /** @return Collection<int, array{key: string, label: string, file_count: int}> */
    private function foldersForGroup(FilesystemAdapter $disk, string $group): Collection
    {
        return $this->foldersForPath($disk, $group);
    }

    /** @return Collection<int, array{key: string, path: string, label: string, file_count: int, folder_count: int, total_file_count: int}> */
    private function foldersForPath(FilesystemAdapter $disk, string $group, ?string $parentPath = null): Collection
    {
        $configuredFolders = $parentPath === null
            ? collect($this->groupDefinition($group)['folders'])
            : collect();
        $deletedConfiguredFolders = $parentPath === null
            ? $this->deletedConfiguredFolders($disk, $group)
            : collect();
        $storagePath = "document-folders/{$group}".($parentPath === null ? '' : "/{$parentPath}");
        $storedFolders = collect($disk->directories($storagePath))
            ->map(fn (string $path): string => basename($path));

        return $configuredFolders->keys()
            ->reject(fn (string $folderKey): bool => $deletedConfiguredFolders->contains($folderKey))
            ->merge($storedFolders)
            ->unique()
            ->map(function (string $folderKey) use ($configuredFolders, $disk, $group, $parentPath): array {
                $path = $parentPath === null ? $folderKey : "{$parentPath}/{$folderKey}";

                return [
                    'key' => $folderKey,
                    'label' => $configuredFolders->get($folderKey, Str::headline($folderKey)),
                    'path' => $path,
                    'file_count' => count($disk->files($this->folderPath($group, $path))),
                    'folder_count' => count($disk->directories($this->folderPath($group, $path))),
                    'total_file_count' => count($disk->allFiles($this->folderPath($group, $path))),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    /** @return array{key: string, path: string, label: string, file_count: int, folder_count: int, total_file_count: int} */
    private function folderDetails(FilesystemAdapter $disk, string $group, string $folder): array
    {
        $folderKey = basename($folder);
        $label = ! str_contains($folder, '/')
            ? ($this->groupDefinition($group)['folders'][$folderKey] ?? Str::headline($folderKey))
            : Str::headline($folderKey);

        return [
            'key' => $folderKey,
            'path' => $folder,
            'label' => $label,
            'file_count' => count($disk->files($this->folderPath($group, $folder))),
            'folder_count' => count($disk->directories($this->folderPath($group, $folder))),
            'total_file_count' => count($disk->allFiles($this->folderPath($group, $folder))),
        ];
    }

    private function folderExists(FilesystemAdapter $disk, string $group, string $folder): bool
    {
        if (! str_contains($folder, '/')
            && array_key_exists($folder, $this->groupDefinition($group)['folders'])
            && ! $this->deletedConfiguredFolders($disk, $group)->contains($folder)) {
            return true;
        }

        return $disk->exists($this->folderPath($group, $folder));
    }

    /** @return Collection<int, array{path: string, label: string}> */
    private function breadcrumbs(string $group, string $folder): Collection
    {
        $configuredFolders = $this->groupDefinition($group)['folders'];
        $path = '';

        return collect(explode('/', $folder))->map(function (string $segment) use (&$path, $configuredFolders): array {
            $path = $path === '' ? $segment : "{$path}/{$segment}";

            return [
                'path' => $path,
                'label' => ! str_contains($path, '/') ? ($configuredFolders[$segment] ?? Str::headline($segment)) : Str::headline($segment),
            ];
        });
    }

    /** @return Collection<int, string> */
    private function deletedConfiguredFolders(FilesystemAdapter $disk, string $group): Collection
    {
        if (! $disk->exists('document-folders/.deleted-folders.json')) {
            return collect();
        }

        $state = json_decode($disk->get('document-folders/.deleted-folders.json'), true);

        return collect(is_array($state) ? ($state[$group] ?? []) : [])
            ->filter(fn (mixed $folder): bool => is_string($folder));
    }

    private function hideConfiguredFolder(FilesystemAdapter $disk, string $group, string $folder): void
    {
        $state = $this->deletedFolderState($disk);
        $state[$group] = collect($state[$group] ?? [])->push($folder)->unique()->values()->all();
        $disk->put('document-folders/.deleted-folders.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function restoreConfiguredFolder(FilesystemAdapter $disk, string $group, string $folder): void
    {
        $state = $this->deletedFolderState($disk);
        if (! array_key_exists($group, $state) || ! in_array($folder, $state[$group], true)) {
            return;
        }

        $state[$group] = collect($state[$group])->reject(fn (string $folderKey): bool => $folderKey === $folder)->values()->all();
        $disk->put('document-folders/.deleted-folders.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, list<string>> */
    private function deletedFolderState(FilesystemAdapter $disk): array
    {
        if (! $disk->exists('document-folders/.deleted-folders.json')) {
            return [];
        }

        $state = json_decode($disk->get('document-folders/.deleted-folders.json'), true);

        return is_array($state) ? $state : [];
    }

    /** @return Collection<int, array{stored_name: string, display_name: string, size: string, modified_at: string}> */
    private function filesForFolder(FilesystemAdapter $disk, string $group, string $folder): Collection
    {
        return collect($disk->files($this->folderPath($group, $folder)))
            ->map(function (string $filePath) use ($disk): array {
                $storedName = basename($filePath);
                $modifiedAt = CarbonImmutable::createFromTimestamp($disk->lastModified($filePath));

                return [
                    'stored_name' => $storedName,
                    'display_name' => $this->displayName($storedName),
                    'size' => $this->formatFileSize($disk->size($filePath)),
                    'modified_at' => $modifiedAt->format('d M Y, H:i'),
                ];
            })
            ->sortByDesc('modified_at')
            ->values();
    }

    private function folderPath(string $group, string $folder): string
    {
        return "document-folders/{$group}/{$folder}";
    }

    private function normaliseFolderPath(string $folder): string
    {
        $folder = trim($folder, '/');
        $segments = collect(explode('/', $folder));
        abort_unless($folder !== '' && $segments->every(
            fn (string $segment): bool => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment) === 1,
        ), 404);

        return $segments->implode('/');
    }

    private function safeFileName(UploadedFile $file): string
    {
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $baseName = Str::of($baseName)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._() -]+/', '-')
            ->trim(' .-_')
            ->limit(100, '')
            ->value() ?: 'document';
        $extension = strtolower($file->getClientOriginalExtension());

        return $baseName.'.'.$extension;
    }

    private function displayName(string $storedName): string
    {
        return Str::contains($storedName, '--') ? Str::after($storedName, '--') : $storedName;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
