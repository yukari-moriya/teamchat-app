@extends('layouts.app')

@section('title', 'チャンネルを編集する')

@section('page_class', 'page--narrow')

@section('content')
    <h1 class="h-title" style="margin-bottom:var(--sp-6)">チャンネルを編集する</h1>
    <div class="card" style="margin-bottom:var(--sp-8)">
        <div class="card__body">
            <form method="POST" action="{{ route('channels.update', $channel) }}" novalidate>
                @csrf
                @method('PUT')
                @include('channels._fields', [
                    'channel' => $channel,
                    'nameHelp' => ($channel->is_private ? 'プライベートチャンネルです。' : '公開チャンネルです。').'公開範囲はあとから変更できません。',
                    'descriptionHelp' => '',
                ])
                <div class="inline-2" style="justify-content:flex-end;margin-top:var(--sp-6)">
                    <a class="btn" href="{{ route('channels.show', $channel) }}">キャンセル</a>
                    <button type="submit" class="btn btn--primary">保存する</button>
                </div>
            </form>
        </div>
    </div>

    @can('delete', $channel)
        <h2 class="h-heading" style="margin-bottom:var(--sp-3)">チャンネルを削除する</h2>
        <div class="danger-box">
            <div class="spread">
                <div>
                    <div class="t-label" style="color:var(--danger)">このチャンネルを削除します</div>
                    <div class="t-caption">メッセージと返信もすべて削除され、元に戻せません。</div>
                </div>
                <a class="btn btn--danger" href="{{ route('channels.edit', ['channel' => $channel, 'confirm_delete' => 1]) }}">削除する</a>
            </div>
            @if ($confirmDelete)
                <div class="card" style="border-color:var(--danger-line);margin-top:var(--sp-4)">
                    <div class="card__body">
                        <div class="h-heading" style="margin-bottom:var(--sp-2)">「{{ $channel->name }}」を削除しますか？</div>
                        <p class="t-body t-muted" style="margin-bottom:var(--sp-4)">メッセージ {{ $channel->message_count }}件と返信 {{ $channel->reply_count }}件も削除されます。この操作は取り消せません。確認のためチャンネル名を入力してください。</p>
                        <form method="POST" action="{{ route('channels.destroy', $channel) }}" novalidate>
                            @csrf
                            @method('DELETE')
                            <div class="field @error('confirm_name') is-error @enderror" style="margin-bottom:0">
                                <input class="input" name="confirm_name" type="text" value="{{ old('confirm_name') }}" placeholder="{{ $channel->name }}" aria-label="チャンネル名の確認" style="max-width:280px">
                                @error('confirm_name')<span class="err">{{ $message }}</span>@enderror
                            </div>
                            <div class="inline-2" style="justify-content:flex-end;margin-top:var(--sp-5)">
                                <a class="btn" href="{{ route('channels.edit', $channel) }}">やめる</a>
                                <button type="submit" class="btn btn--danger-solid">削除する</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    @endcan
@endsection
