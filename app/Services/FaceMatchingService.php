<?php
// sentinel-back/app/Services/FaceMatchingService.php
namespace App\Services;

use InvalidArgumentException;

class FaceMatchingService
{
    private const EMBEDDING_SIZE = 128;

    public function euclideanDistance(array $a, array $b): float
    {
        if (count($a) !== self::EMBEDDING_SIZE || count($b) !== self::EMBEDDING_SIZE) {
            throw new InvalidArgumentException('Ambos embeddings deben tener exactamente 128 dimensiones');
        }

        $sumSquares = 0.0;
        foreach (array_values($a) as $i => $valueA) {
            $diff = $valueA - $b[$i];
            $sumSquares += $diff * $diff;
        }

        return sqrt($sumSquares);
    }

    public function isMatch(float $distance): bool
    {
        return $distance <= (float) config('biometrics.match_threshold');
    }
}
