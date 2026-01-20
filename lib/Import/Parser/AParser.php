<?php

namespace Rwb\Massops\Import\Parser;

use Bitrix\Crm\Communication\Normalizer;
use Rwb\Massops\Import\MultiValue;

abstract class AParser implements IParser
{
    protected function splitMulti(string $value): array
    {
        return preg_split('/[;,\\n]+/u', $value) ?: [];
    }

    protected function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        return Normalizer::normalizePhone($phone);
    }

    /**
     * @return MultiValue[]
     */
    public function parseMultiField(string $value, string $field): array
    {
        $result = [];

        foreach ($this->splitMulti($value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if ($field === 'PHONE') {
                $part = $this->normalizePhone($part);
                if (!$part) {
                    continue;
                }
            }

            $result[] = new MultiValue($part);
        }

        return $result;
    }
}
