<x-mail.transactional title="Tilbakestill passordet ditt">
    <h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;">Tilbakestill passordet ditt</h1>

    <p style="margin:0 0 16px;">Hei{{ $displayName ? ' '.$displayName : '' }},</p>

    <p style="margin:0 0 24px;">
        Vi mottok en forespørsel om å tilbakestille passordet til Uncovr-kontoen din.
        Lenken er gyldig i {{ $expiresInMinutes }} minutter og kan bare brukes én gang.
    </p>

    <p style="margin:0 0 24px;">
        <a href="{{ $resetUrl }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#18181b;color:#ffffff;text-decoration:none;font-weight:700;">
            Velg nytt passord
        </a>
    </p>

    <p style="margin:0 0 8px;color:#52525b;font-size:14px;">
        Hvis knappen ikke virker, kopier denne adressen inn i nettleseren:
    </p>
    <p style="margin:0;overflow-wrap:anywhere;font-size:13px;">
        <a href="{{ $resetUrl }}" style="color:#3f3f46;">{{ $resetUrl }}</a>
    </p>

    <p style="margin:24px 0 0;color:#52525b;font-size:14px;">
        Hvis du ikke ba om nytt passord, kan du se bort fra denne e-posten.
    </p>
</x-mail.transactional>
