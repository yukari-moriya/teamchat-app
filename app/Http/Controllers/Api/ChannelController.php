<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 公開API（F-23）。呼んだのが誰かは見ず、返す範囲を公開チャンネルに絞る（docs/design/permissions-api.md 1-4） */
class ChannelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ChannelResource::collection(Channel::public()->inListOrder()->with('creator')->get());
    }
}
