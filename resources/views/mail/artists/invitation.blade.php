<x-mail.transactional title="Invitasjon til {{ $artistName }}">
    <h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;">Du er invitert til {{ $artistName }}</h1>
    <p style="margin:0 0 24px;">{{ $inviterName ?: 'En administrator' }} har invitert deg som administrator for artisten i Uncovr.</p>
    <p style="margin:0 0 24px;"><a href="{{ $acceptUrl }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#18181b;color:#fff;text-decoration:none;font-weight:700;">Godta invitasjonen</a></p>
    <p style="margin:0;color:#52525b;font-size:14px;">Lenken utløper {{ $expiresAt->utc()->format('d.m.Y H:i') }} UTC og kan bare brukes én gang.</p>
</x-mail.transactional>
