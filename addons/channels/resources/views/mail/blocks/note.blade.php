@if ($plain)
{!! $block->text !!}
@else
@php($color = $block->tone?->color() ?? 'fg2')
<p class="c-{{ $color }}" style="margin:0;color:{{ $light[$color] }};font-size:13px;line-height:20px;">{{ $block->text }}</p>
@endif
