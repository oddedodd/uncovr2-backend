<x-mail.transactional title="Bekreft e-postadressen din">
    <h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;">Bekreft e-postadressen din</h1>

    <p style="margin:0 0 16px;">Hei{{ $displayName ? ' '.$displayName : '' }},</p>

    <p style="margin:0 0 24px;">
        Bekreft e-postadressen din for å aktivere Uncovr-kontoen. Lenken er gyldig i
        {{ $expiresInMinutes }} minutter.
    </p>

    <p style="margin:0 0 24px;">
        <a href="{{ $verificationUrl }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#18181b;color:#ffffff;text-decoration:none;font-weight:700;">
            Bekreft e-postadresse
        </a>
    </p>

    <p style="margin:0 0 8px;color:#52525b;font-size:14px;">
        Hvis knappen ikke virker, kopier denne adressen inn i nettleseren:
    </p>
    <p style="margin:0;overflow-wrap:anywhere;font-size:13px;">
        <a href="{{ $verificationUrl }}" style="color:#3f3f46;">{{ $verificationUrl }}</a>
    </p>

    <p style="margin:24px 0 0;color:#52525b;font-size:14px;">
        Hvis du ikke opprettet kontoen, kan du se bort fra denne e-posten.
    </p>
</x-mail.transactional>
