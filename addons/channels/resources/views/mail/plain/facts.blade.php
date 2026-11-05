@include('channels::mail.plain.title', ['title' => $block->title])
@foreach ($block->rows as $row)
<div>{{ $row['label'] }}: @include('channels::mail.plain.cell', ['cell' => $row['value']])</div>
@endforeach
