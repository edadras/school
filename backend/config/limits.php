<?php

return [
    // Public school self-registration, per IP per hour (anti-abuse). E2E raises it.
    'register_per_hour' => (int) env('REGISTER_PER_HOUR', 5),
];
