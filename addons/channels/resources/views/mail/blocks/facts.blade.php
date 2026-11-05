@if ($plain)
{!! $block->title !!}
@foreach ($block->rows as $row)
{!! $row['label'] !!}: {!! $row['value']->text !!}{!! $row['value']->badge === null ? '' : ' ('.$row['value']->badge.')' !!}
@endforeach
@else
@if ($block->title !== '')
<p class="c-fg3" style="margin:0 0 8px;color:#5c6771;font-size:12px;">{{ $block->title }}</p>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg-sunken" style="background:#f5f6f7;border:1px solid #e1e5e8;border-radius:8px;"><tr><td style="padding:12px 14px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
@foreach ($block->rows as $row)
<tr><td valign="top" class="c-fg3" style="color:#5c6771;font-size:12px;padding:3px 12px 3px 0;width:38%;">{{ $row['label'] }}</td><td valign="top" style="padding:3px 0;">@include('channels::mail.blocks.cell', ['cell' => $row['value']])</td></tr>
@endforeach
</table></td></tr></table>
@endif
