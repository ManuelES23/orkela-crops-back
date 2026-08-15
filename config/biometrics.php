<?php
// sentinel-back/config/biometrics.php
return [
    // Distancia euclidiana máxima entre embeddings para considerar match (128-dim, @vladmandic/face-api)
    'match_threshold' => (float) env('BIOMETRICS_MATCH_THRESHOLD', 0.5),

    // Minutos de desfase entre el reloj del dispositivo y el servidor antes de marcar para revisión
    'clock_skew_tolerance_minutes' => (int) env('BIOMETRICS_CLOCK_SKEW_TOLERANCE_MINUTES', 10),

    // Ventana en minutos para considerar dos chequeos del mismo empleado+tipo como duplicado
    'duplicate_collapse_minutes' => (int) env('BIOMETRICS_DUPLICATE_COLLAPSE_MINUTES', 5),
];
