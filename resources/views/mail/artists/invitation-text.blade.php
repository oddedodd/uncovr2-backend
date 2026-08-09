Du er invitert til {{ $artistName }}

{{ $inviterName ?: 'En administrator' }} har invitert deg som administrator for artisten i Uncovr.

Godta invitasjonen: {{ $acceptUrl }}

Lenken utløper {{ $expiresAt->utc()->format('d.m.Y H:i') }} UTC og kan bare brukes én gang.
