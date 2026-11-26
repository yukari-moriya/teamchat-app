{{--
    チャンネル名と説明の欄（作成と編集で共通）。
    $channel は編集のときの対象（作成のときは null）。$nameHelp・$descriptionHelp は欄の下の補足。エラーがあるときは、補足の場所にエラーを出す
--}}
<div class="field @error('name') is-error @enderror">
    <label class="label" for="name">チャンネル名<span class="req">*</span></label>
    <input class="input" id="name" name="name" type="text" value="{{ old('name', $channel?->name) }}">
    <div class="field-foot">
        @error('name')<span class="err">{{ $message }}</span>@else<span class="help">{{ $nameHelp }}</span>@enderror
        <span class="count">50文字まで</span>
    </div>
</div>
<div class="field @error('description') is-error @enderror">
    <label class="label" for="description">説明</label>
    <textarea class="textarea" id="description" name="description">{{ old('description', $channel?->description) }}</textarea>
    <div class="field-foot">
        @error('description')<span class="err">{{ $message }}</span>@else<span class="help">{{ $descriptionHelp }}</span>@enderror
        <span class="count">200文字まで</span>
    </div>
</div>
