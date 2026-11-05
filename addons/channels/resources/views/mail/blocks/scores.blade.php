@if ($plain)
{!! $block->title !!}
@foreach ($block->items as $item)
{!! $item['label'] !!}: {!! $item['value'] !!}{!! $item['min'] === null ? '' : ' (≥ '.$item['min'].')' !!}
@endforeach
@if ($block->note !== null)
{!! $block->note !!}
@endif
@else
@if ($block->title !== '')
<p class="c-fg3" style="margin:0 0 8px;color:#5c6771;font-size:12px;">{{ $block->title }}</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-sunken" style="background:#f5f6f7;border:1px solid #e1e5e8;border-radius:8px;"><tr><td style="padding:12px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
@foreach ($block->items as $item)
@php($color = $item['min'] === null ? 'fg' : ($item['value'] >= $item['min'] ? 'ok' : 'warn'))
<td align="center" style="padding:4px;"><div class="c-{{ $color }}" style="color:{{ $light[$color] }};font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,'Liberation Mono',monospace;font-size:22px;line-height:28px;">{{ $item['value'] }}</div><div class="c-fg3" style="color:#5c6771;font-size:11px;">{{ $item['label'] }}@if ($item['min'] !== null) · ≥ {{ $item['min'] }}@endif</div></td>
@endforeach
</tr></table>
@if ($block->note !== null)
<p class="c-fg3" style="margin:8px 0 0;color:#5c6771;font-size:12px;">{{ $block->note }}</p>
@endif
</td></tr></table>
@endif
