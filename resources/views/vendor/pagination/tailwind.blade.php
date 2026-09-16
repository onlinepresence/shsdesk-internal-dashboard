@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">

        <div class="flex gap-2 items-center justify-between sm:hidden">

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

        </div>

        <div class="hidden sm:flex-1 sm:flex sm:gap-2 sm:items-center sm:justify-between">

            <div>
                <p class="text-sm text-slate-600 leading-5 dark:text-slate-400">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-medium">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-medium">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-medium">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div>
                <span class="inline-flex rtl:flex-row-reverse shadow-sm rounded-md">

                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                            <span class="inline-flex items-center px-2 py-2 text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded-l-md leading-5 dark:bg-white/5 dark:border-white/10 dark:text-slate-500" aria-hidden="true">
                                <x-lucide-chevron-left class="h-5 w-5" aria-hidden="true" />
                            </span>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center px-2 py-2 text-sm font-medium text-slate-500 bg-white border border-slate-300 rounded-l-md leading-5 hover:text-brand focus:outline-none focus:ring focus:ring-brand/40 focus:border-brand active:bg-slate-100 active:text-brand transition ease-in-out duration-150 dark:bg-deep dark:border-white/10 dark:active:bg-white/10 dark:focus:border-accent dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white" aria-label="{{ __('pagination.previous') }}">
                            <x-lucide-chevron-left class="h-5 w-5" aria-hidden="true" />
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true">
                                <span class="inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-slate-500 bg-white border border-slate-200 cursor-default leading-5 dark:bg-deep dark:border-white/10 dark:text-slate-400">{{ $element }}</span>
                            </span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page">
                                        <span class="inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-white bg-brand border border-brand cursor-default leading-5 dark:bg-brand dark:border-white/20 dark:text-white">{{ $page }}</span>
                                    </span>
                                @else
                                    <a href="{{ $url }}" class="inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-slate-700 bg-white border border-slate-300 leading-5 hover:text-brand focus:outline-none focus:ring focus:ring-brand/40 focus:border-brand active:bg-slate-100 active:text-brand transition ease-in-out duration-150 dark:bg-deep dark:border-white/10 dark:text-slate-200 dark:hover:text-white dark:active:bg-white/10 dark:focus:border-accent hover:bg-slate-50 dark:hover:bg-white/10" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center px-2 py-2 -ml-px text-sm font-medium text-slate-500 bg-white border border-slate-300 rounded-r-md leading-5 hover:text-brand focus:outline-none focus:ring focus:ring-brand/40 focus:border-brand active:bg-slate-100 active:text-brand transition ease-in-out duration-150 dark:bg-deep dark:border-white/10 dark:active:bg-white/10 dark:focus:border-accent dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white" aria-label="{{ __('pagination.next') }}">
                            <x-lucide-chevron-right class="h-5 w-5" aria-hidden="true" />
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                            <span class="inline-flex items-center px-2 py-2 -ml-px text-sm font-medium text-slate-400 bg-slate-50 border border-slate-200 cursor-not-allowed rounded-r-md leading-5 dark:bg-white/5 dark:border-white/10 dark:text-slate-500" aria-hidden="true">
                                <x-lucide-chevron-right class="h-5 w-5" aria-hidden="true" />
                            </span>
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
