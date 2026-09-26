{{-- Shared proposal document fragment. Preview pane, PDF download, and
Word export all render through these blocks — one path, no divergence. --}}
@foreach ($blocks as $block)
    <section class="proposal-section">
        @if ($block['heading'] !== '')
            <h2 class="proposal-heading">{{ $block['number'] }}. {!! $block['heading'] !!}</h2>
        @endif
        <div class="proposal-body">{!! $block['html'] !!}</div>
    </section>
@endforeach
