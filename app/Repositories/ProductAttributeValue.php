<?php
/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 */

declare(strict_types=1);

namespace Pim\Repositories;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Json;
use Espo\ORM\Entity;
use Pim\Core\Exceptions\ProductAttributeAlreadyExists;
use Treo\Core\Utils\Util;

/**
 * Class ProductAttributeValue
 */
class ProductAttributeValue extends AbstractRepository
{
    /**
     * @var array
     */
    protected $ownershipSettings = [
        'assignedUser' => 'assignedUserAttributeOwnership',
        'ownerUser' => 'ownerUserAttributeOwnership',
        'teams' => 'teamsAttributeOwnership'
    ];

    /**
     * @param string $pavId
     * @param string $locale
     *
     * @return array
     */
    public function getLocaleTeamsIds(string $pavId, string $locale): array
    {
        $localeId = $pavId . \Pim\Services\ProductAttributeValue::LOCALE_IN_ID_SEPARATOR . $locale;

        return $this
            ->getEntityManager()
            ->nativeQuery("SELECT team_id FROM entity_team WHERE entity_type='ProductAttributeValue' AND entity_id='$localeId'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @param string $productFamilyAttributeId
     */
    public function removeCollectionByProductFamilyAttribute(string $productFamilyAttributeId)
    {
        $this
            ->where(['productFamilyAttributeId' => $productFamilyAttributeId])
            ->removeCollection(['skipProductAttributeValueHook' => true]);
    }

    /**
     * @param Entity $entity
     * @param array  $options
     *
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options = [])
    {
        parent::beforeSave($entity, $options);

        if (!$this->isValidForSave($entity, $options)) {
            return;
        }

        /**
         * Custom attributes are always required
         */
        if (empty($entity->get('productFamilyAttributeId'))) {
            $entity->set('isRequired', true);
        }

        /**
         * If scope Global then channelId should be empty
         */
        if ($entity->get('scope') == 'Global') {
            $entity->set('channelId', null);
        }

        // get attribute
        $attribute = $entity->get('attribute');

        // get fields
        $fields = $this->getMetadata()->get(['entityDefs', 'ProductAttributeValue', 'fields'], []);

        if ($attribute->get('type') == 'enum' && !empty($attribute->get('isMultilang')) && $entity->isAttributeChanged('value')) {
            // find key
            $key = array_search($entity->get('value'), $attribute->get('typeValue'));

            foreach ($fields as $mField => $mData) {
                if (isset($mData['multilangField']) && $mData['multilangField'] == 'value') {
                    $data = $attribute->get('type' . ucfirst($mField));
                    if (isset($data[$key])) {
                        $entity->set($mField, $data[$key]);
                    } else {
                        $entity->set($mField, $entity->get('value'));
                    }
                }
            }
        }

        if ($attribute->get('type') == 'multiEnum' && !empty($attribute->get('isMultilang')) && $entity->isAttributeChanged('value')) {
            $values = Json::decode($entity->get('value'), true);

            $keys = [];
            foreach ($values as $value) {
                $keys[] = array_search($value, $attribute->get('typeValue'));
            }

            foreach ($fields as $mField => $mData) {
                if (isset($mData['multilangField']) && $mData['multilangField'] == 'value') {
                    $data = $attribute->get('type' . ucfirst($mField));
                    $values = [];
                    foreach ($keys as $key) {
                        $values[] = isset($data[$key]) ? $data[$key] : null;
                    }
                    $entity->set($mField, Json::encode($values));
                }
            }
        }
    }

    /**
     * @param Entity $entity
     * @param array  $options
     */
    public function afterSave(Entity $entity, array $options = array())
    {
        if (!$entity->isNew() && !empty($field = $this->getPreparedInheritedField($entity, 'assignedUser', 'isInheritAssignedUser'))) {
            $this->inheritOwnership($entity, $field, $this->getConfig()->get('assignedUserAttributeOwnership', null));
        }

        if (!$entity->isNew() && !empty($field = $this->getPreparedInheritedField($entity, 'ownerUser', 'isInheritOwnerUser'))) {
            $this->inheritOwnership($entity, $field, $this->getConfig()->get('ownerUserAttributeOwnership', null));
        }

        if (!$entity->isNew() && !empty($field = $this->getPreparedInheritedField($entity, 'teams', 'isInheritTeams'))) {
            $this->inheritOwnership($entity, $field, $this->getConfig()->get('teamsAttributeOwnership', null));
        }

        $this->inheritAfterChangeField($entity);

        // update modifiedAt for product
        $this
            ->getEntityManager()
            ->nativeQuery("UPDATE `product` SET modified_at='{$entity->get('modifiedAt')}' WHERE id='{$entity->get('productId')}'");

        parent::afterSave($entity, $options);
    }

    /**
     * @param string $id
     * @param string $locale
     *
     * @return array
     */
    public function getMultilangAttributeId(string $id, string $locale): array
    {
        $separator = \Pim\Services\ProductAttributeValue::LOCALE_IN_ID_SEPARATOR;

        $sql = "SELECT CONCAT(pav.attribute_id, '{$separator}', '{$locale}') AS id
                FROM product_attribute_value pav
                WHERE pav.id = '{$id}'";

        return $this->getEntityManager()->nativeQuery($sql)->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @param Entity $entity
     * @param string $field
     * @param string $param
     *
     * @return string|null
     */
    protected function getPreparedInheritedField(Entity $entity, string $field, string $param): ?string
    {
        if ($entity->isAttributeChanged($param) && $entity->get($param)) {
            return $field;
        }

        if ($this->getConfig()->get('isMultilangActive', false) && $entity->get('isLocale')) {

            if (isset($entity->locale)) {
                foreach ($this->getConfig()->get('inputLanguageList', []) as $locale) {
                    if ($locale == $entity->locale) {
                        $camelCaseLocale = Util::toCamelCase(strtolower($locale), '_', true);
                        $param .= $camelCaseLocale;

                        if ($entity->isAttributeChanged($param) && $entity->get($param)) {
                            return $field;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    protected function getInheritedEntity(Entity $entity, string $config): ?Entity
    {
        $result = null;

        if ($config == 'fromAttribute') {
            $result = $entity->get('attribute');
        } elseif ($config == 'fromProduct') {
            $result = $entity->get('product');
        }

        return $result;
    }

    /**
     * @param Entity $entity
     *
     * @return Entity|null
     */
    public function findCopy(Entity $entity): ?Entity
    {
        $where = [
            'id!='        => $entity->get('id'),
            'productId'   => $entity->get('productId'),
            'attributeId' => $entity->get('attributeId'),
            'scope'       => $entity->get('scope'),
        ];
        if ($entity->get('scope') == 'Channel') {
            $where['channelId'] = $entity->get('channelId');
        }

        return $this->where($where)->findOne();
    }

    /**
     * @inheritDoc
     */
    protected function init()
    {
        parent::init();

        $this->addDependency('language');
    }

    /**
     * @param Entity $entity
     * @param array  $options
     *
     * @return bool
     * @throws BadRequest
     * @throws ProductAttributeAlreadyExists
     */
    protected function isValidForSave(Entity $entity, array $options): bool
    {
        // exit
        if (!empty($options['skipProductAttributeValueHook'])) {
            return true;
        }

        /**
         * Validation. Product and Attribute can't by empty
         */
        if (empty($entity->get('product')) || empty($entity->get('attribute'))) {
            throw new BadRequest($this->exception('Product and Attribute cannot be empty'));
        }

        /**
         * Validation. ProductFamilyAttribute doesn't changeable
         */
        if (!$entity->isNew() && !empty($entity->get('productFamilyAttributeId')) && empty($entity->skipPfValidation)) {
            if ($entity->isAttributeChanged('scope')
                || $entity->isAttributeChanged('isRequired')
                || ($entity->getFetched('channelId') != $entity->get('channelId'))
                || $entity->isAttributeChanged('attributeId')) {
                throw new BadRequest($this->exception('attributeInheritedFromProductFamilyCannotBeChanged'));
            }
        }
        /**
         * Validation. Is such ProductAttribute exist?
         */
        if (!$this->isUnique($entity)) {
            $channelName = $entity->get('scope');
            if ($channelName == 'Channel') {
                $channelName = !empty($entity->get('channelId')) ? "'" . $entity->get('channel')->get('name') . "'" : '';
            }

            throw new ProductAttributeAlreadyExists(sprintf($this->exception('productAttributeAlreadyExists'), $entity->get('attribute')->get('name'), $channelName));
        }

        /**
         * Validation. Only product channels can be used.
         */
        if ($entity->get('scope') == 'Channel' && empty($entity->skipProductChannelValidation)) {
            $productChannelsIds = array_column($entity->get('product')->get('channels')->toArray(), 'id');
            if (!in_array($entity->get('channelId'), $productChannelsIds)) {
                throw new BadRequest($this->exception('noSuchChannelInProduct'));
            }
        }

        return true;
    }

    /**
     * @param Entity $entity
     *
     * @return bool
     */
    protected function isUnique(Entity $entity): bool
    {
        return empty($this->findCopy($entity));
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function exception(string $key): string
    {
        return $this->getInjection('language')->translate($key, 'exceptions', 'ProductAttributeValue');
    }
}
