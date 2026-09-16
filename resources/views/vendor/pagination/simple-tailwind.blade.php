@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex gap-2 items-center justify-between">

        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed leading-5 rounded-md dark:text-slate-500 dark:bg-white/5 dark:border-white/10">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 leading-5 rounded-md hover:text-brand focus:outline-none focus:ring focus:ring-brand/40 focus:border-brand active:bg-slate-100 active:text-brand transition ease-in-out duration-150 dark:bg-deep dark:border-white/10 dark:text-slate-200 dark:focus:border-accent dark:active:bg-white/10 dark:active:text-white hover:bg-slate-50 dark:hover:bg-white/10 dark:hover:text-white">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 leading-5 rounded-md hover:text-brand focus:outline-none focus:ring focus:ring-brand/40 focus:border-brand active:bg-slate-100 active:text-brand transition ease-in-out duration-150 dark:bg-deep dark:border-white/10 dark:text-slate-200 dark:focus:border-accent dark:active:bg-white/10 dark:active:text-white hover:bg-slate-50 dark:hover:bg-white/10 dark:hover:text-white">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed leading-5 rounded-md dark:text-slate-500 dark:bg-white/5 dark:border-white/10">
                {!! __('pagination.next') !!}
            </span>
        @endif

    </nav>
@endif
