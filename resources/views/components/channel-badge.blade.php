{{-- 公開範囲の印 --}}
@props(['channel'])
@if ($channel->is_private)
    <span class="badge badge--private">プライベート</span>
@else
    <span class="badge badge--public">公開</span>
@endif
