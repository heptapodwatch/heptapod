@include('channels::mail.plain.title', ['title' => $block->title])
@foreach ($block->items as $item)
<div>{{ $item['label'] }}: {{ $item['value'] }}@if ($item['min'] !== null) (≥ {{ $item['min'] }})@endif</div>
@endforeach
@if ($block->note !== null)
<p style="margin:4px 0 0;">{{ $block->note }}</p>
@endif
