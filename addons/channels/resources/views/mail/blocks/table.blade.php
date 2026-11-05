@if ($plain)
{!! $block->title !!}
{!! implode(' | ', $block->head) !!}
@foreach ($block->rows as $row)
{!! implode(' | ', array_map(static fn ($cell) => $cell->text.($cell->badge === null ? '' : ' ('.$cell->badge.')'), $row)) !!}
@endforeach
@if ($block->more !== null)
{!! $block->more !!}
@endif
@else
@if ($block->title !== '')
<p class="c-fg3" style="margin:0 0 8px;color:#5c6771;font-size:12px;">{{ $block->title }}</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-sunken" style="background:#f5f6f7;border:1px solid #e1e5e8;border-radius:8px;"><tr><td style="padding:12px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
@foreach ($block->head as $heading)
<td class="c-fg3" style="color:#5c6771;font-size:11px;padding:0 10px 6px 0;">{{ $heading }}</td>
@endforeach
</tr>
@foreach ($block->rows as $row)
<tr>
@foreach ($row as $cell)
<td valign="top" class="bd-soft" style="padding:6px 10px 6px 0;border-top:1px solid #e1e5e8;">@include('channels::mail.blocks.cell')</td>
@endforeach
</tr>
@endforeach
</table>
@if ($block->more !== null)
<p class="c-fg3" style="margin:8px 0 0;color:#5c6771;font-size:12px;">{{ $block->more }}</p>
@endif
</td></tr></table>
@endif
