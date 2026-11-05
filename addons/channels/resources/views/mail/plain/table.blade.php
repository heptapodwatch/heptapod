@include('channels::mail.plain.title', ['title' => $block->title])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;line-height:20px;">
<tr>
@foreach ($block->head as $heading)
<th align="left" valign="bottom" style="padding:4px 8px 4px 0;border-bottom:1px solid #c4cad0;font-weight:600;">{{ $heading }}</th>
@endforeach
</tr>
@foreach ($block->rows as $row)
<tr>
@foreach ($row as $cell)
<td valign="top" style="padding:4px 8px 4px 0;border-bottom:1px solid #c4cad0;">@include('channels::mail.plain.cell')</td>
@endforeach
</tr>
@endforeach
</table>
@if ($block->more !== null)
<p style="margin:4px 0 0;">{{ $block->more }}</p>
@endif
