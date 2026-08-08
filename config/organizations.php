<?php

return [
    'invitation_ttl_hours' => max(1, (int) env('ORGANIZATION_INVITATION_TTL_HOURS', 72)),
];
