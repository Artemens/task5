<?php

namespace Only\Site\Handlers;


class Iblock
{
    public static function addLog($arFields)
    {
        // Проверяем, что это не сам инфоблок LOG
        if ($arFields['IBLOCK_ID'] == \Only\Site\Helpers\IBlock::getIblockID('LOGS', 'LOG')) {
            return;
        }

        // Подключаем необходимые модули
        if (!Loader::includeModule('iblock')) {
            return;
        }

        // Получаем ID инфоблока LOG
        $logIblockId = \Only\Site\Helpers\IBlock::getIblockID('LOGS', 'LOG');
        if (!$logIblockId) {
            return;
        }

        // Получаем информацию об инфоблоке, который изменяем
        $iblock = \CIBlock::GetByID($arFields['IBLOCK_ID'])->Fetch();
        if (!$iblock) {
            return;
        }

        // Получаем или создаем раздел для логирования
        $sectionId = $this->getOrCreateLogSection($logIblockId, $iblock);

        // Получаем информацию об элементе
        $element = \CIBlockElement::GetByID($arFields['ID'])->Fetch();
        if (!$element) {
            return;
        }

        // Получаем полный путь разделов элемента
        $sectionPath = $this->getElementSectionPath($element['IBLOCK_SECTION_ID'], $arFields['IBLOCK_ID']);

        // Формируем данные для нового элемента лога
        $el = new \CIBlockElement;

        $logFields = [
            'IBLOCK_ID' => $logIblockId,
            'IBLOCK_SECTION_ID' => $sectionId,
            'NAME' => $arFields['ID'],
            'ACTIVE_FROM' => $element['TIMESTAMP_X'] ?: $element['DATE_CREATE'],
            'PREVIEW_TEXT' => $iblock['NAME'] . ' -> ' . $sectionPath . ' -> ' . $element['NAME'],
            'PROPERTY_VALUES' => [
                'ELEMENT_ID' => $arFields['ID'],
                'IBLOCK_ID' => $arFields['IBLOCK_ID'],
                'OPERATION_TYPE' => isset($arFields['RESULT']) ? 'UPDATE' : 'ADD',
                'USER_ID' => $GLOBALS['USER']->GetID(),
            ],
        ];

        // Добавляем элемент в лог
        $el->Add($logFields);
    }

    /**
     * Получает или создает раздел в инфоблоке логов
     */
    private function getOrCreateLogSection($logIblockId, $iblock)
    {
        $section = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $logIblockId, 'CODE' => $iblock['ID']],
            false,
            ['ID']
        )->Fetch();

        if ($section) {
            return $section['ID'];
        }

        // Создаем новый раздел
        $bs = new \CIBlockSection;
        $sectionFields = [
            'IBLOCK_ID' => $logIblockId,
            'NAME' => $iblock['NAME'],
            'CODE' => $iblock['ID'],
            'ACTIVE' => 'Y',
        ];

        return $bs->Add($sectionFields);
    }

    /**
     * Получает полный путь разделов элемента
     */
    private function getElementSectionPath($sectionId, $iblockId)
    {
        if (!$sectionId) {
            return '';
        }

        $path = [];
        $nav = \CIBlockSection::GetNavChain($iblockId, $sectionId);
        while ($section = $nav->Fetch()) {
            $path[] = $section['NAME'];
        }

        return implode(' -> ', $path);
    }

    function OnBeforeIBlockElementAddHandler(&$arFields)
    {
        $iQuality = 95;
        $iWidth = 1000;
        $iHeight = 1000;
        /*
         * Получаем пользовательские свойства
         */
        $dbIblockProps = \Bitrix\Iblock\PropertyTable::getList(array(
            'select' => array('*'),
            'filter' => array('IBLOCK_ID' => $arFields['IBLOCK_ID'])
        ));
        /*
         * Выбираем только свойства типа ФАЙЛ (F)
         */
        $arUserFields = [];
        while ($arIblockProps = $dbIblockProps->Fetch()) {
            if ($arIblockProps['PROPERTY_TYPE'] == 'F') {
                $arUserFields[] = $arIblockProps['ID'];
            }
        }
        /*
         * Перебираем и масштабируем изображения
         */
        foreach ($arUserFields as $iFieldId) {
            foreach ($arFields['PROPERTY_VALUES'][$iFieldId] as &$file) {
                if (!empty($file['VALUE']['tmp_name'])) {
                    $sTempName = $file['VALUE']['tmp_name'] . '_temp';
                    $res = \CAllFile::ResizeImageFile(
                        $file['VALUE']['tmp_name'],
                        $sTempName,
                        array("width" => $iWidth, "height" => $iHeight),
                        BX_RESIZE_IMAGE_PROPORTIONAL_ALT,
                        false,
                        $iQuality);
                    if ($res) {
                        rename($sTempName, $file['VALUE']['tmp_name']);
                    }
                }
            }
        }

        if ($arFields['CODE'] == 'brochures') {
            $RU_IBLOCK_ID = \Only\Site\Helpers\IBlock::getIblockID('DOCUMENTS', 'CONTENT_RU');
            $EN_IBLOCK_ID = \Only\Site\Helpers\IBlock::getIblockID('DOCUMENTS', 'CONTENT_EN');
            if ($arFields['IBLOCK_ID'] == $RU_IBLOCK_ID || $arFields['IBLOCK_ID'] == $EN_IBLOCK_ID) {
                \CModule::IncludeModule('iblock');
                $arFiles = [];
                foreach ($arFields['PROPERTY_VALUES'] as $id => &$arValues) {
                    $arProp = \CIBlockProperty::GetByID($id, $arFields['IBLOCK_ID'])->Fetch();
                    if ($arProp['PROPERTY_TYPE'] == 'F' && $arProp['CODE'] == 'FILE') {
                        $key_index = 0;
                        while (isset($arValues['n' . $key_index])) {
                            $arFiles[] = $arValues['n' . $key_index++];
                        }
                    } elseif ($arProp['PROPERTY_TYPE'] == 'L' && $arProp['CODE'] == 'OTHER_LANG' && $arValues[0]['VALUE']) {
                        $arValues[0]['VALUE'] = null;
                        if (!empty($arFiles)) {
                            $OTHER_IBLOCK_ID = $RU_IBLOCK_ID == $arFields['IBLOCK_ID'] ? $EN_IBLOCK_ID : $RU_IBLOCK_ID;
                            $arOtherElement = \CIBlockElement::GetList([],
                                [
                                    'IBLOCK_ID' => $OTHER_IBLOCK_ID,
                                    'CODE' => $arFields['CODE']
                                ], false, false, ['ID'])
                                ->Fetch();
                            if ($arOtherElement) {
                                /** @noinspection PhpDynamicAsStaticMethodCallInspection */
                                \CIBlockElement::SetPropertyValues($arOtherElement['ID'], $OTHER_IBLOCK_ID, $arFiles, 'FILE');
                            }
                        }
                    } elseif ($arProp['PROPERTY_TYPE'] == 'E') {
                        $elementIds = [];
                        foreach ($arValues as &$arValue) {
                            if ($arValue['VALUE']) {
                                $elementIds[] = $arValue['VALUE'];
                                $arValue['VALUE'] = null;
                            }
                        }
                        if (!empty($arFiles && !empty($elementIds))) {
                            $rsElement = \CIBlockElement::GetList([],
                                [
                                    'IBLOCK_ID' => \Only\Site\Helpers\IBlock::getIblockID('PRODUCTS', 'CATALOG_' . $RU_IBLOCK_ID == $arFields['IBLOCK_ID'] ? '_RU' : '_EN'),
                                    'ID' => $elementIds
                                ], false, false, ['ID', 'IBLOCK_ID', 'NAME']);
                            while ($arElement = $rsElement->Fetch()) {
                                /** @noinspection PhpDynamicAsStaticMethodCallInspection */
                                \CIBlockElement::SetPropertyValues($arElement['ID'], $arElement['IBLOCK_ID'], $arFiles, 'FILE');
                            }
                        }
                    }
                }
            }
        }
    }

}