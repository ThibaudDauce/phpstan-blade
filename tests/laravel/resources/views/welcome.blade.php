<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Document</title>
</head>
<body>
    {{ $user->email }}
    Errors on the same line {{ $user->nam }} with stuff {{ $user->emai }} around.

    {{ $invoice }}

    @if (isset($invoice))
        {{ $invoice }}
    @endif

    {{ $controller->test() }}

    {{ $controller->not_existing }}

    @datetime($user->email)

    @datetime(now())

    @foreach ($users as $user)
        {{ $user->email }}

        @foreach (['one', 'two', 'three'] as $text)
            {{ $user->email }} {{ $text }}
        @endforeach

        {{ $user->email }}

        @include('user')

        @foreach ($user->email as $oups)
            {{ $oups }}
        @endforeach
    @endforeach

    @php
        /** @var string */
        $value = config('app.name');
        $value_without_doc_block = config('app.name');

        $constant_string = 'Hi!';
        $constant_int = 42;
    @endphp

    {{ $value }}
    {{ $value_without_doc_block }}

    {{ $constant_string }}
    {{ $constant_int }}
    {{ $constant_int + $constant_string }}

    @include('addition', [
        'a' => $constant_int,
        'b' => $constant_int,
    ])

    @include(
        'addition',
        [
            'a' => $constant_string,
            'b' => $constant_int,
        ]
    )
</body>
</html>