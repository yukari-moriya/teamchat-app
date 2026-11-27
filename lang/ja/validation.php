<?php

// 使っているのは、ログインの入力チェック（Fortify のもの）の文言。ほかの画面の文言は、それぞれの入力チェックのところで指定している。
// required は入力が空のとき（screens.md 4-2 の SC-01）。
// string は画面を通さずに配列を送ったときで、ほかの画面と同じく、空のときと同じ文言にする。
return [
    'required' => ':attributeを入力してください',
    'string' => ':attributeを入力してください',

    'attributes' => [
        'email' => 'メールアドレス',
        'password' => 'パスワード',
    ],
];
