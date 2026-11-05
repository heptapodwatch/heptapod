@if ($plain)
{!! $block->title !!}
@foreach ($block->lines as $line)
{!! $line['sign'] !!} {!! $line['text'] !!}
@endforeach
@if ($block->action !== null)
{!! $block->action['label'] !!}: {!! $block->action['url'] !!}
@endif
@else
@if ($block->title !== '')
<p class="c-fg3" style="margin:0 0 8px;color:#5c6771;font-size:12px;">{{ $block->title }}</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-sunken" style="background:#f5f6f7;border:1px solid #e1e5e8;border-radius:8px;"><tr><td style="padding:12px 14px;">
@foreach ($block->lines as $line)
@php($color = $line['tone']->color())
<div class="c-{{ $color }}" style="color:{{ $light[$color] }};font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,'Liberation Mono',monospace;font-size:12px;line-height:20px;overflow-wrap:anywhere;word-break:break-word;">{{ $line['sign'] }}&nbsp;{{ $line['text'] }}</div>
@endforeach
@if ($block->action !== null)
<p style="margin:10px 0 0;font-size:13px;"><a href="{{ $block->action['url'] }}" class="c-fg" style="color:#101418;text-decoration:underline;">{{ $block->action['label'] }}</a></p>
@endif
</td></tr></table>
@endif
