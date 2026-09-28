<?php

use App\Jobs\ImportExecutionsCsv;
use App\Models\Journal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public Journal $journal;

    #[Validate('required|file|mimes:csv,txt|max:10240')]
    public $csvFile = null;

    public string $importId  = '';
    public string $state     = 'idle';   // idle | uploading | processing | complete | failed
    public array  $result    = [];
    public string $errorMsg  = '';

    public function updatedCsvFile(): void
    {
        $this->validateOnly('csvFile');
        $this->submit();
    }

    public function submit(): void
    {
        if (blank($this->journal->timezone)) {
            $this->errorMsg = 'Set your journal time zone in Settings before importing.';
            $this->state    = 'failed';
            return;
        }

        $this->validate();

        $this->state    = 'uploading';
        $this->importId = Str::uuid()->toString();
        $this->result   = [];
        $this->errorMsg = '';

        $this->csvFile->storeAs(
            'csv-imports',
            $this->importId . '.csv',
            'local'
        );

        $this->state = 'processing';

        ImportExecutionsCsv::dispatch(
            $this->journal,
            Storage::disk('local')->path('csv-imports/' . $this->importId . '.csv'),
            $this->importId,
        );

        $this->csvFile = null;
    }

    public function poll(): void
    {
        if ($this->state !== 'processing' || $this->importId === '') {
            return;
        }

        $cached = Cache::get("csv_import_{$this->importId}");

        if ($cached === null) {
            return;
        }

        if ($cached['status'] === 'complete') {
            $this->state  = 'complete';
            $this->result = $cached['result'];
        } else {
            $this->state    = 'failed';
            $this->errorMsg = $cached['error'] ?? 'Import failed.';
        }
    }

    public function clear(): void
    {
        $this->state    = 'idle';
        $this->importId = '';
        $this->result   = [];
        $this->errorMsg = '';
        $this->csvFile  = null;
    }
}; ?>

<div
    wire:poll.2000ms="poll"
    class="space-y-4"
>
    @if ($state === 'idle' || $state === 'uploading')
        <div
            x-data="csvDropzone($wire)"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop($event)"
            :class="dragging ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/20' : 'border-gray-300 dark:border-gray-600 hover:border-indigo-400 dark:hover:border-indigo-500'"
            class="relative flex items-center gap-4 rounded-lg border-2 border-dashed px-5 py-4 transition-colors"
        >
            <svg class="h-6 w-6 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>

            <div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Drop your NT8 Executions CSV here, or
                    <label for="csv-file-input" class="cursor-pointer font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                        browse to upload
                    </label>
                </p>
            </div>

            <input
                id="csv-file-input"
                type="file"
                accept=".csv,text/csv"
                wire:model="csvFile"
                class="sr-only"
            />
        </div>

        @error('csvFile')
            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        @if ($state === 'uploading')
            <p class="text-sm text-gray-500 dark:text-gray-400">Uploading…</p>
        @endif
    @endif

    @if ($state === 'processing')
        <div class="flex items-center gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-4 dark:border-indigo-800 dark:bg-indigo-900/20">
            <svg class="h-5 w-5 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-sm font-medium text-indigo-700 dark:text-indigo-300">
                Importing executions — this may take a few seconds…
            </p>
        </div>
    @endif

    @if ($state === 'complete')
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-4 dark:border-green-800 dark:bg-green-900/20">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <div class="flex-1 text-sm">
                    <p class="font-semibold text-green-800 dark:text-green-200">Import complete</p>
                    <ul class="mt-2 space-y-1 text-green-700 dark:text-green-300">
                        <li>{{ $result['trades_created'] }} trade{{ $result['trades_created'] === 1 ? '' : 's' }} imported</li>
                        @if ($result['trades_skipped'] > 0)
                            <li>{{ $result['trades_skipped'] }} duplicate{{ $result['trades_skipped'] === 1 ? '' : 's' }} skipped</li>
                        @endif
                        @if (count($result['errors']) > 0)
                            <li class="font-medium text-amber-700 dark:text-amber-400">
                                {{ count($result['errors']) }} trade{{ count($result['errors']) === 1 ? '' : 's' }} had errors
                            </li>
                            @foreach (array_slice($result['errors'], 0, 5) as $err)
                                <li class="ml-4 font-mono text-xs">{{ $err }}</li>
                            @endforeach
                            @if (count($result['errors']) > 5)
                                <li class="ml-4 text-xs">…and {{ count($result['errors']) - 5 }} more</li>
                            @endif
                        @endif
                    </ul>
                </div>
            </div>
            <div class="mt-3 flex justify-end">
                <button wire:click="clear" type="button" class="text-xs font-semibold text-green-700 hover:text-green-900 dark:text-green-400 dark:hover:text-green-200">
                    Import another file
                </button>
            </div>
        </div>
    @endif

    @if ($state === 'failed')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-4 dark:border-red-800 dark:bg-red-900/20">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div class="flex-1 text-sm">
                    <p class="font-semibold text-red-800 dark:text-red-200">Import failed</p>
                    <p class="mt-1 text-red-700 dark:text-red-300">{{ $errorMsg }}</p>
                </div>
            </div>
            <div class="mt-3 flex justify-end">
                <button wire:click="clear" type="button" class="text-xs font-semibold text-red-700 hover:text-red-900 dark:text-red-400 dark:hover:text-red-200">
                    Try again
                </button>
            </div>
        </div>
    @endif
</div>

@script
<script>
    Alpine.data('csvDropzone', ($wire) => ({
        dragging: false,
        onDrop(event) {
            this.dragging = false;
            const file = event.dataTransfer?.files?.[0];
            if (!file) return;
            $wire.upload('csvFile', file, () => {}, () => {});
        },
    }));
</script>
@endscript
