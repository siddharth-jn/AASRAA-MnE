<x-layouts.app heading="Documents">
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Files are private and visible only to administrators. On the Vercel preview, uploaded files use temporary local storage and may be cleared when the application restarts or is redeployed.
    </div>

    <nav class="mb-5 flex flex-wrap items-center gap-2 text-sm" aria-label="Document folders">
        <a href="{{ route('documents.index') }}" class="font-semibold {{ $selectedGroup ? 'text-brand-700' : 'text-slate-900' }}">Documents</a>
        @if($selectedGroup)
            <span class="text-slate-400">/</span>
            <a href="{{ route('documents.index', ['group' => $selectedGroup['key']]) }}" class="font-semibold {{ $selectedFolder ? 'text-brand-700' : 'text-slate-900' }}">{{ $selectedGroup['label'] }}</a>
        @endif
        @foreach($breadcrumbs as $breadcrumb)
            <span class="text-slate-400">/</span>
            @if($loop->last)
                <span class="font-semibold text-slate-900">{{ $breadcrumb['label'] }}</span>
            @else
                <a href="{{ route('documents.index', ['group' => $selectedGroup['key'], 'folder' => $breadcrumb['path']]) }}" class="font-semibold text-brand-700">{{ $breadcrumb['label'] }}</a>
            @endif
        @endforeach
    </nav>

    @if(!$selectedGroup)
        <div class="mb-4">
            <h2 class="text-lg font-bold text-ink">Document libraries</h2>
            <p class="mt-1 text-sm text-slate-500">Open a library to browse its folders and files.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($documentGroups as $group)
                <a href="{{ route('documents.index', ['group' => $group['key']]) }}" class="card group p-5 transition hover:border-brand-300 hover:shadow-sm">
                    <div class="flex items-start gap-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-50 text-2xl text-brand-700" aria-hidden="true">▰</span>
                        <div class="min-w-0">
                            <h3 class="font-bold text-slate-900 group-hover:text-brand-700">{{ $group['label'] }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ $group['description'] }}</p>
                            <p class="mt-3 text-xs font-semibold text-slate-400">{{ $group['folder_count'] }} {{ Str::plural('folder', $group['folder_count']) }} · {{ $group['file_count'] }} {{ Str::plural('file', $group['file_count']) }}</p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div x-data="{ selecting: false, selected: [] }" class="space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-ink">{{ $selectedFolder['label'] ?? $selectedGroup['label'] }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        @if($selectedFolder)
                            {{ $selectedFolder['folder_count'] }} {{ Str::plural('folder', $selectedFolder['folder_count']) }} · {{ $files->count() }} {{ Str::plural('file', $files->count()) }}
                        @else
                            {{ $selectedGroup['description'] }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if($selectedFolder && $files->isNotEmpty())
                        <button
                            type="button"
                            class="btn-secondary"
                            @click="selecting = !selecting; if (!selecting) selected = []"
                            x-text="selecting ? 'Done selecting' : 'Select files'"
                        >Select files</button>

                        <form
                            x-cloak
                            x-show="selecting"
                            method="POST"
                            action="{{ route('documents.files.destroy', ['group' => $selectedGroup['key']]) }}"
                            onsubmit="return confirm('Delete the selected files? This cannot be undone.')"
                            class="flex items-center gap-2"
                        >
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="folder" value="{{ $selectedFolder['path'] }}">
                            <template x-for="file in selected" :key="file">
                                <input type="hidden" name="files[]" :value="file">
                            </template>
                            <button type="submit" class="btn-secondary text-red-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="selected.length === 0">
                                Delete selected <span x-show="selected.length > 0" x-text="'(' + selected.length + ')'"></span>
                            </button>
                        </form>
                    @endif

                    <details class="relative">
                        <summary class="btn-primary cursor-pointer list-none [&::-webkit-details-marker]:hidden">New</summary>
                        <div class="absolute right-0 top-full z-20 mt-2 w-[min(28rem,calc(100vw-2rem))] rounded-xl border border-slate-200 bg-white p-5 shadow-xl">
                            <form method="POST" action="{{ route('documents.folders.store', ['group' => $selectedGroup['key']]) }}">
                                @csrf
                                @if($selectedFolder)<input type="hidden" name="parent_path" value="{{ $selectedFolder['path'] }}">@endif
                                <label for="folder_name">New folder</label>
                                <input id="folder_name" name="folder_name" value="{{ old('folder_name') }}" maxlength="80" required class="mt-1 w-full" placeholder="Folder name">
                                <button class="btn-secondary mt-3 w-full">Create folder</button>
                            </form>

                            @if($selectedFolder)
                                <div class="my-5 border-t border-slate-200"></div>
                                <form method="POST" enctype="multipart/form-data" action="{{ route('documents.upload', ['group' => $selectedGroup['key']]) }}">
                                    @csrf
                                    <input type="hidden" name="folder" value="{{ $selectedFolder['path'] }}">
                                    <label for="files">Upload files</label>
                                    <input id="files" name="files[]" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png" multiple required class="mt-1 block w-full">
                                    <p class="mt-2 text-xs text-slate-500">Up to 10 files at once, maximum 10 MB each.</p>
                                    <button class="btn-primary mt-3 w-full">Upload files</button>
                                </form>
                            @endif
                        </div>
                    </details>

                    @if($selectedFolder)
                        <details class="relative">
                            <summary class="grid h-10 w-10 cursor-pointer list-none place-items-center rounded-lg border border-slate-200 text-xl font-bold text-slate-500 hover:bg-slate-50 [&::-webkit-details-marker]:hidden" aria-label="Folder options">⋯</summary>
                            <div class="absolute right-0 top-full z-20 mt-2 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                                <form method="POST" action="{{ route('documents.folders.destroy', ['group' => $selectedGroup['key']]) }}" onsubmit="return confirm('Delete this folder, its subfolders, and all files inside it? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="folder" value="{{ $selectedFolder['path'] }}">
                                    <button class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">Delete folder</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            </div>

            @if($folders->isNotEmpty())
                <section>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-semibold text-slate-800">Folders</h3>
                        <span class="text-xs text-slate-400">{{ $folders->count() }} {{ Str::plural('folder', $folders->count()) }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach($folders as $folder)
                            <article class="card group flex items-center gap-2 p-3 transition hover:border-brand-300 hover:shadow-sm">
                                <a href="{{ route('documents.index', ['group' => $selectedGroup['key'], 'folder' => $folder['path']]) }}" class="flex min-w-0 flex-1 items-center gap-3 rounded-lg p-1">
                                    <span class="text-3xl text-amber-500" aria-hidden="true">▰</span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-800 group-hover:text-brand-700">{{ $folder['label'] }}</span>
                                        <span class="mt-1 block text-xs text-slate-500">{{ $folder['folder_count'] }} {{ Str::plural('folder', $folder['folder_count']) }} · {{ $folder['total_file_count'] }} {{ Str::plural('file', $folder['total_file_count']) }}</span>
                                    </span>
                                </a>
                                <details class="relative">
                                    <summary class="grid h-9 w-9 cursor-pointer list-none place-items-center rounded-lg text-lg font-bold text-slate-400 hover:bg-slate-100 [&::-webkit-details-marker]:hidden" aria-label="{{ $folder['label'] }} options">⋯</summary>
                                    <div class="absolute right-0 top-full z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                                        <form method="POST" action="{{ route('documents.folders.destroy', ['group' => $selectedGroup['key']]) }}" onsubmit="return confirm('Delete this folder, its subfolders, and all files inside it? This cannot be undone.')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="folder" value="{{ $folder['path'] }}">
                                            <button class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">Delete folder</button>
                                        </form>
                                    </div>
                                </details>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($selectedFolder)
                <section>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-slate-800">Files</h3>
                            <p class="text-xs text-slate-400">Select files to delete several at once.</p>
                        </div>
                        @if($files->isNotEmpty())
                            <button
                                x-cloak
                                x-show="selecting"
                                type="button"
                                class="text-sm font-semibold text-brand-700"
                                @click="selected = selected.length === {{ $files->count() }} ? [] : @js($files->pluck('stored_name')->values())"
                                x-text="selected.length === {{ $files->count() }} ? 'Clear selection' : 'Select all'"
                            >Select all</button>
                        @endif
                    </div>

                    @if($files->isEmpty())
                        <div class="card grid min-h-52 place-items-center p-8 text-center">
                            <div>
                                <p class="text-4xl text-slate-300" aria-hidden="true">▤</p>
                                <p class="mt-3 font-semibold text-slate-700">This folder is empty</p>
                                <p class="mt-1 text-sm text-slate-500">Use New to create a subfolder or upload the first file.</p>
                            </div>
                        </div>
                    @else
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            @foreach($files as $file)
                                <article
                                    class="card flex min-h-40 flex-col p-4 transition"
                                    :class="selected.includes(@js($file['stored_name'])) ? 'border-brand-500 bg-brand-50/40 ring-1 ring-brand-400' : ''"
                                >
                                    <div class="flex min-w-0 items-start gap-3">
                                        <input
                                            x-cloak
                                            x-show="selecting"
                                            x-model="selected"
                                            type="checkbox"
                                            value="{{ $file['stored_name'] }}"
                                            class="mt-1"
                                            aria-label="Select {{ $file['display_name'] }}"
                                        >
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-xl text-slate-500" aria-hidden="true">▤</span>
                                        <div class="min-w-0">
                                            <p class="break-words text-sm font-semibold text-slate-800">{{ $file['display_name'] }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ $file['size'] }} · {{ $file['modified_at'] }}</p>
                                        </div>
                                    </div>
                                    <div class="mt-auto pt-4" x-show="!selecting">
                                        <a class="btn-secondary" href="{{ route('documents.download', ['group' => $selectedGroup['key'], 'file' => $file['stored_name'], 'folder' => $selectedFolder['path']]) }}">Download</a>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @elseif($folders->isEmpty())
                <div class="card grid min-h-52 place-items-center p-8 text-center">
                    <div>
                        <p class="text-4xl text-slate-300" aria-hidden="true">▰</p>
                        <p class="mt-3 font-semibold text-slate-700">No folders yet</p>
                        <p class="mt-1 text-sm text-slate-500">Use New to create the first folder in this library.</p>
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-layouts.app>
