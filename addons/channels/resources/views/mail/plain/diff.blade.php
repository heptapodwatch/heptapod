@include('channels::mail.plain.title', ['title' => $block->title])
@foreach ($block->lines as $line)
<div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,'Liberation Mono',monospace;font-size:13px;line-height:20px;overflow-wrap:anywhere;word-break:break-word;">{{ $line['sign'] }}&nbsp;{{ $line['text'] }}</div>
@endforeach
@if ($block->action !== null)
<p style="margin:4px 0 0;"><a href="{{ $block->action['url'] }}">{{ $block->action['label'] }}</a></p>
@endif
