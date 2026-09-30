{{--
    Multi-select account filter shared by the journal and statistics pages.
    Must be rendered inside a Livewire component that has a public
    `?array $selectedAccountIds` property (null = all accounts).
--}}
@props(['accounts'])

<div class="flex flex-col gap-1" x-data="{
    open: false,
    accounts: {{ $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->toJson() }},
    checkedIds: [],
    init() {
        // Reflect the component's current selection (e.g. ?accounts[] in the URL);
        // null means all accounts.
        const selected = $wire.selectedAccountIds;
        this.checkedIds = selected == null ? this.accounts.map(a => a.id) : selected.map(Number);
    },
    get allChecked() { return this.checkedIds.length === this.accounts.length; },
    get label() {
        if (this.allChecked) return 'All accounts';
        const c = this.checkedIds.length;
        return c === 0 ? 'No accounts' : c + ' of ' + this.accounts.length + ' accounts';
    },
    isChecked(id) { return this.checkedIds.includes(Number(id)); },
    toggleAll() {
        this.checkedIds = this.allChecked ? [] : this.accounts.map(a => a.id);
        this.sync();
    },
    toggle(id) {
        id = Number(id);
        const idx = this.checkedIds.indexOf(id);
        if (idx === -1) this.checkedIds.push(id);
        else this.checkedIds.splice(idx, 1);
        this.sync();
    },
    sync() {
        if (this.allChecked) $wire.set('selectedAccountIds', null);
        else $wire.set('selectedAccountIds', [...this.checkedIds]);
    }
}" @click.outside="open = false">
    <label class="text-xs font-semibold text-gray-600 dark:text-gray-500 uppercase tracking-wider">Accounts</label>
    <div class="relative">
        <button type="button" @click="open = !open"
            class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-900 dark:text-gray-100 text-sm rounded px-2 py-1 w-48 text-left flex items-center justify-between gap-2 focus:outline-none focus:ring-1 focus:ring-indigo-500">
            <span x-text="label" class="truncate"></span>
            <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="open" x-cloak class="bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600" style="position:absolute;z-index:50;top:calc(100% + 4px);left:0;width:12rem;border-radius:0.375rem;box-shadow:0 10px 15px -3px rgba(0,0,0,.4);padding:0.25rem 0;max-height:16rem;overflow-y:auto">
            <label class="flex items-center gap-2 px-3 py-1.5 cursor-pointer select-none border-b border-gray-300 dark:border-gray-600 hover:bg-gray-300 dark:hover:bg-gray-600">
                <input type="checkbox" :checked="allChecked" @change="toggleAll()"
                    class="rounded border-gray-300 dark:border-gray-500 bg-white dark:bg-gray-600 text-indigo-500 focus:ring-indigo-500">
                <span class="text-sm font-medium text-gray-800 dark:text-gray-200">All accounts</span>
            </label>
            <template x-for="acct in accounts" :key="acct.id">
                <label class="flex items-center gap-2 px-3 py-1.5 cursor-pointer select-none hover:bg-gray-300 dark:hover:bg-gray-600">
                    <input type="checkbox" :checked="isChecked(acct.id)" @change="toggle(acct.id)"
                        class="rounded border-gray-300 dark:border-gray-500 bg-white dark:bg-gray-600 text-indigo-500 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700 dark:text-gray-300 truncate" x-text="acct.name"></span>
                </label>
            </template>
        </div>
    </div>
</div>
