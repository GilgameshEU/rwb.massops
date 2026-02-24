<?php

namespace Rwb\Massops\Service;

use Bitrix\Main\Loader;
use Rwb\Massops\Repository\CrmRepository;
use Rwb\Massops\Support\UserFieldHelper;

/**
 * Генератор CID компании
 *
 * CID = "{ИНН}-{KOD_ALFA_2}", где:
 * - ИНН берётся из UF-поля с XML_ID=INN
 * - KOD_ALFA_2 берётся из свойства инфоблока элемента, на который
 *   ссылается UF-поле с XML_ID=COUNTRY
 *
 * Пример: 7707083893-RU
 *
 * Перед генерацией необходимо вызвать validate() и убедиться,
 * что он вернул null (конфигурация корректна).
 */
class CidGenerator
{
    private ?string $cidFieldCode = null;
    private ?string $innFieldCode = null;
    private ?string $countryFieldCode = null;
    private ?int $countryIblockId = null;
    private bool $validated = false;
    private ?string $validationError = null;

    public function __construct(private CrmRepository $repository)
    {
    }

    /**
     * Проверяет наличие всех необходимых полей и свойств CRM.
     *
     * @return string|null null — конфигурация корректна; строка — описание проблемы
     */
    public function validate(): ?string
    {
        if ($this->validated) {
            return $this->validationError;
        }

        $this->validated = true;

        Loader::requireModule('iblock');

        $this->cidFieldCode = UserFieldHelper::getFieldCodeByXmlId('CRM_COMPANY', 'CID');
        if (!$this->cidFieldCode) {
            $this->validationError = 'Не настроено пользовательское поле CID (XML_ID=CID) в компаниях CRM';
            return $this->validationError;
        }

        $this->innFieldCode = UserFieldHelper::getFieldCodeByXmlId('CRM_COMPANY', 'INN');
        if (!$this->innFieldCode) {
            $this->validationError = 'Не настроено пользовательское поле ИНН (XML_ID=INN) в компаниях CRM';
            return $this->validationError;
        }

        $this->countryFieldCode = UserFieldHelper::getFieldCodeByXmlId('CRM_COMPANY', 'COUNTRY');
        if (!$this->countryFieldCode) {
            $this->validationError = 'Не настроено пользовательское поле страны (XML_ID=COUNTRY) в компаниях CRM';
            return $this->validationError;
        }

        $ufSettings = $this->repository->getUfFieldsSettings();
        $countrySettings = $ufSettings[$this->countryFieldCode] ?? [];
        $this->countryIblockId = (int) ($countrySettings['IBLOCK_ID'] ?? 0);
        if ($this->countryIblockId <= 0) {
            $this->validationError = 'Поле страны (XML_ID=COUNTRY) не привязано к инфоблоку';
            return $this->validationError;
        }

        if (!$this->iblockHasProperty($this->countryIblockId, 'KOD_ALFA_2')) {
            $this->validationError = 'В инфоблоке стран не найдено свойство с кодом KOD_ALFA_2';
            return $this->validationError;
        }

        return null;
    }

    /**
     * Генерирует CID, сохраняет его в поле компании и возвращает сгенерированное значение.
     *
     * Вызывать только после успешного validate().
     * Если ИНН или страна не заполнены — возвращает null.
     *
     * @param int   $companyId ID созданной компании
     * @param array $uf        Нормализованные UF-поля строки
     *
     * @return string|null Сгенерированный CID или null если данных недостаточно
     */
    public function generateForCompany(int $companyId, array $uf): ?string
    {
        $inn = trim((string) ($uf[$this->innFieldCode] ?? ''));
        $countryElementId = (int) ($uf[$this->countryFieldCode] ?? 0);

        if ($inn === '' || $countryElementId <= 0) {
            return null;
        }

        $alfa2 = $this->getKodAlfa2($countryElementId);
        if ($alfa2 === null) {
            return null;
        }

        $cid = $inn . '-' . $alfa2;

        Loader::requireModule('crm');
        $company = new \CCrmCompany(false);
        // CCrmCompany::Update принимает $arFields по ссылке — переменная обязательна
        $updateFields = [$this->cidFieldCode => $cid];
        $company->Update($companyId, $updateFields);

        return $cid;
    }

    /**
     * Проверяет наличие свойства с заданным кодом в инфоблоке
     */
    private function iblockHasProperty(int $iblockId, string $code): bool
    {
        $result = \CIBlockProperty::getList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code, 'ACTIVE' => 'Y']
        );
        return (bool) $result->fetch();
    }

    /**
     * Возвращает значение свойства KOD_ALFA_2 элемента инфоблока
     */
    private function getKodAlfa2(int $elementId): ?string
    {
        $res = \CIBlockElement::getList(
            [],
            ['ID' => $elementId, 'IBLOCK_ID' => $this->countryIblockId, 'ACTIVE' => 'Y'],
            false,
            ['nTopCount' => 1],
            ['ID', 'PROPERTY_KOD_ALFA_2']
        );

        $element = $res->fetch();
        if (!$element) {
            return null;
        }

        $value = trim((string) ($element['PROPERTY_KOD_ALFA_2_VALUE'] ?? ''));
        return $value !== '' ? $value : null;
    }
}
