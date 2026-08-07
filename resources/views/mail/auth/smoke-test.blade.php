<x-mail.transactional title="Kontrollert test av e-postlevering">
    <h1 style="margin:0 0 16px;font-size:26px;line-height:1.25;">E-postleveringen fungerer</h1>

    <p style="margin:0 0 16px;">
        Dette er den kontrollerte Resend-testsendingen for autentiseringsdelen i Uncovr.
    </p>

    <p style="margin:0 0 8px;color:#52525b;font-size:14px;">
        Test-ID: {{ $runId }}
    </p>
    <p style="margin:0;color:#52525b;font-size:14px;">
        Sendt: {{ $sentAt }}
    </p>
</x-mail.transactional>
