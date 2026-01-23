<?php

namespace Rwb\Massops\Import\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Rwb\Massops\Import\ImportError;
use Rwb\Massops\Import\Parser\Csv;
use Rwb\Massops\Import\Parser\Xlsx;
use Rwb\Massops\Import\RowNormalizer;
use Rwb\Massops\Import\ValidationResult;
use Rwb\Massops\Repository\CRM\ARepository;

/**
 * Базовый сервис импорта данных
 *
 * Реализует общий алгоритм импорта:
 * - нормализация строк
 * - валидация
 * - сохранение через репозиторий
 */
abstract class AImport
{
    protected ARepository $repository;
    protected RowNormalizer $normalizer;

    /**
     * @param ARepository $repository Репозиторий CRM-сущности
     * @param RowNormalizer $normalizer Нормализатор строк импорта
     */
    public function __construct(
        ARepository $repository,
        RowNormalizer $normalizer
    ) {
        $this->repository = $repository;
        $this->normalizer = $normalizer;
    }

    /**
     * Выполняет импорт данных
     *
     * @param array $rows Данные для импорта
     *
     * @return array{success: int, errors: array}
     *
     * @throws LoaderException
     * @throws ArgumentException
     */
    public function import(array $rows): array
    {
        $result = $this->processRows($rows, ImportMode::IMPORT);

        return [
            'success' => $result['success'],
            'errors' => $result['errors'],
            'added' => $result['items'],
        ];
    }

    /**
     * Выполняет dry run импорта (симуляция без сохранения)
     *
     * @param array $rows Данные для импорта
     *
     * @return array{success: int, errors: array, wouldBeAdded: array}
     *
     * @throws LoaderException
     * @throws ArgumentException
     */
    public function dryRun(array $rows): array
    {
        $result = $this->processRows($rows, ImportMode::DRY_RUN);

        return [
            'success' => $result['success'],
            'errors' => $result['errors'],
            'wouldBeAdded' => $result['items'],
        ];
    }

    /**
     * Обрабатывает строки импорта: валидация, нормализация и подготовка данных
     *
     * @param array $rows Исходные строки файла
     *
     * @return array{
     *     success: int,
     *     errors: array<int, ImportError[]>,
     *     prepared: array<int, array{row: int, data: array}>
     * }
     *
     * @throws LoaderException
     * @throws ArgumentException
     */
    protected function processRows(array $rows, string $mode): array
    {
        $fieldCodes = array_keys(
            $this->repository->getFieldList()
        );

        $success = 0;
        $errors = [];
        $items = [];

        foreach ($rows as $rowIndex => $row) {
            [$fields, $uf, $fm] = $this->normalizer->normalize(
                array_values($row['data']),
                $fieldCodes
            );

            $validation = $this->validateRow($fields, $uf, $fm);

            if (!$validation->isValid()) {
                foreach ($validation->getErrors() as $error) {
                    $errors[$rowIndex][] = $this->attachRowToError(
                        $error,
                        $rowIndex
                    );
                }
                continue;
            }

            if ($mode === ImportMode::IMPORT) {
                $result = $this->repository->add(
                    $fields,
                    $uf,
                    $fm
                );

                if (!$result->isSuccess()) {
                    foreach ($result->getErrorMessages() as $message) {
                        $errors[$rowIndex][] = new ImportError(
                            type: 'system',
                            code: 'INVALID',
                            message: $message,
                            row: $rowIndex + 1
                        );
                    }
                    continue;
                }
            }

            $success++;
            $items[$rowIndex] = [
                'row' => $rowIndex + 1,
                'data' => $fields,
            ];
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'items' => $items,
        ];
    }

    /**
     * Привязывает номер строки к ошибке импорта
     *
     * @param ImportError $error Объект ошибки
     * @param int $rowIndex      Номер строки файла
     *
     * @return ImportError
     */
    protected function attachRowToError(
        ImportError $error,
        int $rowIndex
    ): ImportError {
        if ($error->row !== null) {
            return $error;
        }

        return new ImportError(
            type: $error->type,
            code: $error->code,
            message: $error->message,
            row: $rowIndex + 1,
            field: $error->field,
            context: $error->context
        );
    }

    /**
     * Парсит файл импорта
     *
     * @param string $path Путь к файлу
     * @param string $ext  Расширение файла
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
                default => throw new InvalidArgumentException('Unsupported format'),
            };

            $data = $this->normalizeUtf8(
                $parser->parse($path)
            );

            return [
                'data' => $data,
                'errors' => $errors,
            ];
        } catch (RuntimeException|InvalidArgumentException $e) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: $e->getMessage()
            );
        } catch (Exception $e) {
            $errors[] = new ImportError(
                type: 'file',
                code: 'FILE_INVALID',
                message: 'Ошибка при чтении файла: ' . $e->getMessage()
            );
        }

        return [
            'data' => [],
            'errors' => $errors,
        ];
    }

    /**
     * Приводит данные к UTF-8
     *
     * @param array $data Исходные данные
     *
     * @return array
     */
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
     * Выполняет валидацию строки импорта
     *
     * Реализуется в конкретных сервисах импорта.
     *
     * @param array $fields Основные поля
     * @param array $uf     Пользовательские поля
     * @param array $fm     Мультиполя
     *
     * @return ValidationResult
     */
    abstract protected function validateRow(
        array $fields,
        array $uf,
        array $fm
    ): ValidationResult;
}
