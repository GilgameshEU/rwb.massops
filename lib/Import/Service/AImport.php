<?php


namespace Rwb\Massops\Import\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use RuntimeException;
use Rwb\Massops\Import\FieldValidator;
use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\Parser\Csv;
use Rwb\Massops\Import\Parser\Xlsx;
use Rwb\Massops\Import\RowNormalizer;
use Rwb\Massops\Import\ValidationResult;
use Rwb\Massops\Repository\CRM\ARepository;

abstract class AImport
{
    protected ARepository $repository;
    protected RowNormalizer $normalizer;

    public function __construct(
        ARepository $repository,
        RowNormalizer $normalizer
    ) {
        $this->repository = $repository;
        $this->normalizer = $normalizer;
    }

    /**
     * Основной импорт
     *
     * @throws LoaderException|ArgumentException
     */
    public function import(array $rows): array
    {
        $fieldCodes = array_keys(
            $this->repository->getFieldList()
        );

        $success = 0;
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            [$fields, $uf, $fm] = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes
            );

            $validation = $this->validateRow($fields, $uf, $fm);

            if (!$validation->isValid()) {
                $rowErrors = $validation->getErrors();
                // Устанавливаем номер строки для всех ошибок валидации
                foreach ($rowErrors as $error) {
                    if ($error->row === null) {
                        $errors[$rowIndex][] = new ImportError(
                            type: $error->type,
                            code: $error->code,
                            message: $error->message,
                            row: $rowIndex + 1, // +1 потому что обычно строки считаются с 1
                            field: $error->field,
                            context: $error->context
                        );
                    } else {
                        $errors[$rowIndex][] = $error;
                    }
                }
                continue;
            }

            $result = $this->repository->add(
                $fields,
                $uf,
                $fm
            );

            if ($result->isSuccess()) {
                $success++;
            } else {
                // Конвертируем ошибки Bitrix Result в ImportError
                $rowErrors = [];
                foreach ($result->getErrorMessages() as $message) {
                    $rowErrors[] = new ImportError(
                        type: 'system',
                        code: 'INVALID',
                        message: $message,
                        row: $rowIndex + 1
                    );
                }
                $errors[$rowIndex] = $rowErrors;
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
        ];
    }

    /**
     * Парсинг файла
     *
     * @return array{data: array, errors: ImportError[]}
     */
    public function parseFile(string $path, string $ext): array
    {
        $errors = [];

        try {
            $parser = match ($ext) {
                'csv' => new Csv(),
                'xlsx' => new Xlsx(),
                default => throw new \InvalidArgumentException('Unsupported format'),
            };

            $data = $this->normalizeUtf8(
                $parser->parse($path)
            );

            return [
                'data' => $data,
                'errors' => $errors,
            ];
        } catch (\RuntimeException $e) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: $e->getMessage()
            );
            return [
                'data' => [],
                'errors' => $errors,
            ];
        } catch (\InvalidArgumentException $e) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: $e->getMessage()
            );
            return [
                'data' => [],
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            // Обрабатываем любые другие исключения от парсеров (например, PhpOffice)
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: 'Ошибка при чтении файла: ' . $e->getMessage()
            );
            return [
                'data' => [],
                'errors' => $errors,
            ];
        }
    }

    private function normalizeUtf8(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (!is_string($value)) {
                return;
            }

            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding(
                    $value,
                    'UTF-8',
                    ['Windows-1251', 'ISO-8859-1']
                );
            }
        });

        return $data;
    }

    /**
     * Проверка строки (переопределяется в наследниках)
     */
    abstract protected function validateRow(
        array $fields,
        array $uf,
        array $fm
    ): ValidationResult;
}
