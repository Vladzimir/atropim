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

namespace Pim\Listeners;

use Espo\Core\Utils\Json;
use Pim\Entities\Channel;
use Pim\Repositories\AbstractRepository;
use Treo\Core\Utils\Util;
use Treo\Listeners\AbstractListener;
use Treo\Core\EventManager\Event;

/**
 * Class SettingsController
 */
class SettingsController extends AbstractListener
{
    protected $removeFields = [
        'assignedUserAttributeOwnership' => 'overrideAttributeAssignedUser',
        'ownerUserAttributeOwnership' => 'overrideAttributeOwnerUser',
        'teamsAttributeOwnership' => 'overrideAttributeTeams',
        'assignedUserProductOwnership' => 'overrideProductAssignedUser',
        'ownerUserProductOwnership' => 'overrideProductOwnerUser',
        'teamsProductOwnership' => 'overrideProductTeams'
    ];

    /**
     * @param Event $event
     */
    public function beforeActionUpdate(Event $event): void
    {
        // open session
        session_start();

        // set to session
        $_SESSION['isMultilangActive'] = $this->getConfig()->get('isMultilangActive', false);
        $_SESSION['inputLanguageList'] = $this->getConfig()->get('inputLanguageList', []);
    }

    /**
     * @param Event $event
     */
    public function afterActionUpdate(Event $event): void
    {
        $this->updateChannelsLocales();

        // cleanup
        unset($_SESSION['isMultilangActive']);
        unset($_SESSION['inputLanguageList']);

        $data = Json::decode(Json::encode($event->getArgument('data')), true);

        $qm = false;
        foreach (array_keys($this->removeFields) as $key) {
            if (isset($data[$key]) && $data[$key] != 'notInherit') {
                $qm = true;
                break;
            }
        }

        if ($qm) {
            $this
                ->getContainer()
                ->get('queueManager')
                ->push($this->getLanguage()->translate('updatingOwnershipInformation', 'queueManager', 'Settings'), 'QueueManagerOwnership', $data, 1);
        }

        $this->removeConfigFields();
    }

    /**
     * Update Channel locales field
     */
    protected function updateChannelsLocales(): void
    {
        if (!$this->getConfig()->get('isMultilangActive', false)) {
            $this->getEntityManager()->nativeQuery("UPDATE channel SET locales=NULL WHERE 1");
        } elseif (!empty($_SESSION['isMultilangActive'])) {
            /** @var array $deletedLocales */
            $deletedLocales = array_diff($_SESSION['inputLanguageList'], $this->getConfig()->get('inputLanguageList', []));

            /** @var Channel[] $channels */
            $channels = $this
                ->getEntityManager()
                ->getRepository('Channel')
                ->select(['id', 'locales'])
                ->find();

            if (count($channels) > 0) {
                foreach ($channels as $channel) {
                    if (!empty($locales = $channel->get('locales'))) {
                        $newLocales = [];
                        foreach ($locales as $locale) {
                            if (!in_array($locale, $deletedLocales)) {
                                $newLocales[] = $locale;
                            }
                        }
                        $channel->set('locales', $newLocales);
                        $this->getEntityManager()->saveEntity($channel);
                    }
                }
            }
        }
    }

    /**
     * Remove unnecessary config fields
     */
    protected function removeConfigFields()
    {
        $config = $this->getConfig();

        foreach (array_values($this->removeFields) as $field) {
            if ($config->has($field)) {
                $config->remove($field);
            }
        }
        $config->save();
    }
}