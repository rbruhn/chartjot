<?php

use App\Enums\UserStatus;
use App\Mail\UserApproved;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $message = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        return User::where('is_admin', false)
            ->when(
                $this->search,
                fn ($q) => $q->where(function ($sub) {
                    $sub->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                })
            )
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function approve(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->status = UserStatus::Active;
        $user->save();
        Mail::to($user)->send(new UserApproved($user));
        $this->message = "{$user->name} approved and notified.";
        unset($this->users);
    }

    public function suspend(int $userId): void
    {
        $user = User::findOrFail($userId);
        $user->status = UserStatus::Suspended;
        $user->save();
        $this->message = "{$user->name} has been suspended.";
        unset($this->users);
    }

    public function deleteUser(int $userId): void
    {
        $user = User::findOrFail($userId);
        $name = $user->name;
        $user->delete();
        $this->message = "{$name} has been deleted.";
        unset($this->users);
    }
}; ?>

<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('User Management') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        @if ($message)
            <div class="mb-4 p-4 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 text-sm flex items-center justify-between">
                <span>{{ $message }}</span>
                <button wire:click="$set('message', '')" class="text-green-600 dark:text-green-400 hover:text-green-800">&times;</button>
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">

                <!-- Search -->
                <div class="mb-6">
                    <x-text-input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="Search by name or email…"
                        class="w-full sm:max-w-sm"
                    />
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <th class="pb-3 pr-6">Name</th>
                                <th class="pb-3 pr-6">Email</th>
                                <th class="pb-3 pr-6">Status</th>
                                <th class="pb-3 pr-6">Registered</th>
                                <th class="pb-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($this->users as $user)
                                <tr class="text-gray-800 dark:text-gray-200">
                                    <td class="py-3 pr-6 font-medium">{{ $user->name }}</td>
                                    <td class="py-3 pr-6 text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                                    <td class="py-3 pr-6">
                                        @php $status = $user->status @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            {{ $status === \App\Enums\UserStatus::Active    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : '' }}
                                            {{ $status === \App\Enums\UserStatus::Pending   ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400' : '' }}
                                            {{ $status === \App\Enums\UserStatus::Suspended ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' : '' }}
                                        ">
                                            {{ $status->label() }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-6 text-gray-500 dark:text-gray-400 text-xs">
                                        {{ $user->created_at->diffForHumans() }}
                                    </td>
                                    <td class="py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            @if ($user->status !== \App\Enums\UserStatus::Active)
                                                <button wire:click="approve({{ $user->id }})"
                                                        wire:confirm="Approve {{ $user->name }}?"
                                                        class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold uppercase tracking-wider bg-indigo-100 text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-300 dark:hover:bg-indigo-800/60 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 transition ease-in-out duration-150">
                                                    Approve
                                                </button>
                                            @endif

                                            @if ($user->status !== \App\Enums\UserStatus::Suspended)
                                                <button wire:click="suspend({{ $user->id }})"
                                                        wire:confirm="Suspend {{ $user->name }}?"
                                                        class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold uppercase tracking-wider bg-yellow-100 text-yellow-800 hover:bg-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-300 dark:hover:bg-yellow-900/50 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-1 transition ease-in-out duration-150">
                                                    Suspend
                                                </button>
                                            @endif

                                            <button wire:click="deleteUser({{ $user->id }})"
                                                    wire:confirm="Permanently delete {{ $user->name }}? This cannot be undone."
                                                    class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold uppercase tracking-wider bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1 transition ease-in-out duration-150">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-gray-400 dark:text-gray-500 text-sm">
                                        No users found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $this->users->links() }}
                </div>

            </div>
        </div>
    </div>
</div>
