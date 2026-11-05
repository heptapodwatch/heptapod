@include('channels::mail.plain.title', ['title' => $block->title])
@foreach ($block->items as $item)
<div style="overflow-wrap:anywhere;word-break:break-word;">{{ $item['text'] }}@if ($item['meta'] !== '') · {{ $item['meta'] }}@endif</div>
@endforeach
