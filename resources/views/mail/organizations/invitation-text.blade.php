Du er invitert til {{ $organizationName }}

{{ $inviterName ?: 'En administrator' }} har invitert deg til organisasjonen i Uncovr.

Godta invitasjonen: {{ $acceptUrl }}

Lenken utløper {{ $expiresAt->utc()->format('d.m.Y H:i') }} UTC og kan bare brukes én gang.
