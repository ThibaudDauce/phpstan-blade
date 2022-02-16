@php 
use App\Models\User;
@endphp

{{ User::HELLO_WORLD }}
{{ User::HELLO_WORL }}

Some HTML lines

<a href="{{ $user->di }}">{{ $user->oups }}</a>

Other stuff?

@include('addition', [
    'a' => $user->id,
    'b' => 'string',
])