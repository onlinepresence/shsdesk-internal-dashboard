<p>Hello {{ $staff->name }},</p>

<p>A ControlDesk staff account was created for you. Sign in here:</p>

<p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>

<ul>
    <li>Email: {{ $staff->email }}</li>
    <li>Password: {{ $plainPassword }}</li>
</ul>

<p>You will be asked to change this password on first login — it is shown once and never stored.</p>

<p>— ControlDesk</p>
