<?php

namespace App\Services;

class SsccGeneratorService
{
    public function calculateCheckDigit(string $number17): int
    {
        if (! preg_match('/^\d{17}$/', $number17)) {
            throw new \InvalidArgumentException('number17 debe contener exactamente 17 digitos.');
        }

        $sum = 0;
        $multiplier = 3;

        for ($index = strlen($number17) - 1; $index >= 0; $index--) {
            $sum += ((int) $number17[$index]) * $multiplier;
            $multiplier = $multiplier === 3 ? 1 : 3;
        }

        $remainder = $sum % 10;

        return (10 - $remainder) % 10;
    }

    public function generate(string $companyPrefix, string $extensionDigit, int|string $serialReference): string
    {
        $prefix = trim($companyPrefix);
        $extension = trim($extensionDigit);
        $serial = trim((string) $serialReference);

        if (! preg_match('/^\d+$/', $prefix)) {
            throw new \InvalidArgumentException('companyPrefix debe contener solo digitos.');
        }

        if (! preg_match('/^\d$/', $extension)) {
            throw new \InvalidArgumentException('extensionDigit debe contener exactamente un digito.');
        }

        if ($serial !== '' && ! preg_match('/^\d+$/', $serial)) {
            throw new \InvalidArgumentException('serialReference debe contener solo digitos.');
        }

        $serialLength = 17 - strlen($extension) - strlen($prefix);
        if ($serialLength <= 0) {
            throw new \InvalidArgumentException('No hay espacio para la referencia serial con ese prefijo.');
        }

        if (strlen($serial) > $serialLength) {
            throw new \InvalidArgumentException("serialReference no puede exceder {$serialLength} digitos.");
        }

        $paddedSerial = str_pad($serial, $serialLength, '0', STR_PAD_LEFT);
        $number17 = $extension . $prefix . $paddedSerial;
        $checkDigit = $this->calculateCheckDigit($number17);

        return $number17 . (string) $checkDigit;
    }
}
