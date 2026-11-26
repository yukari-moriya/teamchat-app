{{-- アバター。表示名の先頭2文字。地色は4色で、利用者の ID で人ごとに固定（docs/design/screens.md 3-3 の No 7） --}}
@props(['user'])
@php($color = ['', 'avatar--b', 'avatar--c', 'avatar--d'][($user->id - 1) % 4])
<span {{ $attributes->class(['avatar', $color => $color !== '']) }}>{{ mb_substr($user->name, 0, 2) }}</span>
