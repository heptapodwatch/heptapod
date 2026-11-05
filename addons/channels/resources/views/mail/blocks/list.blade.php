@if ($plain)
{!! $block->title !!}
@foreach ($block->items as $item)
{!! '- '.$item['text'].($item['meta'] !== '' ? ' · '.$item['meta'] : '') !!}
@endforeach
@else
<p class="c-fg3" style="margin:0 0 8px;color:#5c6771;font-size:12px;">{{ $block->title }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-sunken" style="background:#f5f6f7;border:1px solid #e1e5e8;border-radius:8px;"><tr><td style="padding:12px 14px;">
@foreach ($block->items as $item)
<div style="font-size:13px;line-height:22px;overflow-wrap:anywhere;word-break:break-word;"><span class="c-fg" style="color:#101418;">{{ $item['text'] }}</span>@if ($item['meta'] !== '') <span class="c-fg3" style="color:#5c6771;">· {{ $item['meta'] }}</span>@endif</div>
@endforeach
</td></tr></table>
@endif
