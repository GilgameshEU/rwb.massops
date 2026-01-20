<?php

namespace Rwb\Massops\Import;

class RowNormalizer
{
    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    /**
     * @return array [$fields, $uf, $fm]
     */
    public function normalize(array $row, array $fieldCodes): array
    {
        $fields = [];
        $uf = [];
        $fm = [];

        $multiFields = $this->config->getMultiFields();

        foreach ($fieldCodes as $index => $code) {
            $value = trim((string) ($row[$index] ?? ''));

            if ($value === '') {
                continue;
            }

            // мультиполя (PHONE / EMAIL)
            if (isset($multiFields[$code])) {
                $fm[$code] = $this->normalizeMultiField(
                    $value,
                    $multiFields[$code]['delimiter']
                );
                continue;
            }

            // пользовательские поля
            if (str_starts_with($code, 'UF_')) {
                $uf[$code] = $value;
                continue;
            }

            // обычные поля
            $fields[$code] = $value;
        }

        return [$fields, $uf, $fm];
    }

    private function normalizeMultiField(
        string $value,
        string $delimiter
    ): array {
        $items = array_map(
            'trim',
            explode($delimiter, $value)
        );

        $result = [];

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            $result[] = [
                'VALUE' => $item,
                'VALUE_TYPE' => 'WORK',
            ];
        }

        return $result;
    }
}
