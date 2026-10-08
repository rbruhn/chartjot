@props(['value', 'label' => 'Copy'])

{{--
    A read-only text box with a copy button (#111, #113). The button shows a check mark and "Copied" for two
    seconds. navigator.clipboard needs https or localhost; elsewhere it falls back to selecting the box and
    execCommand('copy'). The check mark and "Copied" start hidden with display:none (there's no [x-cloak] rule).
--}}
<div {{ $attributes->only('class')->merge(['class' => 'flex items-center gap-2']) }}
    x-data="{
        copied: false,
        async copy() {
            const box = $refs.box;
            try {
                await navigator.clipboard.writeText(box.value);
            } catch (e) {
                box.select();
                document.execCommand('copy');
            }
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },
    }">
    <x-text-input x-ref="box" type="text" :value="$value" readonly class="block w-full font-mono text-sm" {{ $attributes->except('class') }} />
    <button type="button" x-on:click="copy()"
        title="{{ $label }}" aria-label="{{ $label }}"
        class="inline-flex shrink-0 items-center gap-1 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:border-gray-400 hover:text-gray-900 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:text-gray-100"
        style="cursor:pointer">
        <x-heroicon-o-clipboard-document class="h-5 w-5" x-show="! copied" />
        <x-heroicon-o-check class="h-5 w-5 text-green-600 dark:text-green-400" x-show="copied" style="display:none" />
        <span x-show="copied" style="display:none" class="text-green-600 dark:text-green-400">Copied</span>
    </button>
</div>
