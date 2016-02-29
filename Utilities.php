<?php

class Utilities
{
    /**
     * @param mixed $amount
     * @param string $currency
     * @return integer
     */
    public static function toMinorUnits($amount, $currency)
    {
        return self::roundToInt($amount * self::getMinorUnitsFactor($currency));
    }

    /**
     * @param integer $amountInMinorUnits
     * @param string $currency
     * @return float
     */
    public static function fromMinorUnits($amountInMinorUnits, $currency)
    {
        return (float) ($amountInMinorUnits / self::getMinorUnitsFactor($currency));
    }

    /**
     * @param string $currency
     * @return float
     */
    protected static function getMinorUnitsFactor($currency)
    {
        $minorUnitsLookup = array(
            'BHD' => 3, 'BYR' => 0, 'BIF' => 0, 'CLF' => 0, 'CLP' => 0, 'KMF' => 0, 'DJF' => 0, 'XAF' => 0, 'GNF' => 0,
            'ISK' => 0, 'IQD' => 3, 'JPY' => 0, 'JOD' => 3, 'KRW' => 0, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'PYG' => 0,
            'RWF' => 0, 'XOF' => 0, 'TND' => 3, 'UYI' => 0, 'VUV' => 0, 'VND' => 0, 'XPF' => 0
        );

        $minorUnits = isset($minorUnitsLookup[$currency]) ? $minorUnitsLookup[$currency] : 2;

        return bcpow("10", "$minorUnits");
    }

    /**
     * @param mixed $value
     * @return integer
     */
    protected static function roundToInt($value)
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

}
