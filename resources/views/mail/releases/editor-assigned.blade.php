<x-mail.transactional title="Du kan nå redigere {{ $releaseTitle }}">
    <h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;">Du kan nå redigere {{ $releaseTitle }}</h1>
    <p style="margin:0 0 24px;">{{ $assignedByName ?: 'En administrator' }} har gitt deg tilgang til å redigere utgivelsen «{{ $releaseTitle }}» for {{ $ownerName }} i Uncovr.</p>
    <p style="margin:0 0 24px;"><a href="{{ $builderUrl }}" style="display:inline-block;padding:13px 20px;border-radius:8px;background:#18181b;color:#fff;text-decoration:none;font-weight:700;">Åpne utgivelsen</a></p>
    <p style="margin:0;color:#52525b;font-size:14px;">Du kan bygge sider og innholdsblokker, og sende utgivelsen til godkjenning når den er klar.</p>
</x-mail.transactional>
